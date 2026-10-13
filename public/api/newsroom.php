<?php
// public/api/newsroom.php: what the Newsroom page may ask the server for. The page runs in a sandbox with no access to the admin session, so
// this endpoint never looks at cookies. It accepts only the limited pass (header X-News-Pass) that the admin page hands the Newsroom, and it
// answers cross-origin requests from the sandbox (which has no origin) with CORS headers that allow no credentials.
//   GET  ?action=feed&url=..&count=20   a news feed as a clean list of stories (cached ten minutes)
//   GET  ?action=article&url=..         an article's page, reduced to harmless markup
//   GET  ?action=image&url=..           a picture, so the page can use it without the browser's cross-site limits
//   POST ?action=ai   {kind, model?, body}   the AI helper, using the company's key
//   POST ?action=pref {k, v}            save bookmarks or custom feeds for this person
//   POST ?action=paper_begin {size, name} / ?action=paper_chunk&id=&offset= (raw bytes) / ?action=paper_finish {id}
//                                       keep a copy of a finished newspaper for two days and get a link to it
//   GET  ?action=paper&id=&exp=&sig=    that link (needs no pass: the address carries its own signature)
// Use is limited per person, generously (a newspaper makes dozens of calls), and only a few AI calls run at the same moment.

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Newsroom\{Ai, Data, Feed, Limit, Net, Papers, Pass};

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-News-Pass, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 600');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$action = (string)($_GET['action'] ?? '');

// A saved newspaper is opened with its signed address, not a pass (it is opened by a browser, not by the page).
if ($action === 'paper') {
    rate_limit('news_paper_get', 60);
    $f = Papers::open((string)($_GET['id'] ?? ''), (int)($_GET['exp'] ?? 0), (string)($_GET['sig'] ?? ''));
    if (!$f) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'This link has expired or is not valid.'; exit; }
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $f['name'] . '"'); header('Content-Length: ' . filesize($f['path'])); header('Cache-Control: private, no-store');
    readfile($f['path']); exit;
}

$aid = Pass::verify((string)($_SERVER['HTTP_X_NEWS_PASS'] ?? ''));
if ($aid === null) json_err('Your Newsroom session has ended. Please reload this tab.', 401);

$url  = (string)($_GET['url'] ?? '');
$fail = static function (\Throwable $e): never {
    if ($e instanceof \InvalidArgumentException) json_err($e->getMessage(), 422);
    error_log('newsroom: ' . get_class($e) . ': ' . $e->getMessage());
    json_err('That could not be fetched right now.', 502);
};
$within = static function (string $what, int $perMinute) use ($aid): void { if (!Limit::hit($aid, $what, $perMinute)) { header('Retry-After: 5'); json_err('You are going too fast. Please wait a moment.', 429); } };

try {
    switch ($action) {
        case 'feed': {
            $within('news_feed', 600);
            $count = max(1, min(50, (int)($_GET['count'] ?? 20)));
            $body = Data::cached("feed|{$count}|{$url}", 600, function () use ($url, $count) {
                $r = Net::fetch($url, ['maxBytes' => 2_500_000, 'accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5']);
                if ($r['status'] >= 400) return json_encode(['status' => 'error', 'message' => 'That feed could not be read (HTTP ' . $r['status'] . ').']);
                return json_encode(Feed::parse($r['body'], $count) ?? ['status' => 'error', 'message' => 'That address is not a news feed.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            });
            header('Content-Type: application/json; charset=utf-8'); echo $body; exit;
        }
        case 'article': {
            $within('news_article', 400);
            $body = Data::cached("article|{$url}", 600, function () use ($url) {
                $r = Net::fetch($url, ['maxBytes' => 3_000_000, 'accept' => 'text/html, application/xhtml+xml;q=0.9, */*;q=0.3']);
                if ($r['status'] >= 400) return json_encode(['contents' => '']);
                $html = mb_check_encoding($r['body'], 'UTF-8') ? $r['body'] : mb_convert_encoding($r['body'], 'UTF-8', 'Windows-1252');
                $inner = preg_match('~<body[^>]*>(.*)</body>~is', $html, $m) ? $m[1] : $html;
                return json_encode(['contents' => Feed::html($inner, 400000, ['nav', 'header', 'footer', 'aside', 'figure', 'menu', 'dialog'])], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            });
            header('Content-Type: application/json; charset=utf-8'); echo $body; exit;
        }
        case 'image': {
            $within('news_image', 600);
            $r = Net::fetch($url, ['maxBytes' => 8_000_000, 'accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1', 'timeout' => 10]);
            if ($r['status'] !== 200 || !in_array($r['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true) || strlen($r['body']) < 100) json_err('That is not a usable picture.', 404);
            header('Content-Type: ' . $r['type']); header('Cache-Control: private, max-age=86400'); echo $r['body']; exit;
        }
        case 'ai': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
            $within('news_ai', 600);
            $raw = (string)file_get_contents('php://input', false, null, 0, Ai::MAX_BODY + 2000);
            $req = Ai::clean(json_decode($raw, true) ?: []);
            $key = \Gemini\Client::getStoredApiKey();
            if (!$key) json_err('The AI helper is not set up yet (no Gemini key).', 503);
            $slot = Limit::acquire('news-ai', 4);                 // each call holds a server worker until Google answers, so only a few run together
            if (!$slot) { header('Retry-After: 3'); json_err('The AI helper is busy. Please try again.', 503); }
            $model = $req['model'] ?? \Writer\Editor::model();
            try { $out = (new \Gemini\Client($key))->generateRaw($model, $req['body'], $req['image'] ? 90 : 60); }
            catch (\RuntimeException $e) {
                Limit::release($slot);
                $code = preg_match('/error (\d{3})/', $e->getMessage(), $m) ? (int)$m[1] : 502;
                error_log('newsroom ai: ' . $e->getMessage());
                json_out(['error' => in_array($code, [429, 503], true) ? 'The AI helper is busy. Please try again.' : 'The AI helper could not answer that.'], in_array($code, [404, 429, 503], true) ? $code : 502);
            }
            Limit::release($slot);
            json_out($out);
        }
        case 'pref': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
            $within('news_pref', 120);
            $b = json_decode((string)file_get_contents('php://input', false, null, 0, Data::MAX_PREF + 1000), true) ?: [];
            Data::setPref($aid, (string)($b['k'] ?? ''), (string)($b['v'] ?? ''));
            json_out(['ok' => true]);
        }
        case 'paper_begin': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
            $within('news_paper', 60);
            $b = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
            json_out(['id' => Papers::begin($aid, (int)($b['size'] ?? 0), (string)($b['name'] ?? ''))]);
        }
        case 'paper_chunk': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
            $within('news_paper_chunk', 600);
            $bytes = (string)file_get_contents('php://input', false, null, 0, Papers::CHUNK_MAX + 1);
            json_out(['received' => Papers::chunk((string)($_GET['id'] ?? ''), $aid, (int)($_GET['offset'] ?? -1), $bytes)]);
        }
        case 'paper_finish': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
            $within('news_paper', 60);
            $b = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true) ?: [];
            $p = Papers::finish((string)($b['id'] ?? ''), $aid);
            $host = preg_match('/\A[A-Za-z0-9.-]+(:\d+)?\z/', (string)($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : 'blakegroup.uk';
            json_out(['url' => "https://{$host}/api/newsroom.php?action=paper&id={$p['id']}&exp={$p['exp']}&sig={$p['sig']}", 'expires' => $p['exp'], 'name' => $p['name']]);
        }
    }
    json_err('Unknown action', 400);
} catch (\Throwable $e) { $fail($e); }

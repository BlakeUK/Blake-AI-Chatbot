<?php
// public/api/admin/import_page_links.php — POST: import selected non-PDF
// links (from discover_urls.php's other_links) as knowledge entries.
//
// Deliberately not routed through import_urls.php/knowledge_files: these
// are live HTML pages, not downloadable documents, and text/html isn't in
// that endpoint's allowed-mimetype list for good reason (raw page HTML is
// full of nav/header/footer noise, not the kind of content that belongs in
// the same pending-file-then-Gemini-extraction pipeline as a PDF manual).
// Instead: fetch each page, pull just <title> and the meta description
// (cheap, no Gemini call needed), and store that as a knowledge entry
// carrying the URL - enough for the bot to know the page exists and link
// to it, e.g. "browse our CCTV range: https://www.blake-uk.com/cctv.html".

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$raw  = $body['urls'] ?? '';
$urls = is_array($raw) ? $raw : preg_split('/[\s,]+/', trim((string)$raw));
$urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

if (!$urls) {
    json_err('At least one URL required');
}

// Hand-picked via checkboxes, not a bulk sitemap dump - a much smaller cap
// than import_urls.php's is appropriate, and each entry here does a real
// fetch synchronously (no pending/cron deferral), so this also bounds the
// request's own runtime.
const MAX_BATCH = 50;
if (count($urls) > MAX_BATCH) {
    json_err('Too many URLs in one batch (max ' . MAX_BATCH . ') — submit in smaller batches');
}

set_time_limit(120);

$pdo     = db();
$results = [];

foreach ($urls as $url) {
    $result = ['url' => $url];

    if (!preg_match('#^https?://#i', $url)) {
        $results[] = $result + ['status' => 'error', 'error' => 'Not a valid http(s) URL'];
        continue;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'BlakeUKChatbotImporter/1.0',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $code !== 200) {
        $results[] = $result + ['status' => 'error', 'error' => $err ?: "HTTP {$code}"];
        continue;
    }

    $title = _extract_title($data) ?: $url;
    $desc  = _extract_meta_description($data);
    $body_text = trim($title . ($desc ? "\n\n{$desc}" : ''));

    $pdo->prepare('
        INSERT INTO knowledge_entries (title, body, category, product_codes, url, active)
        VALUES (?, ?, NULL, NULL, ?, 1)
    ')->execute([$title, $body_text, $url]);
    $id = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url) VALUES (?,?,?,?)')
        ->execute(['manual', $id, $title . ' ' . $body_text, $url]);

    $results[] = $result + ['status' => 'imported', 'title' => $title];
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'page_links_imported', null, count(array_filter($results, fn($r) => $r['status'] === 'imported')) . ' of ' . count($urls)]);

json_out(['results' => $results]);

function _extract_title(string $html): ?string
{
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    }
    return null;
}

function _extract_meta_description(string $html): ?string
{
    if (preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $m)
        || preg_match('/<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/is', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }
    return null;
}

<?php
// public/check/check_link.php
// Lightweight "is this URL broken" check for the optional broken-link/
// broken-image pass in public/check/index.html - unlike probe.php this
// doesn't run Sitemap\PageAnalyzer or care about SEO signals, just
// whether the URL loads. Deliberately not restricted to
// Sitemap\AllowedSites: a broken-*link* checker has to be able to check
// links a page points *off-site* to (that's the whole point - a dead
// partner/resource link is still a real finding), so this is the one
// check/ endpoint that fetches arbitrary public URLs.
//
// Still SSRF-safe the same way every other endpoint here is: every hop,
// including ones reached only by following a redirect, is validated with
// Http\SafeFetcher::assertPublicUrl() (rejects anything resolving to a
// private/loopback/link-local/reserved IP). Sits behind
// Auth\CheckTool::requireAuth() like the rest of /check/, so this isn't
// reachable as an anonymous open-fetch proxy despite allowing arbitrary
// hosts.
//
// Tries HEAD first (cheap - no body download) since that's all a broken-
// link check needs; falls back to a capped GET for servers that reject
// HEAD (some CDNs/older servers do, with a 405/501 or a hard connection
// error) - capped small (50KB) because a link can point at a large file
// (PDF, video, archive) and this only needs enough to know the request
// succeeded, not the file's actual content.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();
\Auth\CheckTool::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

// Broken-link/image passes can easily touch far more distinct URLs than
// the page-count itself (every unique image/link across the whole
// scan), so this needs the same generous ceiling probe.php's rate limit
// needed for the same reason - a normal 5-concurrency pass over a few
// hundred distinct URLs would otherwise self-trigger a low limit.
rate_limit('check_link', 6000);

const LINK_MAX_REDIRECTS = 5;
const LINK_MAX_BODY_BYTES = 50000;
const LINK_CONNECT_TIMEOUT = 6;
const LINK_REQUEST_TIMEOUT = 12;

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '' || !preg_match('#^https?://#i', $url)) json_err('A valid http(s) url is required');

$start = microtime(true);
$elapsedMs = fn() => (int)round((microtime(true) - $start) * 1000);

function link_probe(string $url, string $method): array
{
    $current = $url;
    $chainLen = 0;

    for ($hop = 0; $hop <= LINK_MAX_REDIRECTS; $hop++) {
        try {
            \Http\SafeFetcher::assertPublicUrl($current);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'type' => 'fetch_error', 'error' => $e->getMessage()];
        }

        $headers = [];
        $bodyBuf = '';
        $truncated = false;

        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_CONNECTTIMEOUT => LINK_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => LINK_REQUEST_TIMEOUT,
            CURLOPT_USERAGENT      => 'BlakeUKSiteChecker/1.0 (+https://blakegroup.uk)',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bodyBuf, &$truncated) {
                if ($truncated) return 0;
                $bodyBuf .= $chunk;
                if (strlen($bodyBuf) >= LINK_MAX_BODY_BYTES) { $truncated = true; return 0; }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 && !$truncated) {
            $type = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'fetch_error';
            return ['ok' => false, 'type' => $type, 'error' => $err ?: 'Request failed'];
        }

        $chainLen++;

        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = $headers['location'] ?? null;
            if (!$location) return ['ok' => false, 'type' => 'fetch_error', 'error' => "HTTP {$code} redirect with no Location header"];
            $current = \Sitemap\UrlHelper::resolve($current, $location);
            continue;
        }

        return [
            'ok' => true,
            'code' => $code,
            'final_url' => $current,
            'redirect_count' => $chainLen - 1,
        ];
    }

    return ['ok' => false, 'type' => 'fetch_error', 'error' => 'Too many redirects'];
}

$result = link_probe($url, 'HEAD');

// Some servers reject HEAD outright (405/501) or behave oddly with it -
// a broken-link checker's job is "does GET work", so retry with GET
// rather than reporting a false positive off a HEAD-specific quirk.
if (($result['ok'] && in_array($result['code'], [405, 501], true)) || (!$result['ok'] && $result['type'] === 'fetch_error')) {
    $result = link_probe($url, 'GET');
}

if (!$result['ok']) {
    json_out([
        'ok' => false, 'url' => $url, 'type' => $result['type'],
        'error' => $result['error'], 'response_time_ms' => $elapsedMs(),
    ]);
}

$type = $result['code'] >= 200 && $result['code'] < 400 ? 'ok' : 'http_error';

json_out([
    'ok'               => true,
    'url'              => $url,
    'type'             => $type,
    'status'           => $result['code'],
    'final_url'        => $result['final_url'],
    'redirect_count'   => $result['redirect_count'],
    'response_time_ms' => $elapsedMs(),
]);

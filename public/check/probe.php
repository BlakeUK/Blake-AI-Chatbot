<?php
// public/check/probe.php
// Single-URL diagnostic probe for the sitemap/SEO health checker
// (public/check/index.html): follows redirects itself (rather than
// leaving that to curl) so every hop's individual status code is visible
// - distinguishing a 301 from a 302/307/308 chain, which a plain
// fetch()-based checker in the browser can't do, since fetch() silently
// follows redirects before JS ever sees the intermediate response - then
// runs Sitemap\PageAnalyzer over the final HTML for title/meta/canonical/
// robots/soft-404 signals.
//
// Deliberately unauthenticated - this is a self-serve QA tool, not an
// admin feature - but scoped tightly so it can't become an open
// fetch-any-URL proxy or an SSRF pivot into the VPS's own network:
//   - the URL a caller supplies (?url=) must resolve to blake-uk.com or
//     www.blake-uk.com; anything else is refused before any request is made.
//   - every hop, including ones reached only by following a redirect, is
//     re-validated with Http\SafeFetcher::assertPublicUrl() (rejects
//     anything resolving to a private/loopback/link-local/reserved IP) -
//     a redirect off-domain is allowed to happen (and is reported to the
//     user as "external redirect", which is itself a useful finding) but
//     never to somewhere internal.
//   - rate-limited per IP the same way the rest of this app's public
//     endpoints are.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

// The frontend runs 5 probes concurrently; on a fast-responding site
// that alone can sustain well over 600 requests/minute (5 workers x a
// few hundred ms per page easily clears 10+ req/sec), so a limit that
// low was self-triggering partway through an entirely normal scan -
// not an abuse case, just this tool tripping its own guard. Scoped to
// blake-uk.com only anyway (the host allowlist above), so a higher
// ceiling here doesn't meaningfully change what it protects against.
rate_limit('check_probe', 6000);

const PROBE_ALLOWED_HOSTS = ['blake-uk.com', 'www.blake-uk.com'];
const PROBE_MAX_REDIRECTS = 5;
// Real-world pages - especially e-commerce ones loaded with tracking
// pixels, chat widgets, review-platform embeds and inlined JSON-LD -
// routinely land well past a few hundred KB of HTML alone, so this needs
// to be generous rather than "plenty for <head> plus a bit of body": the
// first cut (400,000) was tripping on essentially every page on the site.
// 3MB still bounds worst-case memory/time per request to something a
// PHP-FPM worker handles comfortably.
const PROBE_MAX_BODY_BYTES = 3000000;
const PROBE_CONNECT_TIMEOUT = 6;
const PROBE_REQUEST_TIMEOUT = 15;

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '') json_err('url required');

$startHost = parse_url($url, PHP_URL_HOST);
if (!$startHost || !in_array(strtolower($startHost), PROBE_ALLOWED_HOSTS, true)) {
    json_out([
        'ok' => false, 'type' => 'disallowed_host', 'url' => $url,
        'error' => 'This tool only checks pages on blake-uk.com',
    ]);
}

$start = microtime(true);
$elapsedMs = fn() => (int)round((microtime(true) - $start) * 1000);

$current = $url;
$chain = [];
$response = null;

for ($hop = 0; $hop <= PROBE_MAX_REDIRECTS; $hop++) {
    try {
        \Http\SafeFetcher::assertPublicUrl($current);
    } catch (\RuntimeException $e) {
        json_out([
            'ok' => false, 'type' => 'fetch_error', 'url' => $url, 'final_url' => $current,
            'chain' => $chain, 'error' => $e->getMessage(), 'response_time_ms' => $elapsedMs(),
        ]);
    }

    $headers = [];
    $bodyBuf = '';
    $truncated = false;

    $ch = curl_init($current);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => PROBE_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => PROBE_REQUEST_TIMEOUT,
        CURLOPT_USERAGENT      => 'BlakeUKSiteChecker/1.0 (+https://blakegroup.uk)',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        // Advertises and auto-decodes whatever compressed encodings this
        // libcurl build supports. WRITEFUNCTION still sees the fully
        // decompressed bytes either way (curl decodes before invoking it,
        // so this doesn't change what counts against
        // PROBE_MAX_BODY_BYTES) - the point is transfer speed: fetching
        // real HTML as gzip/br over the wire instead of plain text cuts
        // the time spent inside PROBE_REQUEST_TIMEOUT on a slow or
        // metered connection to the target site.
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bodyBuf, &$truncated) {
            if ($truncated) return 0;
            $bodyBuf .= $chunk;
            if (strlen($bodyBuf) >= PROBE_MAX_BODY_BYTES) {
                $truncated = true;
                return 0; // aborts the transfer once we have enough to analyse
            }
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // A deliberate WRITEFUNCTION-return-0 abort (once we've read enough
    // body) also surfaces as a curl error (CURLE_WRITE_ERROR) - that's
    // not a real failure, so don't let it fall into the error branch below.
    if ($errno !== 0 && !$truncated) {
        $type = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'fetch_error';
        json_out([
            'ok' => false, 'type' => $type, 'url' => $url, 'final_url' => $current,
            'chain' => $chain, 'error' => $err ?: 'Request failed', 'response_time_ms' => $elapsedMs(),
        ]);
    }

    $chain[] = ['url' => $current, 'status' => $code];

    if (in_array($code, [301, 302, 303, 307, 308], true)) {
        $location = $headers['location'] ?? null;
        if (!$location) {
            json_out([
                'ok' => false, 'type' => 'fetch_error', 'url' => $url, 'final_url' => $current,
                'chain' => $chain, 'error' => "HTTP {$code} redirect with no Location header",
                'response_time_ms' => $elapsedMs(),
            ]);
        }
        $current = \Sitemap\UrlHelper::resolve($current, $location);
        continue;
    }

    $response = ['code' => $code, 'headers' => $headers, 'body' => $bodyBuf, 'truncated' => $truncated, 'final_url' => $current];
    break;
}

if ($response === null) {
    json_out([
        'ok' => false, 'type' => 'fetch_error', 'url' => $url,
        'chain' => $chain, 'error' => 'Too many redirects', 'response_time_ms' => $elapsedMs(),
    ]);
}

$contentType = $response['headers']['content-type'] ?? '';
$isHtml = $contentType === '' || stripos($contentType, 'text/html') !== false;
$finalHost = parse_url($response['final_url'], PHP_URL_HOST) ?: '';
$redirectCount = count($chain) - 1;
$externalRedirect = $redirectCount > 0 && !in_array(strtolower($finalHost), PROBE_ALLOWED_HOSTS, true);

$analysis = $isHtml
    ? \Sitemap\PageAnalyzer::analyze($response['body'], $response['final_url'])
    : \Sitemap\PageAnalyzer::empty();

$xRobots = $response['headers']['x-robots-tag'] ?? '';
$noindexHeader = $xRobots !== '' && stripos($xRobots, 'noindex') !== false;

if ($response['code'] >= 200 && $response['code'] < 300) {
    $type = $redirectCount > 0 ? 'redirect' : 'ok';
} elseif ($response['code'] >= 300 && $response['code'] < 400) {
    $type = 'redirect'; // e.g. a bare 304 with no Location, reached without a preceding hop
} else {
    $type = 'http_error';
}

json_out(array_merge($analysis, [
    'ok'                => true,
    'type'              => $type,
    'url'               => $url,
    'final_url'         => $response['final_url'],
    'status'            => $response['code'],
    'chain'             => $chain,
    'redirect_count'    => $redirectCount,
    'external_redirect' => $externalRedirect,
    'https'             => stripos($response['final_url'], 'https://') === 0,
    'content_type'      => $contentType,
    'content_encoding'  => $response['headers']['content-encoding'] ?? null,
    'is_html'           => $isHtml,
    'bytes'             => strlen($response['body']),
    'body_truncated'    => $response['truncated'],
    'response_time_ms'  => $elapsedMs(),
    'noindex_header'    => $noindexHeader,
    'noindex'           => $analysis['noindex_meta'] || $noindexHeader,
]));

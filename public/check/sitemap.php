<?php
// public/check/sitemap.php
// Fetches (and, for a sitemap index, recursively expands one level of
// child sitemaps) blake-uk.com's sitemap, returning the flat list of page
// URLs for public/check/index.html to then probe one at a time via
// probe.php.
//
// Deliberately scoped to blake-uk.com's own sitemap files rather than an
// arbitrary URL an unauthenticated caller supplies - ?url= only ever
// selects which sitemap document under that domain to read (the top-level
// one, or - if this is ever called directly rather than through the UI -
// a specific child sitemap), never an unrelated third-party site. See
// probe.php's header comment for why that restriction matters.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

rate_limit('check_sitemap', 20);

const CHECK_ALLOWED_HOSTS = ['blake-uk.com', 'www.blake-uk.com'];
const CHECK_MAX_CHILD_SITEMAPS = 50;
const CHECK_MAX_URLS = 3000;
const CHECK_DEFAULT_SITEMAP = 'https://www.blake-uk.com/sitemap.xml';

function check_host_allowed(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    return $host !== null && $host !== false && in_array(strtolower($host), CHECK_ALLOWED_HOSTS, true);
}

function check_fetch_xml(string $url): array
{
    $fetch = \Http\SafeFetcher::get($url, 20);
    if (!$fetch['ok']) {
        return ['ok' => false, 'error' => $fetch['error'] ?: "HTTP {$fetch['code']}"];
    }
    return ['ok' => true, 'xml' => $fetch['body']];
}

$rootUrl = trim((string)($_GET['url'] ?? '')) ?: CHECK_DEFAULT_SITEMAP;
if (!check_host_allowed($rootUrl)) {
    json_err('This tool only reads sitemaps on blake-uk.com', 400);
}

$root = check_fetch_xml($rootUrl);
if (!$root['ok']) {
    json_out(['ok' => false, 'error' => "Could not fetch {$rootUrl}: {$root['error']}"]);
}

$parsed = \Sitemap\Parser::parse($root['xml']);
if ($parsed['type'] === 'error') {
    json_out(['ok' => false, 'error' => "{$rootUrl}: {$parsed['error']}"]);
}

$sitemapsScanned = [$rootUrl];
$urls = [];
$truncated = false;

if ($parsed['type'] === 'urlset') {
    $urls = $parsed['entries'];
} else {
    // sitemapindex: fetch each child (one level deep - real-world sitemap
    // indexes are essentially always exactly this shape) and merge their
    // <url> entries.
    $children = array_slice($parsed['entries'], 0, CHECK_MAX_CHILD_SITEMAPS);

    foreach ($children as $child) {
        if (count($urls) >= CHECK_MAX_URLS) { $truncated = true; break; }

        if (!check_host_allowed($child['loc'])) {
            // A sitemap index pointing off-domain is itself worth flagging
            // as odd, but not worth following - skip rather than fetch it.
            $sitemapsScanned[] = $child['loc'] . ' (skipped: not on blake-uk.com)';
            continue;
        }

        $childFetch = check_fetch_xml($child['loc']);
        if (!$childFetch['ok']) {
            $sitemapsScanned[] = $child['loc'] . ' (failed: ' . $childFetch['error'] . ')';
            continue;
        }

        $sitemapsScanned[] = $child['loc'];
        $childParsed = \Sitemap\Parser::parse($childFetch['xml']);
        if ($childParsed['type'] === 'urlset') {
            $urls = array_merge($urls, $childParsed['entries']);
        }
    }
}

if (count($urls) > CHECK_MAX_URLS) {
    $urls = array_slice($urls, 0, CHECK_MAX_URLS);
    $truncated = true;
}

// Duplicate-URL detection across the whole merged list (including across
// different child sitemaps, which is exactly the kind of mistake this
// should catch) - done as a second pass over the final list rather than
// while collecting, so every occurrence of a duplicate gets flagged, not
// just the second-and-later ones.
$counts = array_count_values(array_column($urls, 'loc'));
$out = [];
foreach ($urls as $u) {
    $host = parse_url($u['loc'], PHP_URL_HOST) ?: '';
    $out[] = [
        'loc'             => $u['loc'],
        'lastmod'         => $u['lastmod'] ?? null,
        'https'           => stripos($u['loc'], 'https://') === 0,
        'outside_domain'  => !in_array(strtolower($host), CHECK_ALLOWED_HOSTS, true),
        'duplicate'       => ($counts[$u['loc']] ?? 1) > 1,
    ];
}

json_out([
    'ok'               => true,
    'source'           => $rootUrl,
    'root_type'        => $parsed['type'],
    'sitemaps_scanned' => $sitemapsScanned,
    'urls'             => $out,
    'count'            => count($out),
    'truncated'        => $truncated,
]);

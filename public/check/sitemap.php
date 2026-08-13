<?php
// public/check/sitemap.php
// Fetches (and, for a sitemap index, recursively expands one level of
// child sitemaps) a site's sitemap, returning the flat list of page URLs
// for public/check/index.html to then probe one at a time via probe.php.
// Falls back to Sitemap\HtmlLinkExtractor - pulling same-site <a href>
// links off the page instead - when the URL isn't valid sitemap XML at
// all, for sites with no XML sitemap that instead publish an HTML
// "sitemap" page or rely on a directory-listing index for navigation.
//
// Deliberately scoped to Sitemap\AllowedSites' fixed list of sites rather
// than an arbitrary URL an unauthenticated caller supplies - ?url= only
// ever selects which document under one of those sites to read (the
// top-level sitemap, a specific child sitemap, or an HTML navigation
// page), never an unrelated third-party site. See probe.php's header
// comment for why that restriction matters.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

rate_limit('check_sitemap', 20);

const CHECK_MAX_CHILD_SITEMAPS = 50;
const CHECK_MAX_URLS = 3000;
const CHECK_DEFAULT_SITEMAP = 'https://www.blake-uk.com/sitemap.xml';

function check_fetch(string $url): array
{
    $fetch = \Http\SafeFetcher::get($url, 20);
    if (!$fetch['ok']) {
        return ['ok' => false, 'error' => $fetch['error'] ?: "HTTP {$fetch['code']}"];
    }
    return ['ok' => true, 'body' => $fetch['body']];
}

$rootUrl = trim((string)($_GET['url'] ?? '')) ?: CHECK_DEFAULT_SITEMAP;
if (!\Sitemap\AllowedSites::isUrlAllowed($rootUrl)) {
    json_err('This tool only reads sitemaps on a fixed list of allowed sites', 400);
}

$root = check_fetch($rootUrl);
if (!$root['ok']) {
    json_out(['ok' => false, 'error' => "Could not fetch {$rootUrl}: {$root['error']}"]);
}

$parsed = \Sitemap\Parser::parse($root['body']);
$usedHtmlFallback = false;

if ($parsed['type'] === 'error') {
    $htmlLinks = \Sitemap\HtmlLinkExtractor::extractLinks($root['body'], $rootUrl);
    if (!$htmlLinks) {
        json_out(['ok' => false, 'error' => "{$rootUrl}: {$parsed['error']}, and no same-site links were found on the page either"]);
    }
    $parsed = ['type' => 'urlset', 'entries' => $htmlLinks];
    $usedHtmlFallback = true;
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

        if (!\Sitemap\AllowedSites::isUrlAllowed($child['loc'])) {
            // A sitemap index pointing off-allowlist is itself worth
            // flagging as odd, but not worth following - skip rather than fetch it.
            $sitemapsScanned[] = $child['loc'] . ' (skipped: not an allowed site)';
            continue;
        }

        $childFetch = check_fetch($child['loc']);
        if (!$childFetch['ok']) {
            $sitemapsScanned[] = $child['loc'] . ' (failed: ' . $childFetch['error'] . ')';
            continue;
        }

        $sitemapsScanned[] = $child['loc'];
        $childParsed = \Sitemap\Parser::parse($childFetch['body']);
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
        'outside_domain'  => !\Sitemap\AllowedSites::isHostAllowed($host),
        'duplicate'       => ($counts[$u['loc']] ?? 1) > 1,
    ];
}

json_out([
    'ok'                 => true,
    'source'             => $rootUrl,
    'root_type'          => $usedHtmlFallback ? 'html_links' : $parsed['type'],
    'used_html_fallback' => $usedHtmlFallback,
    'sitemaps_scanned'   => $sitemapsScanned,
    'urls'               => $out,
    'count'              => count($out),
    'truncated'          => $truncated,
]);

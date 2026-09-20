#!/usr/bin/env php
<?php
// scripts/refresh_site_pages.php — run on a schedule (e.g. daily) to keep
// page-imported knowledge content in sync with the live site, instead of
// relying on an admin remembering to re-run "Scan for Pages" whenever
// something changes.
//
// Reads the sitemap URL(s) configured under Files/RAG -> Pages ->
// Scheduled Site Refresh (settings keys site_sitemap_urls, JSON array,
// and site_refresh_days), discovers every page URL (expanding one level
// of <sitemapindex> if the site uses one), and re-indexes via
// Knowledge\PageIndexer any page that's new or older than the configured
// refresh interval. A no-op (exit 0) if no sitemap URL is configured.
//
// Deliberately conservative about how much it does per run - a full-site
// crawl on every invocation would be wasteful and could look like a
// scrape to the live site; only pages actually due for a refresh are
// fetched, in a bounded batch per run.

require dirname(__DIR__) . '/src/bootstrap.php';

const BATCH_LIMIT   = 40;   // pages indexed per run (runs every 15 minutes)
const TIME_BUDGET_S = 240;

$pdo = db();

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : $val;
}

// Defaults to the live sitemap so the refresh runs even if nobody has
// configured it in the admin (it used to do nothing at all in that case).
$sitemapUrls = json_decode(get_setting($pdo, 'site_sitemap_urls') ?? '[]', true) ?: [\Products\SiteScraper::BASE_URL . '/sitemap.xml'];

$refreshDays = (int)(get_setting($pdo, 'site_refresh_days') ?? 7);
$staleBefore = time() - max(1, $refreshDays) * 86400;

$pageUrls = [];
foreach ($sitemapUrls as $sitemapUrl) {
    $pageUrls = array_merge($pageUrls, discover_urls_from_sitemap($sitemapUrl));
}
// The sitemap misses the category pages entirely, so the site is also
// crawled from the homepage through its category listings (cached for a
// week) and those URLs are refreshed too.
$pageUrls = array_merge($pageUrls, discover_by_crawl($pdo));
$pageUrls = array_values(array_unique(array_filter(array_map('normalise_page_url', $pageUrls))));

if (!$pageUrls) {
    echo "Configured sitemap(s) yielded no page URLs.\n";
    exit(0);
}

// Only pages that are new (never indexed) or stale (older than the
// configured interval) are due for a refresh.
$placeholders  = implode(',', array_fill(0, count($pageUrls), '?'));
$existingRows  = $pdo->prepare("SELECT url, updated_at FROM knowledge_entries WHERE url IN ($placeholders)");
$existingRows->execute($pageUrls);
$updatedAtByUrl = [];
foreach ($existingRows->fetchAll() as $r) {
    $updatedAtByUrl[$r['url']] = (int)$r['updated_at'];
}

$due = array_values(array_filter($pageUrls, fn($url) =>
    !isset($updatedAtByUrl[$url]) || $updatedAtByUrl[$url] < $staleBefore
));

if (!$due) {
    echo count($pageUrls) . " page(s) discovered, all up to date.\n";
    exit(0);
}

echo count($due) . " of " . count($pageUrls) . " page(s) due for refresh.\n";

$start = time();
$done  = 0;
foreach (array_slice($due, 0, BATCH_LIMIT) as $url) {
    if (time() - $start > TIME_BUDGET_S) {
        echo "Time budget reached, stopping — remaining pages will run next invocation.\n";
        break;
    }
    try {
        $result = \Knowledge\PageIndexer::indexPage($url);
        echo "{$url}: {$result['status']} ({$result['chunk_count']} chunk(s)).\n";
    } catch (\Throwable $e) {
        echo "{$url}: error - {$e->getMessage()}\n";
    }
    $done++;
}

$remaining = count($due) - $done;
echo "Processed {$done} page(s)." . ($remaining > 0 ? " {$remaining} still due, will run next invocation." : '') . "\n";

// ── URL tidying and crawl discovery ─────────────────────────────────────────

// Same page, one address: decode %2d-style escapes, drop query/fragment.
function normalise_page_url(string $u): string
{
    $u = trim(html_entity_decode($u));
    if (!preg_match('#^https?://#i', $u)) return '';
    [$u] = explode('#', $u, 2);
    [$u] = explode('?', $u, 2);
    $p = parse_url($u);
    if (!$p || empty($p['host'])) return '';
    $path = rawurldecode($p['path'] ?? '/');
    return 'https://' . strtolower($p['host']) . $path;
}

// Crawls the homepage and every category listing it leads to (including
// pagination) for internal .html pages. Cached in settings for a week: a
// full crawl on every run would be wasteful and looks like scraping.
function discover_by_crawl(PDO $pdo, int $maxFetches = 260): array
{
    $cached = json_decode(get_setting($pdo, 'site_crawl_urls') ?? '{}', true) ?: [];
    if (!empty($cached['at']) && time() - (int)$cached['at'] < 7 * 86400 && !empty($cached['urls'])) {
        return $cached['urls'];
    }
    $base = \Products\SiteScraper::BASE_URL;
    $queue = [$base . '/'];
    $seen = [$base . '/' => true];
    $found = [];
    $fetches = 0;
    while ($queue && $fetches < $maxFetches) {
        $u = array_shift($queue);
        $f = \Http\SafeFetcher::get($u, 30);
        $fetches++;
        if (!$f['ok'] || !preg_match_all('#href=["\']([^"\'\#]+)["\']#i', (string)$f['body'], $m)) continue;
        foreach ($m[1] as $h) {
            $h = html_entity_decode($h);
            if (str_starts_with($h, '/')) $h = $base . $h;
            if (!preg_match('#^https?://(www\.)?blake-uk\.com/#i', $h)) continue;
            $n = normalise_page_url($h);
            if ($n === '' || !str_ends_with($n, '.html')) continue;
            if (preg_match('#/(customer|checkout|cart|account|wishlist|login|search|compare)#i', $n)) continue;
            $found[$n] = true;
            if (str_contains($n, '/category/')) {
                $q = (string)parse_url($h, PHP_URL_QUERY);
                $page = preg_match('/(?:^|&)p=(\d+)/', $q, $pm) ? '?p=' . $pm[1] : '';
                $key = $n . $page;
                if (!isset($seen[$key])) { $seen[$key] = true; $queue[] = $key; }
            }
        }
        usleep(200000);
    }
    $urls = array_keys($found);
    if ($urls) {
        $pdo->prepare("INSERT INTO settings (key,value,updated_at) VALUES ('site_crawl_urls',?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
            ->execute([json_encode(['at' => time(), 'urls' => $urls]), time()]);
    }
    echo count($urls) . " page(s) discovered by crawling ({$fetches} fetches).\n";
    return $urls;
}

// ── Sitemap discovery ────────────────────────────────────────────────────────

function fetch_sitemap_xml(string $url): ?string
{
    $fetch = \Http\SafeFetcher::get($url, 30);
    if (!$fetch['ok']) {
        echo "Could not fetch sitemap {$url}: " . ($fetch['error'] ?: "HTTP {$fetch['code']}") . "\n";
        return null;
    }
    return $fetch['body'];
}

// Expands a sitemap URL into page URLs, following one level of
// <sitemapindex> -> child sitemaps -> urls - matches the common case of a
// single index fanning out to a handful of per-section sitemaps, without
// a fully general recursive crawler for the rare deeper nesting.
function discover_urls_from_sitemap(string $sitemapUrl): array
{
    $xml = fetch_sitemap_xml($sitemapUrl);
    if ($xml === null) return [];

    $parsed = \Knowledge\PageIndexer::parseSitemapXml($xml);
    $urls   = $parsed['urls'];

    foreach ($parsed['child_sitemaps'] as $childUrl) {
        $childXml = fetch_sitemap_xml($childUrl);
        if ($childXml === null) continue;
        $urls = array_merge($urls, \Knowledge\PageIndexer::parseSitemapXml($childXml)['urls']);
    }

    return $urls;
}

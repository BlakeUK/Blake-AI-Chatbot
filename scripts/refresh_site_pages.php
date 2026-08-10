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

const BATCH_LIMIT   = 30;   // pages indexed per run
const TIME_BUDGET_S = 240;

$pdo = db();

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : $val;
}

$sitemapUrls = json_decode(get_setting($pdo, 'site_sitemap_urls') ?? '[]', true) ?: [];
if (!$sitemapUrls) {
    echo "No sitemap URLs configured (Files/RAG -> Pages -> Scheduled Site Refresh) — nothing to do.\n";
    exit(0);
}

$refreshDays = (int)(get_setting($pdo, 'site_refresh_days') ?? 7);
$staleBefore = time() - max(1, $refreshDays) * 86400;

$pageUrls = [];
foreach ($sitemapUrls as $sitemapUrl) {
    $pageUrls = array_merge($pageUrls, discover_urls_from_sitemap($sitemapUrl));
}
$pageUrls = array_values(array_unique(array_filter($pageUrls)));

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

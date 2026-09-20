<?php
// scripts/audit_site_coverage.php - read-only audit: has every page of
// blake-uk.com been captured? Compares the live sitemap(s) against what is
// indexed (products + page knowledge), reports missing/thin/stale pages, and
// crawls category/listing pages for internal links that are NOT in the
// sitemap (pages the sitemap-driven jobs would never find).
// Usage: php scripts/audit_site_coverage.php [--crawl=150] [--json=/path]
require dirname(__DIR__) . '/src/bootstrap.php';
$opts  = getopt('', ['crawl::', 'json::']);
$crawlMax = (int)($opts['crawl'] ?? 150);
$pdo = db();
$norm = function (string $u): string {
    $p = parse_url(trim($u));
    if (!$p || empty($p['host'])) return '';
    $h = preg_replace('/^www\./', '', strtolower($p['host']));
    $path = rtrim($p['path'] ?? '/', '/') ?: '/';
    return $h . $path;
};
$base = \Products\SiteScraper::BASE_URL;
$get = fn(string $u) => \Http\SafeFetcher::get($u, 45);

// 1. Sitemap(s)
$configured = json_decode((string)$pdo->query("SELECT value FROM settings WHERE key='site_sitemap_urls'")->fetchColumn(), true) ?: [];
$roots = array_values(array_unique(array_merge([$base . '/sitemap.xml'], $configured)));
$sitemap = [];
$sitemapFiles = [];
foreach ($roots as $r) {
    $queue = [$r]; $seen = [];
    while ($queue && count($seen) < 60) {
        $u = array_shift($queue);
        if (isset($seen[$u])) continue;
        $seen[$u] = true;
        $f = $get($u);
        if (!$f['ok']) { $sitemapFiles[$u] = 'FAILED ' . ($f['error'] ?: $f['code']); continue; }
        $px = \Knowledge\PageIndexer::parseSitemapXml((string)$f['body']);
        $sitemapFiles[$u] = count($px['urls']) . ' urls, ' . count($px['child_sitemaps']) . ' child sitemaps';
        foreach ($px['urls'] as $x) $sitemap[$norm($x)] = $x;
        foreach ($px['child_sitemaps'] as $c) $queue[] = $c;
    }
}
unset($sitemap['']);

// 2. What we hold
$products = [];
foreach ($pdo->query("SELECT url, active, name, price_inc_vat, description, updated_at FROM products WHERE url IS NOT NULL") as $r) $products[$norm($r['url'])] = $r;
$pages = [];
foreach ($pdo->query("SELECT url, active, title, length(body) blen, updated_at FROM knowledge_entries WHERE url IS NOT NULL AND url != ''") as $r) $pages[$norm($r['url'])] = $r;

$type = function (string $n): string {
    $path = substr($n, strpos($n, '/') ?: 0);
    if (preg_match('#^/category/#', $path)) return 'category';
    if (preg_match('#^/brand/#', $path)) return 'brand';
    if (preg_match('#^/(blog|news)/#', $path)) return 'blog/news';
    if (preg_match('#^/guides?/#', $path)) return 'guides';
    if (str_ends_with($path, '.html')) return 'page (.html)';
    return 'other';
};
$byType = []; $missing = []; $inactive = [];
foreach ($sitemap as $n => $orig) {
    $t = $type($n);
    $byType[$t]['total'] = ($byType[$t]['total'] ?? 0) + 1;
    $isProd = isset($products[$n]) && (int)$products[$n]['active'] === 1;
    $isPage = isset($pages[$n]) && (int)$pages[$n]['active'] === 1;
    if ($isProd) $byType[$t]['as_product'] = ($byType[$t]['as_product'] ?? 0) + 1;
    if ($isPage) $byType[$t]['as_page'] = ($byType[$t]['as_page'] ?? 0) + 1;
    if (!$isProd && !$isPage) { $missing[] = $orig; $byType[$t]['missing'] = ($byType[$t]['missing'] ?? 0) + 1; }
}
// Held but no longer in the sitemap
$gone = [];
foreach ($products as $n => $r) if ((int)$r['active'] === 1 && !isset($sitemap[$n]) && str_contains($n, 'blake-uk.com')) $gone[] = $r['url'];

// 3. Quality
$thinPages = []; foreach ($pages as $n => $r) if ((int)$r['active'] === 1 && (int)$r['blen'] < 300) $thinPages[] = $r['url'] . ' (' . $r['blen'] . ' chars)';
$noPrice = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1 AND (price_inc_vat IS NULL OR price_inc_vat=0)")->fetchColumn();
$noDesc  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1 AND (description IS NULL OR length(description) < 50)")->fetchColumn();
$staleP  = (int)$pdo->query("SELECT COUNT(*) FROM knowledge_entries WHERE active=1 AND url LIKE '%blake-uk.com%' AND updated_at < unixepoch()-14*86400")->fetchColumn();

// 4. Crawl the homepage and every category/listing page it leads to
// (including ?p= pagination) for internal links NOT in the sitemap.
$queue = [$base . '/']; $queued = [$base . '/' => true];
$orphans = []; $crawled = 0; $crawlFail = []; $categoryPages = [];
while ($queue && $crawled < $crawlMax) {
    $u = array_shift($queue);
    $f = $get($u);
    $crawled++;
    if (!$f['ok']) { $crawlFail[] = $u . ' (' . ($f['error'] ?: $f['code']) . ')'; continue; }
    if (!preg_match_all('#href=["\']([^"\'\#]+)["\']#i', (string)$f['body'], $m)) continue;
    foreach ($m[1] as $h) {
        $h = html_entity_decode($h);
        if (str_starts_with($h, '/')) $h = $base . $h;
        if (!preg_match('#^https?://(www\.)?blake-uk\.com/#i', $h)) continue;
        $n = $norm($h);
        $path = substr($n, strpos($n, '/'));
        if (!preg_match('#\.html$#', $path) || preg_match('#/(customer|checkout|cart|account|wishlist|login|search|compare)#i', $path)) continue;
        // follow category listings and their pagination
        if (preg_match('#^/category/#', $path)) {
            $categoryPages[$n] = true;
            $q = (string)parse_url($h, PHP_URL_QUERY);
            $key = $base . $path . (preg_match('/(?:^|&)p=(\d+)/', $q, $pm) ? '?p=' . $pm[1] : '');
            if (!isset($queued[$key])) { $queued[$key] = true; $queue[] = $key; }
        }
        if (!isset($sitemap[$n])) $orphans[$n] = ($orphans[$n] ?? 0) + 1;
    }
    usleep(150000);
}
$orphanHeld = 0; $orphanNotHeld = [];
foreach (array_keys($orphans) as $n) { if (isset($products[$n]) || isset($pages[$n])) $orphanHeld++; else $orphanNotHeld[] = $n; }
$catNotHeld = array_values(array_filter(array_keys($categoryPages), fn($n) => !isset($pages[$n])));
// thin pages that are not product pages (product detail lives in the products table)
$thinNonProduct = array_values(array_filter($thinPages, fn($t) => !isset($products[$norm(explode(' ', $t)[0])])));

$r = [
    'sitemap_files' => $sitemapFiles,
    'sitemap_urls' => count($sitemap),
    'configured_refresh_sitemaps' => $configured,
    'by_type' => $byType,
    'products_active' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1")->fetchColumn(),
    'pages_active' => (int)$pdo->query("SELECT COUNT(*) FROM knowledge_entries WHERE active=1 AND url LIKE '%blake-uk.com%'")->fetchColumn(),
    'missing_count' => count($missing),
    'missing_sample' => array_slice($missing, 0, 40),
    'held_but_not_in_sitemap' => count($gone),
    'thin_pages' => count($thinPages), 'thin_sample' => array_slice($thinPages, 0, 10),
    'products_without_price' => $noPrice, 'products_without_description' => $noDesc,
    'pages_older_than_14_days' => $staleP,
    'thin_non_product_pages' => count($thinNonProduct), 'thin_non_product_sample' => array_slice($thinNonProduct, 0, 15),
    'crawled_pages' => $crawled, 'crawl_failures' => count($crawlFail), 'crawl_failure_sample' => array_slice($crawlFail, 0, 10),
    'category_pages_found' => count($categoryPages), 'category_pages_not_indexed' => count($catNotHeld), 'category_not_indexed_sample' => array_slice($catNotHeld, 0, 20),
    'linked_but_not_in_sitemap' => count($orphans), 'of_which_already_held' => $orphanHeld,
    'not_in_sitemap_and_not_held' => count($orphanNotHeld), 'not_held_sample' => array_slice($orphanNotHeld, 0, 40),
];
if (!empty($opts['json'])) file_put_contents($opts['json'], json_encode(['missing' => $missing, 'orphans_not_held' => $orphanNotHeld, 'categories_not_indexed' => $catNotHeld], JSON_PRETTY_PRINT));
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

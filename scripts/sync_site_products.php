#!/usr/bin/env php
<?php
// scripts/sync_site_products.php — keeps the products table in step with
// blake-uk.com. Reads the sitemap, fetches each candidate product page and
// parses it with Products\SiteScraper (schema.org JSON-LD + page HTML: sku,
// name, prices inc/exc VAT, stock, specs, bullets, description, downloads,
// related products). No Gemini calls.
//
// Products that were active but are no longer on the site are marked
// inactive, only after a healthy run (most pages fetched) so a site outage
// can't wipe the catalogue.
//
// Usage: php scripts/sync_site_products.php [--limit=N] [--delay-ms=250] [--no-deactivate]
// Cron: nightly (installed by deploy_remote.sh).

require dirname(__DIR__) . '/src/bootstrap.php';

$opts         = getopt('', ['limit::', 'delay-ms::', 'no-deactivate']);
$limit        = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$delayUs      = (int)($opts['delay-ms'] ?? 250) * 1000;
$deactivate   = !isset($opts['no-deactivate']) && $limit === 0;
$sitemapUrl   = \Products\SiteScraper::BASE_URL . '/sitemap.xml';
$logFile      = rtrim(CFG['log_path'] ?? (ROOT . '/logs/'), '/') . '/product_sync.log';

$lock = fopen(sys_get_temp_dir() . '/blake_product_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another sync is running - exiting.\n";
    exit(0);
}

function out(string $msg): void {
    global $logFile;
    $line = gmdate('Y-m-d H:i:s') . ' ' . $msg . "\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

$t0 = time();
$sm = \Http\SafeFetcher::get($sitemapUrl, 60);
if (!$sm['ok']) {
    out("ABORT: sitemap fetch failed: " . ($sm['error'] ?? 'HTTP ' . $sm['code']));
    exit(1);
}
$parsed = \Knowledge\PageIndexer::parseSitemapXml((string)$sm['body']);
$urls   = $parsed['urls'];
foreach ($parsed['child_sitemaps'] as $child) {
    $c = \Http\SafeFetcher::get($child, 60);
    if ($c['ok']) $urls = array_merge($urls, \Knowledge\PageIndexer::parseSitemapXml((string)$c['body'])['urls']);
}
$candidates = \Products\SiteScraper::candidateUrls(array_values(array_unique($urls)));
if ($limit) $candidates = array_slice($candidates, 0, $limit);
out('sitemap: ' . count($urls) . ' URLs, ' . count($candidates) . ' candidate pages');

$batch = [];
$seen = [];
$fetched = $failed = $notProduct = 0;
$totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
$flush = function () use (&$batch, &$totals) {
    if (!$batch) return;
    $r = \Products\Importer::import($batch, \Products\SiteScraper::BASE_URL);
    $totals['created'] += $r['created'];
    $totals['updated'] += $r['updated'];
    $totals['skipped'] += $r['skipped'];
    $totals['errors']   = array_merge($totals['errors'], $r['errors']);
    $batch = [];
};

foreach ($candidates as $i => $url) {
    $res = \Http\SafeFetcher::get($url, 30, 10, 'BlakeUKChatbotProductSync/1.0');
    if (!$res['ok']) {
        $failed++;
        if ($failed <= 20) out("fetch failed {$url}: " . ($res['error'] ?? 'HTTP ' . $res['code']));
    } else {
        $fetched++;
        $rec = \Products\SiteScraper::parse((string)$res['body'], $url);
        if ($rec === null) {
            $notProduct++;
        } elseif (!isset($seen[$rec['product_code']])) {
            $seen[$rec['product_code']] = true;
            $batch[] = $rec;
            if (count($batch) >= 50) $flush();
        }
    }
    if (($i + 1) % 200 === 0) out('progress: ' . ($i + 1) . '/' . count($candidates) . ', products ' . count($seen));
    if ($delayUs) usleep($delayUs);
}
$flush();

$pdo = db();
$deactivated = 0;
$healthy = count($candidates) > 0 && $failed <= count($candidates) * 0.05 && count($seen) > 0;
if ($deactivate && $healthy) {
    $active = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
    // Guard against a site/template change making parse() fail everywhere.
    if (count($seen) >= $active * 0.8) {
        $codes = array_keys($seen);
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS _seen_codes (code TEXT PRIMARY KEY)');
        $pdo->exec('DELETE FROM _seen_codes');
        $ins = $pdo->prepare('INSERT OR IGNORE INTO _seen_codes (code) VALUES (?)');
        foreach ($codes as $c) $ins->execute([$c]);
        $deactivated = $pdo->exec("UPDATE products SET active = 0, updated_at = unixepoch()
            WHERE active = 1 AND url LIKE 'https://www.blake-uk.com/%' AND product_code NOT IN (SELECT code FROM _seen_codes)");
    } else {
        out('deactivation skipped: only ' . count($seen) . " products parsed vs {$active} active");
    }
} elseif ($deactivate) {
    out("deactivation skipped: unhealthy run ({$failed} fetch failures)");
}

out(sprintf('done in %ds: fetched %d, failed %d, non-product %d, products %d (created %d, updated %d, skipped %d, errors %d), deactivated %d',
    time() - $t0, $fetched, $failed, $notProduct, count($seen), $totals['created'], $totals['updated'], $totals['skipped'], count($totals['errors']), (int)$deactivated));
foreach (array_slice($totals['errors'], 0, 10) as $e) out('import error: ' . (is_string($e) ? $e : json_encode($e)));
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
    ->execute(['product_sync_last', json_encode(['at' => time(), 'products' => count($seen), 'failed' => $failed, 'deactivated' => (int)$deactivated])]);

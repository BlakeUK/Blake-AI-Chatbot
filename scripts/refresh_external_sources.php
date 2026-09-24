<?php
// scripts/refresh_external_sources.php - keeps a small list of trusted
// OUTSIDE reference pages indexed (Freeview channel listings and updates).
// They are refreshed daily and marked as external so answers cite them as
// third-party sources; Blake UK's own content always ranks first.
// Sources are held in the setting 'external_sources' (JSON) and seeded here.
require dirname(__DIR__) . '/src/bootstrap.php';

const SEED = [
    ['url' => 'https://www.terrestrialtv.uk/dtt.php',
     'title' => 'Freeview channel listings by multiplex (Terrestrial TV)',
     'keywords' => ['freeview channel', 'freeview channels', 'channel number', 'which multiplex', 'mux', 'lcn', 'channel list', 'freeview listing']],
    ['url' => 'https://rxtvinfo.com/freeview-updates/',
     'title' => 'Freeview changes and updates (RXTV info)',
     'keywords' => ['freeview update', 'freeview changes', 'channel moved', 'new freeview channel', 'freeview retune', 'channel closed']],
    ['url' => 'https://www.freeview.co.uk/corporate/platform-management/channel-listings-industry-professionals',
     'title' => 'Freeview official channel listings for industry professionals',
     'keywords' => ['freeview official', 'channel listings', 'freeview lcn', 'freeview platform']],
];

$pdo = db();
$cfgRow = $pdo->query("SELECT value FROM settings WHERE key = 'external_sources'")->fetchColumn();
$sources = json_decode((string)$cfgRow, true);
if (!is_array($sources) || !$sources) {
    $sources = SEED;
    $pdo->prepare("INSERT INTO settings (key,value,updated_at) VALUES ('external_sources',?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
        ->execute([json_encode($sources), time()]);
}

$indexed = 0; $failed = 0;
foreach ($sources as $s) {
    $url = (string)($s['url'] ?? '');
    if ($url === '') continue;
    try {
        $r = \Knowledge\PageIndexer::indexPage($url, null, 'Freeview reference');
        $indexed += empty($r['skipped']) ? 1 : 0;
    } catch (\Throwable $e) {
        $failed++;
        error_log('external source failed: ' . $url . ' - ' . $e->getMessage());
        continue;
    }
    // A keyword link so Max always points customers at the source page too.
    $exists = $pdo->prepare('SELECT id FROM keyword_links WHERE url = ?');
    $exists->execute([$url]);
    if (!$exists->fetchColumn() && !empty($s['keywords'])) {
        $pdo->prepare('INSERT INTO keyword_links (keywords, title, url, active) VALUES (?,?,?,1)')
            ->execute([json_encode($s['keywords']), (string)$s['title'], $url]);
    }
    usleep(500000);
}
echo "External sources: {$indexed} indexed, {$failed} failed, " . count($sources) . " configured.\n";

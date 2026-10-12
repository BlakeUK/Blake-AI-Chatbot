<?php
// scripts/diag/files_large_check.php: for the "Diagnose large file sharing" workflow. Run as the web user.
//   prepare : makes a 500 MB file (the largest allowed) and a link to it, and prints URL=, FILEID=, SHA=, SIZE=
//   cleanup : deletes everything prepare made
// Made-up owner id 999998. The workflow then downloads the link from OUTSIDE, through Caddy and PHP-FPM, and compares.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
use Files\{Shares, Store};

$O = 999998; $mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $free = (float)disk_free_space(Store::root());
    fwrite(STDERR, "disk free: " . round($free / 1073741824, 1) . " GB\n");
    if ($free < 3 * Store::MAX_FILE) { fwrite(STDERR, "NOT ENOUGH DISK to test safely\n"); exit(2); }
    $up = Store::beginUpload($O, null, 'diag-500MB.bin', Store::MAX_FILE);
    $block = random_bytes(Store::CHUNK_MAX); $h = hash_init('sha256'); $off = 0; $i = 0; $t0 = microtime(true);
    while ($off < Store::MAX_FILE) {
        $piece = substr(pack('J', $i) . substr($block, 8), 0, min(Store::CHUNK_MAX, Store::MAX_FILE - $off));
        hash_update($h, $piece); Store::chunk($up, $O, $off, $piece); $off += strlen($piece); $i++;
    }
    $f = Store::finishUpload($up, $O);
    fwrite(STDERR, "uploaded {$i} pieces in " . round(microtime(true) - $t0, 1) . " s\n");
    $s = Shares::create($O, 'link', [(int)$f['id']], ['title' => 'diag']);
    echo "URL=" . Shares::url($s) . "\nFILEID=" . $f['id'] . "\nSHA=" . hash_final($h) . "\nSIZE=" . $f['size'] . "\n";
    exit(0);
}
if ($mode === 'cleanup') {
    $ids = db()->query("SELECT id FROM fs_nodes WHERE owner_id = {$O} AND parent_id IS NULL")->fetchAll(\PDO::FETCH_COLUMN);
    Store::delete($O, array_map('intval', $ids));
    foreach (db()->query("SELECT id FROM fs_shares WHERE owner_id = {$O}")->fetchAll(\PDO::FETCH_COLUMN) as $id) foreach (['fs_shares' => 'id', 'fs_share_items' => 'share_id', 'fs_share_users' => 'share_id', 'fs_access' => 'share_id'] as $t => $c) db()->exec("DELETE FROM {$t} WHERE {$c} = " . (int)$id);
    db()->exec("DELETE FROM fs_uploads WHERE owner_id = {$O}");
    foreach (glob(Store::root() . '/tmp/*') ?: [] as $f) if (filemtime($f) < time() - 60 && filesize($f) >= Store::MAX_FILE - 1) @unlink($f);
    echo "cleaned up; test items left: " . (int)db()->query("SELECT COUNT(*) FROM fs_nodes WHERE owner_id = {$O}")->fetchColumn() . "; disk free " . round(disk_free_space(Store::root()) / 1073741824, 1) . " GB\n";
    exit(0);
}
fwrite(STDERR, "usage: php files_large_check.php prepare|cleanup\n"); exit(1);

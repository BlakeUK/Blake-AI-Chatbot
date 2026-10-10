<?php
// scripts/diag/files_check.php: checks file sharing on THIS server and cleans up after itself. Run as the web user:
//   cd /var/www/chat && runuser -u www-data -- php scripts/diag/files_check.php
// It uses a made-up owner (id 999999), uploads and zips real data, shares it with a password, fetches it as a customer would, then deletes everything it made.
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Files\{Shares, SharePage, Store, Zip};

$fail = 0;
$say = function (string $what, bool $ok, string $extra = '') use (&$fail) { echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . ($extra !== '' ? "  ({$extra})" : '') . "\n"; if (!$ok) $fail++; };
echo "php " . PHP_VERSION . " as " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user()) . "\n";
$say('zlib streaming is available (needed for zip)', function_exists('deflate_init') && function_exists('inflate_init'));
$say('hash_file and random_bytes are available', function_exists('hash_file') && function_exists('random_bytes'));
$root = Store::root();
echo "  files folder: {$root}  mode " . substr(sprintf('%o', fileperms($root)), -4) . "\n";
$say('the files folder can be written to', is_writable($root) && is_writable($root . '/tmp'));
$free = (float)disk_free_space($root);
echo "  disk free: " . round($free / 1073741824, 1) . " GB;  storage cap: " . round(Store::quotaBytes() / 1048576) . " MB;  in use: " . round(Store::totalUsed() / 1048576, 1) . " MB\n";
$say('there is room for the storage cap, with a margin', $free > Store::quotaBytes() * 1.2, 'lower fs_quota_mb if not');
echo "  post_max_size " . ini_get('post_max_size') . ", upload_max_filesize " . ini_get('upload_max_filesize') . " (uploads are sent in 1 MB pieces, so these only need to exceed that)\n";
$say('uploads of 1 MB pieces fit the PHP limits', (int)ini_get('post_max_size') >= 2 || str_ends_with(strtoupper(ini_get('post_max_size')), 'G'));
foreach (['fs_nodes', 'fs_shares', 'fs_share_items', 'fs_share_users', 'fs_uploads', 'fs_access'] as $t) $say("table {$t} exists", (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$t}'")->fetchColumn());

$O = 999999;
try {
    $dir = Store::mkdir($O, null, 'diag-folder');
    $data = random_bytes(2_500_000); $up = Store::beginUpload($O, $dir, 'data.bin', strlen($data));
    for ($o = 0; $o < strlen($data); $o += 1048576) Store::chunk($up, $O, $o, substr($data, $o, 1048576));
    $f = Store::finishUpload($up, $O);
    $say('a 2.5 MB file uploads in pieces and is stored intact', hash_file('sha256', Store::blobPath($f['blob'])) === hash('sha256', $data));
    $z = Store::zip($O, [$dir], null);
    $names = array_column(Zip::entries(Store::blobPath($z['blob'])), 'name');
    $say('it zips', in_array('diag-folder/data.bin', $names, true), implode(',', $names));
    $u = Store::unzip($O, (int)$z['id']);
    $back = array_values(array_filter(Store::contents((int)$u['id']), fn($n) => $n['kind'] === 'folder'));
    $inner = $back ? Store::contents((int)$back[0]['id']) : [];
    $say('it unzips to identical bytes', $inner && hash_file('sha256', Store::blobPath($inner[0]['blob'])) === hash('sha256', $data));
    if (trim((string)@shell_exec('command -v unzip'))) {
        $tmp = tempnam(sys_get_temp_dir(), 'dz'); copy(Store::blobPath($z['blob']), $tmp);
        $say("the server's own unzip tool accepts our zip", str_contains((string)shell_exec('unzip -tq ' . escapeshellarg($tmp) . ' 2>&1'), 'No errors'));
        @unlink($tmp);
    }
    $s = Shares::create($O, 'link', [$dir], ['title' => 'diag', 'password' => 'diag-password-1', 'to' => (new DateTimeImmutable('+1 hour', new DateTimeZone('Europe/London')))->format('Y-m-d\TH:i')]);
    $path = '/' . $s['id'] . '/' . basename(Shares::url($s));
    $gate = SharePage::handle($path, 'GET', [], [], [], '127.0.0.1');
    $say('a customer meets the password gate, which shows nothing of the contents', $gate['status'] === 200 && !str_contains($gate['body'], 'diag-folder') && str_contains($gate['body'], 'type="password"'));
    $ok = SharePage::handle($path, 'POST', ['password' => 'diag-password-1'], [], [], '127.0.0.1');
    preg_match('/fsu_\d+=([^;]+)/', implode("\n", $ok['headers']), $m);
    $page = SharePage::handle($path, 'GET', [], [], ['fsu_' . $s['id'] => $m[1] ?? ''], '127.0.0.1');
    $say('the right password unlocks it and lists the folder', $ok['status'] === 303 && str_contains($page['body'], 'diag-folder'));
    $dl = SharePage::handle($path, 'GET', [], ['z' => $dir], ['fsu_' . $s['id'] => $m[1] ?? ''], '127.0.0.1');
    ob_start(); $bytes = 0; Zip::write(Store::collect($dl['stream']['nodes']), function ($b) use (&$bytes) { $bytes += strlen($b); }); ob_end_clean();
    $say('the folder downloads as a zip', isset($dl['stream']) && $dl['stream']['kind'] === 'zip' && $bytes > 1000);
    $row = Shares::get((int)$s['id']); $say('the download was counted', (int)$row['downloads'] === 1);
} catch (\Throwable $e) {
    $say('the smoke test ran to the end', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $ids = db()->query("SELECT id FROM fs_nodes WHERE owner_id = {$O} AND parent_id IS NULL")->fetchAll(\PDO::FETCH_COLUMN);
    Store::delete($O, array_map('intval', $ids));
    $sh = db()->query("SELECT id FROM fs_shares WHERE owner_id = {$O}")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($sh as $id) foreach (['fs_shares' => 'id', 'fs_share_items' => 'share_id', 'fs_share_users' => 'share_id', 'fs_access' => 'share_id'] as $t => $c) db()->exec("DELETE FROM {$t} WHERE {$c} = " . (int)$id);
    db()->exec("DELETE FROM fs_uploads WHERE owner_id = {$O}");
    echo "  (cleaned up: " . (int)db()->query("SELECT COUNT(*) FROM fs_nodes WHERE owner_id = {$O}")->fetchColumn() . " test items left)\n";
}
echo "\nRESULT: " . ($fail === 0 ? 'ALL PASSED' : "{$fail} FAILED") . "\n";
exit($fail === 0 ? 0 : 1);

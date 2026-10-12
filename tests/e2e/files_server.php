<?php
// Starts the real site on a throwaway database with three staff accounts, for tests/e2e/files_ui.py. Stays up for 8 minutes.
require dirname(__DIR__) . '/bootstrap.php';
$hash = password_hash('wr-pass-12345', PASSWORD_BCRYPT);
db()->prepare("INSERT INTO admin_users (username, password, role) VALUES ('fs-admin', ?, 'admin'), ('fs-colleague', ?, 'editor'), ('fs-other', ?, 'user')")->execute([$hash, $hash, $hash]);
// FS_FILES_DIR puts the file store somewhere else (the large-file test uses memory-backed disk, which has the room)
if (($d = getenv('FS_FILES_DIR')) && !file_exists(dirname(CFG['db_path']) . '/files')) { @mkdir($d, 0750, true); symlink($d, dirname(CFG['db_path']) . '/files'); }
file_put_contents('/tmp/files_e2e.json', json_encode(['db' => CFG['db_path'], 'files' => \Files\Store::root()]));
$srv = proc_open(['php', '-S', '127.0.0.1:18504', '-t', dirname(__DIR__, 2) . '/public'], [1 => ['file', '/tmp/files_srv.log', 'w'], 2 => ['file', '/tmp/files_srv.log', 'a']], $p, null, array_merge(getenv(), ['BLAKE_UK_CONFIG' => getenv('BLAKE_UK_CONFIG')]));
sleep(480);
proc_terminate($srv);

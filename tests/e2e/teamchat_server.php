<?php
// Starts the real site on a throwaway database with the console's screen at /_console/ and four staff accounts (ann is an administrator,
// bob and dan are editors, cat is read-only), for tests/e2e/teamchat_ui.py. Stays up for 8 minutes.
require dirname(__DIR__) . '/bootstrap.php';
$hash = password_hash('wr-pass-12345', PASSWORD_BCRYPT);
db()->prepare("INSERT INTO admin_users (username, password, role) VALUES ('ann', ?, 'admin'), ('bob', ?, 'editor'), ('cat', ?, 'user'), ('dan', ?, 'editor')")->execute([$hash, $hash, $hash, $hash]);
$pub = dirname(__DIR__, 2) . '/public';
@symlink(dirname(__DIR__, 2) . '/operator-console/dist', $pub . '/_console');
$srv = proc_open(['php', '-d', 'auto_prepend_file=' . __DIR__ . '/teamai_stub.php', '-S', '127.0.0.1:18505', '-t', $pub], [1 => ['file', '/tmp/teamchat_srv.log', 'w'], 2 => ['file', '/tmp/teamchat_srv.log', 'a']], $p, null, array_merge(getenv(), ['BLAKE_UK_CONFIG' => getenv('BLAKE_UK_CONFIG')]));
sleep(480);
proc_terminate($srv); @unlink($pub . '/_console');

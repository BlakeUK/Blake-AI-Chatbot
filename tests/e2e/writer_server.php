<?php
// Starts the real site on a throwaway database for tests/e2e/writer_ui.py, with two staff accounts. Stays up for 5 minutes.
// Run:  php tests/e2e/writer_server.php &
require dirname(__DIR__) . '/bootstrap.php';
$hash = password_hash('wr-pass-12345', PASSWORD_BCRYPT);
db()->prepare("INSERT INTO admin_users (username, password, role) VALUES ('wr-admin', ?, 'admin'), ('wr-viewer', ?, 'user')")->execute([$hash, $hash]);
$srv = proc_open(['php', '-S', '127.0.0.1:18502', '-t', dirname(__DIR__, 2) . '/public'], [1 => ['file', '/tmp/writer_srv.log', 'w'], 2 => ['file', '/tmp/writer_srv.log', 'a']], $p, null, array_merge(getenv(), ['BLAKE_UK_CONFIG' => getenv('BLAKE_UK_CONFIG')]));
sleep(300);
proc_terminate($srv);

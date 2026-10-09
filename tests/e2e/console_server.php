<?php
// Starts the real site on a throwaway database, with the Windows console's screen served at /_console/, two staff accounts,
// and one ticket with a short conversation, for tests/e2e/console_ui.py. Stays up for 5 minutes.
require dirname(__DIR__) . '/bootstrap.php';
$hash = password_hash('wr-pass-12345', PASSWORD_BCRYPT);
db()->prepare("INSERT INTO admin_users (username, password, role) VALUES ('wr-admin', ?, 'admin'), ('wr-viewer', ?, 'user')")->execute([$hash, $hash]);
$pub = dirname(__DIR__, 2) . '/public';
@symlink(dirname(__DIR__, 2) . '/operator-console/dist', $pub . '/_console');
$pdo = db();
$now = time();
$pdo->prepare('INSERT INTO support_tickets (status, subject, customer_email, customer_name, details, notes, department, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute(['open', 'Aerial not working', 'pete@example.com', 'Pete', 'The aerial stopped working after the storm.', '', 'technical', $now - 7200, $now - 3600]);
$id = (int)$pdo->lastInsertId();
$m = $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author, admin_id, author_name, body, created_at) VALUES (?,?,?,?,?,?)');
$m->execute([$id, 'customer', null, 'Pete', 'It stopped working after the storm. What should I check?', $now - 7200]);
$m->execute([$id, 'staff', 1, 'wr-admin', 'Please check the cable at the back of the aerial.', $now - 3600]);
$srv = proc_open(['php', '-S', '127.0.0.1:18503', '-t', $pub], [1 => ['file', '/tmp/console_srv.log', 'w'], 2 => ['file', '/tmp/console_srv.log', 'a']], $p, null, array_merge(getenv(), ['BLAKE_UK_CONFIG' => getenv('BLAKE_UK_CONFIG')]));
sleep(300);
proc_terminate($srv); @unlink($pub . '/_console');

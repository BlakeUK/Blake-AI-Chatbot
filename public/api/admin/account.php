<?php
// public/api/admin/account.php — self-service account actions (any logged-in role)
// POST: change own password

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$current = (string)($body['current_password'] ?? '');
$new     = (string)($body['new_password'] ?? '');

if (!$current || !$new) json_err('current_password and new_password required');
if (strlen($new) < 8) json_err('New password must be at least 8 characters');

$pdo = db();
$id  = (int)$_SESSION['admin_id'];

$row = $pdo->prepare('SELECT password FROM admin_users WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();

if (!$row || !password_verify($current, $row['password'])) {
    json_err('Current password is incorrect', 401);
}

$hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare('UPDATE admin_users SET password=? WHERE id=?')->execute([$hash, $id]);

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$id, 'password_changed', $id]);

json_out(['ok' => true]);

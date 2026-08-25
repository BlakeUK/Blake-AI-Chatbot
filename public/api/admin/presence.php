<?php
// public/api/admin/presence.php — GET: my current status | POST: set it
// Explicit Online/Busy/Offline (see schema_live_chat.sql) - distinct from
// the passive last_active-derived flag agents.php already exposes.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT presence_status FROM admin_users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    json_out(['status' => $stmt->fetchColumn() ?: 'offline']);
}

if ($method !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$status = trim((string)($body['status'] ?? ''));
if (!in_array($status, ['online', 'busy', 'offline'], true)) {
    json_err('Invalid status');
}

$pdo->prepare('UPDATE admin_users SET presence_status = ? WHERE id = ?')->execute([$status, $_SESSION['admin_id']]);

json_out(['ok' => true, 'status' => $status]);

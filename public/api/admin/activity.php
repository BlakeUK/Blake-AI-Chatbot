<?php
// public/api/admin/activity.php — GET: recent audit_log entries for the Dashboard's activity feed
//
// Deliberately just the raw rows, joined for a username - turning action
// codes into full sentences ("Tom Ellis moved billing view to In Progress")
// is presentation, and easier to iterate on in the frontend than to keep
// redeploying PHP every time the copy needs adjusting.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$limit = min((int)($_GET['limit'] ?? 20), 100);

$stmt = db()->prepare('
    SELECT a.id, a.action, a.target, a.detail, a.created_at, u.username
    FROM audit_log a
    LEFT JOIN admin_users u ON u.id = a.admin_id
    ORDER BY a.created_at DESC
    LIMIT ?
');
$stmt->execute([$limit]);
json_out($stmt->fetchAll());

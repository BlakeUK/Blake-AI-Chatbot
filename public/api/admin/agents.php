<?php
// public/api/admin/agents.php — GET: list staff for ticket assignment
//
// Deliberately separate from users.php rather than reusing it: users.php
// is account *administration* (create/edit/delete, role changes) and is
// role-gated to admin only. This is just "who can I hand this ticket to",
// which every operator needs regardless of their own role - a technical
// operator assigning a billing question to accounts still needs to see
// accounts staff in the list.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$cutoff = time() - \Auth\Admin::ONLINE_WINDOW_SECONDS;

// effective_status is the source of truth for display: the staff-set
// presence_status (online/busy/offline) while the client is actually
// connected (heartbeat within the window), otherwise forced to 'offline'
// since a stale/closed client can't really be "online" no matter what
// status was last selected. online (bool) is kept for existing callers
// that only care about the binary case.
$stmt = db()->prepare("
    SELECT id, username, role, presence_status,
           CASE WHEN last_active IS NOT NULL AND last_active >= ? THEN presence_status ELSE 'offline' END AS effective_status
    FROM admin_users
    ORDER BY (CASE WHEN last_active IS NOT NULL AND last_active >= ? AND presence_status = 'online' THEN 1 ELSE 0 END) DESC, username COLLATE NOCASE ASC
");
$stmt->execute([$cutoff, $cutoff]);
$rows = $stmt->fetchAll();

$deptRows = db()->query('SELECT admin_id, department FROM admin_user_departments')->fetchAll();
$byAdmin = [];
foreach ($deptRows as $d) {
    $byAdmin[$d['admin_id']][] = $d['department'];
}

foreach ($rows as &$r) {
    $r['id']          = (int)$r['id'];
    $r['online']       = $r['effective_status'] === 'online';
    $r['departments']  = $byAdmin[$r['id']] ?? [];
}

json_out($rows);

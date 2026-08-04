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

$stmt = db()->prepare('
    SELECT id, username, role,
           CASE WHEN last_active IS NOT NULL AND last_active >= ? THEN 1 ELSE 0 END AS online
    FROM admin_users
    ORDER BY online DESC, username COLLATE NOCASE ASC
');
$stmt->execute([$cutoff]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['id']     = (int)$r['id'];
    $r['online'] = (bool)$r['online'];
}

json_out($rows);

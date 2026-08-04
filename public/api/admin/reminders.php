<?php
// public/api/admin/reminders.php
// GET ?ticket_id=X   -> reminders for one ticket
// GET ?due=1         -> this admin's due, unacknowledged reminders (for the console's poll-driven notification)
// POST               -> create {ticket_id, admin_id?, remind_at, note?}
// PUT                -> {id, acknowledged:true} or {id, snooze_hours:N}

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    if (!empty($_GET['due'])) {
        $stmt = $pdo->prepare('
            SELECT r.*, t.subject AS ticket_subject
            FROM reminders r
            JOIN support_tickets t ON t.id = r.ticket_id
            WHERE r.admin_id = ? AND r.acknowledged = 0 AND r.remind_at <= ?
            ORDER BY r.remind_at ASC
        ');
        $stmt->execute([$_SESSION['admin_id'], time()]);
        json_out($stmt->fetchAll());
    }

    if (!empty($_GET['mine'])) {
        $stmt = $pdo->prepare('
            SELECT r.*, t.subject AS ticket_subject
            FROM reminders r
            JOIN support_tickets t ON t.id = r.ticket_id
            WHERE r.admin_id = ? AND r.acknowledged = 0
            ORDER BY r.remind_at ASC
            LIMIT ?
        ');
        $stmt->execute([$_SESSION['admin_id'], min((int)($_GET['limit'] ?? 10), 50)]);
        json_out($stmt->fetchAll());
    }

    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if (!$ticketId) json_err('ticket_id, due, or mine required');

    $stmt = $pdo->prepare('
        SELECT r.*, a.username AS for_username, c.username AS created_by_username
        FROM reminders r
        JOIN admin_users a ON a.id = r.admin_id
        JOIN admin_users c ON c.id = r.created_by
        WHERE r.ticket_id = ?
        ORDER BY r.remind_at ASC
    ');
    $stmt->execute([$ticketId]);
    json_out($stmt->fetchAll());
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $ticketId = (int)($body['ticket_id'] ?? 0);
    $remindAt = (int)($body['remind_at'] ?? 0);
    if (!$ticketId || !$remindAt) json_err('ticket_id and remind_at required');

    $ticketExists = $pdo->prepare('SELECT 1 FROM support_tickets WHERE id=?');
    $ticketExists->execute([$ticketId]);
    if (!$ticketExists->fetch()) json_err('Unknown ticket_id');

    $forAdmin = (int)($body['admin_id'] ?? $_SESSION['admin_id']);
    $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE id=?');
    $exists->execute([$forAdmin]);
    if (!$exists->fetch()) json_err('Unknown admin_id');

    $pdo->prepare('
        INSERT INTO reminders (ticket_id, admin_id, created_by, remind_at, note)
        VALUES (?,?,?,?,?)
    ')->execute([$ticketId, $forAdmin, $_SESSION['admin_id'], $remindAt, trim((string)($body['note'] ?? '')) ?: null]);

    json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $row = $pdo->prepare('SELECT admin_id, remind_at FROM reminders WHERE id=?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row) json_err('Reminder not found', 404);

    // Only the person it's for (or an admin) can acknowledge/snooze it -
    // otherwise anyone with console access could silence someone else's
    // reminder before they ever see it.
    if ((int)$row['admin_id'] !== (int)$_SESSION['admin_id'] && \Auth\Admin::role() !== 'admin') {
        json_err('Only the assignee or an admin can update this reminder', 403);
    }

    if (!empty($body['acknowledged'])) {
        $pdo->prepare('UPDATE reminders SET acknowledged=1 WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }

    if (!empty($body['snooze_hours'])) {
        $newTime = max((int)$row['remind_at'], time()) + ((int)$body['snooze_hours'] * 3600);
        $pdo->prepare('UPDATE reminders SET remind_at=?, acknowledged=0 WHERE id=?')->execute([$newTime, $id]);
        json_out(['ok' => true, 'remind_at' => $newTime]);
    }

    if (!empty($body['remind_at'])) {
        $newTime = (int)$body['remind_at'];
        $pdo->prepare('UPDATE reminders SET remind_at=?, acknowledged=0 WHERE id=?')->execute([$newTime, $id]);
        json_out(['ok' => true, 'remind_at' => $newTime]);
    }

    json_err('acknowledged, snooze_hours, or remind_at required');
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/tickets.php
// GET: list tickets | POST: add note | PUT: update status

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $status     = $_GET['status'] ?? null;
    $department = $_GET['department'] ?? null;
    $limit      = min((int)($_GET['limit'] ?? 50), 200);

    $where  = [];
    $params = [];
    if ($status)     { $where[] = 't.status = ?';     $params[] = $status; }
    if ($department) { $where[] = 't.department = ?'; $params[] = $department; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT t.*, s.page_url, s.product_code,
               a.username AS assigned_username,
               COUNT(m.id) AS message_count
        FROM support_tickets t
        LEFT JOIN chat_sessions s ON s.id = t.session_id
        LEFT JOIN chat_messages m ON m.session_id = t.session_id
        LEFT JOIN admin_users a ON a.id = t.assigned_admin_id
        {$whereSql}
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([...$params, $limit]);

    json_out($stmt->fetchAll());
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    // Add internal note
    $id   = (int)($body['id']   ?? 0);
    $note = trim($body['note']  ?? '');
    if (!$id || !$note) json_err('id and note required');

    $ticket = $pdo->prepare('SELECT notes FROM support_tickets WHERE id=?');
    $ticket->execute([$id]);
    $existing = $ticket->fetchColumn() ?: '';
    $ts       = date('d/m/Y H:i');
    $new      = $existing ? $existing . "\n\n[$ts] " . $note : "[$ts] " . $note;

    $pdo->prepare('UPDATE support_tickets SET notes=?, updated_at=? WHERE id=?')
        ->execute([$new, time(), $id]);

    json_out(['ok' => true]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $hasStatus     = array_key_exists('status', $body);
    $hasDept       = array_key_exists('department', $body);
    $hasAssignee   = array_key_exists('assigned_admin_id', $body);
    $hasPriority   = array_key_exists('priority', $body);
    if (!$hasStatus && !$hasDept && !$hasAssignee && !$hasPriority) {
        json_err('status, department, assigned_admin_id, or priority required');
    }

    if ($hasStatus) {
        $status = trim((string)$body['status']);
        if (!in_array($status, ['open', 'in_progress', 'waiting', 'resolved'], true)) json_err('Invalid status');

        $pdo->prepare('UPDATE support_tickets SET status=?, updated_at=? WHERE id=?')
            ->execute([$status, time(), $id]);

        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'ticket_status_change', $id, $status]);
    }

    if ($hasDept) {
        $department = trim((string)$body['department']);
        // Empty string is a valid, meaningful value: send it back to the
        // general/unrouted queue rather than a specific department.
        if ($department !== '' && !in_array($department, ['sales', 'technical', 'accounts'], true)) {
            json_err('Invalid department');
        }

        $pdo->prepare('UPDATE support_tickets SET department=?, updated_at=? WHERE id=?')
            ->execute([$department !== '' ? $department : null, time(), $id]);

        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'ticket_department_change', $id, $department !== '' ? $department : '(unassigned)']);
    }

    if ($hasAssignee) {
        // Passing a chat/ticket to a specific colleague - the actual handoff
        // mechanic. Null/empty clears assignment back to "unassigned" for
        // the department queue at large, same convention as department above.
        $raw = $body['assigned_admin_id'];
        $assigneeId = ($raw === null || $raw === '') ? null : (int)$raw;

        $assigneeName = null;
        if ($assigneeId !== null) {
            $chk = $pdo->prepare('SELECT username FROM admin_users WHERE id=?');
            $chk->execute([$assigneeId]);
            $assigneeName = $chk->fetchColumn();
            if ($assigneeName === false) json_err('Unknown assignee');
        }

        $pdo->prepare('UPDATE support_tickets SET assigned_admin_id=?, updated_at=? WHERE id=?')
            ->execute([$assigneeId, time(), $id]);

        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'ticket_reassigned', $id, $assigneeName ?? '(unassigned)']);
    }

    if ($hasPriority) {
        $priority = trim((string)$body['priority']);
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) json_err('Invalid priority');

        // SLA window recalculated from the ticket's original created_at, not
        // from now - upgrading an old ticket to urgent should measure "2h
        // from when it actually came in", not reset the clock to this edit.
        $created = $pdo->prepare('SELECT created_at FROM support_tickets WHERE id=?');
        $created->execute([$id]);
        $createdAt = (int)($created->fetchColumn() ?: time());
        $slaDeadline = \Tickets\Sla::deadline($priority, $createdAt);

        $pdo->prepare('UPDATE support_tickets SET priority=?, sla_deadline=?, updated_at=? WHERE id=?')
            ->execute([$priority, $slaDeadline, time(), $id]);

        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'ticket_priority_change', $id, $priority]);
    }

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

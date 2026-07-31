<?php
// public/api/admin/tickets.php
// GET: list tickets | POST: add note | PUT: update status

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $status = $_GET['status'] ?? null;
    $limit  = min((int)($_GET['limit'] ?? 50), 200);

    if ($status) {
        $stmt = $pdo->prepare('
            SELECT t.*, s.page_url, s.product_code,
                   COUNT(m.id) AS message_count
            FROM support_tickets t
            LEFT JOIN chat_sessions s ON s.id = t.session_id
            LEFT JOIN chat_messages m ON m.session_id = t.session_id
            WHERE t.status = ?
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$status, $limit]);
    } else {
        $stmt = $pdo->prepare('
            SELECT t.*, s.page_url, s.product_code,
                   COUNT(m.id) AS message_count
            FROM support_tickets t
            LEFT JOIN chat_sessions s ON s.id = t.session_id
            LEFT JOIN chat_messages m ON m.session_id = t.session_id
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
    }

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
    $id     = (int)($body['id']     ?? 0);
    $status = trim($body['status']  ?? '');
    if (!$id || !$status) json_err('id and status required');
    if (!in_array($status, ['open', 'pending', 'closed'], true)) json_err('Invalid status');

    $pdo->prepare('UPDATE support_tickets SET status=?, updated_at=? WHERE id=?')
        ->execute([$status, time(), $id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'ticket_status_change', $id, $status]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

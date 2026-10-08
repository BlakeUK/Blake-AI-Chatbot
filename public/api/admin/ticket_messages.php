<?php
// public/api/admin/ticket_messages.php
// GET ?ticket_id=N                      : the conversation with the customer
// POST {ticket_id, body}                : staff reply (saved, and emailed to the customer with their secure link)
// POST {action:'revoke_link', ticket_id}: invalidates every secure link already emailed for this ticket

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

$findTicket = function (int $id) use ($pdo) {
    $q = $pdo->prepare('SELECT id, customer_email, status FROM support_tickets WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
};

if ($method === 'GET') {
    $id = (int)($_GET['ticket_id'] ?? 0);
    $t  = $findTicket($id);
    if (!$t) json_err('Ticket not found', 404);
    $m = $pdo->prepare('SELECT m.id, m.author, m.body, m.created_at, u.username AS staff_name
                        FROM ticket_messages m LEFT JOIN admin_users u ON u.id = m.admin_id
                        WHERE m.ticket_id = ? ORDER BY m.id');
    $m->execute([$id]);
    json_out(['code' => \Tickets\Mailer::code($id), 'can_email' => trim((string)$t['customer_email']) !== '', 'messages' => $m->fetchAll()]);
}

if ($method !== 'POST') json_err('Method not allowed', 405);
\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$id = (int)($body['ticket_id'] ?? 0);
$t  = $findTicket($id);
if (!$t) json_err('Ticket not found', 404);
$audit = fn(string $action) => $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$_SESSION['admin_id'], $action, \Tickets\Mailer::code($id)]);

if (($body['action'] ?? 'reply') === 'revoke_link') {
    \Tickets\Link::rotate($id);
    $audit('ticket_link_revoked');
    json_out(['ok' => true]);
}

$r = \Tickets\Messages::staffReply($id, (int)$_SESSION['admin_id'], (string)($body['body'] ?? ''));
if (!$r['ok']) json_err((string)$r['error'], 422);
$audit('ticket_reply');
json_out(['ok' => true, 'id' => $r['id'], 'emailed' => $r['emailed']]);

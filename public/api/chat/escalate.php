<?php
// public/api/chat/escalate.php
// POST — create support ticket from a chat session

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('escalate', 5);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body       = json_body();
$session_id = $body['session_id'] ?? '';
$email      = trim($body['email']   ?? '');
$subject    = trim($body['subject'] ?? '');

if (!$session_id) json_err('session_id required');

$pdo = db();
$sess = $pdo->prepare('SELECT * FROM chat_sessions WHERE id = ?');
$sess->execute([$session_id]);
$session = $sess->fetch();
if (!$session) json_err('Invalid session', 404);

// Check not already escalated
$existing = $pdo->prepare('SELECT id FROM support_tickets WHERE session_id = ?');
$existing->execute([$session_id]);
if ($existing->fetch()) {
    json_out(['ok' => true, 'message' => 'A support ticket already exists for this session.']);
}

// Build subject from session context if not provided
if (!$subject) {
    $lastMsg = $pdo->prepare('SELECT content FROM chat_messages WHERE session_id=? AND role=? ORDER BY created_at DESC LIMIT 1');
    $lastMsg->execute([$session_id, 'user']);
    $lastQuestion = $lastMsg->fetchColumn() ?: 'Customer support request';
    $subject = substr($lastQuestion, 0, 100);
}

$pdo->prepare('
    INSERT INTO support_tickets (session_id, status, subject, customer_email)
    VALUES (?, ?, ?, ?)
')->execute([$session_id, 'open', $subject, $email ?: null]);

$ticketId = $pdo->lastInsertId();

// Mark recent bot messages as escalated
$pdo->prepare('UPDATE chat_messages SET escalated=1 WHERE session_id=? AND role=?')
    ->execute([$session_id, 'assistant']);

json_out([
    'ok'        => true,
    'ticket_id' => $ticketId,
    'message'   => 'Your query has been passed to our support team. We\'ll get back to you as soon as possible. You can also reach us at https://www.blake-uk.com/support.html',
]);

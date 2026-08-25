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

// A ticket support can't reply to isn't much use - required, not optional.
// Deliberately simple format check (not full RFC 5322) matched by the
// widget's own client-side check (public/widget/chat.js) so the two never
// disagree about what counts as valid.
if (!$email || mb_strlen($email) > 254 || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
    json_err('A valid email address is required so our support team can reply');
}

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

// Department routing - classify from the conversation itself. Wrapped like
// the Telegram alert below: a Gemini hiccup here must never block ticket
// creation or the customer's confirmation message. Unsure/failure already
// resolves to 'sales' inside the classifier, so this never leaves a ticket
// unrouted - it either lands correctly or lands somewhere a human sees it.
try {
    $recent = $pdo->prepare("SELECT role, content FROM chat_messages WHERE session_id=? AND role IN ('user','assistant') ORDER BY created_at ASC");
    $recent->execute([$session_id]);
    $routing = \Chat\DepartmentClassifier::classify($recent->fetchAll());
} catch (\Throwable $e) {
    error_log('escalate.php: department classification failed: ' . $e->getMessage());
    $routing = ['department' => 'sales', 'confident' => false];
}

$now = time();
$pdo->prepare('
    INSERT INTO support_tickets (session_id, status, subject, customer_email, department, priority, sla_deadline, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute([$session_id, 'open', $subject, $email, $routing['department'], 'medium', \Tickets\Sla::deadline('medium', $now), $now, $now]);

$ticketId = $pdo->lastInsertId();

if (!$routing['confident']) {
    $ts = date('d/m/Y H:i');
    $pdo->prepare('UPDATE support_tickets SET notes=? WHERE id=?')
        ->execute(["[$ts] Auto-routed to Sales — the AI wasn't confident which department this belongs to. Please reassign if it isn't a sales query.", $ticketId]);
}

// Mark recent bot messages as escalated
$pdo->prepare('UPDATE chat_messages SET escalated=1 WHERE session_id=? AND role=?')
    ->execute([$session_id, 'assistant']);

// Staff alert - deliberately after the DB writes above and wrapped so it can
// never affect this response. If Telegram isn't configured or is down, the
// customer still gets their normal escalation confirmation below.
\Telegram\Notifier::sendTicketAlert($ticketId, $subject, $email, $session['page_url'] ?? null);

json_out([
    'ok'        => true,
    'ticket_id' => $ticketId,
    'message'   => "Thanks - I've raised ticket #{$ticketId} with our support team. They'll reply by email to {$email} as soon as they can. You can also reach us directly at https://www.blake-uk.com/support.html",
]);

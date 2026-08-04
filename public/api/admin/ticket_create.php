<?php
// public/api/admin/ticket_create.php — POST: staff-created ticket, no chat session involved
//
// The AI-generated path (escalate.php) always has a session_id to hang the
// ticket off. This is the other source named in the spec: "logged-in
// internal users can manually create tickets for customer issues, bugs,
// internal tasks, or project work orders" - deliberately session_id-less
// (the column's always been nullable for exactly this reason, just never
// used) rather than inventing a synthetic session to satisfy a constraint
// that was never actually there.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$subject = trim((string)($body['subject'] ?? ''));
if ($subject === '') json_err('subject required');

$email = trim((string)($body['customer_email'] ?? ''));

$department = trim((string)($body['department'] ?? ''));
if ($department !== '' && !in_array($department, ['sales', 'technical', 'accounts'], true)) {
    json_err('Invalid department');
}

$priority = trim((string)($body['priority'] ?? 'medium'));
if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) json_err('Invalid priority');

$pdo = db();
$now = time();
$pdo->prepare('
    INSERT INTO support_tickets (session_id, status, subject, customer_email, department, priority, sla_deadline, created_at, updated_at)
    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute(['open', $subject, $email ?: null, $department !== '' ? $department : null, $priority, \Tickets\Sla::deadline($priority, $now), $now, $now]);

$ticketId = $pdo->lastInsertId();

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'ticket_created_manually', $ticketId, $subject]);

json_out(['ok' => true, 'ticket_id' => $ticketId]);

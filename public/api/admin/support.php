<?php
// public/api/admin/support.php - the Operator Console's support desk API.
// GET  ?queue=1              chats waiting / live / recently active, with
//                            which ones should pop up for this staff member
// GET  ?session_id=...       one chat: messages, staff notes, tickets
// POST { csrf, action, session_id, ... }
//      claim | send {message} | end | transfer {department?, admin_id?, note?}
//      note {note} | create_ticket {subject, details, name, email, phone,
//      department, priority} | set_customer {name, email, phone}
// Logic lives in Chat\Handoff / Chat\LiveChat; any signed-in staff member
// can handle chats.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();

$pdo = db();
$me  = (int)$_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['queue'])) {
        try { \Chat\Handoff::sweepTimeouts(); } catch (\Throwable $e) {}
        $staff = $pdo->query("SELECT a.id, a.username, a.presence_status, GROUP_CONCAT(d.department) AS departments
                              FROM admin_users a LEFT JOIN admin_user_departments d ON d.admin_id = a.id
                              GROUP BY a.id ORDER BY a.username")->fetchAll();
        foreach ($staff as &$s) { $s['departments'] = $s['departments'] ? explode(',', $s['departments']) : []; }
        unset($s);
        json_out([
            'chats'          => \Chat\Handoff::queue($me),
            'my_departments' => \Chat\Handoff::departmentsOf($me),
            'departments'    => \Chat\Handoff::DEPARTMENTS,
            'staff'          => $staff,
            'open_now'       => \Support\Hours::isOpen(),
            'hours'          => \Support\Hours::SUMMARY,
            'me'             => $me,
        ]);
    }
    $sid = (string)($_GET['session_id'] ?? '');
    $session = \Chat\Handoff::session($sid);
    if (!$session) json_err('Chat not found', 404);
    $m = $pdo->prepare('SELECT id, role, content, created_at FROM chat_messages WHERE session_id = ? ORDER BY id');
    $m->execute([$sid]);
    $t = $pdo->prepare('SELECT id, subject, status, department, priority, customer_email, customer_name, customer_phone, created_at FROM support_tickets WHERE session_id = ? ORDER BY id DESC');
    $t->execute([$sid]);
    $tickets = $t->fetchAll();
    foreach ($tickets as &$tk) { $tk['code'] = \Tickets\Mailer::code((int)$tk['id']); }
    unset($tk);
    $names = $pdo->prepare('SELECT username FROM admin_users WHERE id = ?');
    $claimed = null;
    if ($session['claimed_by']) { $names->execute([$session['claimed_by']]); $claimed = $names->fetchColumn() ?: null; }
    unset($session['ip_hash'], $session['intake']);
    json_out([
        'session'          => $session + ['claimed_username' => $claimed, 'department_label' => \Chat\Handoff::deptLabel($session['department'])],
        'messages'         => $m->fetchAll(),
        'notes'            => \Chat\Handoff::notes($sid),
        'tickets'          => $tickets,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');
$sid = trim((string)($body['session_id'] ?? ''));
if ($sid === '') json_err('session_id required');

$num = fn($v) => ($v === null || $v === '') ? null : (int)$v;
$result = match ($body['action'] ?? '') {
    'claim'         => \Chat\Handoff::claim($sid, $me),
    'send'          => \Chat\LiveChat::sendAgentMessage($sid, $me, (string)($body['message'] ?? '')),
    'end'           => \Chat\Handoff::end($sid, $me),
    'transfer'      => \Chat\Handoff::transfer($sid, $me, ($body['department'] ?? '') ?: null, $num($body['admin_id'] ?? null), (string)($body['note'] ?? '')),
    'note'          => \Chat\Handoff::addNote($sid, $me, (string)($body['note'] ?? '')),
    'create_ticket' => \Chat\Handoff::createTicket($sid, $me, array_intersect_key($body, array_flip(['subject', 'details', 'name', 'email', 'phone', 'department', 'priority']))),
    'set_customer'  => \Chat\Handoff::setCustomer($sid, $body),
    default         => ['ok' => false, 'error' => 'Unknown action'],
};
json_out($result, $result['ok'] ? 200 : 400);

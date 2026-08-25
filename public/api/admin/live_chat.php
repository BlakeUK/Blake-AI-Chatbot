<?php
// public/api/admin/live_chat.php — POST: claim | send | end
// See src/Chat/LiveChat.php for the actual logic; this is just the HTTP
// wrapper around it, same shape as telegram.php's action-based endpoint.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$action     = $body['action'] ?? '';
$session_id = trim((string)($body['session_id'] ?? ''));
if (!$session_id) {
    json_err('session_id required');
}

$adminId = (int)$_SESSION['admin_id'];

$result = match ($action) {
    'claim' => \Chat\LiveChat::claim($session_id, $adminId),
    'send'  => \Chat\LiveChat::sendAgentMessage($session_id, $adminId, (string)($body['message'] ?? '')),
    'end'   => \Chat\LiveChat::endLive($session_id, $adminId),
    default => ['ok' => false, 'error' => 'Unknown action'],
};

if (!$result['ok']) {
    json_out($result, 400);
}

json_out($result);

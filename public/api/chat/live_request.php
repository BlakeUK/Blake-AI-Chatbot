<?php
// public/api/chat/live_request.php — POST: customer asks to talk to a person
// See src/Chat/LiveChat.php for the actual logic.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('live_chat', 10);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body       = json_body();
$session_id = $body['session_id'] ?? '';
if (!$session_id) {
    json_err('session_id required');
}

$result = \Chat\LiveChat::requestLive($session_id);
if (!$result['ok']) {
    json_out($result, 400);
}

json_out($result);

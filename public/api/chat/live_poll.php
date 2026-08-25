<?php
// public/api/chat/live_poll.php — GET: new agent/system messages since a
// given message id, plus the session's current mode (so the widget knows
// when it's been claimed, or when the chat has ended). Polled from the
// widget every few seconds while a live chat is pending or active.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('live_chat_poll', 60);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$session_id = $_GET['session_id'] ?? '';
$after_id   = (int)($_GET['after_id'] ?? 0);

if (!$session_id) {
    json_err('session_id required');
}

$result = \Chat\LiveChat::newMessagesForCustomer($session_id, $after_id);
if (!$result['ok']) {
    json_out($result, 400);
}

json_out($result);

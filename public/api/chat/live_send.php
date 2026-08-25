<?php
// public/api/chat/live_send.php — POST: customer sends a message during a
// live (human) chat. Deliberately separate from send.php - this never
// touches Gemini, see src/Chat/LiveChat.php.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('live_chat', 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

const MAX_MESSAGE_LENGTH = 4000; // matches send.php's own cap

$body       = json_body();
$session_id = $body['session_id'] ?? '';
$message    = trim($body['message'] ?? '');

if (!$session_id || !$message) {
    json_err('session_id and message required');
}
if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
    json_err('Message too long (max ' . MAX_MESSAGE_LENGTH . ' characters)');
}

$result = \Chat\LiveChat::sendCustomerMessage($session_id, $message);
if (!$result['ok']) {
    json_out($result, 400);
}

json_out($result);

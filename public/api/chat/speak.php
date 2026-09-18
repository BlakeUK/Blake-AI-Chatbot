<?php
// public/api/chat/speak.php
// POST { session_id, message_id }  -> audio/wav of Max speaking a short
//                                     summary of that assistant reply
// POST { session_id, kind: "welcome" } -> audio/wav of the fixed greeting
// Only speaks assistant messages that belong to the given session, so it
// cannot be used as an open text-to-speech service. 204 when voice is off.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('speak', CFG['rate_limit_chat']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$cfg = \Speech\Speaker::settings();
if (!$cfg['enabled']) {
    http_response_code(204);
    exit;
}

$body      = json_body();
$sessionId = (string)($body['session_id'] ?? '');
$pdo       = db();

$s = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ?');
$s->execute([$sessionId]);
if (!$s->fetchColumn()) {
    json_err('Invalid session', 404);
}

try {
    if (($body['kind'] ?? '') === 'welcome') {
        $text = \Speech\Speaker::WELCOME;
    } else {
        $m = $pdo->prepare("SELECT content FROM chat_messages WHERE id = ? AND session_id = ? AND role = 'assistant'");
        $m->execute([(int)($body['message_id'] ?? 0), $sessionId]);
        $reply = $m->fetchColumn();
        if ($reply === false) {
            json_err('Message not found', 404);
        }
        $text = \Speech\Speaker::spokenSummary((string)$reply);
    }
    if ($text === '') {
        http_response_code(204);
        exit;
    }
    $wav = \Speech\Speaker::synthesise($text);
} catch (\Throwable $e) {
    json_err('Speech unavailable', 502);
}

header('Content-Type: audio/wav');
header('Content-Length: ' . strlen($wav));
header('Cache-Control: no-store');
header('X-Spoken-Text: ' . rawurlencode(mb_substr($text, 0, 500)));
header('Access-Control-Expose-Headers: X-Spoken-Text');
echo $wav;

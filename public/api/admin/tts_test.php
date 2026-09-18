<?php
// public/api/admin/tts_test.php
// POST { csrf, text? } -> audio/wav: hear Max's current voice settings.
// With a reply-style text, it also runs the spoken-summary step so admins
// hear exactly what customers would.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$text = trim((string)($body['text'] ?? ''));
if (mb_strlen($text) > 2000) json_err('Text too long');

try {
    $spoken = $text === '' ? \Speech\Speaker::WELCOME : \Speech\Speaker::spokenSummary($text);
    $wav    = \Speech\Speaker::synthesise($spoken);
} catch (\Throwable $e) {
    json_err($e->getMessage(), 502);
}
header('Content-Type: audio/wav');
header('X-Spoken-Text: ' . rawurlencode($spoken));
echo $wav;

<?php
// One-off diagnostic: shows which Gemini key/model the server uses and the
// FULL 429 error body (quota metric, quotaId, limit) that Client truncates.
// Never prints the key itself, only its last 4 characters.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$key = \Gemini\Client::getStoredApiKey();
echo "key configured: " . ($key ? 'yes, ends ...' . substr($key, -4) . ' (len ' . strlen($key) . ')' : 'NO') . "\n";
$chat = \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash');
$ext  = \Gemini\Client::getModel('gemini_extract_model', 'gemini_pro');
echo "chat model: $chat\nextract model: $ext\n";
$db = db();
foreach ($db->query("SELECT status, COUNT(*) c FROM knowledge_files GROUP BY status") as $r) echo "files {$r['status']}: {$r['c']}\n";
if (!$key) exit;
foreach (array_unique([$ext, $chat, 'gemini-2.5-flash']) as $m) {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/$m:generateContent?key=$key");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply OK']]]]])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "\n== $m -> HTTP $code\n";
    if ($code !== 200) echo str_replace($key, '[KEY]', (string)$resp), "\n";
}

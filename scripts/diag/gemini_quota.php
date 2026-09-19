<?php
// One-off diagnostic: shows which Gemini key/model the server uses and the
// FULL 429 error body (quota metric, quotaId, limit) that Client truncates.
// Never prints the key itself, only its last 4 characters.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
if (in_array('--requeue-failed', $argv ?? [], true)) {
    print_r(\Knowledge\FileQueue::requeueFailed(db()));
}
$key = \Gemini\Client::getStoredApiKey();
echo "key configured: " . ($key ? 'yes, ends ...' . substr($key, -4) . ' (len ' . strlen($key) . ')' : 'NO') . "\n";
$chat = \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash');
$ext  = \Gemini\Client::getModel('gemini_extract_model', 'gemini_pro');
echo "chat model: $chat\nextract model: $ext\n";
$db = db();
foreach ($db->query("SELECT status, COUNT(*) c FROM knowledge_files GROUP BY status") as $r) echo "files {$r['status']}: {$r['c']}\n";
if (!$key) exit;
foreach (array_unique([$ext, $chat, 'gemini-2.5-flash']) as $m) {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/$m:generateContent");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply OK']]]]])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "\n== $m -> HTTP $code\n";
    if ($code !== 200) echo str_replace($key, '[KEY]', (string)$resp), "\n";
}
try {
    foreach (db()->query("SELECT service, COUNT(*) c, SUM(ok=0) e, ROUND(SUM(cost_usd),6) cost FROM api_usage_log WHERE created_at > unixepoch()-86400 GROUP BY service") as $r) {
        echo "usage 24h {$r['service']}: calls {$r['c']}, errors {$r['e']}, cost \${$r['cost']}\n";
    }
} catch (\Throwable $e) { echo "api_usage_log: " . $e->getMessage() . "\n"; }
echo "pdftotext: " . (trim((string)shell_exec('command -v pdftotext')) ?: 'NOT INSTALLED') . "\n";
foreach (['/etc/php/8.2/fpm/php.ini', '/etc/php/8.3/fpm/php.ini'] as $ini) {
    if (is_file($ini) && preg_match('/^disable_functions\s*=\s*(.*)$/m', file_get_contents($ini), $m)) echo "fpm disable_functions ($ini): '" . trim($m[1]) . "'\n";
}
foreach (db()->query("SELECT id, filename, status, substr(error,1,120) e FROM knowledge_files WHERE status <> 'indexed'") as $r) echo "file {$r['id']} {$r['status']}: {$r['filename']} {$r['e']}\n";
echo "--- recent voice calls (UTC)\n";
foreach (db()->query("SELECT datetime(created_at,'unixepoch') t, model, http_code, ok, substr(error,1,150) e, latency_ms FROM api_usage_log WHERE operation = 'voice' ORDER BY id DESC LIMIT 25") as $r) echo "{$r['t']} {$r['model']} {$r['http_code']} ok={$r['ok']} {$r['latency_ms']}ms {$r['e']}\n";
echo "--- recent chat sessions (UTC)\n";
foreach (db()->query("SELECT s.id, datetime(s.created_at,'unixepoch') t, substr(s.page_url,1,60) u, (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id) n FROM chat_sessions s ORDER BY s.created_at DESC LIMIT 12") as $r) echo "{$r['t']} {$r['id']} msgs={$r['n']} {$r['u']}\n";
echo "--- tts cache: " . count(glob('/var/www/chat/data/tts/*.wav') ?: []) . " files\n";
foreach (['/var/log/caddy', '/var/log/caddy/access.log'] as $l) if (file_exists($l)) echo "log exists: $l\n";
echo "--- caddy log files\n";
foreach (glob('/var/log/caddy/*') ?: [] as $f) echo basename($f) . ' ' . filesize($f) . "\n";
foreach (glob('/var/log/caddy/*.log') ?: [] as $f) {
    $lines = @file($f) ?: [];
    foreach (array_slice($lines, -4000) as $l) {
        if (!preg_match('#/(api/chat/(speak|session)\.php|widget/chat\.(js|css))#', $l)) continue;
        $j = json_decode($l, true);
        if (!$j) continue;
        $r = $j['request'] ?? [];
        echo gmdate('H:i:s', (int)($j['ts'] ?? 0)) . ' ' . ($r['method'] ?? '') . ' ' . ($r['uri'] ?? '') . ' ' . ($j['status'] ?? '') . ' ua=' . substr($r['headers']['User-Agent'][0] ?? '', 0, 90) . ' ip=' . substr(md5($r['remote_ip'] ?? ''), 0, 6) . "\n";
    }
}
echo "--- speech.log tail\n";
foreach (array_slice(@file(dirname(__DIR__, 2) . '/logs/speech.log') ?: [], -40) as $l) echo $l;

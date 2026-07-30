<?php
// public/api/admin/models.php
// GET: fetch available Gemini models from Google API and return list
// Requires Gemini API key already stored in DB.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$apiKey = getApiKey('gemini');
if (!$apiKey) {
    json_err('Gemini API key not configured', 503);
}

$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey);
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$resp || $code !== 200) {
    json_err('Failed to fetch models from Google API', 502);
}

$data   = json_decode($resp, true);
$models = $data['models'] ?? [];

// Filter to generative models only, extract name + displayName
$out = [];
foreach ($models as $m) {
    $methods = $m['supportedGenerationMethods'] ?? [];
    if (!in_array('generateContent', $methods, true)) continue;
    $name = $m['name'] ?? '';
    // Strip 'models/' prefix for cleaner display
    $short = str_replace('models/', '', $name);
    $out[] = [
        'id'          => $short,
        'displayName' => $m['displayName'] ?? $short,
        'description' => $m['description'] ?? '',
    ];
}

usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
json_out($out);

// ── Helper ────────────────────────────────────────────────────────────────────
function getApiKey(string $service): ?string
{
    $row = db()->prepare('SELECT key_enc, iv, tag FROM api_keys WHERE service = ?');
    $row->execute([$service]);
    $r = $row->fetch();
    if (!$r) return null;
    $key = hex2bin(CFG['encrypt_key']);
    $dec = openssl_decrypt(
        hex2bin($r['key_enc']), 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag'])
    );
    return $dec ?: null;
}

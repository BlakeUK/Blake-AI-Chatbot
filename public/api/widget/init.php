<?php
// public/api/widget/init.php
// External sites POST here to get a short-lived widget token.
// Validates API key + IP allowlist before issuing token.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors_widget();
rate_limit('widget_init', 10);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body   = json_body();
$apiKey = trim($body['api_key'] ?? '');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

if (!$apiKey) {
    json_err('api_key required', 401);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM widget_clients WHERE api_key = ? AND active = 1');
$stmt->execute([$apiKey]);
$client = $stmt->fetch();

if (!$client) {
    _audit($pdo, null, $ip, $origin, 'invalid_key');
    json_err('Invalid API key', 401);
}

// ── IP allowlist ──────────────────────────────────────────────────────────────
$allowed_ips = json_decode($client['allowed_ips'] ?? '[]', true);
if (!empty($allowed_ips) && !ip_in_list($ip, $allowed_ips)) {
    _audit($pdo, $client['id'], $ip, $origin, 'ip_blocked');
    json_err('IP not permitted', 403);
}

// ── Origin allowlist ──────────────────────────────────────────────────────────
$allowed_origins = json_decode($client['allowed_origins'] ?? '[]', true);
if (!empty($allowed_origins) && !in_array($origin, $allowed_origins, true)) {
    _audit($pdo, $client['id'], $ip, $origin, 'origin_blocked');
    json_err('Origin not permitted', 403);
}

// ── Issue token ───────────────────────────────────────────────────────────────
$token      = bin2hex(random_bytes(24));
$expires_at = time() + 300;

$pdo->prepare('INSERT INTO widget_tokens (token, client_id, ip, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$token, $client['id'], $ip, $expires_at]);

_audit($pdo, $client['id'], $ip, $origin, 'token_issued');

json_out(['token' => $token, 'expires_in' => 300]);

// ── Helpers ───────────────────────────────────────────────────────────────────
function ip_in_list(string $ip, array $list): bool
{
    foreach ($list as $entry) {
        $entry = trim($entry);
        if ($entry === $ip) return true;
        if (str_contains($entry, '/') && ip_in_cidr($ip, $entry)) return true;
    }
    return false;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipl = ip2long($ip); $subl = ip2long($subnet);
    if ($ipl === false || $subl === false) return false;
    $mask = -1 << (32 - (int)$bits);
    return ($ipl & $mask) === ($subl & $mask);
}

function _audit(PDO $pdo, ?int $clientId, string $ip, string $origin, string $action): void
{
    $pdo->prepare('INSERT INTO widget_access_log (client_id, ip, origin, action) VALUES (?, ?, ?, ?)')
        ->execute([$clientId, $ip, $origin, $action]);
}

function cors_widget(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

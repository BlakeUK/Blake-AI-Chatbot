<?php
// public/api/admin/apikeys.php — GET/POST for encrypted API key storage

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    // Return service names only — never expose key values
    $stmt = $pdo->query('SELECT id, service, updated_at FROM api_keys');
    json_out($stmt->fetchAll());
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST' || $method === 'PUT') {
    $service = trim($body['service'] ?? '');
    $value   = trim($body['value']   ?? '');
    if (!$service || !$value) {
        json_err('service and value required');
    }

    $encKey = hex2bin(CFG['encrypt_key']);
    $iv     = random_bytes(12);
    $tag    = '';
    $enc    = openssl_encrypt($value, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);

    $pdo->prepare('
        INSERT INTO api_keys (service, key_enc, iv, tag, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(service) DO UPDATE SET key_enc=excluded.key_enc, iv=excluded.iv, tag=excluded.tag, updated_at=excluded.updated_at
    ')->execute([$service, bin2hex($enc), bin2hex($iv), bin2hex($tag), time()]);

    // Audit
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'api_key_set', $service]);

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $service = trim($body['service'] ?? '');
    if (!$service) json_err('service required');
    $pdo->prepare('DELETE FROM api_keys WHERE service=?')->execute([$service]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'api_key_deleted', $service]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/email_settings.php - SMTP settings for ticket emails.
// GET: settings (password never returned) + recent outbox
// POST {csrf, host, port, security, username, password?, from_email, from_name, notify_email}
// POST {csrf, action: 'test', to}   send a test email now
// POST {csrf, action: 'retry'}      re-queue failed emails

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::requireRole('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = \Mail\Smtp::settings();
    $has = $cfg['password'] !== null && $cfg['password'] !== '';
    unset($cfg['password']);
    $out = $pdo->query('SELECT id, to_addr, subject, status, attempts, last_error, created_at, sent_at FROM email_outbox ORDER BY id DESC LIMIT 25')->fetchAll();
    json_out(['settings' => $cfg + ['has_password' => $has, 'configured' => \Mail\Smtp::isConfigured()], 'outbox' => $out]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');
$save = function (string $k, string $v) use ($pdo) {
    $pdo->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')->execute([$k, $v, time()]);
};

$action = $body['action'] ?? 'save';
if ($action === 'test') {
    $to = trim((string)($body['to'] ?? ''));
    try {
        \Mail\Smtp::send($to, 'Blake UK support desk: test email', "This is a test email from the Blake UK support desk.\n\nIf you can read this, ticket confirmation emails will be delivered.\n");
        json_out(['ok' => true]);
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}
if ($action === 'retry') {
    $n = $pdo->exec("UPDATE email_outbox SET status = 'pending', attempts = 0 WHERE status = 'failed'");
    json_out(['ok' => true, 'requeued' => $n]);
}

$sec = (string)($body['security'] ?? 'tls');
if (!in_array($sec, ['tls', 'ssl', 'none'], true)) json_err('Security must be tls, ssl or none');
$port = (int)($body['port'] ?? 587);
if ($port < 1 || $port > 65535) json_err('Invalid port');
foreach (['from_email', 'notify_email'] as $k) {
    $v = trim((string)($body[$k] ?? ''));
    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) json_err("Invalid email address: {$v}");
}
$save('smtp_host', trim((string)($body['host'] ?? '')));
$save('smtp_port', (string)$port);
$save('smtp_security', $sec);
$save('smtp_username', trim((string)($body['username'] ?? '')));
$save('smtp_from_email', trim((string)($body['from_email'] ?? '')));
$save('smtp_from_name', trim((string)($body['from_name'] ?? '')) ?: 'Blake UK Support');
$save('support_notify_email', trim((string)($body['notify_email'] ?? '')) ?: \Mail\Smtp::DEFAULT_NOTIFY);
$pw = (string)($body['password'] ?? '');
if ($pw !== '') {
    $iv = random_bytes(12); $tag = '';
    $enc = openssl_encrypt($pw, 'aes-256-gcm', hex2bin(CFG['encrypt_key']), OPENSSL_RAW_DATA, $iv, $tag);
    $pdo->prepare('INSERT INTO api_keys (service, key_enc, iv, tag, updated_at) VALUES (?,?,?,?,?)
                   ON CONFLICT(service) DO UPDATE SET key_enc=excluded.key_enc, iv=excluded.iv, tag=excluded.tag, updated_at=excluded.updated_at')
        ->execute(['smtp', bin2hex($enc), bin2hex($iv), bin2hex($tag), time()]);
}
$pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], 'email_settings_saved', null]);
json_out(['ok' => true]);

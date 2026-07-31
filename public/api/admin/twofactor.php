<?php
// public/api/admin/twofactor.php — self-service 2FA (any logged-in role)
// GET: status | POST: start enrollment | PUT: confirm enrollment | DELETE: disable

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();
$id     = (int)$_SESSION['admin_id'];

if ($method === 'GET') {
    $row = $pdo->prepare('SELECT totp_enabled FROM admin_users WHERE id = ?');
    $row->execute([$id]);
    $row = $row->fetch();
    json_out(['enabled' => (bool)($row['totp_enabled'] ?? 0)]);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    // Start (or restart) enrollment — not enabled until confirmed via PUT.
    $userRow = $pdo->prepare('SELECT username FROM admin_users WHERE id = ?');
    $userRow->execute([$id]);
    $username = $userRow->fetchColumn();

    $secret = \Auth\Totp::generateSecret();
    $enc    = \Auth\Admin::encryptTotpSecret($secret);

    $pdo->prepare('
        UPDATE admin_users
        SET totp_secret_enc=?, totp_secret_iv=?, totp_secret_tag=?, totp_enabled=0, backup_codes=NULL
        WHERE id=?
    ')->execute([$enc['enc'], $enc['iv'], $enc['tag'], $id]);

    json_out([
        'secret'       => $secret,
        'otpauth_uri'  => \Auth\Totp::provisioningUri($secret, $username),
    ]);
}

if ($method === 'PUT') {
    // Confirm enrollment with a code from the authenticator app.
    $code = trim((string)($body['code'] ?? ''));
    if (!$code) json_err('code required');

    $row = $pdo->prepare('SELECT totp_secret_enc, totp_secret_iv, totp_secret_tag FROM admin_users WHERE id = ?');
    $row->execute([$id]);
    $row = $row->fetch();

    $secret = $row ? \Auth\Admin::decryptTotpSecret($row) : null;
    if (!$secret) json_err('No pending enrollment — start enrollment first', 400);
    if (!\Auth\Totp::verifyCode($secret, $code)) json_err('Invalid code', 401);

    $backupCodes = [];
    $hashedCodes = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(bin2hex(random_bytes(4))); // 8-char backup code
        $backupCodes[] = $c;
        $hashedCodes[] = password_hash($c, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    $pdo->prepare('UPDATE admin_users SET totp_enabled=1, backup_codes=? WHERE id=?')
        ->execute([json_encode($hashedCodes), $id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$id, '2fa_enabled', $id]);

    json_out(['ok' => true, 'backup_codes' => $backupCodes]);
}

if ($method === 'DELETE') {
    // Disable — requires current password AND a valid code/backup code, so a
    // hijacked session alone can't turn off 2FA protection.
    $password = (string)($body['password'] ?? '');
    $code     = trim((string)($body['code'] ?? ''));
    if (!$password || !$code) json_err('password and code required');

    $row = $pdo->prepare('SELECT password, totp_secret_enc, totp_secret_iv, totp_secret_tag, backup_codes FROM admin_users WHERE id = ?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || !password_verify($password, $row['password'])) {
        json_err('Current password is incorrect', 401);
    }

    $secret  = \Auth\Admin::decryptTotpSecret($row);
    $valid   = $secret && \Auth\Totp::verifyCode($secret, $code);
    if (!$valid) {
        $codes = json_decode($row['backup_codes'] ?? '[]', true) ?: [];
        foreach ($codes as $hash) {
            if (password_verify($code, $hash)) { $valid = true; break; }
        }
    }
    if (!$valid) json_err('Invalid code', 401);

    $pdo->prepare('
        UPDATE admin_users
        SET totp_secret_enc=NULL, totp_secret_iv=NULL, totp_secret_tag=NULL, totp_enabled=0, backup_codes=NULL
        WHERE id=?
    ')->execute([$id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$id, '2fa_disabled', $id]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/login.php — POST: login | DELETE: logout

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];

// ── Logout ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    \Auth\Admin::logout();
    json_out(['ok' => true]);
}

if ($method !== 'POST') {
    json_err('Method not allowed', 405);
}

rate_limit('admin_login', 5); // strict: 5/min

$body = json_body();

// Step 2: completing login with a pending 2FA challenge
if (!empty($body['code'])) {
    if (\Auth\Admin::verifyTwoFactor(trim((string)$body['code']))) {
        json_out(['ok' => true, 'csrf' => \Auth\Admin::csrf(), 'role' => \Auth\Admin::role(), 'id' => $_SESSION['admin_id']]);
    }
    json_err('Invalid or expired code', 401);
}

// Step 1: username + password
$user = trim($body['username'] ?? '');
$pass = $body['password'] ?? '';

if (!$user || !$pass) {
    json_err('username and password required');
}

\Auth\Admin::session();

$result = \Auth\Admin::login($user, $pass);
if ($result === 'ok') {
    json_out(['ok' => true, 'csrf' => \Auth\Admin::csrf(), 'role' => \Auth\Admin::role(), 'id' => $_SESSION['admin_id']]);
} elseif ($result === 'requires_2fa') {
    json_out(['ok' => false, 'requires_2fa' => true]);
} elseif ($result === 'locked') {
    $mins = (int)ceil((\Auth\Admin::lockedForSeconds($user) ?? 0) / 60);
    json_err("Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.', 429);
} else {
    json_err('Invalid credentials', 401);
}

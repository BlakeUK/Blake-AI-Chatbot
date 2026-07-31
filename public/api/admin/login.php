<?php
// public/api/admin/login.php — POST: login | DELETE: logout

require dirname(__DIR__, 3) . '/src/bootstrap.php';

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
$user = trim($body['username'] ?? '');
$pass = $body['password'] ?? '';

if (!$user || !$pass) {
    json_err('username and password required');
}

\Auth\Admin::session();

if (\Auth\Admin::login($user, $pass)) {
    json_out(['ok' => true, 'csrf' => \Auth\Admin::csrf(), 'role' => \Auth\Admin::role()]);
} else {
    json_err('Invalid credentials', 401);
}

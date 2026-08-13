<?php
// public/check/login.php
// GET: current login state for the frontend's login-overlay check.
// POST {username, password}: attempts login, sets the session on success.
//
// Deliberately its own tiny gate (Auth\CheckTool) rather than
// Auth\Admin::login() - this is one fixed credential for the sitemap
// tool, not another admin_users account with a role and access to the
// rest of the admin panel.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['ok' => true, 'authed' => \Auth\CheckTool::isLoggedIn()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit('check_login', 10); // strict: this is a login endpoint

    $body = json_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') json_err('Username and password required');

    if (\Auth\CheckTool::attempt($username, $password)) {
        json_out(['ok' => true]);
    }
    json_err('Invalid username or password', 401);
}

json_err('Method not allowed', 405);

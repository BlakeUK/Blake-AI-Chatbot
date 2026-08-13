<?php
// src/Auth/CheckTool.php
// A small, dedicated login gate for public/check/ - deliberately separate
// from Auth\Admin (this is one fixed username/password for the sitemap
// tool, not another admin_users account with a role and access to the
// rest of the admin panel). Session-cookie config mirrors Auth\Admin::
// session() so both behave identically for HTTPS detection/SameSite -
// duplicated rather than shared, since the two are independent gates on
// independent credentials and this file should be able to change on its
// own without touching the main admin auth path.
//
// verifyCredentials() is kept as a pure function with no session calls
// specifically so it's unit testable: session_start() (called from
// attempt()/isLoggedIn()/logout()) warns "headers already sent" under
// this project's CLI test harness, which runs every test in one
// continuous process rather than one process per request the way
// real PHP-FPM requests work - the existing Auth\Admin session path has
// the same characteristic and, for the same reason, isn't unit tested
// directly either.

declare(strict_types=1);

namespace Auth;

class CheckTool
{
    private const SESSION_KEY = 'check_tool_authed';

    public static function session(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_set_cookie_params([
            'lifetime' => CFG['session_lifetime'],
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => $https ? 'None' : 'Strict',
        ]);
        session_start();
    }

    // Constant-time-safe on both the username and password comparison
    // (password_verify already is; hash_equals() gives the username
    // check the same property, rather than leaking "wrong username" vs
    // "wrong password" through response timing).
    public static function verifyCredentials(string $username, string $password): bool
    {
        $expectedUser = (string)(CFG['check_tool_username'] ?? '');
        $expectedHash = (string)(CFG['check_tool_password_hash'] ?? '');
        if ($expectedUser === '' || $expectedHash === '') return false;

        return hash_equals($expectedUser, $username) && password_verify($password, $expectedHash);
    }

    public static function attempt(string $username, string $password): bool
    {
        if (!self::verifyCredentials($username, $password)) return false;

        self::session();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        return true;
    }

    public static function isLoggedIn(): bool
    {
        self::session();
        return $_SESSION[self::SESSION_KEY] ?? false;
    }

    public static function requireAuth(): void
    {
        if (!self::isLoggedIn()) {
            json_err('Not logged in', 401);
        }
    }

    public static function logout(): void
    {
        self::session();
        unset($_SESSION[self::SESSION_KEY]);
    }
}

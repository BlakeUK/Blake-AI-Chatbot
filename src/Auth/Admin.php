<?php
// src/Auth/Admin.php

declare(strict_types=1);

namespace Auth;

class Admin
{
    public static function session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => CFG['session_lifetime'],
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public static function check(): void
    {
        self::session();
        if (empty($_SESSION['admin_id'])) {
            json_err('Unauthorised', 401);
        }
    }

    public static function login(string $username, string $password): bool
    {
        $stmt = db()->prepare('SELECT id, password FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password'])) {
            self::session();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $row['id'];
            db()->prepare('UPDATE admin_users SET last_login=? WHERE id=?')
                 ->execute([time(), $row['id']]);
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        self::session();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf(): string
    {
        self::session();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(string $token): void
    {
        self::session();
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            json_err('Invalid CSRF token', 403);
        }
    }
}

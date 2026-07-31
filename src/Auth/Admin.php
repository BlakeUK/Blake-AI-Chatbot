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

    public const ROLES = ['admin', 'editor', 'user'];

    public static function check(): void
    {
        self::session();
        if (empty($_SESSION['admin_id'])) {
            json_err('Unauthorised', 401);
        }
    }

    public static function role(): string
    {
        self::session();
        return $_SESSION['admin_role'] ?? 'user';
    }

    // Require the logged-in admin to hold one of the given roles.
    public static function requireRole(string ...$allowed): void
    {
        self::check();
        if (!in_array(self::role(), $allowed, true)) {
            json_err('Forbidden', 403);
        }
    }

    // Returns 'ok' (fully logged in), 'requires_2fa' (password correct, code needed),
    // or 'invalid' (bad username/password).
    public static function login(string $username, string $password): string
    {
        $stmt = db()->prepare('SELECT id, password, role, totp_enabled FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password'])) {
            return 'invalid';
        }

        if ((int)$row['totp_enabled'] === 1) {
            self::session();
            session_regenerate_id(true);
            $_SESSION['pending_2fa_id'] = $row['id'];
            return 'requires_2fa';
        }

        self::completeLogin($row);
        return 'ok';
    }

    // Step 2 of login when 2FA is enabled: verify a TOTP code or an unused backup code.
    public static function verifyTwoFactor(string $code): bool
    {
        self::session();
        $id = $_SESSION['pending_2fa_id'] ?? null;
        if (!$id) return false;

        $stmt = db()->prepare('SELECT id, role, totp_secret_enc, totp_secret_iv, totp_secret_tag, backup_codes FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $secret = self::decryptTotpSecret($row);
        if ($secret && Totp::verifyCode($secret, $code)) {
            self::completeLogin($row);
            return true;
        }

        $codes = json_decode($row['backup_codes'] ?? '[]', true) ?: [];
        foreach ($codes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($codes[$i]);
                db()->prepare('UPDATE admin_users SET backup_codes=? WHERE id=?')
                    ->execute([json_encode(array_values($codes)), $id]);
                self::completeLogin($row);
                return true;
            }
        }

        return false;
    }

    private static function completeLogin(array $row): void
    {
        self::session();
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $row['id'];
        $_SESSION['admin_role'] = $row['role'];
        unset($_SESSION['pending_2fa_id']);
        db()->prepare('UPDATE admin_users SET last_login=? WHERE id=?')
             ->execute([time(), $row['id']]);
    }

    public static function logout(): void
    {
        self::session();
        $_SESSION = [];
        session_destroy();
    }

    // ── TOTP secret encryption (AES-256-GCM, same pattern as api_keys) ────────
    public static function encryptTotpSecret(string $secret): array
    {
        $key = hex2bin(CFG['encrypt_key']);
        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return ['enc' => bin2hex($enc), 'iv' => bin2hex($iv), 'tag' => bin2hex($tag)];
    }

    public static function decryptTotpSecret(array $row): ?string
    {
        if (empty($row['totp_secret_enc'])) return null;
        $key = hex2bin(CFG['encrypt_key']);
        $dec = openssl_decrypt(
            hex2bin($row['totp_secret_enc']), 'aes-256-gcm', $key,
            OPENSSL_RAW_DATA, hex2bin($row['totp_secret_iv']), hex2bin($row['totp_secret_tag'])
        );
        return $dec ?: null;
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

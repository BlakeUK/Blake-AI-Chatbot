<?php
// src/Auth/Admin.php

declare(strict_types=1);

namespace Auth;

class Admin
{
    public static function session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Browsers only accept "Secure" cookies over an actual HTTPS connection
            // (localhost/127.0.0.1 get an exception, but not other plain-HTTP hosts —
            // e.g. an IP address before a domain + TLS cert are set up). Forcing
            // secure=true unconditionally would silently break every session on
            // such a deployment: the cookie gets set but the browser never stores it.
            $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            session_set_cookie_params([
                'lifetime' => CFG['session_lifetime'],
                'path'     => '/',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public const ROLES = ['admin', 'editor', 'user'];

    // Account-level lockout, distinct from rate_limit('admin_login', 5) in
    // login.php - that's a per-IP, per-60-second-window throttle that counts
    // every request (successful or not) and resets on the next window, so a
    // patient attacker pacing under it is never blocked. This counts actual
    // consecutive failures per account and persists a lock once they hit the
    // threshold, regardless of how slowly they're spaced out.
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS     = 3600; // 1 hour

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
    // 'locked' (too many recent failures - check lockedUntil() for when), or
    // 'invalid' (bad username/password).
    public static function login(string $username, string $password): string
    {
        // Usernames are matched case-insensitively at login: different people
        // on different machines naturally type their own username with their
        // own casing, which won't always match whatever case an admin typed
        // when creating the account.
        $stmt = db()->prepare('SELECT id, password, role, totp_enabled, failed_attempts, locked_until FROM admin_users WHERE username = ? COLLATE NOCASE');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        // Unknown username: nothing to lock, but still don't leak whether the
        // account exists via timing/response differences beyond what already
        // happens below (password_verify against a bad row already takes a
        // similar path). rate_limit() in login.php is what actually protects
        // against hammering nonexistent usernames, since there's no account
        // row here to attach a failure count to.
        if (!$row) {
            return 'invalid';
        }

        if ($row['locked_until'] && (int)$row['locked_until'] > time()) {
            return 'locked';
        }

        if (!password_verify($password, $row['password'])) {
            self::registerFailure((int)$row['id'], (int)$row['failed_attempts']);
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

    // Seconds until the account unlocks, or null if it isn't locked. Kept
    // separate from login()'s return value so login.php can build a specific
    // "try again in N minutes" message without login() needing to return
    // anything more than its existing simple status string.
    public static function lockedForSeconds(string $username): ?int
    {
        $stmt = db()->prepare('SELECT locked_until FROM admin_users WHERE username = ? COLLATE NOCASE');
        $stmt->execute([$username]);
        $until = (int)($stmt->fetchColumn() ?: 0);
        return $until > time() ? $until - time() : null;
    }

    private static function registerFailure(int $id, int $currentFailures): void
    {
        $failures = $currentFailures + 1;
        if ($failures >= self::MAX_FAILED_ATTEMPTS) {
            db()->prepare('UPDATE admin_users SET failed_attempts=?, locked_until=? WHERE id=?')
                ->execute([$failures, time() + self::LOCKOUT_SECONDS, $id]);
        } else {
            db()->prepare('UPDATE admin_users SET failed_attempts=? WHERE id=?')
                ->execute([$failures, $id]);
        }
    }

    // Step 2 of login when 2FA is enabled: verify a TOTP code or an unused backup code.
    public static function verifyTwoFactor(string $code): bool
    {
        self::session();
        $id = $_SESSION['pending_2fa_id'] ?? null;
        if (!$id) return false;

        $stmt = db()->prepare('SELECT id, role, totp_secret_enc, totp_secret_iv, totp_secret_tag, backup_codes, failed_attempts, locked_until FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return false;

        if ($row['locked_until'] && (int)$row['locked_until'] > time()) {
            return false;
        }

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

        self::registerFailure((int)$row['id'], (int)$row['failed_attempts']);
        return false;
    }

    private static function completeLogin(array $row): void
    {
        self::session();
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $row['id'];
        $_SESSION['admin_role'] = $row['role'];
        unset($_SESSION['pending_2fa_id']);
        db()->prepare('UPDATE admin_users SET last_login=?, failed_attempts=0, locked_until=NULL WHERE id=?')
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

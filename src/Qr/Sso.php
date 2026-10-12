<?php
// src/Qr/Sso.php: signing staff in to the QR tool without a second password. A signed-in person asks for a one-time code; the QR tool is
// sent to /sso?t=<code>; the QR tool then asks this server (api/qr_sso_verify.php) who the code belongs to. The code works once, for sixty
// seconds, and only its hash is kept here. Staff roles map to the QR tool's two: admin to admin, editor to member. The read-only role has none.

declare(strict_types=1);

namespace Qr;

final class Sso
{
    public const TTL = 60;
    public const DEFAULT_BASE = 'https://qr.blakegroup.uk';

    public static function base(): string
    {
        $q = db()->prepare("SELECT value FROM settings WHERE key = 'qr_base_url'"); $q->execute();
        $v = rtrim((string)$q->fetchColumn(), '/');
        return preg_match('~\Ahttps://[A-Za-z0-9.-]+(:\d{1,5})?\z~', $v) ? $v : self::DEFAULT_BASE;
    }

    /** The QR tool's role for a staff role, or null if that role may not use the QR tool. */
    public static function qrRole(string $staffRole): ?string
    {
        return ['admin' => 'admin', 'editor' => 'member'][$staffRole] ?? null;
    }

    /** A new one-time code for this staff member. */
    public static function mint(int $adminId, ?int $now = null): string
    {
        $now ??= time();
        db()->prepare('DELETE FROM qr_sso_tokens WHERE expires_at < ?')->execute([$now - 3600]);
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        db()->prepare('INSERT INTO qr_sso_tokens (token_hash, admin_id, created_at, expires_at) VALUES (?,?,?,?)')->execute([hash('sha256', $code), $adminId, $now, $now + self::TTL]);
        return $code;
    }

    public static function url(string $code): string { return self::base() . '/sso?t=' . rawurlencode($code); }

    /** Spends a code and says who it was for, as the QR tool wants it: ['username' => .., 'role' => 'admin'|'member']. Null if unknown, spent, expired, or the person may not use the QR tool. */
    public static function redeem(string $code, ?int $now = null): ?array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{20,120}\z/', $code)) return null;
        $now ??= time(); $h = hash('sha256', $code);
        $u = db()->prepare('UPDATE qr_sso_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?');
        $u->execute([$now, $h, $now]);
        if ($u->rowCount() !== 1) return null;                           // only the one request that flips it from unused may go on
        $q = db()->prepare('SELECT a.username, a.role FROM qr_sso_tokens t JOIN admin_users a ON a.id = t.admin_id WHERE t.token_hash = ?'); $q->execute([$h]);
        $r = $q->fetch();
        if (!$r || ($role = self::qrRole((string)$r['role'])) === null) return null;
        return ['username' => (string)$r['username'], 'role' => $role];
    }
}

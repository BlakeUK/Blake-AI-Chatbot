<?php
// src/Newsroom/Pass.php: the limited pass the Newsroom page carries. The page runs in a sandbox with no access to the admin session, so it
// cannot use the admin's cookie. Instead the admin page it was opened from hands it this signed, expiring pass, and the Newsroom address
// accepts nothing else. It opens only the news endpoints (feeds, pictures, the AI helper), never the rest of the admin.

declare(strict_types=1);

namespace Newsroom;

final class Pass
{
    public const TTL = 43200;     // twelve hours

    private static function key(): string { return hash('sha256', 'newsroom|' . hex2bin(CFG['encrypt_key']), true); }
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

    public static function mint(int $adminId, ?int $now = null): string
    {
        $payload = self::b64(json_encode(['a' => $adminId, 'e' => ($now ?? time()) + self::TTL]));
        return $payload . '.' . self::b64(hash_hmac('sha256', 'pass|' . $payload, self::key(), true));
    }

    /** The staff member's id if the pass is genuine, unexpired, and they still exist; otherwise null. */
    public static function verify(string $token, ?int $now = null): ?int
    {
        if (!preg_match('/\A([A-Za-z0-9_-]{10,200})\.([A-Za-z0-9_-]{43})\z/', $token, $m)) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', 'pass|' . $m[1], self::key(), true)), $m[2])) return null;
        $d = json_decode(self::unb64($m[1]), true);
        if (!is_array($d) || !isset($d['a'], $d['e']) || (int)$d['e'] < ($now ?? time())) return null;
        $q = db()->prepare('SELECT 1 FROM admin_users WHERE id = ?'); $q->execute([(int)$d['a']]);
        return $q->fetchColumn() ? (int)$d['a'] : null;
    }
}

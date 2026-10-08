<?php
// src/Tickets/Link.php
// The customer's secure ("magic") link to their own ticket: no account, no password.
//
// The token is an HMAC of the ticket number and a per-ticket random salt, keyed from the server's
// secret. Nothing secret is stored, so a leaked database does not leak working links; and rotate()
// replaces the salt, which instantly revokes every link already emailed.

declare(strict_types=1);

namespace Tickets;

class Link
{
    public const BASE = 'https://blakegroup.uk';

    private static function salt(int $ticketId): string
    {
        $pdo = db();
        $q = $pdo->prepare('SELECT salt FROM ticket_links WHERE ticket_id = ?');
        $q->execute([$ticketId]);
        $salt = $q->fetchColumn();
        if (!$salt) {
            $pdo->prepare('INSERT OR IGNORE INTO ticket_links (ticket_id, salt) VALUES (?, ?)')->execute([$ticketId, bin2hex(random_bytes(16))]);
            $q->execute([$ticketId]);
            $salt = $q->fetchColumn();
        }
        return (string)$salt;
    }

    public static function token(int $ticketId): string
    {
        $key = hash('sha256', 'ticket-link|' . hex2bin(CFG['encrypt_key']), true);   // a key used for nothing else
        $mac = hash_hmac('sha256', "ticket|{$ticketId}|" . self::salt($ticketId), $key, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');                  // 43 URL-safe characters
    }

    public static function url(int $ticketId): string
    {
        return self::BASE . '/ticket.php/' . Mailer::code($ticketId) . '/' . self::token($ticketId);
    }

    // Returns the ticket id when the code and token belong together, otherwise null.
    // A wrong code and a wrong token are indistinguishable to the caller.
    public static function verify(string $code, string $token): ?int
    {
        if (!preg_match('/^TCK-(\d{4,9})$/', $code, $m)) return null;
        $id = (int)$m[1] - 1000;
        if ($id < 1) return null;
        $q = db()->prepare('SELECT 1 FROM support_tickets WHERE id = ?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) {
            hash_equals(str_repeat('a', 43), str_pad(substr($token, 0, 43), 43, 'b'));   // keep the work similar
            return null;
        }
        return hash_equals(self::token($id), $token) ? $id : null;
    }

    // Revokes every link already sent for this ticket.
    public static function rotate(int $ticketId): void
    {
        db()->prepare('INSERT INTO ticket_links (ticket_id, salt) VALUES (?, ?)
                       ON CONFLICT(ticket_id) DO UPDATE SET salt = excluded.salt, created_at = unixepoch()')
            ->execute([$ticketId, bin2hex(random_bytes(16))]);
    }
}

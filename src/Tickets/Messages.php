<?php
// src/Tickets/Messages.php
// The conversation on a ticket: replies from staff and from the customer (through their secure link).
// Internal notes are a separate thing (support_tickets.notes) and are never shown to the customer.

declare(strict_types=1);

namespace Tickets;

class Messages
{
    public const MAX_LEN = 5000;
    public const MAX_PER_HOUR = 10;   // customer messages per ticket per hour

    // Normalises line endings, removes control characters (keeping new lines and tabs) and trims.
    // Returns null if the text is not valid UTF-8.
    public static function clean(string $s): ?string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s);
        if ($s === null) return null;
        $s = preg_replace("/\n{4,}/", "\n\n\n", $s) ?? $s;
        return trim($s);
    }

    public static function all(int $ticketId): array
    {
        $q = db()->prepare('SELECT id, author, author_name, body, created_at FROM ticket_messages WHERE ticket_id = ? ORDER BY id');
        $q->execute([$ticketId]);
        return $q->fetchAll();
    }

    private static function ticket(int $ticketId): ?array
    {
        $q = db()->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $q->execute([$ticketId]);
        return $q->fetch() ?: null;
    }

    private static function insert(int $ticketId, string $author, string $body, ?int $adminId, string $name): int
    {
        $pdo = db();
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author, admin_id, author_name, body) VALUES (?,?,?,?,?)')
            ->execute([$ticketId, $author, $adminId, $name, $body]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE support_tickets SET updated_at = ? WHERE id = ?')->execute([time(), $ticketId]);
        return $id;
    }

    // A reply from the customer, through their secure link.
    // Returns ['ok' => bool, 'error' => ?string, 'id' => ?int].
    public static function customerReply(int $ticketId, string $body): array
    {
        $ticket = self::ticket($ticketId);
        if (!$ticket) return ['ok' => false, 'error' => 'Ticket not found.'];
        $body = self::clean($body);
        if ($body === null) return ['ok' => false, 'error' => 'That message could not be read. Please try again.'];
        if ($body === '') return ['ok' => false, 'error' => 'Please type a message first.'];
        if (mb_strlen($body) > self::MAX_LEN) return ['ok' => false, 'error' => 'Please keep your message under ' . number_format(self::MAX_LEN) . ' characters.'];

        $pdo = db();
        $recent = $pdo->prepare("SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = ? AND author = 'customer' AND created_at > ?");
        $recent->execute([$ticketId, time() - 3600]);
        if ((int)$recent->fetchColumn() >= self::MAX_PER_HOUR) {
            return ['ok' => false, 'error' => 'You have sent several messages in a short time. Please wait a while, or phone us.'];
        }
        // a double-click or a refresh must not send the same message twice
        $last = $pdo->prepare("SELECT id, body, created_at FROM ticket_messages WHERE ticket_id = ? AND author = 'customer' ORDER BY id DESC LIMIT 1");
        $last->execute([$ticketId]);
        if (($l = $last->fetch()) && $l['body'] === $body && (time() - (int)$l['created_at']) < 300) {
            return ['ok' => true, 'error' => null, 'id' => (int)$l['id'], 'duplicate' => true];
        }

        $name = trim((string)($ticket['customer_name'] ?? '')) ?: 'Customer';
        $id = self::insert($ticketId, 'customer', $body, null, $name);
        // they have come back to us: anything parked, resolved or closed is open again
        if (in_array($ticket['status'], ['waiting', 'resolved', 'closed'], true)) {
            $pdo->prepare("UPDATE support_tickets SET status = 'open' WHERE id = ?")->execute([$ticketId]);
        }
        Mailer::notifyStaffOfCustomerReply($ticketId, $id);
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    // A reply from a member of staff. The customer is emailed with their secure link.
    // Returns ['ok' => bool, 'error' => ?string, 'id' => ?int, 'emailed' => bool].
    public static function staffReply(int $ticketId, int $adminId, string $body): array
    {
        $ticket = self::ticket($ticketId);
        if (!$ticket) return ['ok' => false, 'error' => 'Ticket not found.'];
        $body = self::clean($body);
        if ($body === null || $body === '') return ['ok' => false, 'error' => 'Please type a reply first.'];
        if (mb_strlen($body) > self::MAX_LEN) return ['ok' => false, 'error' => 'Replies can be up to ' . number_format(self::MAX_LEN) . ' characters.'];

        $id = self::insert($ticketId, 'staff', $body, $adminId, 'Blake UK Support');
        if ($ticket['status'] === 'open') {
            db()->prepare("UPDATE support_tickets SET status = 'in_progress' WHERE id = ?")->execute([$ticketId]);
        }
        $emailed = trim((string)($ticket['customer_email'] ?? '')) !== '' && Mailer::sendStaffReply($ticketId, $id);
        return ['ok' => true, 'error' => null, 'id' => $id, 'emailed' => $emailed];
    }
}

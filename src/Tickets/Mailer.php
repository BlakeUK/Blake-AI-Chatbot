<?php
// src/Tickets/Mailer.php
// Ticket reference numbers and the confirmation emails sent when a ticket
// is raised: one to the support inbox (sales@blake-uk.com by default, see
// Admin > Email) with the full details and chat transcript, and one to the
// customer quoting their ticket number (only when we have their email).

declare(strict_types=1);

namespace Tickets;

class Mailer
{
    public static function code(int $ticketId): string
    {
        return 'TCK-' . (1000 + $ticketId);
    }

    // Queues both emails for $ticketId. Returns ['staff' => bool, 'customer' => bool].
    public static function sendConfirmations(int $ticketId): array
    {
        $pdo = db();
        $t = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $t->execute([$ticketId]);
        $ticket = $t->fetch();
        if (!$ticket) return ['staff' => false, 'customer' => false];

        $code  = self::code($ticketId);
        $dept  = \Chat\Handoff::deptLabel($ticket['department'] ?? null);
        $name  = trim((string)($ticket['customer_name'] ?? ''));
        $email = trim((string)($ticket['customer_email'] ?? ''));
        $phone = trim((string)($ticket['customer_phone'] ?? ''));
        $subject = trim((string)($ticket['subject'] ?? '')) ?: 'Support request';
        $details = trim((string)($ticket['details'] ?? ''));

        $transcript = '';
        if (!empty($ticket['session_id'])) {
            $m = $pdo->prepare("SELECT role, content, created_at FROM chat_messages WHERE session_id = ? AND role IN ('user','assistant','agent','bot','system') ORDER BY id");
            $m->execute([$ticket['session_id']]);
            foreach ($m->fetchAll() as $row) {
                $who = ['user' => 'Customer', 'assistant' => 'Max (AI)', 'bot' => 'Max (AI)', 'agent' => 'Staff', 'system' => 'Notice'][$row['role']] ?? $row['role'];
                $transcript .= '[' . self::ukTime((int)$row['created_at'], 'H:i') . "] {$who}: " . trim($row['content']) . "\n";
            }
            $s = $pdo->prepare('SELECT page_url FROM chat_sessions WHERE id = ?');
            $s->execute([$ticket['session_id']]);
            $pageUrl = $s->fetchColumn() ?: null;
        }

        $cfg = \Mail\Smtp::settings();
        $staffBody = "A new support ticket has been raised.\n\n"
            . "Ticket:      {$code}\n"
            . "Subject:     {$subject}\n"
            . "Department:  {$dept}\n"
            . "Priority:    " . ucfirst((string)($ticket['priority'] ?? 'medium')) . "\n"
            . 'Raised:      ' . self::ukTime((int)$ticket['created_at'], 'd/m/Y H:i') . "\n\n"
            . "Customer\n"
            . 'Name:        ' . ($name ?: 'not given') . "\n"
            . 'Email:       ' . ($email ?: 'not given') . "\n"
            . 'Telephone:   ' . ($phone ?: 'not given') . "\n"
            . (!empty($pageUrl) ? "Page:        {$pageUrl}\n" : '')
            . "\nIssue\n" . ($details ?: $subject) . "\n"
            . ($transcript !== '' ? "\nChat transcript\n{$transcript}" : '')
            . "\nManage this ticket in the Operator Console or at https://blakegroup.uk/admin/\n";
        $staffOk = \Mail\Outbox::queue($cfg['notify'], "New support ticket {$code}: {$subject}", $staffBody, $ticketId) !== null;

        $customerOk = false;
        if ($email !== '') {
            $open = \Support\Hours::isOpen();
            $body = 'Hello' . ($name ? " {$name}" : '') . ",\n\n"
                . "Thank you for contacting Blake UK. We have raised support ticket {$code} for your enquiry:\n\n"
                . "{$subject}\n" . ($details && $details !== $subject ? "\n{$details}\n" : '')
                . "\nOur {$dept} team will get back to you as soon as possible"
                . ($open ? '.' : ', from ' . \Support\Hours::nextOpening() . '.') . "\n"
                . 'Our opening hours are ' . \Support\Hours::SUMMARY . ".\n\n"
                . "Please quote {$code} if you contact us about this enquiry.\n\n"
                . "Kind regards,\nBlake UK Support\nhttps://www.blake-uk.com/support.html\n";
            $customerOk = \Mail\Outbox::queue($email, "Your Blake UK support ticket {$code}", $body, $ticketId) !== null;
        }
        return ['staff' => $staffOk, 'customer' => $customerOk];
    }

    private static function ukTime(int $ts, string $fmt): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(\Support\Hours::TZ))->format($fmt);
    }
}

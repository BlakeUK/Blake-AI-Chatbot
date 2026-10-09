<?php
// src/Tickets/Mailer.php
// Ticket reference numbers and the emails around a ticket:
//  - when a ticket is raised: the customer gets a confirmation (ticket number, what happens next, opening
//    hours, telephone number and their secure link to follow the ticket) with the staff addresses blind
//    copied; each staff address also gets the full details and chat transcript (Admin > Email sets who);
//  - when staff reply: the customer is emailed the reply and their secure link;
//  - when the customer replies on the ticket page: the staff addresses are emailed.

declare(strict_types=1);

namespace Tickets;

class Mailer
{
    public static function code(int $ticketId): string
    {
        return 'TCK-' . (1000 + $ticketId);
    }

    // Queues the staff emails and the customer's confirmation for $ticketId. Returns ['staff' => bool, 'customer' => bool].
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

        $cfg   = \Mail\Smtp::settings();
        $staff = \Mail\Smtp::staff($cfg);
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
            . "\nManage this ticket, and reply to the customer, in the Operator Console or at https://blakegroup.uk/admin/\n";
        $staffOk = false;
        foreach ($staff as $addr) {
            if (\Mail\Outbox::queue($addr, "New support ticket {$code}: {$subject}", $staffBody, $ticketId) !== null) $staffOk = true;
        }

        $customerOk = false;
        if ($email !== '') {
            $open = \Support\Hours::isOpen();
            $body = 'Hello' . ($name ? " {$name}" : '') . ",\n\n"
                . "Thank you for contacting Blake UK. We have raised support ticket {$code} for your enquiry.\n\n"
                . "Your enquiry\n------------\n"
                . "{$subject}\n" . ($details && $details !== $subject ? "\n{$details}\n" : '')
                . "\nWhat happens next\n-----------------\n"
                . "Our {$dept} team will get back to you as soon as possible"
                . ($open ? '.' : ', from ' . \Support\Hours::nextOpening() . '.') . "\n\n"
                . "Follow your ticket\n------------------\n"
                . "Use this secure link to see our replies and to send us more information. You do not need to log in:\n\n"
                . \Tickets\Link::url($ticketId) . "\n\n"
                . "Please keep this email private, as anyone with the link can see and reply to your ticket.\n\n"
                . self::contactBlock($cfg, $code)
                . "Please do not reply to this email. Replies go through the link above, so they reach the right person straight away.\n\n"
                . "Kind regards,\nBlake UK Support\nhttps://www.blake-uk.com/support.html\n";
            // the staff addresses are blind copied, so the customer never sees them
            $customerOk = \Mail\Outbox::queue($email, "Your Blake UK support ticket {$code}", $body, $ticketId, $staff) !== null;
        }
        return ['staff' => $staffOk, 'customer' => $customerOk];
    }

    // "Contact us" for the emails: the opening hours and the ticket number to quote.
    // Emails deliberately never carry a telephone number (the ticket page can; see Admin > Email).
    public static function contactBlock(array $cfg, string $code): string
    {
        return "Contact us\n----------\n"
            . 'Opening hours: ' . \Support\Hours::SUMMARY . "\n"
            . "Please quote {$code} if you contact us about this enquiry.\n\n";
    }

    // Emails the customer a reply that staff have written on the ticket page. Returns false if it could not be queued.
    public static function sendStaffReply(int $ticketId, int $messageId): bool
    {
        $pdo = db();
        $t = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?'); $t->execute([$ticketId]); $ticket = $t->fetch();
        $m = $pdo->prepare("SELECT * FROM ticket_messages WHERE id = ? AND ticket_id = ? AND author = 'staff'"); $m->execute([$messageId, $ticketId]); $msg = $m->fetch();
        $email = trim((string)($ticket['customer_email'] ?? ''));
        if (!$ticket || !$msg || $email === '') return false;
        $code = self::code($ticketId);
        $name = trim((string)($ticket['customer_name'] ?? ''));
        $subject = trim((string)($ticket['subject'] ?? '')) ?: 'Support request';
        $body = 'Hello' . ($name ? " {$name}" : '') . ",\n\n"
            . "There is a new reply on your support ticket {$code} ({$subject}).\n\n"
            . 'Reply from Blake UK Support, ' . self::ukTime((int)$msg['created_at'], 'd/m/Y H:i') . "\n"
            . "-------------------------------------------\n"
            . $msg['body'] . "\n"
            . "-------------------------------------------\n\n"
            . "To see the whole conversation, or to reply, use your secure link. You do not need to log in:\n\n"
            . \Tickets\Link::url($ticketId) . "\n\n"
            . self::contactBlock(\Mail\Smtp::settings(), $code)
            . "Please do not reply to this email. Replies go through the link above, so they reach the right person straight away.\n\n"
            . "Kind regards,\nBlake UK Support\nhttps://www.blake-uk.com/support.html\n";
        return \Mail\Outbox::queue($email, "New reply on your Blake UK support ticket {$code}", $body, $ticketId) !== null;
    }

    // Tells the staff addresses that the customer has written back on the ticket page.
    public static function notifyStaffOfCustomerReply(int $ticketId, int $messageId): bool
    {
        $pdo = db();
        $t = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?'); $t->execute([$ticketId]); $ticket = $t->fetch();
        $m = $pdo->prepare("SELECT * FROM ticket_messages WHERE id = ? AND ticket_id = ? AND author = 'customer'"); $m->execute([$messageId, $ticketId]); $msg = $m->fetch();
        if (!$ticket || !$msg) return false;
        $code = self::code($ticketId);
        $subject = trim((string)($ticket['subject'] ?? '')) ?: 'Support request';
        $name = trim((string)($ticket['customer_name'] ?? ''));
        $body = "The customer has replied on support ticket {$code}.\n\n"
            . "Subject:   {$subject}\n"
            . 'Customer:  ' . ($name ?: 'not given') . ' <' . (trim((string)($ticket['customer_email'] ?? '')) ?: 'no email') . ">\n"
            . 'Sent:      ' . self::ukTime((int)$msg['created_at'], 'd/m/Y H:i') . "\n"
            . "Status:    " . str_replace('_', ' ', (string)$ticket['status']) . "\n\n"
            . "Their message\n-------------\n" . $msg['body'] . "\n\n"
            . "Reply in the Operator Console or at https://blakegroup.uk/admin/ (Tickets, then View {$code}).\n";
        $ok = false;
        foreach (\Mail\Smtp::staff() as $addr) {
            if (\Mail\Outbox::queue($addr, "Customer reply on ticket {$code}: {$subject}", $body, $ticketId) !== null) $ok = true;
        }
        return $ok;
    }

    private static function ukTime(int $ts, string $fmt): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(\Support\Hours::TZ))->format($fmt);
    }
}

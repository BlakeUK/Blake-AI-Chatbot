<?php
// src/Files/ShareMail.php: the email that carries a share link to a customer (or anyone). The password is never put in it:
// it is not kept anywhere in readable form, and sending it with the link would defeat the point of it.

declare(strict_types=1);

namespace Files;

class ShareMail
{
    public const MAX_RECIPIENTS = 5;

    /** @return list<string> the valid, distinct addresses in a list typed or pasted by the sender */
    public static function addresses(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
            $a = trim($a, "<>\"'");
            if (filter_var($a, FILTER_VALIDATE_EMAIL) && strlen($a) <= 200) $out[strtolower($a)] ??= $a;
        }
        return array_values($out);
    }

    public static function body(array $share, string $sender, string $note = ''): string
    {
        $when = '';
        $from = $share['available_from'] !== null ? Shares::ukTime((int)$share['available_from']) : '';
        $to   = $share['available_to'] !== null ? Shares::ukTime((int)$share['available_to']) : '';
        if ($from && $to) $when = "It is available from {$from} until {$to} (UK time).";
        elseif ($to)      $when = "It is available until {$to} (UK time).";
        elseif ($from)    $when = "It becomes available on {$from} (UK time).";
        $items = Shares::items((int)$share['id']);
        $what = count($items) === 1 ? $items[0]['name'] : count($items) . ' items';
        $msg = trim((string)$share['message']);
        return "Hello,\n\n"
            . "{$sender} at Blake UK has shared " . ($share['title'] !== $what ? "\"{$share['title']}\" ({$what})" : "\"{$what}\"") . " with you.\n\n"
            . ($msg !== '' ? $msg . "\n\n" : '')
            . ($note !== '' ? $note . "\n\n" : '')
            . "You can get it here:\n" . Shares::url($share) . "\n\n"
            . (!empty($share['password_hash']) ? "The link is protected by a password. You will be given the password separately.\n" : '')
            . ($when !== '' ? $when . "\n" : '')
            . (($when !== '' || !empty($share['password_hash'])) ? "\n" : '')
            . "If you were not expecting this, you can ignore this email.\n\nBlake UK\n";
    }

    /** Queues one email per address. Returns how many were queued. @param list<string> $to */
    public static function send(array $share, array $to, string $sender, string $note = ''): int
    {
        if ($share['kind'] !== 'link') throw new \InvalidArgumentException('Only link shares can be emailed.');
        if (Shares::state($share) === 'revoked') throw new \InvalidArgumentException('That share has been withdrawn.');
        $to = array_slice($to, 0, self::MAX_RECIPIENTS);
        if (!$to) throw new \InvalidArgumentException('Enter at least one valid email address.');
        $note = mb_substr(trim(preg_replace('/[^\P{C}\n\t]/u', '', $note) ?? ''), 0, 1000);
        $subject = mb_substr($sender . ' has shared ' . mb_substr((string)$share['title'], 0, 80) . ' with you', 0, 150);
        $subject = preg_replace('/[\r\n]+/', ' ', $subject);
        $n = 0;
        foreach ($to as $addr) if (\Mail\Outbox::queue($addr, $subject, self::body($share, $sender, $note)) !== null) $n++;
        try { db()->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')->execute([$share['owner_id'], 'file_share_email', 'share ' . $share['id'], "{$n} email(s)"]); } catch (\Throwable $e) {}
        return $n;
    }
}

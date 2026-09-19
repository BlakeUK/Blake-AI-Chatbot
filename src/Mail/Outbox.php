<?php
// src/Mail/Outbox.php
// Queue of outgoing emails. queue() never fails the caller (a chat must
// never break because the mail server is down); process() is run by the
// support cron every minute and retries failures up to MAX_ATTEMPTS.

declare(strict_types=1);

namespace Mail;

class Outbox
{
    public const MAX_ATTEMPTS = 6;

    public static function queue(string $to, string $subject, string $body, ?int $ticketId = null): ?int
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return null;
        try {
            db()->prepare('INSERT INTO email_outbox (to_addr, subject, body_text, ticket_id) VALUES (?,?,?,?)')
                ->execute([$to, $subject, $body, $ticketId]);
            return (int)db()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Outbox::queue failed: ' . $e->getMessage());
            return null;
        }
    }

    // Sends due emails. $sender is injectable for tests. Returns counts.
    public static function process(int $limit = 20, ?callable $sender = null): array
    {
        $pdo    = db();
        if ($sender === null) {
            if (!Smtp::isConfigured()) {
                return ['sent' => 0, 'failed' => 0, 'skipped' => 'not configured'];
            }
            $sender = fn(string $to, string $s, string $b) => Smtp::send($to, $s, $b);
        }
        $rows = $pdo->prepare("SELECT * FROM email_outbox WHERE status = 'pending' AND attempts < ? ORDER BY id LIMIT ?");
        $rows->execute([self::MAX_ATTEMPTS, $limit]);
        $sent = 0; $failed = 0;
        foreach ($rows->fetchAll() as $r) {
            try {
                $sender($r['to_addr'], $r['subject'], $r['body_text']);
                $pdo->prepare("UPDATE email_outbox SET status='sent', attempts=attempts+1, sent_at=?, last_error=NULL WHERE id=?")
                    ->execute([time(), $r['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int)$r['attempts'] + 1;
                $pdo->prepare('UPDATE email_outbox SET status=?, attempts=?, last_error=? WHERE id=?')
                    ->execute([$attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending', $attempts, mb_substr($e->getMessage(), 0, 500), $r['id']]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }
}

<?php
namespace Knowledge;

// Background extraction queue for knowledge_files. Rows in 'pending' are
// picked up by scripts/process_pending_files.php (cron, every minute).
// Gemini rate limits (HTTP 429) are not a property of the file, so a
// rate-limited extraction goes back to 'pending' to be retried
// automatically instead of being left in 'error' for someone to click
// Retry on one row at a time.
class FileQueue
{
    public const RATE_LIMIT_NOTE = 'Gemini rate limit reached - queued, will retry automatically';

    public static function isRateLimited(?string $err): bool
    {
        return $err !== null && str_contains($err, 'Gemini API error 429');
    }

    // Record an extraction failure: rate limits re-queue, anything else is
    // a real error. Returns the status written.
    public static function recordFailure(\PDO $pdo, int $fileId, string $err): string
    {
        $status = self::isRateLimited($err) ? 'pending' : 'error';
        $note   = $status === 'pending' ? self::RATE_LIMIT_NOTE : $err;
        $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')
            ->execute([$status, $note, $fileId]);
        return $status;
    }

    // Move every 'error' row whose stored file still exists back to
    // 'pending' (clearing any partial chunks) so the cron worker retries
    // it. Rows whose file is gone stay in error - retrying can't help.
    // Returns ['queued' => n, 'skipped_missing' => n].
    public static function requeueFailed(\PDO $pdo): array
    {
        $rows = $pdo->query("SELECT id, stored_path FROM knowledge_files WHERE status = 'error'")->fetchAll();
        $queued = 0;
        $missing = 0;
        $delChunks = $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type = ? AND source_id = ?');
        $upd = $pdo->prepare("UPDATE knowledge_files SET status = 'pending', error = NULL WHERE id = ? AND status = 'error'");
        foreach ($rows as $r) {
            if (!$r['stored_path'] || !file_exists($r['stored_path'])) {
                $missing++;
                continue;
            }
            $delChunks->execute(['file', (int)$r['id']]);
            $upd->execute([(int)$r['id']]);
            $queued += $upd->rowCount();
        }
        return ['queued' => $queued, 'skipped_missing' => $missing];
    }
}

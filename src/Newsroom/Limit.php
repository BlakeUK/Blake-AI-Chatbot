<?php
// src/Newsroom/Limit.php: how much of the Newsroom one person may use, and how many AI calls may run at the same moment.
// Limits are PER PERSON (not per office address, which several colleagues share). A newspaper makes a lot of calls (every story is rewritten,
// pictures are drawn), so the limits are generous; the concurrency cap is what protects the website: each AI call holds one of the server's
// workers while Google answers, so only a few may run together and the page waits and tries again when it is told the helper is busy.

declare(strict_types=1);

namespace Newsroom;

final class Limit
{
    /** Counts one use by this person in this minute; false once they are over $perMinute. */
    public static function hit(int $adminId, string $what, int $perMinute, ?int $now = null): bool
    {
        $key = hash('sha256', 'news-person:' . $adminId); $win = intdiv($now ?? time(), 60); $pdo = db();
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$win - 1]);
        $pdo->prepare('INSERT INTO rate_limits (ip_hash, endpoint, window_start, count) VALUES (?,?,?,1) ON CONFLICT(ip_hash, endpoint, window_start) DO UPDATE SET count = count + 1')->execute([$key, $what, $win]);
        $q = $pdo->prepare('SELECT count FROM rate_limits WHERE ip_hash = ? AND endpoint = ? AND window_start = ?'); $q->execute([$key, $what, $win]);
        return (int)$q->fetchColumn() <= $perMinute;
    }

    /** Takes one of $max places for $name, or null if all are taken. Give it back with release(); a place is also freed when the request ends, however it ends. @return resource|null */
    public static function acquire(string $name, int $max, ?string $dir = null)
    {
        $dir ??= sys_get_temp_dir() . '/blake-slots'; @mkdir($dir, 0700, true);
        for ($i = 0; $i < $max; $i++) {
            $f = @fopen("{$dir}/{$name}-{$i}.lock", 'c');
            if ($f && flock($f, LOCK_EX | LOCK_NB)) return $f;
            if ($f) fclose($f);
        }
        return null;
    }

    /** @param resource|null $slot */
    public static function release($slot): void { if (is_resource($slot)) { flock($slot, LOCK_UN); fclose($slot); } }
}

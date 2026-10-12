<?php
// src/Newsroom/Data.php: the Newsroom's small stores: a ten-minute cache of fetched feeds (so many people reading the same feed cost the
// news sites one visit, not many), and each person's own bookmarks and custom feeds. Only two named settings can be saved, and only as JSON.

declare(strict_types=1);

namespace Newsroom;

final class Data
{
    public const PREF_KEYS = ['cb_bookmarks', 'cb_custom_feeds'];
    public const MAX_PREF = 200000;

    /** Returns the cached text for $key if it is younger than $ttl seconds, otherwise makes it, keeps it, and returns it. */
    public static function cached(string $key, int $ttl, callable $make, ?int $now = null): string
    {
        $now ??= time(); $k = hash('sha256', $key);
        $q = db()->prepare('SELECT body, fetched_at FROM news_cache WHERE cache_key = ?'); $q->execute([$k]);
        if (($r = $q->fetch()) && $now - (int)$r['fetched_at'] < $ttl) return (string)$r['body'];
        $body = (string)$make();
        db()->prepare('INSERT OR REPLACE INTO news_cache (cache_key, fetched_at, body) VALUES (?,?,?)')->execute([$k, $now, $body]);
        if (random_int(1, 50) === 1) db()->prepare('DELETE FROM news_cache WHERE fetched_at < ?')->execute([$now - 86400]);
        return $body;
    }

    /** @return array<string,string> this person's saved settings */
    public static function prefs(int $adminId): array
    {
        $q = db()->prepare('SELECT k, v FROM news_prefs WHERE admin_id = ?'); $q->execute([$adminId]);
        return $q->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
    }

    public static function setPref(int $adminId, string $k, string $v): void
    {
        if (!in_array($k, self::PREF_KEYS, true)) throw new \InvalidArgumentException('That setting cannot be saved.');
        if (strlen($v) > self::MAX_PREF) throw new \InvalidArgumentException('That is too large to save.');
        if (json_decode($v) === null && trim($v) !== 'null') throw new \InvalidArgumentException('That setting is not valid.');
        db()->prepare('INSERT OR REPLACE INTO news_prefs (admin_id, k, v, updated_at) VALUES (?,?,?,?)')->execute([$adminId, $k, $v, time()]);
    }
}

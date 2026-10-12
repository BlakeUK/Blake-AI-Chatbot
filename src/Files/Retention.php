<?php
// src/Files/Retention.php: automatic deletion of shared files, for link shares whose maker ticked "delete the files automatically".
// The files are deleted from the owner's storage the retention period (setting fs_retention_days, default 7) after the link ends, or after it
// is first downloaded, whichever comes first. It is cautious: a file that is still shared another way is kept until that share ends too,
// withdrawing a share cancels its deletion, and nothing is deleted without a date to delete it on.

declare(strict_types=1);

namespace Files;

class Retention
{
    public const DEFAULT_DAYS = 7;

    public static function days(): int
    {
        $q = db()->prepare("SELECT value FROM settings WHERE key = 'fs_retention_days'");
        $q->execute();
        $d = (int)$q->fetchColumn();
        return $d >= 1 && $d <= 365 ? $d : self::DEFAULT_DAYS;
    }

    /** Is this file or folder still part of some other share that is open now or yet to open? (Another link, or a colleague share, that has not ended or been withdrawn.) */
    public static function stillShared(int $nodeId, int $exceptShare, int $now): bool
    {
        $q = db()->prepare('SELECT * FROM fs_shares WHERE id != ? AND revoked_at IS NULL AND purged_at IS NULL');
        $q->execute([$exceptShare]);
        foreach ($q->fetchAll() as $s) {
            if (!in_array(Shares::state($s, $now), ['open', 'not_yet'], true)) continue;
            if (Shares::covers((int)$s['id'], $nodeId)) return true;
        }
        return false;
    }

    /** Deletes what is due. Returns ['shares' => shares finished, 'items' => items deleted, 'waiting' => shares held back by another share]. Safe to run every minute. */
    public static function purgeDue(?int $now = null): array
    {
        $now ??= time();
        $out = ['shares' => 0, 'items' => 0, 'waiting' => 0];
        $q = db()->query("SELECT * FROM fs_shares WHERE kind = 'link' AND auto_delete = 1 AND purged_at IS NULL AND revoked_at IS NULL");
        foreach ($q->fetchAll() as $s) {
            $at = Shares::deleteAt($s);
            if ($at === null || $at > $now) continue;
            $held = false; $deleted = 0;
            foreach (Shares::items((int)$s['id']) as $item) {
                if (self::stillShared((int)$item['id'], (int)$s['id'], $now)) { $held = true; continue; }
                $deleted += Store::delete((int)$s['owner_id'], [(int)$item['id']]);
            }
            $out['items'] += $deleted;
            if ($held) { $out['waiting']++; continue; }
            db()->prepare('UPDATE fs_shares SET purged_at = ? WHERE id = ?')->execute([$now, $s['id']]);
            $out['shares']++;
            try { db()->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')->execute([$s['owner_id'], 'file_share_autodelete', 'share ' . $s['id'], "{$deleted} item(s) deleted automatically"]); } catch (\Throwable $e) {}
        }
        return $out;
    }
}

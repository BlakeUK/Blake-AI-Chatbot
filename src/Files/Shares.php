<?php
// src/Files/Shares.php: shares made from a staff member's files.
//   link   anyone with the address can open it (optionally with a password, and between two dates and times). For customers.
//   staff  chosen colleagues (or all staff) see it under "Shared with me" once signed in.
// The address of a link is never stored: it is worked out from the share's number and a random salt with a secret key, as for ticket
// links, so it can be copied again later but cannot be guessed. Changing the salt ("reset link") kills every copy of the old address.

declare(strict_types=1);

namespace Files;

class Shares
{
    public const PASSWORD_MIN = 6;
    public const UNLOCK_SECONDS = 7200;

    private static function key(): string { return hash('sha256', 'fs-share|' . hex2bin(CFG['encrypt_key']), true); }

    private static function b64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }

    public static function token(int $id, string $salt): string { return self::b64(hash_hmac('sha256', "share|{$id}|{$salt}", self::key(), true)); }

    public static function url(array $s): string { return \Tickets\Link::BASE . '/s.php/' . (int)$s['id'] . '/' . self::token((int)$s['id'], (string)$s['salt']); }

    public static function get(int $id): ?array
    {
        $q = db()->prepare('SELECT * FROM fs_shares WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** The share, if (and only if) the token is the right one for it. A wrong token and a missing share look the same. */
    public static function verify(int $id, string $token): ?array
    {
        $s = self::get($id);
        return ($s && $s['kind'] === 'link' && hash_equals(self::token($id, (string)$s['salt']), $token)) ? $s : null;
    }

    // ---- dates (entered and shown in UK time) ------------------------------------------------------------------------

    public static function parseLocal(?string $s): ?int
    {
        $s = trim((string)$s);
        if ($s === '') return null;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) {
            throw new \InvalidArgumentException('That date and time is not valid.');
        }
        return (new \DateTimeImmutable($s, new \DateTimeZone('Europe/London')))->getTimestamp();
    }

    public static function ukTime(?int $ts, string $fmt = 'j M Y, H:i'): string
    {
        return $ts === null ? '' : (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Europe/London'))->format($fmt);
    }

    public static function toLocalInput(?int $ts): string { return self::ukTime($ts, 'Y-m-d\TH:i'); }

    /** revoked, not_yet, expired or open */
    public static function state(array $s, ?int $now = null): string
    {
        $now ??= time();
        if (!empty($s['revoked_at'])) return 'revoked';
        if (!empty($s['available_from']) && $now < (int)$s['available_from']) return 'not_yet';
        if (!empty($s['available_to']) && $now >= (int)$s['available_to']) return 'expired';
        return 'open';
    }

    // ---- making and changing -----------------------------------------------------------------------------------------

    private static function options(array $o, bool $isNew): array
    {
        $out = [];
        if ($isNew || array_key_exists('title', $o)) $out['title'] = mb_substr(trim(preg_replace('/[\x00-\x1f]/u', '', (string)($o['title'] ?? '')) ?? ''), 0, 120);
        if ($isNew || array_key_exists('message', $o)) {
            $m = trim(str_replace("\r\n", "\n", (string)($o['message'] ?? '')));
            if (mb_strlen($m) > 2000) throw new \InvalidArgumentException('Please keep the message under 2,000 characters.');
            $out['message'] = preg_replace('/[^\P{C}\n\t]/u', '', $m) ?? '';
        }
        foreach (['available_from' => 'from', 'available_to' => 'to'] as $col => $key) {
            if ($isNew || array_key_exists($key, $o)) $out[$col] = self::parseLocal($o[$key] ?? null);
        }
        return $out;
    }

    private static function checkWindow(?int $from, ?int $to): void
    {
        if ($from !== null && $to !== null && $to <= $from) throw new \InvalidArgumentException('The end time must be after the start time.');
        if ($to !== null && $to <= time()) throw new \InvalidArgumentException('The end time has already passed.');
    }

    /** @param list<int> $nodeIds  @param array<string,mixed> $o title, message, password, from, to (UK local), staff (admin ids), all_staff */
    public static function create(int $owner, string $kind, array $nodeIds, array $o): array
    {
        if (!in_array($kind, ['link', 'staff'], true)) throw new \InvalidArgumentException('Choose who to share with.');
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
        if (!$nodeIds) throw new \InvalidArgumentException('Choose something to share first.');
        if (count($nodeIds) > 200) throw new \InvalidArgumentException('Please share fewer items at once, or put them in a folder and share that.');
        $nodes = [];
        foreach ($nodeIds as $id) $nodes[] = Store::mine($id, $owner) ?? throw new \InvalidArgumentException('An item was not found.');
        $v = self::options($o, true);
        if ($v['title'] === '') $v['title'] = count($nodes) === 1 ? (string)$nodes[0]['name'] : count($nodes) . ' items';
        self::checkWindow($v['available_from'], $v['available_to']);

        $hash = null; $staff = []; $all = 0;
        if ($kind === 'link') {
            $pw = (string)($o['password'] ?? '');
            if ($pw !== '') {
                if (mb_strlen($pw) < self::PASSWORD_MIN) throw new \InvalidArgumentException('The password must be at least ' . self::PASSWORD_MIN . ' characters.');
                if (mb_strlen($pw) > 100) throw new \InvalidArgumentException('The password is too long.');
                $hash = password_hash($pw, PASSWORD_BCRYPT);
            }
        } else {
            $all = !empty($o['all_staff']) ? 1 : 0;
            foreach ((array)($o['staff'] ?? []) as $a) {
                $a = (int)$a;
                if ($a !== $owner && self::adminExists($a)) $staff[$a] = $a;
            }
            if (!$all && !$staff) throw new \InvalidArgumentException('Choose which colleagues to share with, or share with all staff.');
        }
        $pdo = db();
        $pdo->prepare('INSERT INTO fs_shares (owner_id, kind, title, message, salt, password_hash, available_from, available_to, all_staff, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$owner, $kind, $v['title'], $v['message'], bin2hex(random_bytes(16)), $hash, $v['available_from'], $v['available_to'], $all, time()]);
        $id = (int)$pdo->lastInsertId();
        foreach ($nodeIds as $n) $pdo->prepare('INSERT OR IGNORE INTO fs_share_items (share_id, node_id) VALUES (?,?)')->execute([$id, $n]);
        foreach ($staff as $a) $pdo->prepare('INSERT OR IGNORE INTO fs_share_users (share_id, admin_id) VALUES (?,?)')->execute([$id, $a]);
        self::audit($owner, 'file_share_create', "share {$id}", "{$kind}: " . $v['title']);
        return self::get($id);
    }

    private static function adminExists(int $id): bool
    {
        $q = db()->prepare('SELECT 1 FROM admin_users WHERE id = ?');
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    private static function audit(int $admin, string $action, string $target, string $detail): void
    {
        try { db()->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')->execute([$admin, $action, $target, mb_substr($detail, 0, 300)]); } catch (\Throwable $e) {}
    }

    private static function owned(int $id, int $actor, bool $isAdmin): array
    {
        $s = self::get($id);
        if (!$s || (!$isAdmin && (int)$s['owner_id'] !== $actor)) throw new \InvalidArgumentException('That share was not found.');
        return $s;
    }

    /** Changes a share's title, message, dates and (for links) password. Only the fields present in $o change; 'clear_password' removes the password. */
    public static function update(int $actor, int $id, array $o, bool $isAdmin = false): array
    {
        $s = self::owned($id, $actor, $isAdmin);
        $v = self::options($o, false);
        if (isset($v['title']) && $v['title'] === '') unset($v['title']);
        $from = array_key_exists('available_from', $v) ? $v['available_from'] : ($s['available_from'] !== null ? (int)$s['available_from'] : null);
        $to   = array_key_exists('available_to', $v) ? $v['available_to'] : ($s['available_to'] !== null ? (int)$s['available_to'] : null);
        if (array_key_exists('available_to', $v) || array_key_exists('available_from', $v)) self::checkWindow($from, $to);
        if ($s['kind'] === 'link') {
            if (!empty($o['clear_password'])) $v['password_hash'] = null;
            elseif (($pw = (string)($o['password'] ?? '')) !== '') {
                if (mb_strlen($pw) < self::PASSWORD_MIN || mb_strlen($pw) > 100) throw new \InvalidArgumentException('The password must be between ' . self::PASSWORD_MIN . ' and 100 characters.');
                $v['password_hash'] = password_hash($pw, PASSWORD_BCRYPT);
            }
        }
        if ($v) {
            $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($v)));
            db()->prepare("UPDATE fs_shares SET {$sets} WHERE id = ?")->execute([...array_values($v), $id]);
        }
        self::audit($actor, 'file_share_update', "share {$id}", implode(',', array_keys($v)));
        return self::get($id);
    }

    public static function revoke(int $actor, int $id, bool $isAdmin = false): void
    {
        self::owned($id, $actor, $isAdmin);
        db()->prepare('UPDATE fs_shares SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([time(), $id]);
        self::audit($actor, 'file_share_revoke', "share {$id}", '');
    }

    /** Gives the share a new address; every copy of the old one stops working. */
    public static function reset(int $actor, int $id, bool $isAdmin = false): array
    {
        self::owned($id, $actor, $isAdmin);
        db()->prepare('UPDATE fs_shares SET salt = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $id]);
        self::audit($actor, 'file_share_reset', "share {$id}", '');
        return self::get($id);
    }

    // ---- what is in a share, and who may see it ----------------------------------------------------------------------

    /** @return list<array<string,mixed>> the items chosen for the share (files and folders) */
    public static function items(int $shareId): array
    {
        $q = db()->prepare("SELECT n.* FROM fs_share_items i JOIN fs_nodes n ON n.id = i.node_id WHERE i.share_id = ? ORDER BY n.kind = 'file', LOWER(n.name)");
        $q->execute([$shareId]);
        return $q->fetchAll();
    }

    /** Is this file or folder one of the shared items, or somewhere inside a shared folder? */
    public static function covers(int $shareId, int $nodeId): bool
    {
        foreach (self::items($shareId) as $it) if (Store::isInside($nodeId, (int)$it['id'])) return true;
        return false;
    }

    public static function canStaffView(array $s, int $adminId): bool
    {
        if ($s['kind'] !== 'staff' || self::state($s) !== 'open') return false;
        if ((int)$s['owner_id'] === $adminId) return true;
        if ((int)$s['all_staff'] === 1) return true;
        $q = db()->prepare('SELECT 1 FROM fs_share_users WHERE share_id = ? AND admin_id = ?');
        $q->execute([$s['id'], $adminId]);
        return (bool)$q->fetchColumn();
    }

    /** @return list<array<string,mixed>> shares made by this person (or everyone's, for an administrator), newest first, each with its state, address and a summary */
    public static function listMine(int $owner, bool $all = false): array
    {
        $q = db()->prepare('SELECT s.*, (SELECT username FROM admin_users WHERE id = s.owner_id) AS owner_name FROM fs_shares s ' . ($all ? '' : 'WHERE s.owner_id = ? ') . 'ORDER BY s.id DESC LIMIT 300');
        $q->execute($all ? [] : [$owner]);
        return array_map([self::class, 'decorate'], $q->fetchAll());
    }

    /** @return list<array<string,mixed>> colleagues' shares that are open to this person now */
    public static function sharedWith(int $adminId): array
    {
        $q = db()->prepare("SELECT s.*, (SELECT username FROM admin_users WHERE id = s.owner_id) AS owner_name FROM fs_shares s WHERE s.kind = 'staff' AND s.owner_id != ? AND s.revoked_at IS NULL
            AND (s.all_staff = 1 OR s.id IN (SELECT share_id FROM fs_share_users WHERE admin_id = ?)) ORDER BY s.id DESC LIMIT 300");
        $q->execute([$adminId, $adminId]);
        $out = [];
        foreach ($q->fetchAll() as $s) if (self::state($s) === 'open') $out[] = self::decorate($s);
        return $out;
    }

    public static function decorate(array $s): array
    {
        $items = self::items((int)$s['id']);
        $users = [];
        if ($s['kind'] === 'staff' && !(int)$s['all_staff']) {
            $q = db()->prepare('SELECT a.username FROM fs_share_users u JOIN admin_users a ON a.id = u.admin_id WHERE u.share_id = ? ORDER BY a.username');
            $q->execute([$s['id']]);
            $users = $q->fetchAll(\PDO::FETCH_COLUMN);
        }
        return [
            'id' => (int)$s['id'], 'kind' => $s['kind'], 'title' => $s['title'], 'message' => $s['message'], 'owner_id' => (int)$s['owner_id'], 'owner_name' => $s['owner_name'] ?? null,
            'state' => self::state($s), 'has_password' => !empty($s['password_hash']), 'all_staff' => (bool)$s['all_staff'], 'users' => $users,
            'available_from' => $s['available_from'] !== null ? (int)$s['available_from'] : null, 'available_to' => $s['available_to'] !== null ? (int)$s['available_to'] : null,
            'from_input' => self::toLocalInput($s['available_from'] !== null ? (int)$s['available_from'] : null), 'to_input' => self::toLocalInput($s['available_to'] !== null ? (int)$s['available_to'] : null),
            'created_at' => (int)$s['created_at'], 'downloads' => (int)$s['downloads'], 'last_access' => $s['last_access'] !== null ? (int)$s['last_access'] : null,
            'url' => $s['kind'] === 'link' ? self::url($s) : null,
            'items' => array_map(fn($n) => ['id' => (int)$n['id'], 'kind' => $n['kind'], 'name' => $n['name']], $items),
        ];
    }

    /** Link shares by this person that include the item or a folder above it, newest first: what "Copy link" should use. */
    public static function linksForNode(int $owner, int $nodeId): array
    {
        $ids = [];
        for ($n = Store::get($nodeId), $i = 0; $n && $i < 60; $i++) { $ids[] = (int)$n['id']; $n = $n['parent_id'] !== null ? Store::get((int)$n['parent_id']) : null; }
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare("SELECT DISTINCT s.* FROM fs_shares s JOIN fs_share_items i ON i.share_id = s.id WHERE s.owner_id = ? AND s.kind = 'link' AND s.revoked_at IS NULL AND i.node_id IN ({$in}) ORDER BY s.id DESC");
        $q->execute([$owner, ...$ids]);
        return array_values(array_filter(array_map([self::class, 'decorate'], $q->fetchAll()), fn($d) => in_array($d['state'], ['open', 'not_yet'], true)));
    }

    // ---- passwords and access --------------------------------------------------------------------------------------------

    public static function checkPassword(array $s, string $pw): bool
    {
        return !empty($s['password_hash']) && $pw !== '' && password_verify($pw, (string)$s['password_hash']);
    }

    public static function unlockValue(array $s, int $exp): string
    {
        return $exp . '.' . self::b64(hash_hmac('sha256', "unlock|{$s['id']}|{$s['salt']}|{$exp}", self::key(), true));
    }

    public static function isUnlocked(array $s, ?string $cookie): bool
    {
        if (empty($s['password_hash'])) return true;
        if (!$cookie || !preg_match('/^(\d{9,11})\.([A-Za-z0-9_-]{43})$/', $cookie, $m) || (int)$m[1] < time()) return false;
        return hash_equals(self::unlockValue($s, (int)$m[1]), $cookie);
    }

    public static function failuresSince(int $shareId, int $seconds = 600): int
    {
        $q = db()->prepare("SELECT COUNT(*) FROM fs_access WHERE share_id = ? AND event = 'password_fail' AND at > ?");
        $q->execute([$shareId, time() - $seconds]);
        return (int)$q->fetchColumn();
    }

    public static function log(int $shareId, string $event, string $detail = '', ?string $ip = null): void
    {
        db()->prepare('INSERT INTO fs_access (share_id, at, event, detail, ip_hash) VALUES (?,?,?,?,?)')->execute([$shareId, time(), $event, mb_substr($detail, 0, 200), $ip ? hash('sha256', $ip) : null]);
        $bump = in_array($event, ['download', 'zip'], true) ? ', downloads = downloads + 1' : '';
        db()->prepare("UPDATE fs_shares SET last_access = ?{$bump} WHERE id = ?")->execute([time(), $shareId]);
    }
}

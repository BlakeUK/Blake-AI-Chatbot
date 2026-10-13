<?php
// src/Chat/Channels.php: staff team chat: one-to-one chats and groups. Every rule lives here so it can be tested without a web server.
//
// Who may do what in a GROUP: the owner (whoever made it) or a staff administrator may add and remove members, rename it, change its
// description and delete it. Any member may leave it and mute it. If the owner leaves, the member who joined earliest becomes the owner; if
// nobody is left the group is deleted. A ONE-TO-ONE chat cannot be changed at all (no members added or removed, no renaming, no deleting).
//
// Who is told: every new message pops up for the other members unless they have muted that chat, but a mention (@name) or a reply to your
// own message always does. Being added to a group, being removed from one, and a group being deleted always pop up. Inside the chat itself
// a short notice records each change ("Pat added Sam", "Sam left", "Pat renamed the group to Parts").

declare(strict_types=1);

namespace Chat;

final class Channels
{
    public const MAX_NAME = 60;
    public const MAX_DESCRIPTION = 300;
    public const MAX_MEMBERS = 100;
    public const MAX_MESSAGE = 4000;

    private static function pdo(): \PDO { return db(); }

    private static function clean(string $s, int $max): string
    {
        return mb_substr(trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/u', '', preg_replace('/\s+/u', ' ', $s) ?? '')), 0, $max);
    }

    public static function username(int $adminId): string
    {
        $q = self::pdo()->prepare('SELECT username FROM admin_users WHERE id = ?'); $q->execute([$adminId]);
        return (string)($q->fetchColumn() ?: 'Someone');
    }

    private static function role(int $adminId): string
    {
        $q = self::pdo()->prepare('SELECT role FROM admin_users WHERE id = ?'); $q->execute([$adminId]);
        return (string)$q->fetchColumn();
    }

    public static function isMember(int $channelId, int $adminId): bool
    {
        $q = self::pdo()->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND admin_id = ?'); $q->execute([$channelId, $adminId]);
        return (bool)$q->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public static function get(int $channelId): ?array
    {
        $q = self::pdo()->prepare('SELECT * FROM channels WHERE id = ?'); $q->execute([$channelId]);
        return $q->fetch() ?: null;
    }

    /** May this person manage the group: its owner, or a staff administrator. Never a one-to-one chat. */
    public static function canManage(array $ch, int $adminId): bool
    {
        return !(int)$ch['is_dm'] && ((int)$ch['created_by'] === $adminId || self::role($adminId) === 'admin');
    }

    private static function group(int $channelId, int $actor, bool $needManage): array
    {
        $ch = self::get($channelId);
        if (!$ch) throw new \InvalidArgumentException('That chat no longer exists.');
        if (!self::isMember($channelId, $actor) && !($needManage && self::role($actor) === 'admin' && !(int)$ch['is_dm'])) throw new \DomainException('You are not in that chat.');
        if ((int)$ch['is_dm']) throw new \DomainException('A one-to-one chat cannot be changed.');
        if ($needManage && !self::canManage($ch, $actor)) throw new \DomainException('Only the group owner or an administrator can do that.');
        return $ch;
    }

    private static function system(int $channelId, int $actor, string $text): void
    {
        self::pdo()->prepare("INSERT INTO channel_messages (channel_id, admin_id, content, kind) VALUES (?,?,?, 'system')")->execute([$channelId, $actor, $text]);
    }

    private static function event(int $recipient, ?int $channelId, string $kind, string $actor, string $channelName, string $detail = ''): void
    {
        self::pdo()->prepare('INSERT INTO channel_events (recipient_id, channel_id, kind, actor_name, channel_name, detail) VALUES (?,?,?,?,?,?)')->execute([$recipient, $channelId, $kind, $actor, $channelName, $detail]);
    }

    /** Keeps only people who exist, once each. @param list<int|string> $ids @return list<int> */
    private static function validIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return [];
        $q = self::pdo()->prepare('SELECT id FROM admin_users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
        return array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function names(array $ids): string
    {
        $n = array_map([self::class, 'username'], $ids); $last = array_pop($n);
        return $n ? implode(', ', $n) . ' and ' . $last : (string)$last;
    }

    // ---- making, finding and changing chats ----

    /** @param list<int|string> $memberIds */
    public static function create(int $creator, string $name, array $memberIds, string $description = ''): int
    {
        $name = self::clean($name, self::MAX_NAME);
        if ($name === '') throw new \InvalidArgumentException('Give the group a name.');
        $members = array_values(array_unique([...self::validIds($memberIds), $creator]));
        if (count($members) > self::MAX_MEMBERS) throw new \InvalidArgumentException('A group can have at most ' . self::MAX_MEMBERS . ' members.');
        $p = self::pdo(); $p->beginTransaction();
        $p->prepare('INSERT INTO channels (name, is_private, is_dm, created_by, description) VALUES (?, 1, 0, ?, ?)')->execute([$name, $creator, self::clean($description, self::MAX_DESCRIPTION)]);
        $id = (int)$p->lastInsertId(); $ins = $p->prepare('INSERT INTO channel_members (channel_id, admin_id, joined_at) VALUES (?,?,?)');
        foreach ($members as $m) $ins->execute([$id, $m, time()]);
        $me = self::username($creator); $others = array_values(array_diff($members, [$creator]));
        self::system($id, $creator, $me . ' created the group' . ($others ? ' and added ' . self::names($others) : ''));
        foreach ($others as $m) self::event($m, $id, 'added', $me, $name);
        $p->commit();
        return $id;
    }

    /** Finds the one-to-one chat between two people, or starts it. */
    public static function dm(int $me, int $other): int
    {
        if (!self::validIds([$other])) throw new \InvalidArgumentException('Unknown person.');
        $q = self::pdo()->prepare('SELECT c.id FROM channels c JOIN channel_members m1 ON m1.channel_id = c.id AND m1.admin_id = ? JOIN channel_members m2 ON m2.channel_id = c.id AND m2.admin_id = ?
            WHERE c.is_dm = 1 AND (SELECT COUNT(*) FROM channel_members m3 WHERE m3.channel_id = c.id) = ' . ($me === $other ? 1 : 2) . ' LIMIT 1');   // a number written into the query: a bound parameter would be text and never equal a count
        $q->execute([$me, $other]);
        if (($id = $q->fetchColumn()) !== false) return (int)$id;
        $p = self::pdo(); $p->beginTransaction();
        $p->prepare("INSERT INTO channels (name, is_private, is_dm, created_by) VALUES ('', 1, 1, ?)")->execute([$me]); $id = (int)$p->lastInsertId();
        $ins = $p->prepare('INSERT INTO channel_members (channel_id, admin_id, joined_at) VALUES (?,?,?)'); $ins->execute([$id, $me, time()]); if ($other !== $me) $ins->execute([$id, $other, time()]);
        $p->commit();
        return $id;
    }

    /** @param list<int|string> $ids @return list<int> the people who were added */
    public static function addMembers(int $channelId, int $actor, array $ids): array
    {
        $ch = self::group($channelId, $actor, true);
        $current = array_map('intval', self::pdo()->query('SELECT admin_id FROM channel_members WHERE channel_id = ' . (int)$channelId)->fetchAll(\PDO::FETCH_COLUMN));
        $new = array_values(array_diff(self::validIds($ids), $current));
        if (!$new) return [];
        if (count($current) + count($new) > self::MAX_MEMBERS) throw new \InvalidArgumentException('A group can have at most ' . self::MAX_MEMBERS . ' members.');
        $p = self::pdo(); $p->beginTransaction(); $ins = $p->prepare('INSERT INTO channel_members (channel_id, admin_id, joined_at, last_read_id) VALUES (?,?,?,?)'); $me = self::username($actor);
        $upTo = (int)$p->query('SELECT COALESCE(MAX(id), 0) FROM channel_messages WHERE channel_id = ' . (int)$channelId)->fetchColumn();   // what was said before they joined is history, not news
        foreach ($new as $m) { $ins->execute([$channelId, $m, time(), $upTo]); self::event($m, $channelId, 'added', $me, (string)$ch['name']); }
        self::system($channelId, $actor, $me . ' added ' . self::names($new));
        $p->commit();
        return $new;
    }

    public static function removeMember(int $channelId, int $actor, int $target): void
    {
        if ($actor === $target) { self::leave($channelId, $actor); return; }
        $ch = self::group($channelId, $actor, true);
        if (!self::isMember($channelId, $target)) throw new \InvalidArgumentException('That person is not in the group.');
        if ((int)$ch['created_by'] === $target) throw new \DomainException('The owner cannot be removed. They can leave, which passes the group to someone else.');
        $p = self::pdo(); $p->beginTransaction(); $me = self::username($actor);
        $p->prepare('DELETE FROM channel_members WHERE channel_id = ? AND admin_id = ?')->execute([$channelId, $target]);
        self::system($channelId, $actor, $me . ' removed ' . self::username($target));
        self::event($target, $channelId, 'removed', $me, (string)$ch['name']);
        $p->commit();
    }

    public static function leave(int $channelId, int $me): void
    {
        $ch = self::group($channelId, $me, false);
        if (!self::isMember($channelId, $me)) throw new \DomainException('You are not in that chat.');
        $p = self::pdo(); $name = self::username($me);
        $rest = $p->prepare('SELECT admin_id FROM channel_members WHERE channel_id = ? AND admin_id != ? ORDER BY joined_at, admin_id'); $rest->execute([$channelId, $me]);
        $rest = array_map('intval', $rest->fetchAll(\PDO::FETCH_COLUMN));
        if (!$rest) { self::wipe($channelId); return; }
        $p->beginTransaction();
        $p->prepare('DELETE FROM channel_members WHERE channel_id = ? AND admin_id = ?')->execute([$channelId, $me]);
        $text = $name . ' left';
        if ((int)$ch['created_by'] === $me) { $p->prepare('UPDATE channels SET created_by = ? WHERE id = ?')->execute([$rest[0], $channelId]); $text .= '. ' . self::username($rest[0]) . ' is now the owner'; }
        self::system($channelId, $me, $text);
        $p->commit();
    }

    public static function rename(int $channelId, int $actor, string $name): void
    {
        $ch = self::group($channelId, $actor, true); $name = self::clean($name, self::MAX_NAME);
        if ($name === '') throw new \InvalidArgumentException('Give the group a name.');
        if ($name === $ch['name']) return;
        self::pdo()->prepare('UPDATE channels SET name = ? WHERE id = ?')->execute([$name, $channelId]);
        self::system($channelId, $actor, self::username($actor) . ' renamed the group to ' . $name);
    }

    public static function setDescription(int $channelId, int $actor, string $text): void
    {
        self::group($channelId, $actor, true);
        self::pdo()->prepare('UPDATE channels SET description = ? WHERE id = ?')->execute([self::clean($text, self::MAX_DESCRIPTION), $channelId]);
        self::system($channelId, $actor, self::username($actor) . ' changed the group description');
    }

    public static function delete(int $channelId, int $actor): void
    {
        $ch = self::group($channelId, $actor, true); $me = self::username($actor);
        $others = array_map('intval', self::pdo()->query('SELECT admin_id FROM channel_members WHERE channel_id = ' . (int)$channelId . ' AND admin_id != ' . (int)$actor)->fetchAll(\PDO::FETCH_COLUMN));
        self::pdo()->beginTransaction();
        foreach ($others as $m) self::event($m, null, 'deleted', $me, (string)$ch['name']);
        self::wipe($channelId, false);
        self::pdo()->commit();
    }

    private static function wipe(int $channelId, bool $tx = true): void
    {
        $p = self::pdo(); if ($tx) $p->beginTransaction();
        foreach (['channel_messages', 'channel_members'] as $t) $p->prepare("DELETE FROM {$t} WHERE channel_id = ?")->execute([$channelId]);
        $p->prepare('DELETE FROM channels WHERE id = ?')->execute([$channelId]);
        if ($tx) $p->commit();
    }

    public static function mute(int $channelId, int $me, bool $muted): void
    {
        if (!self::isMember($channelId, $me)) throw new \DomainException('You are not in that chat.');
        self::pdo()->prepare('UPDATE channel_members SET muted = ? WHERE channel_id = ? AND admin_id = ?')->execute([$muted ? 1 : 0, $channelId, $me]);
    }

    // ---- messages ----

    public static function post(int $channelId, int $me, string $content, ?int $replyTo = null): int
    {
        if (!self::isMember($channelId, $me)) throw new \DomainException('You are not in that chat.');
        $content = trim(str_replace("\r\n", "\n", $content));
        $content = (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/u', '', $content);
        if ($content === '') throw new \InvalidArgumentException('Write something first.');
        if (mb_strlen($content) > self::MAX_MESSAGE) throw new \InvalidArgumentException('That message is too long (the limit is ' . self::MAX_MESSAGE . ' characters).');
        if ($replyTo !== null) {
            $q = self::pdo()->prepare('SELECT 1 FROM channel_messages WHERE id = ? AND channel_id = ?'); $q->execute([$replyTo, $channelId]);
            if (!$q->fetchColumn()) throw new \InvalidArgumentException('That message is no longer there.');
        }
        self::pdo()->prepare("INSERT INTO channel_messages (channel_id, admin_id, content, reply_to_id, kind) VALUES (?,?,?,?, 'message')")->execute([$channelId, $me, $content, $replyTo]);
        return (int)self::pdo()->lastInsertId();
    }

    public static function markRead(int $channelId, int $me): void
    {
        if (!self::isMember($channelId, $me)) throw new \DomainException('You are not in that chat.');
        self::pdo()->prepare('UPDATE channel_members SET last_read_id = (SELECT COALESCE(MAX(id), 0) FROM channel_messages WHERE channel_id = ?), last_read_at = ? WHERE channel_id = ? AND admin_id = ?')->execute([$channelId, time(), $channelId, $me]);
    }

    // ---- reading ----

    /** My chats, most recently active first, with what the list needs: name, last message, unread count, muted, member count. @return list<array<string,mixed>> */
    public static function listFor(int $me): array
    {
        $q = self::pdo()->prepare("SELECT c.id, c.name, c.is_dm, c.description, c.created_by AS owner_id, c.created_at, cm.muted, cm.last_read_id,
              (SELECT COUNT(*) FROM channel_members x WHERE x.channel_id = c.id) AS member_count,
              (SELECT COUNT(*) FROM channel_messages g WHERE g.channel_id = c.id AND g.id > cm.last_read_id AND g.admin_id != ? AND g.kind = 'message') AS unread_count,
              (SELECT MAX(id) FROM channel_messages z WHERE z.channel_id = c.id) AS last_id
            FROM channels c JOIN channel_members cm ON cm.channel_id = c.id AND cm.admin_id = ?");
        $q->execute([$me, $me]); $rows = $q->fetchAll();
        $lm = self::pdo()->prepare('SELECT m.content, m.kind, m.created_at, m.admin_id, u.username FROM channel_messages m JOIN admin_users u ON u.id = m.admin_id WHERE m.id = ?');
        $other = self::pdo()->prepare('SELECT u.id, u.username FROM channel_members m JOIN admin_users u ON u.id = m.admin_id WHERE m.channel_id = ? AND m.admin_id != ? LIMIT 1');
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['is_dm'] = (int)$r['is_dm']; $r['muted'] = (int)$r['muted']; $r['unread_count'] = (int)$r['unread_count']; $r['member_count'] = (int)$r['member_count']; $r['owner_id'] = (int)$r['owner_id'];
            $r['display_name'] = $r['name']; $r['other_id'] = null;
            if ($r['is_dm']) { $other->execute([$r['id'], $me]); $o = $other->fetch(); $r['display_name'] = $o ? $o['username'] : self::username($me); $r['other_id'] = $o ? (int)$o['id'] : $me; }
            $r['last_message'] = null; $r['last_at'] = (int)$r['created_at'];
            if ($r['last_id']) { $lm->execute([$r['last_id']]); if ($m = $lm->fetch()) { $r['last_message'] = ['text' => mb_substr((string)$m['content'], 0, 140), 'kind' => $m['kind'], 'user' => $m['username'], 'mine' => (int)$m['admin_id'] === $me]; $r['last_at'] = (int)$m['created_at']; } }
        }
        unset($r);
        usort($rows, fn($a, $b) => [$b['last_at'], $b['id']] <=> [$a['last_at'], $a['id']]);
        return $rows;
    }

    /** One chat: who is in it, and its messages (the latest 200, or only those after $after). @return array<string,mixed> */
    public static function detail(int $channelId, int $me, int $after = 0): array
    {
        $ch = self::get($channelId);
        if (!$ch || !self::isMember($channelId, $me)) throw new \DomainException('You are not in that chat.');
        $q = self::pdo()->prepare('SELECT u.id, u.username, m.joined_at, m.last_read_id, m.muted FROM channel_members m JOIN admin_users u ON u.id = m.admin_id WHERE m.channel_id = ? ORDER BY u.username COLLATE NOCASE'); $q->execute([$channelId]);
        $ch['members'] = array_map(fn($r) => ['id' => (int)$r['id'], 'username' => $r['username'], 'joined_at' => (int)$r['joined_at'], 'last_read_id' => (int)$r['last_read_id'], 'is_owner' => (int)$r['id'] === (int)$ch['created_by']], $q->fetchAll());
        $ch['id'] = (int)$ch['id']; $ch['is_dm'] = (int)$ch['is_dm']; $ch['owner_id'] = (int)$ch['created_by']; $ch['can_manage'] = self::canManage($ch, $me);
        $ch['muted'] = (int)self::pdo()->query('SELECT muted FROM channel_members WHERE channel_id = ' . $channelId . ' AND admin_id = ' . $me)->fetchColumn();
        $ch['display_name'] = $ch['name'];
        if ($ch['is_dm']) { $o = array_values(array_filter($ch['members'], fn($m) => $m['id'] !== $me)); $ch['display_name'] = $o ? $o[0]['username'] : self::username($me); $ch['other_id'] = $o ? $o[0]['id'] : $me; }
        $sql = 'SELECT m.id, m.content, m.kind, m.reply_to_id, m.created_at, m.admin_id, u.username, r.content AS reply_text, ru.username AS reply_user
                FROM channel_messages m JOIN admin_users u ON u.id = m.admin_id LEFT JOIN channel_messages r ON r.id = m.reply_to_id LEFT JOIN admin_users ru ON ru.id = r.admin_id WHERE m.channel_id = ?';
        $q = self::pdo()->prepare($after > 0 ? "{$sql} AND m.id > ? ORDER BY m.id ASC LIMIT 500" : "SELECT * FROM ({$sql} ORDER BY m.id DESC LIMIT 200) ORDER BY id ASC");
        $q->execute($after > 0 ? [$channelId, $after] : [$channelId]);
        $ch['messages'] = array_map(fn($m) => ['id' => (int)$m['id'], 'content' => $m['content'], 'kind' => $m['kind'], 'created_at' => (int)$m['created_at'], 'admin_id' => (int)$m['admin_id'], 'username' => $m['username'],
            'reply_to' => $m['reply_to_id'] ? ['id' => (int)$m['reply_to_id'], 'username' => (string)$m['reply_user'], 'text' => mb_substr((string)$m['reply_text'], 0, 120)] : null], $q->fetchAll());
        $ch['max_id'] = (int)self::pdo()->query('SELECT COALESCE(MAX(id), 0) FROM channel_messages WHERE channel_id = ' . $channelId)->fetchColumn();
        unset($ch['created_by']);
        return $ch;
    }

    // ---- being told ----

    /** What this person should be told about since the two watermarks. Every message in a chat they have not muted, a mention or a reply to them even in a muted one, and the membership events. @return array<string,mixed> */
    public static function notifications(int $me, int $sinceMessage, int $sinceEvent, bool $baseline = false): array
    {
        $p = self::pdo(); $myName = self::username($me);
        if ($baseline) {       // the first look of a session only sets the starting point: nothing old is replayed as new (asked for explicitly, because 0 is also a real starting point on a system with no messages yet)
            return ['max_id' => (int)$p->query('SELECT COALESCE(MAX(id), 0) FROM channel_messages')->fetchColumn(), 'event_max_id' => (int)$p->query('SELECT COALESCE(MAX(id), 0) FROM channel_events WHERE recipient_id = ' . $me)->fetchColumn(), 'notifications' => [], 'events' => []];
        }
        $q = $p->prepare("SELECT m.id, m.channel_id, m.content, m.reply_to_id, m.created_at, u.username, c.name AS channel_name, c.is_dm, cm.muted
            FROM channel_messages m JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.admin_id = ? JOIN admin_users u ON u.id = m.admin_id JOIN channels c ON c.id = m.channel_id
            WHERE m.id > ? AND m.id > cm.last_read_id AND m.admin_id != ? AND m.kind = 'message' ORDER BY m.id ASC LIMIT 200");
        $q->execute([$me, $sinceMessage, $me]); $rows = $q->fetchAll();
        $maxId = $rows ? max(array_map('intval', array_column($rows, 'id'))) : $sinceMessage;
        $mineIds = [];
        $replyIds = array_values(array_filter(array_map('intval', array_column($rows, 'reply_to_id'))));
        if ($replyIds) { $m = $p->prepare('SELECT id FROM channel_messages WHERE admin_id = ? AND id IN (' . implode(',', array_fill(0, count($replyIds), '?')) . ')'); $m->execute([$me, ...$replyIds]); $mineIds = array_map('intval', $m->fetchAll(\PDO::FETCH_COLUMN)); }
        $out = []; $mention = '/@' . preg_quote($myName, '/') . '(?![A-Za-z0-9_])/i';
        foreach ($rows as $r) {
            $reason = preg_match($mention, (string)$r['content']) ? 'mention' : (($r['reply_to_id'] && in_array((int)$r['reply_to_id'], $mineIds, true)) ? 'reply' : ((int)$r['is_dm'] ? 'dm' : 'message'));
            if ((int)$r['muted'] && in_array($reason, ['dm', 'message'], true)) continue;
            $r['reason'] = $reason; $out[] = $r;
        }
        $e = $p->prepare('SELECT id, channel_id, kind, actor_name, channel_name, detail, created_at FROM channel_events WHERE recipient_id = ? AND id > ? ORDER BY id ASC LIMIT 100'); $e->execute([$me, $sinceEvent]);
        $events = $e->fetchAll();
        $evMax = $events ? max(array_map('intval', array_column($events, 'id'))) : $sinceEvent;
        return ['max_id' => $maxId, 'event_max_id' => $evMax, 'notifications' => $out, 'events' => $events];
    }
}

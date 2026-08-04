<?php
// public/api/admin/channels.php
// GET (list mine, with unread counts) | GET ?id=X (one channel + members + recent messages)
// POST {name, member_ids[]} -> create, creator auto-joined
// PUT {id, add_member_id} | {id, remove_member_id} | {id, mark_read:true}

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();
$me     = (int)$_SESSION['admin_id'];

function _is_member(PDO $pdo, int $channelId, int $adminId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM channel_members WHERE channel_id=? AND admin_id=?');
    $stmt->execute([$channelId, $adminId]);
    return (bool)$stmt->fetch();
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $channelId = (int)$_GET['id'];
        if (!_is_member($pdo, $channelId, $me)) json_err('Not a member of this channel', 403);

        $channel = $pdo->prepare('SELECT * FROM channels WHERE id=?');
        $channel->execute([$channelId]);
        $channel = $channel->fetch();
        if (!$channel) json_err('Channel not found', 404);

        $members = $pdo->prepare('
            SELECT u.id, u.username, cm.joined_at
            FROM channel_members cm JOIN admin_users u ON u.id = cm.admin_id
            WHERE cm.channel_id = ? ORDER BY u.username COLLATE NOCASE
        ');
        $members->execute([$channelId]);
        $channel['members'] = $members->fetchAll();

        if ($channel['is_dm']) {
            $other = array_filter($channel['members'], fn($m) => (int)$m['id'] !== $me);
            $channel['display_name'] = $other ? reset($other)['username'] : $channel['name'];
        } else {
            $channel['display_name'] = $channel['name'];
        }

        $msgs = $pdo->prepare('
            SELECT m.id, m.content, m.reply_to_id, m.created_at, u.username, m.admin_id
            FROM channel_messages m JOIN admin_users u ON u.id = m.admin_id
            WHERE m.channel_id = ? ORDER BY m.created_at ASC LIMIT 200
        ');
        $msgs->execute([$channelId]);
        $channel['messages'] = $msgs->fetchAll();

        json_out($channel);
    }

    $stmt = $pdo->prepare('
        SELECT c.id, c.name, c.is_private, c.is_dm, c.created_at, cm.last_read_at,
               (SELECT COUNT(*) FROM channel_members m2 WHERE m2.channel_id = c.id) AS member_count,
               (SELECT COUNT(*) FROM channel_messages msg WHERE msg.channel_id = c.id AND msg.created_at > cm.last_read_at) AS unread_count,
               (SELECT msg2.content FROM channel_messages msg2 WHERE msg2.channel_id = c.id ORDER BY msg2.created_at DESC LIMIT 1) AS last_message
        FROM channels c
        JOIN channel_members cm ON cm.channel_id = c.id AND cm.admin_id = ?
        ORDER BY (SELECT MAX(created_at) FROM channel_messages m3 WHERE m3.channel_id = c.id) DESC, c.created_at DESC
    ');
    $stmt->execute([$me]);
    $rows = $stmt->fetchAll();

    $dmChannelIds = array_column(array_filter($rows, fn($r) => $r['is_dm']), 'id');
    $otherMemberByChannel = [];
    if ($dmChannelIds) {
        $placeholders = implode(',', array_fill(0, count($dmChannelIds), '?'));
        $stmt2 = $pdo->prepare("
            SELECT cm.channel_id, u.username
            FROM channel_members cm JOIN admin_users u ON u.id = cm.admin_id
            WHERE cm.channel_id IN ($placeholders) AND cm.admin_id != ?
        ");
        $stmt2->execute([...$dmChannelIds, $me]);
        foreach ($stmt2->fetchAll() as $row) {
            $otherMemberByChannel[$row['channel_id']] = $row['username'];
        }
    }
    foreach ($rows as &$r) {
        $r['display_name'] = $r['is_dm'] ? ($otherMemberByChannel[$r['id']] ?? $r['name']) : $r['name'];
    }
    unset($r);

    json_out($rows);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    // Find-or-create a 1:1 DM. Checked first since it's the most specific
    // shape (dm_with alone, no channel_id, no name).
    if (!empty($body['dm_with']) && empty($body['channel_id'])) {
        $otherId = (int)$body['dm_with'];
        $otherExists = $pdo->prepare('SELECT username FROM admin_users WHERE id=?');
        $otherExists->execute([$otherId]);
        $otherUsername = $otherExists->fetchColumn();
        if ($otherUsername === false) json_err('Unknown admin_id');

        // A DM channel is exactly {me, otherId} and nothing else - look for
        // one that already exists before creating a new one, so clicking
        // the same person twice reopens the same conversation rather than
        // spawning duplicates.
        $existing = $pdo->prepare('
            SELECT c.id FROM channels c
            JOIN channel_members m1 ON m1.channel_id = c.id AND m1.admin_id = ?
            JOIN channel_members m2 ON m2.channel_id = c.id AND m2.admin_id = ?
            WHERE c.is_dm = 1
              AND (SELECT COUNT(*) FROM channel_members m3 WHERE m3.channel_id = c.id) = 2
            LIMIT 1
        ');
        $existing->execute([$me, $otherId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            json_out(['ok' => true, 'id' => (int)$existingId, 'display_name' => $otherUsername]);
        }

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO channels (name, is_private, is_dm, created_by) VALUES ('', 1, 1, ?)")->execute([$me]);
        $channelId = $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO channel_members (channel_id, admin_id) VALUES (?,?)');
        $ins->execute([$channelId, $me]);
        if ($otherId !== $me) $ins->execute([$channelId, $otherId]);
        $pdo->commit();

        json_out(['ok' => true, 'id' => $channelId, 'display_name' => $otherUsername]);
    }

    // A channel_id here means "post a message", not "create a channel" -
    // same disambiguation-by-shape approach as elsewhere in this app
    // (ticket_create.php vs tickets.php's note-adding POST).
    if (!empty($body['channel_id'])) {
        $channelId = (int)$body['channel_id'];
        $content   = trim((string)($body['content'] ?? ''));
        if ($content === '') json_err('content required');
        if (!_is_member($pdo, $channelId, $me)) json_err('Not a member of this channel', 403);

        $replyToId = !empty($body['reply_to_id']) ? (int)$body['reply_to_id'] : null;
        if ($replyToId !== null) {
            $chk = $pdo->prepare('SELECT 1 FROM channel_messages WHERE id=? AND channel_id=?');
            $chk->execute([$replyToId, $channelId]);
            if (!$chk->fetch()) json_err('Unknown message to reply to');
        }

        $pdo->prepare('INSERT INTO channel_messages (channel_id, admin_id, content, reply_to_id) VALUES (?,?,?,?)')
            ->execute([$channelId, $me, $content, $replyToId]);

        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }

    // Otherwise: create a channel.
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') json_err('name required');

    $memberIds = array_unique(array_map('intval', is_array($body['member_ids'] ?? null) ? $body['member_ids'] : []));
    $memberIds[] = $me; // creator is always in their own channel
    $memberIds = array_unique($memberIds);

    if (count($memberIds)) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $valid = $pdo->prepare("SELECT id FROM admin_users WHERE id IN ($placeholders)");
        $valid->execute($memberIds);
        $memberIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
    }

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO channels (name, is_private, created_by) VALUES (?,?,?)')
        ->execute([$name, !empty($body['is_private']) ? 1 : 1, $me]);
    $channelId = $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO channel_members (channel_id, admin_id) VALUES (?,?)');
    foreach ($memberIds as $mid) {
        $ins->execute([$channelId, $mid]);
    }
    $pdo->commit();

    json_out(['ok' => true, 'id' => $channelId]);
}

if ($method === 'PUT') {
    $channelId = (int)($body['id'] ?? 0);
    if (!$channelId) json_err('id required');
    if (!_is_member($pdo, $channelId, $me)) json_err('Not a member of this channel', 403);

    if (!empty($body['mark_read'])) {
        $pdo->prepare('UPDATE channel_members SET last_read_at=? WHERE channel_id=? AND admin_id=?')
            ->execute([time(), $channelId, $me]);
        json_out(['ok' => true]);
    }

    if (!empty($body['add_member_id'])) {
        $newId = (int)$body['add_member_id'];
        $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE id=?');
        $exists->execute([$newId]);
        if (!$exists->fetch()) json_err('Unknown admin_id');

        $pdo->prepare('INSERT OR IGNORE INTO channel_members (channel_id, admin_id) VALUES (?,?)')
            ->execute([$channelId, $newId]);
        json_out(['ok' => true]);
    }

    if (!empty($body['remove_member_id'])) {
        $removeId = (int)$body['remove_member_id'];
        $pdo->prepare('DELETE FROM channel_members WHERE channel_id=? AND admin_id=?')
            ->execute([$channelId, $removeId]);
        json_out(['ok' => true]);
    }

    json_err('mark_read, add_member_id, or remove_member_id required');
}

json_err('Method not allowed', 405);

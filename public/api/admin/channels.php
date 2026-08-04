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
        SELECT c.id, c.name, c.is_private, c.created_at, cm.last_read_at,
               (SELECT COUNT(*) FROM channel_members m2 WHERE m2.channel_id = c.id) AS member_count,
               (SELECT COUNT(*) FROM channel_messages msg WHERE msg.channel_id = c.id AND msg.created_at > cm.last_read_at) AS unread_count,
               (SELECT msg2.content FROM channel_messages msg2 WHERE msg2.channel_id = c.id ORDER BY msg2.created_at DESC LIMIT 1) AS last_message
        FROM channels c
        JOIN channel_members cm ON cm.channel_id = c.id AND cm.admin_id = ?
        ORDER BY (SELECT MAX(created_at) FROM channel_messages m3 WHERE m3.channel_id = c.id) DESC, c.created_at DESC
    ');
    $stmt->execute([$me]);
    json_out($stmt->fetchAll());
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
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

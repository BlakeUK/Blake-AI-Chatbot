<?php
// public/api/admin/channel_notify.php — GET ?since=<message_id>
// Returns new messages, across every channel this admin belongs to, that
// either @mention them or reply to a message they sent - not every new
// message in every channel, which would be unread-count territory, not
// notification territory. "since" is a message-id watermark the console
// carries forward itself (same pattern as the reminders notifiedIds set,
// just cursor-based instead of a seen-set, since messages are strictly
// ordered by id).

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$me    = (int)$_SESSION['admin_id'];
$since = (int)($_GET['since'] ?? 0);
$pdo   = db();

$userRow = $pdo->prepare('SELECT username FROM admin_users WHERE id=?');
$userRow->execute([$me]);
$myUsername = $userRow->fetchColumn();

$stmt = $pdo->prepare('
    SELECT m.id, m.channel_id, m.content, m.reply_to_id, m.created_at, u.username, c.name AS channel_name, c.is_dm
    FROM channel_messages m
    JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.admin_id = ?
    JOIN admin_users u ON u.id = m.admin_id
    JOIN channels c ON c.id = m.channel_id
    WHERE m.id > ? AND m.admin_id != ?
    ORDER BY m.id ASC
    LIMIT 200
');
$stmt->execute([$me, $since, $me]);
$candidates = $stmt->fetchAll();

$maxId = $since;
$results = [];
if ($candidates) {
    $maxId = max(array_column($candidates, 'id'));

    // Reply targets: which of these candidates reply to a message *I* sent.
    $replyIds = array_filter(array_column($candidates, 'reply_to_id'));
    $myMessageIds = [];
    if ($replyIds) {
        $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
        $mine = $pdo->prepare("SELECT id FROM channel_messages WHERE id IN ($placeholders) AND admin_id=?");
        $mine->execute([...array_values($replyIds), $me]);
        $myMessageIds = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));
    }

    $mentionPattern = '/@' . preg_quote($myUsername, '/') . '\b/i';
    foreach ($candidates as $c) {
        $isMention = $myUsername && preg_match($mentionPattern, $c['content']);
        $isReply   = $c['reply_to_id'] && in_array((int)$c['reply_to_id'], $myMessageIds, true);
        $isDm      = (bool)$c['is_dm'];
        if ($isDm || $isMention || $isReply) {
            $c['reason'] = $isDm ? 'dm' : ($isMention ? 'mention' : 'reply');
            $results[] = $c;
        }
    }
}

json_out(['max_id' => $maxId, 'notifications' => $results]);

<?php
// public/api/admin/chats.php
// GET (no session_id): recent sessions with message counts
// GET (?session_id=..): full message thread + sources for one session

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pdo       = db();
$sessionId = $_GET['session_id'] ?? '';

if ($sessionId) {
    $exists = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ?');
    $exists->execute([$sessionId]);
    if (!$exists->fetch()) json_err('Session not found', 404);

    $stmt = $pdo->prepare('
        SELECT id, role, content, confidence, escalated, created_at
        FROM chat_messages
        WHERE session_id = ?
        ORDER BY created_at ASC
    ');
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll();

    if ($messages) {
        $ids  = array_column($messages, 'id');
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $srcs = $pdo->prepare("SELECT message_id, url, snippet FROM answer_sources WHERE message_id IN ($in)");
        $srcs->execute($ids);
        $byMessage = [];
        foreach ($srcs->fetchAll() as $s) {
            $byMessage[$s['message_id']][] = ['url' => $s['url'], 'snippet' => $s['snippet']];
        }
        foreach ($messages as &$m) {
            $m['sources'] = $byMessage[$m['id']] ?? [];
        }
        unset($m);
    }

    json_out($messages);
}

$limit = min((int)($_GET['limit'] ?? 50), 200);

$stmt = $pdo->prepare('
    SELECT s.id, s.page_url, s.product_code, s.updated_at,
           COUNT(m.id) AS msg_count
    FROM chat_sessions s
    LEFT JOIN chat_messages m ON m.session_id = s.id
    GROUP BY s.id
    ORDER BY s.updated_at DESC
    LIMIT ?
');
$stmt->execute([$limit]);
json_out($stmt->fetchAll());

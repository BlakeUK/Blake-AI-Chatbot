<?php
// public/api/admin/chats.php — GET recent sessions with message counts

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$limit = min((int)($_GET['limit'] ?? 50), 200);

$stmt = db()->prepare('
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

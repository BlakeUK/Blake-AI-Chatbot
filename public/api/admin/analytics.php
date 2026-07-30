<?php
// public/api/admin/analytics.php — Phase 6 analytics endpoint

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pdo  = db();
$days = (int)($_GET['days'] ?? 30);
$from = time() - ($days * 86400);

$out = [];
$out['sessions']  = (int)$pdo->query('SELECT COUNT(*) FROM chat_sessions')->fetchColumn();
$out['messages']  = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn();
$out['escalated'] = (int)$pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
$out['products']  = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$out['knowledge'] = (int)$pdo->query("SELECT COUNT(*) FROM knowledge_entries WHERE active=1")->fetchColumn();
$out['files']     = (int)$pdo->query("SELECT COUNT(*) FROM knowledge_files WHERE status='indexed'")->fetchColumn();

$answered = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE role='assistant' AND confidence >= ?");
$answered->execute([CFG['escalate_threshold']]);
$total_bot = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='assistant'")->fetchColumn();
$out['answer_rate'] = $total_bot > 0 ? round(((int)$answered->fetchColumn() / $total_bot) * 100, 1) : 0;

$daily = $pdo->prepare("SELECT date(created_at,'unixepoch') AS day, COUNT(*) AS sessions FROM chat_sessions WHERE created_at >= ? GROUP BY day ORDER BY day ASC");
$daily->execute([$from]);
$out['daily_sessions'] = $daily->fetchAll();

$unanswered = $pdo->prepare("
    SELECT m.content, m.confidence, s.page_url, m.created_at
    FROM chat_messages m
    JOIN chat_sessions s ON s.id = m.session_id
    WHERE m.role = 'user'
    AND EXISTS (
        SELECT 1 FROM chat_messages b
        WHERE b.session_id = m.session_id
        AND b.role = 'assistant'
        AND b.confidence < ?
        AND b.created_at > m.created_at
    )
    ORDER BY m.created_at DESC
    LIMIT 50
");
$unanswered->execute([CFG['escalate_threshold']]);
$out['unanswered'] = $unanswered->fetchAll();

$pages = $pdo->prepare("SELECT page_url, COUNT(*) AS sessions FROM chat_sessions WHERE page_url IS NOT NULL AND created_at >= ? GROUP BY page_url ORDER BY sessions DESC LIMIT 10");
$pages->execute([$from]);
$out['top_pages'] = $pages->fetchAll();

$conf = $pdo->query("SELECT AVG(confidence) FROM chat_messages WHERE role='assistant' AND confidence IS NOT NULL")->fetchColumn();
$out['avg_confidence'] = $conf ? round((float)$conf * 100, 1) : null;

json_out($out);

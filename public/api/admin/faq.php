<?php
// public/api/admin/faq.php — manage auto-generated FAQ entries
// GET: list | PUT: edit question/answer | DELETE: remove
// No POST - entries are only ever created by Faq\Builder::capture() from
// real chat exchanges (see public/api/chat/send.php), never hand-added.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('
        SELECT id, question, answer, hit_count, created_at, updated_at
        FROM faq_entries
        ORDER BY hit_count DESC, updated_at DESC
    ')->fetchAll();
    json_out($rows);
}

// Same pattern as keyword_links.php/knowledge.php: read is fine for any
// logged-in role, writes require editor+.
\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'PUT') {
    $id       = (int)($body['id'] ?? 0);
    $question = trim($body['question'] ?? '');
    $answer   = trim($body['answer'] ?? '');
    if (!$id) json_err('id required');
    if (!$question) json_err('question required');
    if (!$answer) json_err('answer required');

    // Recompute question_norm from the edited text so a future customer
    // asking this same (admin-worded) question still matches this entry
    // instead of spawning a near-duplicate - see Faq\Builder::normalise(),
    // duplicated here rather than making that method public for one caller.
    $norm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($question)) ?? '') ?? '');
    if ($norm === '') json_err('question must contain some text');

    $pdo->prepare('UPDATE faq_entries SET question=?, question_norm=?, answer=?, updated_at=? WHERE id=?')
        ->execute([$question, $norm, $answer, time(), $id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'faq_edited', (string)$id]);

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $pdo->prepare('DELETE FROM faq_entries WHERE id=?')->execute([$id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'faq_deleted', (string)$id]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/corrections.php
// POST — save a corrected bot answer; optionally promote to knowledge base

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$messageId = (int)($body['message_id'] ?? 0);
$corrected = trim($body['corrected']   ?? '');
$promote   = (bool)($body['promote']   ?? false);

if (!$messageId || !$corrected) {
    json_err('message_id and corrected required');
}

$pdo = db();

// Get original answer
$orig = $pdo->prepare('SELECT content, session_id FROM chat_messages WHERE id=? AND role=?');
$orig->execute([$messageId, 'assistant']);
$row = $orig->fetch();
if (!$row) json_err('Message not found', 404);

// Save correction
$pdo->prepare('
    INSERT INTO answer_corrections (message_id, original, corrected, promoted, admin_id)
    VALUES (?, ?, ?, ?, ?)
')->execute([$messageId, $row['content'], $corrected, (int)$promote, $_SESSION['admin_id']]);

$correctionId = $pdo->lastInsertId();

// If promote = true, add corrected answer as a knowledge entry
if ($promote) {
    // Get the question that triggered this answer
    $qStmt = $pdo->prepare('
        SELECT content FROM chat_messages
        WHERE session_id=? AND role=? AND created_at < (SELECT created_at FROM chat_messages WHERE id=?)
        ORDER BY created_at DESC LIMIT 1
    ');
    $qStmt->execute([$row['session_id'], 'user', $messageId]);
    $question = $qStmt->fetchColumn() ?: 'Customer question';

    $title = 'Corrected: ' . substr($question, 0, 80);

    $pdo->prepare('
        INSERT INTO knowledge_entries (title, body, category, active)
        VALUES (?, ?, ?, 1)
    ')->execute([$title, $corrected, 'Correction']);

    $entryId = $pdo->lastInsertId();

    // Index it
    // FTS index updated automatically by trigger on knowledge_chunks
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text) VALUES (?,?,?)')
        ->execute(['manual', $entryId, $title . ' ' . $corrected]);

    // Mark correction as promoted
    $pdo->prepare('UPDATE answer_corrections SET promoted=1 WHERE id=?')->execute([$correctionId]);
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$_SESSION['admin_id'], 'correction_saved', $messageId]);

json_out(['ok' => true, 'promoted' => $promote]);

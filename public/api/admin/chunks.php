<?php
// public/api/admin/chunks.php
// GET ?file_id=N | GET ?source=manual&source_id=N | PUT | DELETE
// FTS sync handled automatically by DB triggers.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $fileId     = (int)($_GET['file_id']   ?? 0);
    $sourceId   = (int)($_GET['source_id'] ?? 0);
    $sourceType = $_GET['source'] ?? 'file';

    if ($fileId) {
        $stmt = $pdo->prepare('SELECT id, chunk_text, url, created_at FROM knowledge_chunks WHERE source_type=? AND source_id=? ORDER BY id');
        $stmt->execute(['file', $fileId]);
    } elseif ($sourceId) {
        $stmt = $pdo->prepare('SELECT id, chunk_text, url, created_at FROM knowledge_chunks WHERE source_type=? AND source_id=? ORDER BY id');
        $stmt->execute([$sourceType, $sourceId]);
    } else {
        json_err('file_id or source_id required');
    }

    $chunks = $stmt->fetchAll();
    foreach ($chunks as &$c) {
        $c['word_count'] = str_word_count($c['chunk_text']);
    }
    json_out($chunks);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'PUT') {
    $id   = (int)($body['id'] ?? 0);
    $text = trim($body['chunk_text'] ?? '');
    if (!$id || !$text) json_err('id and chunk_text required');

    // UPDATE fires the FTS update trigger automatically
    $pdo->prepare('UPDATE knowledge_chunks SET chunk_text = ? WHERE id = ?')->execute([$text, $id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'chunk_edited', $id]);

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    // DELETE fires the FTS delete trigger automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

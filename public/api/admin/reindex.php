<?php
// public/api/admin/reindex.php
// POST { file_id: N } — re-extract and re-index a previously uploaded file
// FTS sync handled automatically by DB triggers.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$fileId = (int)($body['file_id'] ?? 0);
if (!$fileId) json_err('file_id required');

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM knowledge_files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) json_err('File not found', 404);
if (!file_exists($file['stored_path'])) json_err('Physical file missing from uploads directory', 404);

// Clearing old chunks fires FTS delete triggers automatically
$pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')->execute(['file', $fileId]);

$pdo->prepare('UPDATE knowledge_files SET status=?, error=NULL WHERE id=?')->execute(['pending', $fileId]);

$err = \Knowledge\FileExtractor::extract($fileId, $file['stored_path'], $file['mime_type']);

if ($err) {
    $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')->execute(['error', $err, $fileId]);
    json_out(['ok' => false, 'error' => $err], 207);
}

$count = $pdo->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE source_type=? AND source_id=?');
$count->execute(['file', $fileId]);

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$_SESSION['admin_id'], 'file_reindexed', $fileId]);

json_out(['ok' => true, 'chunks' => (int)$count->fetchColumn()]);

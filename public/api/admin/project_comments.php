<?php
// public/api/admin/project_comments.php — POST: add a comment to a project

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$projectId = (int)($body['project_id'] ?? 0);
$content   = trim((string)($body['content'] ?? ''));
if (!$projectId || $content === '') json_err('project_id and content required');

$pdo = db();
$exists = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
$exists->execute([$projectId]);
if (!$exists->fetch()) json_err('Project not found', 404);

$pdo->prepare('INSERT INTO project_comments (project_id, admin_id, content) VALUES (?,?,?)')
    ->execute([$projectId, $_SESSION['admin_id'], $content]);

$pdo->prepare('UPDATE projects SET updated_at=? WHERE id=?')->execute([time(), $projectId]);

json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

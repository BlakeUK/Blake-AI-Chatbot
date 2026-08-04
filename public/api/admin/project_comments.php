<?php
// public/api/admin/project_comments.php — POST: add a comment to a project

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'POST') {
    \Auth\Admin::requireRole('admin', 'editor');
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');

    $projectId = (int)($body['project_id'] ?? 0);
    $content   = trim((string)($body['content'] ?? ''));
    if (!$projectId || $content === '') json_err('project_id and content required');

    $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
    $exists->execute([$projectId]);
    if (!$exists->fetch()) json_err('Project not found', 404);

    $pdo->prepare('INSERT INTO project_comments (project_id, admin_id, content) VALUES (?,?,?)')
        ->execute([$projectId, $_SESSION['admin_id'], $content]);

    $pdo->prepare('UPDATE projects SET updated_at=? WHERE id=?')->execute([time(), $projectId]);

    json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

if ($method === 'DELETE') {
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');

    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $row = $pdo->prepare('SELECT admin_id, project_id FROM project_comments WHERE id=?');
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row) json_err('Comment not found', 404);

    // Same boundary as reminders: your own comment, or an admin - not any
    // editor, otherwise staff could delete each other's comments freely.
    if ((int)$row['admin_id'] !== (int)$_SESSION['admin_id'] && \Auth\Admin::role() !== 'admin') {
        json_err('Only the comment author or an admin can delete this', 403);
    }

    $pdo->prepare('DELETE FROM project_comments WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE projects SET updated_at=? WHERE id=?')->execute([time(), $row['project_id']]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

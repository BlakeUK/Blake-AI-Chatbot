<?php
// public/api/admin/project_comments.php
// GET ?task_id=X (list comments on one task - the board view's per-item
// Comments tab; project-level comments are still fetched embedded in
// projects.php?id=X rather than through here) | POST (add a comment,
// either project-level or - with task_id - task-level) | DELETE

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) json_err('task_id required');

    $stmt = $pdo->prepare('
        SELECT c.id, c.content, c.created_at, c.admin_id, u.username
        FROM project_comments c JOIN admin_users u ON u.id = c.admin_id
        WHERE c.task_id = ? ORDER BY c.created_at ASC
    ');
    $stmt->execute([$taskId]);
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    \Auth\Admin::requireRole('admin', 'editor');
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');

    $projectId = (int)($body['project_id'] ?? 0);
    $taskId    = !empty($body['task_id']) ? (int)$body['task_id'] : null;
    $content   = trim((string)($body['content'] ?? ''));
    if ($content === '') json_err('content required');

    // A task-level comment only needs task_id from the caller - the
    // project it belongs to is derived from the task itself, so the
    // board view (which knows the task, not necessarily its project id
    // at that point) doesn't need to look that up separately first.
    if ($taskId !== null) {
        $task = $pdo->prepare('SELECT project_id FROM project_tasks WHERE id=?');
        $task->execute([$taskId]);
        $projectId = $task->fetchColumn();
        if ($projectId === false) json_err('Task not found', 404);
    } else {
        if (!$projectId) json_err('project_id or task_id required');
        $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
        $exists->execute([$projectId]);
        if (!$exists->fetch()) json_err('Project not found', 404);
    }

    $pdo->prepare('INSERT INTO project_comments (project_id, task_id, admin_id, content) VALUES (?,?,?,?)')
        ->execute([$projectId, $taskId, $_SESSION['admin_id'], $content]);

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

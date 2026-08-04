<?php
// public/api/admin/tasks.php
// GET ?project_id=X (list tasks for a project) | POST (create) | PUT (update) | DELETE

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function _task_assignees(PDO $pdo, array $taskIds): array
{
    if (!$taskIds) return [];
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("
        SELECT ta.task_id, u.id, u.username
        FROM task_assignees ta JOIN admin_users u ON u.id = ta.admin_id
        WHERE ta.task_id IN ($placeholders)
        ORDER BY u.username COLLATE NOCASE
    ");
    $stmt->execute($taskIds);
    $byTask = [];
    foreach ($stmt->fetchAll() as $row) {
        $byTask[$row['task_id']][] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }
    return $byTask;
}

function _set_task_assignees(PDO $pdo, int $taskId, array $adminIds): void
{
    $adminIds = array_unique(array_map('intval', $adminIds));
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$taskId]);
    if ($adminIds) {
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $valid = $pdo->prepare("SELECT id FROM admin_users WHERE id IN ($placeholders)");
        $valid->execute($adminIds);
        $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT INTO task_assignees (task_id, admin_id) VALUES (?,?)');
        foreach ($validIds as $aid) {
            $ins->execute([$taskId, $aid]);
        }
    }
    $pdo->commit();
}

if ($method === 'GET') {
    if (!empty($_GET['mine'])) {
        $stmt = $pdo->prepare('
            SELECT t.*, p.name AS project_name
            FROM project_tasks t
            JOIN task_assignees ta ON ta.task_id = t.id AND ta.admin_id = ?
            JOIN projects p ON p.id = t.project_id
            WHERE t.completed = 0
            ORDER BY t.due_date IS NULL, t.due_date ASC, t.created_at ASC
            LIMIT ?
        ');
        $stmt->execute([$_SESSION['admin_id'], min((int)($_GET['limit'] ?? 20), 100)]);
        json_out($stmt->fetchAll());
    }

    $projectId = (int)($_GET['project_id'] ?? 0);
    if (!$projectId) json_err('project_id or mine required');

    $stmt = $pdo->prepare('SELECT * FROM project_tasks WHERE project_id=? ORDER BY completed ASC, due_date IS NULL, due_date ASC, created_at ASC');
    $stmt->execute([$projectId]);
    $tasks = $stmt->fetchAll();

    $assigneesByTask = _task_assignees($pdo, array_column($tasks, 'id'));
    foreach ($tasks as &$t) {
        $t['assignees'] = $assigneesByTask[$t['id']] ?? [];
    }
    unset($t);

    json_out($tasks);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $projectId = (int)($body['project_id'] ?? 0);
    $title     = trim((string)($body['title'] ?? ''));
    if (!$projectId || $title === '') json_err('project_id and title required');

    $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
    $exists->execute([$projectId]);
    if (!$exists->fetch()) json_err('Project not found', 404);

    $dueDate = !empty($body['due_date']) ? (int)$body['due_date'] : null;

    $pdo->prepare('
        INSERT INTO project_tasks (project_id, title, description, due_date, created_by)
        VALUES (?,?,?,?,?)
    ')->execute([
        $projectId, $title,
        trim((string)($body['description'] ?? '')) ?: null,
        $dueDate,
        $_SESSION['admin_id'],
    ]);
    $taskId = $pdo->lastInsertId();

    if (!empty($body['assignee_ids']) && is_array($body['assignee_ids'])) {
        _set_task_assignees($pdo, (int)$taskId, $body['assignee_ids']);
    }

    $pdo->prepare('UPDATE projects SET updated_at=? WHERE id=?')->execute([time(), $projectId]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'task_created', $taskId, $title]);

    json_out(['ok' => true, 'id' => $taskId]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $existing = $pdo->prepare('SELECT project_id FROM project_tasks WHERE id=?');
    $existing->execute([$id]);
    $projectId = $existing->fetchColumn();
    if ($projectId === false) json_err('Task not found', 404);

    $fields = []; $params = [];

    if (array_key_exists('title', $body)) {
        $title = trim((string)$body['title']);
        if ($title === '') json_err('title cannot be empty');
        $fields[] = 'title=?'; $params[] = $title;
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description=?'; $params[] = trim((string)$body['description']) ?: null;
    }
    if (array_key_exists('due_date', $body)) {
        $fields[] = 'due_date=?'; $params[] = !empty($body['due_date']) ? (int)$body['due_date'] : null;
    }
    if (array_key_exists('completed', $body)) {
        $completed = !empty($body['completed']);
        $fields[] = 'completed=?'; $params[] = $completed ? 1 : 0;
        $fields[] = 'completed_at=?'; $params[] = $completed ? time() : null;
    }

    if ($fields) {
        $fields[] = 'updated_at=?'; $params[] = time();
        $params[] = $id;
        $pdo->prepare('UPDATE project_tasks SET ' . implode(', ', $fields) . ' WHERE id=?')->execute($params);
    }

    if (array_key_exists('assignee_ids', $body) && is_array($body['assignee_ids'])) {
        _set_task_assignees($pdo, $id, $body['assignee_ids']);
    } elseif (!$fields) {
        json_err('No updatable fields provided');
    }

    $pdo->prepare('UPDATE projects SET updated_at=? WHERE id=?')->execute([time(), $projectId]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $existing = $pdo->prepare('SELECT project_id, title FROM project_tasks WHERE id=?');
    $existing->execute([$id]);
    $row = $existing->fetch();
    if (!$row) json_err('Task not found', 404);

    $pdo->prepare('DELETE FROM project_tasks WHERE id=?')->execute([$id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'task_deleted', $id, $row['title']]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

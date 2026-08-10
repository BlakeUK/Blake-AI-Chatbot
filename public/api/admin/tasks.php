<?php
// public/api/admin/tasks.php
// GET ?project_id=X (list tasks for a project) | GET ?mine=1 (tasks assigned to me)
// POST (create) | PUT (update) | DELETE
//
// A task is a board item: which group it sits in on the table view
// (group_label), a status (to_do|in_progress|done - the Kanban columns),
// a timeline (start_date/due_date), assignees (many-to-many, unchanged),
// a single reviewer, an optional tag, and optional billable hours. See
// scripts/schema_project_board.sql for why these are a fixed shape
// rather than a per-board custom-field system.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

const VALID_STATUSES = ['to_do', 'in_progress', 'done'];

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

// Single reviewer per task, unlike the many-to-many assignees - resolved
// the same way (one lookup for every task in the result set) so an N+1
// query per row doesn't creep in as list sizes grow.
function _task_reviewers(PDO $pdo, array $taskIds): array
{
    if (!$taskIds) return [];
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("
        SELECT t.id AS task_id, u.id, u.username
        FROM project_tasks t JOIN admin_users u ON u.id = t.reviewer_id
        WHERE t.id IN ($placeholders)
    ");
    $stmt->execute($taskIds);
    $byTask = [];
    foreach ($stmt->fetchAll() as $row) {
        $byTask[$row['task_id']] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }
    return $byTask;
}

function _validate_reviewer_id(PDO $pdo, ?int $reviewerId): ?int
{
    if ($reviewerId === null) return null;
    $chk = $pdo->prepare('SELECT 1 FROM admin_users WHERE id=?');
    $chk->execute([$reviewerId]);
    if (!$chk->fetch()) json_err('Unknown reviewer');
    return $reviewerId;
}

function _validate_billable_hours($raw): ?float
{
    if ($raw === null || $raw === '') return null;
    if (!is_numeric($raw) || (float)$raw < 0) json_err('billable_hours must be a non-negative number');
    return (float)$raw;
}

if ($method === 'GET') {
    if (!empty($_GET['mine'])) {
        $stmt = $pdo->prepare('
            SELECT t.*, p.name AS project_name
            FROM project_tasks t
            JOIN task_assignees ta ON ta.task_id = t.id AND ta.admin_id = ?
            JOIN projects p ON p.id = t.project_id
            WHERE t.status != \'done\'
            ORDER BY t.due_date IS NULL, t.due_date ASC, t.created_at ASC
            LIMIT ?
        ');
        $stmt->execute([$_SESSION['admin_id'], min((int)($_GET['limit'] ?? 20), 100)]);
        json_out($stmt->fetchAll());
    }

    $projectId = (int)($_GET['project_id'] ?? 0);
    if (!$projectId) json_err('project_id or mine required');

    $stmt = $pdo->prepare("
        SELECT * FROM project_tasks
        WHERE project_id=?
        ORDER BY status = 'done', due_date IS NULL, due_date ASC, created_at ASC
    ");
    $stmt->execute([$projectId]);
    $tasks = $stmt->fetchAll();

    $taskIds          = array_column($tasks, 'id');
    $assigneesByTask  = _task_assignees($pdo, $taskIds);
    $reviewersByTask  = _task_reviewers($pdo, $taskIds);
    foreach ($tasks as &$t) {
        $t['assignees'] = $assigneesByTask[$t['id']] ?? [];
        $t['reviewer']  = $reviewersByTask[$t['id']] ?? null;
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

    $status = trim((string)($body['status'] ?? 'to_do')) ?: 'to_do';
    if (!in_array($status, VALID_STATUSES, true)) json_err('Invalid status');

    $reviewerId = _validate_reviewer_id($pdo, !empty($body['reviewer_id']) ? (int)$body['reviewer_id'] : null);
    $billableHours = _validate_billable_hours($body['billable_hours'] ?? null);

    $pdo->prepare('
        INSERT INTO project_tasks
            (project_id, title, description, due_date, created_by, group_label, status, start_date, reviewer_id, tag, billable_hours)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $projectId, $title,
        trim((string)($body['description'] ?? '')) ?: null,
        !empty($body['due_date']) ? (int)$body['due_date'] : null,
        $_SESSION['admin_id'],
        trim((string)($body['group_label'] ?? '')) ?: null,
        $status,
        !empty($body['start_date']) ? (int)$body['start_date'] : null,
        $reviewerId,
        trim((string)($body['tag'] ?? '')) ?: null,
        $billableHours,
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
    if (array_key_exists('start_date', $body)) {
        $fields[] = 'start_date=?'; $params[] = !empty($body['start_date']) ? (int)$body['start_date'] : null;
    }
    if (array_key_exists('group_label', $body)) {
        $fields[] = 'group_label=?'; $params[] = trim((string)$body['group_label']) ?: null;
    }
    if (array_key_exists('status', $body)) {
        $status = trim((string)$body['status']);
        if (!in_array($status, VALID_STATUSES, true)) json_err('Invalid status');
        $fields[] = 'status=?'; $params[] = $status;
    }
    if (array_key_exists('reviewer_id', $body)) {
        $reviewerId = _validate_reviewer_id($pdo, !empty($body['reviewer_id']) ? (int)$body['reviewer_id'] : null);
        $fields[] = 'reviewer_id=?'; $params[] = $reviewerId;
    }
    if (array_key_exists('tag', $body)) {
        $fields[] = 'tag=?'; $params[] = trim((string)$body['tag']) ?: null;
    }
    if (array_key_exists('billable_hours', $body)) {
        $fields[] = 'billable_hours=?'; $params[] = _validate_billable_hours($body['billable_hours']);
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

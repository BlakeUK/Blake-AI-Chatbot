<?php
// public/api/admin/projects.php
// GET (list, with ?id= for one project + its tickets) | POST (create) | PUT (update)

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function _project_assignees(PDO $pdo, array $projectIds): array
{
    if (!$projectIds) return [];
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $stmt = $pdo->prepare("
        SELECT pa.project_id, u.id, u.username
        FROM project_assignees pa JOIN admin_users u ON u.id = pa.admin_id
        WHERE pa.project_id IN ($placeholders)
        ORDER BY u.username COLLATE NOCASE
    ");
    $stmt->execute($projectIds);
    $byProject = [];
    foreach ($stmt->fetchAll() as $row) {
        $byProject[$row['project_id']][] = ['id' => (int)$row['id'], 'username' => $row['username']];
    }
    return $byProject;
}

function _set_project_assignees(PDO $pdo, int $projectId, array $adminIds): void
{
    $adminIds = array_unique(array_map('intval', $adminIds));
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM project_assignees WHERE project_id=?')->execute([$projectId]);
    if ($adminIds) {
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $valid = $pdo->prepare("SELECT id FROM admin_users WHERE id IN ($placeholders)");
        $valid->execute($adminIds);
        $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT INTO project_assignees (project_id, admin_id) VALUES (?,?)');
        foreach ($validIds as $aid) {
            $ins->execute([$projectId, $aid]);
        }
    }
    $pdo->commit();
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare('
            SELECT p.*, u.username AS created_by_username, parent.name AS parent_name
            FROM projects p
            LEFT JOIN admin_users u ON u.id = p.created_by
            LEFT JOIN projects parent ON parent.id = p.parent_id
            WHERE p.id = ?
        ');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) json_err('Project not found', 404);

        $tix = $pdo->prepare('SELECT id, subject, status, priority FROM support_tickets WHERE project_id=? ORDER BY created_at DESC');
        $tix->execute([$id]);
        $project['tickets'] = $tix->fetchAll();

        $comments = $pdo->prepare('
            SELECT c.id, c.content, c.created_at, c.admin_id, u.username
            FROM project_comments c JOIN admin_users u ON u.id = c.admin_id
            WHERE c.project_id = ? AND c.task_id IS NULL ORDER BY c.created_at ASC
        ');
        $comments->execute([$id]);
        $project['comments'] = $comments->fetchAll();

        $children = $pdo->prepare('
            SELECT p2.*,
                   (SELECT COUNT(*) FROM project_comments c2 WHERE c2.project_id = p2.id) AS comment_count,
                   (SELECT COUNT(*) FROM support_tickets t2 WHERE t2.project_id = p2.id) AS ticket_count,
                   (SELECT COUNT(*) FROM projects p3 WHERE p3.parent_id = p2.id) AS child_count
            FROM projects p2 WHERE p2.parent_id = ? ORDER BY p2.created_at DESC
        ');
        $children->execute([$id]);
        $project['children'] = $children->fetchAll();

        $project['assignees'] = (_project_assignees($pdo, [$id]))[$id] ?? [];

        json_out($project);
    }

    $whereSql = empty($_GET['all']) ? 'WHERE p.parent_id IS NULL' : '';
    $rows = $pdo->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM project_comments c WHERE c.project_id = p.id) AS comment_count,
               (SELECT COUNT(*) FROM support_tickets t WHERE t.project_id = p.id) AS ticket_count,
               (SELECT COUNT(*) FROM projects p2 WHERE p2.parent_id = p.id) AS child_count,
               (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id AND pt.status != 'done') AS open_task_count
        FROM projects p
        {$whereSql}
        ORDER BY p.status = 'archived', p.created_at DESC
    ")->fetchAll();

    $assigneesByProject = _project_assignees($pdo, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['assignees'] = $assigneesByProject[$r['id']] ?? [];
    }
    unset($r);

    json_out($rows);
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') json_err('name required');

    $department = trim((string)($body['department'] ?? ''));
    if ($department !== '' && !in_array($department, ['sales', 'technical', 'accounts'], true)) json_err('Invalid department');

    $parentId = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
    if ($parentId !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
        $chk->execute([$parentId]);
        if (!$chk->fetch()) json_err('Unknown parent project');
    }

    $dueDate = !empty($body['due_date']) ? (int)$body['due_date'] : null;

    $color = trim((string)($body['color'] ?? ''));
    if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) json_err('Invalid color');

    $pdo->prepare('
        INSERT INTO projects (name, description, department, due_date, created_by, parent_id, color)
        VALUES (?,?,?,?,?,?,?)
    ')->execute([
        $name,
        trim((string)($body['description'] ?? '')) ?: null,
        $department !== '' ? $department : null,
        $dueDate,
        $_SESSION['admin_id'],
        $parentId,
        $color !== '' ? $color : null,
    ]);

    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'project_created', $id, $name]);

    if (!empty($body['assignee_ids']) && is_array($body['assignee_ids'])) {
        _set_project_assignees($pdo, (int)$id, $body['assignee_ids']);
    }

    json_out(['ok' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
    $exists->execute([$id]);
    if (!$exists->fetch()) json_err('Project not found', 404);

    $fields = []; $params = [];

    if (array_key_exists('name', $body)) {
        $name = trim((string)$body['name']);
        if ($name === '') json_err('name cannot be empty');
        $fields[] = 'name=?'; $params[] = $name;
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description=?'; $params[] = trim((string)$body['description']) ?: null;
    }
    if (array_key_exists('department', $body)) {
        $department = trim((string)$body['department']);
        if ($department !== '' && !in_array($department, ['sales', 'technical', 'accounts'], true)) json_err('Invalid department');
        $fields[] = 'department=?'; $params[] = $department !== '' ? $department : null;
    }
    if (array_key_exists('status', $body)) {
        $status = trim((string)$body['status']);
        if (!in_array($status, ['active', 'on_hold', 'completed', 'archived'], true)) json_err('Invalid status');
        $fields[] = 'status=?'; $params[] = $status;
    }
    if (array_key_exists('progress_pct', $body)) {
        $pct = (int)$body['progress_pct'];
        if ($pct < 0 || $pct > 100) json_err('progress_pct must be 0-100');
        $fields[] = 'progress_pct=?'; $params[] = $pct;
    }
    if (array_key_exists('due_date', $body)) {
        $fields[] = 'due_date=?'; $params[] = !empty($body['due_date']) ? (int)$body['due_date'] : null;
    }
    if (array_key_exists('color', $body)) {
        $color = trim((string)$body['color']);
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) json_err('Invalid color');
        $fields[] = 'color=?'; $params[] = $color !== '' ? $color : null;
    }

    if (!$fields && !array_key_exists('assignee_ids', $body)) json_err('No updatable fields provided');

    if ($fields) {
        $fields[] = 'updated_at=?'; $params[] = time();
        $params[] = $id;
        $pdo->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id=?')->execute($params);

        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'project_updated', $id, implode(',', array_keys($body))]);
    }

    if (array_key_exists('assignee_ids', $body) && is_array($body['assignee_ids'])) {
        _set_project_assignees($pdo, $id, $body['assignee_ids']);
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'project_assignees_updated', $id, count($body['assignee_ids']) . ' assignee(s)']);
    }

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $exists = $pdo->prepare('SELECT name FROM projects WHERE id=?');
    $exists->execute([$id]);
    $name = $exists->fetchColumn();
    if ($name === false) json_err('Project not found', 404);

    // Deleting a project never destroys real data it happens to reference -
    // tickets are real customer interactions and sub-projects are real
    // workspaces in their own right. Both get unlinked/promoted, not
    // cascade-deleted; only the project row itself, and its own comments
    // (which exist only in the context of this project), actually go away.
    $unlinkTickets = $pdo->prepare('UPDATE support_tickets SET project_id=NULL, updated_at=? WHERE project_id=?');
    $unlinkTickets->execute([time(), $id]);
    $ticketsUnlinked = $unlinkTickets->rowCount();

    $promoteChildren = $pdo->prepare('UPDATE projects SET parent_id=NULL, updated_at=? WHERE parent_id=?');
    $promoteChildren->execute([time(), $id]);
    $childrenPromoted = $promoteChildren->rowCount();

    $taskCount = $pdo->prepare('SELECT COUNT(*) FROM project_tasks WHERE project_id=?');
    $taskCount->execute([$id]);
    $tasksRemoved = (int)$taskCount->fetchColumn();

    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'project_deleted', $id, "$name ($ticketsUnlinked tickets unlinked, $childrenPromoted sub-projects promoted, $tasksRemoved tasks removed)"]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

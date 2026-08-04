<?php
// public/api/admin/projects.php
// GET (list, with ?id= for one project + its tickets) | POST (create) | PUT (update)

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare('
            SELECT p.*, u.username AS created_by_username
            FROM projects p
            LEFT JOIN admin_users u ON u.id = p.created_by
            WHERE p.id = ?
        ');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) json_err('Project not found', 404);

        $tix = $pdo->prepare('SELECT id, subject, status, priority FROM support_tickets WHERE project_id=? ORDER BY created_at DESC');
        $tix->execute([$id]);
        $project['tickets'] = $tix->fetchAll();

        $comments = $pdo->prepare('
            SELECT c.id, c.content, c.created_at, u.username
            FROM project_comments c JOIN admin_users u ON u.id = c.admin_id
            WHERE c.project_id = ? ORDER BY c.created_at ASC
        ');
        $comments->execute([$id]);
        $project['comments'] = $comments->fetchAll();

        json_out($project);
    }

    $rows = $pdo->query('
        SELECT p.*,
               (SELECT COUNT(*) FROM project_comments c WHERE c.project_id = p.id) AS comment_count,
               (SELECT COUNT(*) FROM support_tickets t WHERE t.project_id = p.id) AS ticket_count
        FROM projects p
        ORDER BY p.status = "archived", p.created_at DESC
    ')->fetchAll();
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

    $dueDate = !empty($body['due_date']) ? (int)$body['due_date'] : null;

    $pdo->prepare('
        INSERT INTO projects (name, description, department, due_date, created_by)
        VALUES (?,?,?,?,?)
    ')->execute([
        $name,
        trim((string)($body['description'] ?? '')) ?: null,
        $department !== '' ? $department : null,
        $dueDate,
        $_SESSION['admin_id'],
    ]);

    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'project_created', $id, $name]);

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

    if (!$fields) json_err('No updatable fields provided');

    $fields[] = 'updated_at=?'; $params[] = time();
    $params[] = $id;
    $pdo->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id=?')->execute($params);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'project_updated', $id, implode(',', array_keys($body))]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

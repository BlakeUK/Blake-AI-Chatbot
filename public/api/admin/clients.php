<?php
// public/api/admin/clients.php — manage external widget API clients
// GET: list | POST: create | PUT: update | DELETE: revoke

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('
        SELECT c.id, c.name, c.api_key, c.allowed_ips, c.allowed_origins,
               c.active, c.created_at,
               COUNT(l.id) AS total_requests,
               MAX(l.created_at) AS last_seen
        FROM widget_clients c
        LEFT JOIN widget_access_log l ON l.client_id = c.id
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ')->fetchAll();
    // Never expose raw api_key in list — show masked version
    foreach ($rows as &$r) {
        $r['api_key_masked'] = substr($r['api_key'], 0, 8) . '••••••••••••••••' . substr($r['api_key'], -4);
        unset($r['api_key']);
    }
    json_out($rows);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $name = trim($body['name'] ?? '');
    if (!$name) json_err('name required');

    $ips     = _normalise_list($body['allowed_ips'] ?? '');
    $origins = _normalise_list($body['allowed_origins'] ?? '');
    $key     = 'buk_' . bin2hex(random_bytes(20)); // 44-char key

    $pdo->prepare('
        INSERT INTO widget_clients (name, api_key, allowed_ips, allowed_origins, active)
        VALUES (?, ?, ?, ?, 1)
    ')->execute([$name, $key, json_encode($ips), json_encode($origins)]);

    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'widget_client_created', $name, $id]);

    // Return full key ONCE at creation
    json_out(['id' => $id, 'api_key' => $key, 'name' => $name], 201);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $ips     = _normalise_list($body['allowed_ips'] ?? '');
    $origins = _normalise_list($body['allowed_origins'] ?? '');

    $pdo->prepare('
        UPDATE widget_clients
        SET name=?, allowed_ips=?, allowed_origins=?, active=?, updated_at=?
        WHERE id=?
    ')->execute([
        trim($body['name'] ?? ''),
        json_encode($ips),
        json_encode($origins),
        (int)($body['active'] ?? 1),
        time(), $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    $pdo->prepare('UPDATE widget_clients SET active=0, updated_at=? WHERE id=?')->execute([time(), $id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], 'widget_client_revoked', $id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

function _normalise_list(string|array $input): array
{
    if (is_array($input)) return array_filter(array_map('trim', $input));
    return array_filter(array_map('trim', preg_split('/[\s,\n]+/', $input)));
}

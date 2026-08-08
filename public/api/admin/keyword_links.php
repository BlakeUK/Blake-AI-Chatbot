<?php
// public/api/admin/keyword_links.php — manage keyword/phrase -> page pins
// GET: list | POST: create | PUT: update | DELETE: remove

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT id, keywords, title, url, active, created_at, updated_at FROM keyword_links ORDER BY title')->fetchAll();
    json_out($rows);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $title    = trim($body['title'] ?? '');
    $url      = trim($body['url'] ?? '');
    $keywords = _normalise_list($body['keywords'] ?? '');
    if (!$title) json_err('title required');
    if (!$url) json_err('url required');
    if (!$keywords) json_err('at least one keyword required');

    $pdo->prepare('INSERT INTO keyword_links (keywords, title, url, active) VALUES (?, ?, ?, 1)')
        ->execute([json_encode($keywords), $title, $url]);

    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'keyword_link_created', $title]);

    json_out(['id' => $id, 'title' => $title, 'url' => $url, 'keywords' => $keywords], 201);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $keywords = _normalise_list($body['keywords'] ?? '');
    if (!$keywords) json_err('at least one keyword required');

    $pdo->prepare('
        UPDATE keyword_links
        SET keywords=?, title=?, url=?, active=?, updated_at=?
        WHERE id=?
    ')->execute([
        json_encode($keywords),
        trim($body['title'] ?? ''),
        trim($body['url'] ?? ''),
        (int)($body['active'] ?? 1),
        time(), $id,
    ]);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    $pdo->prepare('DELETE FROM keyword_links WHERE id=?')->execute([$id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'keyword_link_deleted', $id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

function _normalise_list(string|array $input): array
{
    if (is_array($input)) return array_values(array_filter(array_map('trim', $input)));
    return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $input))));
}

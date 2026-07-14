<?php
// public/api/admin/knowledge.php — GET/POST/PUT/DELETE
// FTS sync handled automatically by DB triggers (schema_fts_triggers.sql).

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM knowledge_entries ORDER BY updated_at DESC');
    json_out($stmt->fetchAll());
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    if (empty($body['title']) || empty($body['body'])) {
        json_err('title and body required');
    }
    $pdo->prepare('
        INSERT INTO knowledge_entries (title, body, category, product_codes, url, active)
        VALUES (?, ?, ?, ?, ?, 1)
    ')->execute([
        $body['title'],
        $body['body'],
        $body['category']      ?? null,
        $body['product_codes'] ?? null,
        $body['url']           ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Insert chunk — FTS index updated automatically by trigger
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url) VALUES (?,?,?,?)')
        ->execute(['manual', $id, $body['title'] . ' ' . $body['body'], $body['url'] ?? null]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'knowledge_created', $id]);

    json_out(['id' => $id], 201);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $pdo->prepare('
        UPDATE knowledge_entries
        SET title=?, body=?, category=?, product_codes=?, url=?, active=?, updated_at=?
        WHERE id=?
    ')->execute([
        $body['title'], $body['body'], $body['category'] ?? null,
        $body['product_codes'] ?? null, $body['url'] ?? null,
        (int)($body['active'] ?? 1), time(), $id,
    ]);

    // Re-create chunk — DELETE + INSERT both fire FTS triggers automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')
        ->execute(['manual', $id]);
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url) VALUES (?,?,?,?)')
        ->execute(['manual', $id, $body['title'] . ' ' . $body['body'], $body['url'] ?? null]);

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    // Deleting chunks fires the FTS delete trigger automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')->execute(['manual', $id]);
    $pdo->prepare('DELETE FROM knowledge_entries WHERE id=?')->execute([$id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'knowledge_deleted', $id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

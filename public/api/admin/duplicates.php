<?php
// public/api/admin/duplicates.php — GET: list pending near-duplicate
// flags for review | POST: dismiss a flag
//
// Deletion isn't handled here - the admin UI's "Delete" action calls the
// existing files.php/knowledge.php DELETE endpoints directly for
// whichever side is chosen, so there's one delete implementation per
// content type rather than a second copy here.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query("
        SELECT id, source_type, source_id, similar_source_type, similar_source_id, similarity, created_at
        FROM knowledge_duplicate_flags
        WHERE status = 'pending'
        ORDER BY similarity DESC, created_at DESC
    ")->fetchAll();

    foreach ($rows as &$r) {
        $r['source_label']  = _resolve_label($pdo, $r['source_type'], (int)$r['source_id']);
        $r['similar_label'] = _resolve_label($pdo, $r['similar_source_type'], (int)$r['similar_source_id']);
    }
    unset($r);

    // A flag where either side no longer resolves means that item was
    // deleted since being flagged - stale, nothing left to show or act on.
    $rows = array_values(array_filter($rows, fn($r) => $r['source_label'] && $r['similar_label']));

    json_out($rows);
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    $pdo->prepare("UPDATE knowledge_duplicate_flags SET status='dismissed' WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

function _resolve_label(PDO $pdo, string $type, int $id): ?array
{
    if ($type === 'file') {
        $stmt = $pdo->prepare('SELECT filename AS title, NULL AS url FROM knowledge_files WHERE id=?');
    } else {
        $stmt = $pdo->prepare('SELECT title, url FROM knowledge_entries WHERE id=?');
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

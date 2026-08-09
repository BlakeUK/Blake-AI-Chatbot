<?php
// public/api/admin/knowledge.php — GET/POST/PUT/DELETE
// FTS sync handled automatically by DB triggers (schema_fts_triggers.sql).

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $source = $_GET['source'] ?? null;
    if ($source !== null) {
        $stmt = $pdo->prepare('SELECT * FROM knowledge_entries WHERE source = ? ORDER BY updated_at DESC');
        $stmt->execute([$source]);
    } else {
        $stmt = $pdo->query('SELECT * FROM knowledge_entries ORDER BY updated_at DESC');
    }
    json_out($stmt->fetchAll());
}

\Auth\Admin::requireRole('admin', 'editor');
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    if (empty($body['title']) || empty($body['body'])) {
        json_err('title and body required');
    }

    // Exact-duplicate check: identical (title+body) text already active
    // elsewhere is unambiguous, so this is blocked outright rather than
    // flagged - unlike a near-duplicate, there's no judgement call for an
    // admin to make here.
    $hash = \Knowledge\Dedup::hashText($body['title'] . ' ' . $body['body']);
    $dup  = \Knowledge\Dedup::findExactEntryDuplicate($hash);
    if ($dup) {
        json_err("This looks identical to an existing entry: \"{$dup['title']}\" (id {$dup['id']}) — not created.");
    }

    $pdo->prepare('
        INSERT INTO knowledge_entries (title, body, category, product_codes, url, active, source, content_hash)
        VALUES (?, ?, ?, ?, ?, 1, \'manual\', ?)
    ')->execute([
        $body['title'],
        $body['body'],
        $body['category']      ?? null,
        $body['product_codes'] ?? null,
        $body['url']           ?? null,
        $hash,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Insert chunk — FTS index updated automatically by trigger
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url, category) VALUES (?,?,?,?,?)')
        ->execute(['manual', $id, $body['title'] . ' ' . $body['body'], $body['url'] ?? null, $body['category'] ?? null]);

    // Near-duplicate check (flagged for review, never auto-deleted).
    $matches = \Knowledge\Dedup::findNearDuplicates($body['title'] . ' ' . $body['body'], 'manual', $id);
    if ($matches) {
        \Knowledge\Dedup::flag('manual', $id, $matches);
    }

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'knowledge_created', $id]);

    json_out(['id' => $id], 201);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    if (empty($body['title']) || empty($body['body'])) {
        json_err('title and body required');
    }

    $hash = \Knowledge\Dedup::hashText($body['title'] . ' ' . $body['body']);
    $dup  = \Knowledge\Dedup::findExactEntryDuplicate($hash, $id);
    if ($dup) {
        json_err("This looks identical to another existing entry: \"{$dup['title']}\" (id {$dup['id']}) — not saved.");
    }

    $pdo->prepare('
        UPDATE knowledge_entries
        SET title=?, body=?, category=?, product_codes=?, url=?, active=?, updated_at=?, content_hash=?
        WHERE id=?
    ')->execute([
        $body['title'], $body['body'], $body['category'] ?? null,
        $body['product_codes'] ?? null, $body['url'] ?? null,
        (int)($body['active'] ?? 1), time(), $hash, $id,
    ]);

    // Re-create chunk — DELETE + INSERT both fire FTS triggers automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')
        ->execute(['manual', $id]);
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url, category) VALUES (?,?,?,?,?)')
        ->execute(['manual', $id, $body['title'] . ' ' . $body['body'], $body['url'] ?? null, $body['category'] ?? null]);

    $matches = \Knowledge\Dedup::findNearDuplicates($body['title'] . ' ' . $body['body'], 'manual', $id);
    if ($matches) {
        \Knowledge\Dedup::flag('manual', $id, $matches);
    }

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $ids = $body['ids'] ?? null;
    if (is_array($ids)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) json_err('ids required');
        // Sanity cap - this endpoint is meant for reviewed, filtered bulk
        // cleanup (source_type + a preview in the UI), not an unbounded
        // wipe in one request.
        if (count($ids) > 2000) json_err('Too many ids in one request (max 2000) — submit in smaller batches');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM knowledge_chunks WHERE source_type='manual' AND source_id IN ($placeholders)")
            ->execute($ids);
        $pdo->prepare("DELETE FROM knowledge_entries WHERE id IN ($placeholders)")
            ->execute($ids);
        $pdo->prepare("DELETE FROM knowledge_duplicate_flags WHERE (source_type='manual' AND source_id IN ($placeholders)) OR (similar_source_type='manual' AND similar_source_id IN ($placeholders))")
            ->execute(array_merge($ids, $ids));
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'knowledge_bulk_deleted', null, count($ids) . ' entries']);
        json_out(['ok' => true, 'deleted' => count($ids)]);
    }

    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    // Deleting chunks fires the FTS delete trigger automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')->execute(['manual', $id]);
    $pdo->prepare('DELETE FROM knowledge_entries WHERE id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM knowledge_duplicate_flags WHERE (source_type=? AND source_id=?) OR (similar_source_type=? AND similar_source_id=?)')
        ->execute(['manual', $id, 'manual', $id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'knowledge_deleted', $id]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/files.php
// GET: list files | POST: upload | DELETE: remove
// FTS sync handled automatically by DB triggers.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, filename, mime_type, status, error, created_at FROM knowledge_files ORDER BY created_at DESC');
    json_out($stmt->fetchAll());
}

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    \Auth\Admin::verifyCsrf($csrf);

    if (empty($_FILES['file'])) {
        json_err('No file uploaded');
    }

    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_err('Upload failed (error code ' . $f['error'] . ')');
    }

    $mime = mime_content_type($f['tmp_name']);
    $allowed = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain', 'text/csv', 'application/json', 'application/xml', 'text/xml',
        'image/jpeg', 'image/png', 'image/webp',
    ];
    if (!in_array($mime, $allowed, true)) {
        json_err('File type not permitted: ' . $mime);
    }

    if ($f['size'] > 20 * 1024 * 1024) {
        json_err('File exceeds 20 MB limit');
    }

    $ext      = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($f['name'], PATHINFO_EXTENSION));
    $stored   = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
    $destPath = rtrim(CFG['upload_path'], '/') . '/' . $stored;

    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
        json_err('Failed to store file', 500);
    }

    $pdo->prepare('INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES (?,?,?,?)')
        ->execute([$f['name'], $mime, $destPath, 'pending']);
    $fileId = (int)$pdo->lastInsertId();

    $err = \Knowledge\FileExtractor::extract($fileId, $destPath, $mime);
    if ($err) {
        $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')
            ->execute(['error', $err, $fileId]);
        json_out(['id' => $fileId, 'status' => 'error', 'error' => $err], 207);
    }

    json_out(['id' => $fileId, 'status' => 'indexed'], 201);
}

if ($method === 'DELETE') {
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $row = $pdo->prepare('SELECT stored_path FROM knowledge_files WHERE id=?');
    $row->execute([$id]);
    $file = $row->fetch();
    if (!$file) json_err('Not found', 404);

    if (file_exists($file['stored_path'])) {
        unlink($file['stored_path']);
    }

    // Deleting chunks fires FTS delete trigger automatically
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')->execute(['file', $id]);
    $pdo->prepare('DELETE FROM knowledge_files WHERE id=?')->execute([$id]);

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'file_deleted', $id]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

<?php
// public/api/admin/files_retry_failed.php
// POST { csrf } - queue every failed knowledge file for re-extraction in
// the background (scripts/process_pending_files.php, cron every minute).
// Queued rather than run inline so a large batch cannot time out the
// request or burst straight back into Gemini's rate limit.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$pdo    = db();
$result = \Knowledge\FileQueue::requeueFailed($pdo);

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$_SESSION['admin_id'], 'files_retry_failed', (string)$result['queued']]);

json_out(['ok' => true] + $result);

<?php
// public/api/admin/product_page_queue.php — POST: queue selected pages for
// background product extraction (scripts/process_product_pages.php picks
// these up). Requires a confirmed template - queuing without one would
// just sit there with nothing to apply.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$raw  = $body['urls'] ?? '';
$urls = is_array($raw) ? $raw : preg_split('/[\s,]+/', trim((string)$raw));
$urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

if (!$urls) {
    json_err('At least one URL required');
}

const MAX_BATCH = 5000; // this just inserts rows, the expensive part happens in the background - a much higher cap than the synchronous endpoints
if (count($urls) > MAX_BATCH) {
    json_err('Too many URLs in one request (max ' . MAX_BATCH . ') — submit in smaller batches');
}

$templateRow = db()->prepare('SELECT value FROM settings WHERE key=?');
$templateRow->execute(['product_extract_template']);
if (!$templateRow->fetchColumn()) {
    json_err('No confirmed template yet — preview and confirm one first under "Learn from a Product Page"');
}

$pdo = db();
$queued = 0;
foreach ($urls as $url) {
    if (!preg_match('#^https?://#i', $url)) continue;
    // Re-queuing an already-processed URL is fine (e.g. re-running after a
    // page changed) - reset it back to pending rather than skip it.
    $pdo->prepare('
        INSERT INTO product_page_extractions (url, status, extracted_json, error, product_code, processed_at)
        VALUES (?, \'pending\', NULL, NULL, NULL, NULL)
        ON CONFLICT(url) DO UPDATE SET status=\'pending\', error=NULL, processed_at=NULL
    ')->execute([$url]);
    $queued++;
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'product_pages_queued', null, $queued . ' url(s)']);

json_out(['ok' => true, 'queued' => $queued]);

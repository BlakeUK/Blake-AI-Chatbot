<?php
// public/api/admin/product_template.php — GET: current confirmed
// extraction template (if any) | POST: confirm and save one.
//
// Stored in the existing generic settings table rather than a new
// dedicated one - this is a single JSON blob, not a row-per-record thing.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

const SETTING_KEY = 'product_extract_template';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = db()->prepare('SELECT value FROM settings WHERE key=?');
    $row->execute([SETTING_KEY]);
    $val = $row->fetchColumn();
    json_out(['template' => $val ? json_decode($val, true) : null]);
}

\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$shape       = $body['shape'] ?? null;
$exampleUrl  = trim($body['example_url'] ?? '');
$exampleData = $body['example_extracted'] ?? null;

if (!is_array($shape) || !$shape) {
    json_err('Target JSON shape required');
}

$template = json_encode([
    'shape'              => $shape,
    'example_url'        => $exampleUrl ?: null,
    'example_extracted'  => $exampleData,
    'confirmed_at'       => time(),
    'confirmed_by'       => $_SESSION['admin_id'],
], JSON_UNESCAPED_SLASHES);

db()->prepare('
    INSERT INTO settings (key, value, updated_at) VALUES (?, ?, unixepoch())
    ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
')->execute([SETTING_KEY, $template]);

db()->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
    ->execute([$_SESSION['admin_id'], 'product_template_confirmed', $exampleUrl ?: null]);

json_out(['ok' => true]);

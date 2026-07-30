<?php
// public/api/admin/import.php
// POST multipart: upload JSON or XML product feed and import into DB

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
\Auth\Admin::verifyCsrf($csrf);

if (empty($_FILES['feed'])) {
    json_err('No feed file uploaded');
}

$f    = $_FILES['feed'];
$mime = mime_content_type($f['tmp_name']);
$ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

$allowed = ['application/json', 'application/xml', 'text/xml', 'text/plain'];
if (!in_array($mime, $allowed, true) && !in_array($ext, ['json','xml'], true)) {
    json_err('Only JSON or XML feed files accepted');
}

if ($f['size'] > 50 * 1024 * 1024) {
    json_err('Feed file exceeds 50 MB limit');
}

$raw = file_get_contents($f['tmp_name']);
if (!$raw) json_err('Cannot read uploaded file', 500);

// ── Parse ─────────────────────────────────────────────────────────────────────
$products = [];

if ($ext === 'json' || str_contains($mime, 'json')) {
    $data = json_decode($raw, true);
    if (!$data) json_err('Invalid JSON');
    $products = $data['products'] ?? $data;
} else {
    // XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if (!$xml) json_err('Invalid XML');
    $products = \Products\Importer::parseXml($xml);
}

if (empty($products) || !is_array($products)) {
    json_err('No products found in feed');
}

// ── Import ────────────────────────────────────────────────────────────────────
$result = \Products\Importer::import($products);

$pdo = db();
$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'product_import', $f['name'], json_encode($result)]);

json_out($result);

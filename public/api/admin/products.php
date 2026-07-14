<?php
// public/api/admin/products.php — GET: search/list products

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 50), 200);
$pdo   = db();

if ($q) {
    // FTS search
    $clean = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', $q);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    if ($clean) {
        $stmt = $pdo->prepare('
            SELECT p.product_code, p.name, p.category_path, p.price_inc_vat,
                   p.stock_status, p.image_url, p.active
            FROM products_fts
            JOIN products p ON p.id = products_fts.rowid
            WHERE products_fts MATCH ?
            ORDER BY rank
            LIMIT ?
        ');
        $stmt->execute([$clean, $limit]);
    } else {
        $stmt = $pdo->prepare('SELECT product_code, name, category_path, price_inc_vat, stock_status, image_url, active FROM products ORDER BY name LIMIT ?');
        $stmt->execute([$limit]);
    }
} else {
    $stmt = $pdo->prepare('SELECT product_code, name, category_path, price_inc_vat, stock_status, image_url, active FROM products ORDER BY name LIMIT ?');
    $stmt->execute([$limit]);
}

json_out($stmt->fetchAll());

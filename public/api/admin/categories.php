<?php
// public/api/admin/categories.php — GET: distinct category suggestions
//
// Merges categories already used on knowledge content with every segment
// of every product's category_path, so the admin UI can offer autocomplete
// that naturally steers toward strings that actually match the product
// feed's own taxonomy - Search::query()/products() only re-prioritise
// results when a knowledge chunk's category string matches a segment of
// the customer's current product's category_path, so consistent naming
// here is what makes that feature actually fire in practice.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pdo = db();
$set = [];

foreach ($pdo->query("SELECT DISTINCT category FROM knowledge_chunks WHERE category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $set[$c] = true;
}

foreach ($pdo->query('SELECT DISTINCT category_path FROM products WHERE category_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $json) {
    foreach (json_decode($json, true) ?: [] as $segment) {
        $segment = trim((string)$segment);
        if ($segment !== '') $set[$segment] = true;
    }
}

$categories = array_keys($set);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

json_out($categories);

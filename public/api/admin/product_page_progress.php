<?php
// public/api/admin/product_page_progress.php — GET: summary of the
// background product-page extraction queue (counts by status, recent
// errors for review).

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pdo = db();

$counts = ['pending' => 0, 'product' => 0, 'not_a_product' => 0, 'error' => 0];
$stmt = $pdo->query('SELECT status, COUNT(*) as c FROM product_page_extractions GROUP BY status');
foreach ($stmt->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['c'];
    }
}

$errors = $pdo->query('
    SELECT url, error, processed_at
    FROM product_page_extractions
    WHERE status = \'error\'
    ORDER BY processed_at DESC
    LIMIT 50
')->fetchAll();

json_out(['counts' => $counts, 'total' => array_sum($counts), 'recent_errors' => $errors]);

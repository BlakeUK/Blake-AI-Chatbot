<?php
// public/api/admin/product_detail.php — GET: full detail for one product
// (variants, documents, resolved related products) for the admin browser's
// detail view. products.php only returns list-table columns; this is
// everything else staff need to actually verify what an import produced.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    json_err('code required');
}

$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM products WHERE product_code = ?');
$stmt->execute([$code]);
$product = $stmt->fetch();
if (!$product) {
    json_err('Product not found', 404);
}

$variants = $pdo->prepare('SELECT variant_code, attributes, url, price_inc_vat, price_exc_vat FROM product_variants WHERE parent_code = ?');
$variants->execute([$code]);

$documents = $pdo->prepare('SELECT doc_type, title, url FROM product_documents WHERE product_code = ?');
$documents->execute([$code]);

// Resolve related codes to full rows too, not just the bare codes - staff
// verifying an import want to see what's actually linked, same as the bot
// does, not decode JSON themselves. No cap here (unlike the chat context,
// which caps at 3 to keep the Gemini prompt bounded) - this is a review
// screen, show everything that's actually set.
$related_codes = json_decode($product['related_product_codes'] ?? '[]', true) ?: [];
$related       = \Knowledge\Search::byCodes($related_codes, max(count($related_codes), 1));

json_out([
    'product'   => $product,
    'variants'  => $variants->fetchAll(),
    'documents' => $documents->fetchAll(),
    'related'   => $related,
]);

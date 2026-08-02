<?php
// public/api/admin/product_template_preview.php — POST: fetch one example
// product page and extract it against a target JSON shape, for review.
//
// Nothing is saved here - this is the "try it and see" step. The admin
// reviews/corrects the result and only product_template.php's POST
// actually confirms a shape for reuse.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$url   = trim($body['url'] ?? '');
$shape = $body['shape'] ?? null;

if (!preg_match('#^https?://#i', $url)) {
    json_err('Not a valid http(s) URL');
}
if (!is_array($shape) || !$shape) {
    json_err('Target JSON shape required');
}

try {
    $result = \Products\PageExtractor::extract($url, $shape);
} catch (\Throwable $e) {
    json_err($e->getMessage(), 502);
}

// $result is already exactly the shape the frontend expects:
//   ['ok'=>false,'raw'=>...] | ['ok'=>true,'is_product_page'=>false] | ['ok'=>true,'is_product_page'=>true,'extracted'=>...]
// Deliberately no 'error' key added here for the ok:false case - the
// frontend checks that first for hard failures and would otherwise flash a
// generic toast and never reach the raw-response review path.
json_out($result);

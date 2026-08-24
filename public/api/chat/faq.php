<?php
// public/api/chat/faq.php — GET: top auto-generated FAQ entries for the
// widget's quick-question chips. Public/unauthenticated, same CORS posture
// as the rest of public/api/chat/* - the content is already meant to be
// customer-visible.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('faq', 30);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$limit = (int)($_GET['limit'] ?? 6);
$rows  = \Faq\Builder::top($limit);

json_out(array_map(fn($r) => [
    'id'       => (int)$r['id'],
    'question' => $r['question'],
    'answer'   => $r['answer'],
], $rows));

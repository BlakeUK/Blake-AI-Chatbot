<?php
// public/api/chat/leaflet.php?code=BLA-LP20K - generated technical data sheet
// (Products\Leaflet). Public product information only; never prices.
require dirname(__DIR__, 3) . '/src/bootstrap.php';
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/.]{2,40}$/', $code)) { http_response_code(400); exit('Invalid product code'); }
try {
    $r = \Products\Leaflet::generate($code);
} catch (\Throwable $e) {
    error_log('leaflet: ' . $e->getMessage());
    http_response_code(500); exit('The data sheet could not be generated.');
}
if (empty($r['ok'])) { http_response_code(404); exit($r['error']); }
$name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $r['data']['name']) . ' (' . $code . ') technical data sheet.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($r['path']));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($r['path']);

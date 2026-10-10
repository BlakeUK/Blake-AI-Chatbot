<?php
// public/s.php: the page for a shared-files link (https://blakegroup.uk/s.php/<id>/<token>). All the work is in src/Files/SharePage.php.
require dirname(__DIR__) . '/src/bootstrap.php';

rate_limit('share_page', 120);   // per visitor, per minute

$r = \Files\SharePage::handle($_SERVER['PATH_INFO'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST, $_GET, $_COOKIE, $_SERVER['REMOTE_ADDR'] ?? '');
if (isset($r['stream'])) { \Files\SharePage::send($r['stream']); exit; }
http_response_code($r['status']);
foreach ($r['headers'] as $h) header($h);
echo $r['body'];

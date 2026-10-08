<?php
// public/ticket.php: the customer's secure ticket page (https://blakegroup.uk/ticket.php/TCK-1001/<token>).
// All the work is in src/Tickets/CustomerPage.php.
require dirname(__DIR__) . '/src/bootstrap.php';

rate_limit('ticket_page', 60);   // per visitor, per minute

$path = $_SERVER['PATH_INFO'] ?? '';
$self = '/ticket.php' . $path;
$r = \Tickets\CustomerPage::handle($path, $_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST, $self, $_GET);
http_response_code($r['status']);
foreach ($r['headers'] as $h) header($h);
echo $r['body'];

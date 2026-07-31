<?php
// public/api/admin/resolve.php — GET: resolve a hostname's IPv4 address.
// Used by the Widget Clients form to auto-fill "Allowed IPs" when the
// admin types an actual domain name into the client name field.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$domain = trim($_GET['domain'] ?? '');
if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i', $domain)) {
    json_err('Not a valid domain');
}

$records = @dns_get_record($domain, DNS_A);
if (!$records || empty($records[0]['ip'])) {
    json_err('Could not resolve domain', 404);
}

json_out(['ip' => $records[0]['ip']]);

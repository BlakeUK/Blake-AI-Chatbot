<?php
// public/api/admin/qr_sso.php: POST {csrf}. Gives a signed-in staff member a one-time address that opens the QR tool already signed in.
// The program shows it in its centre window; the admin website shows it in the QR tab.

require dirname(__DIR__, 3) . '/src/bootstrap.php';

\Auth\Admin::check();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('Method not allowed', 405);
$b = json_decode((string)file_get_contents('php://input'), true) ?: [];
\Auth\Admin::verifyCsrf((string)($b['csrf'] ?? ''));
rate_limit('qr_sso', 30);

if (\Qr\Sso::qrRole(\Auth\Admin::role()) === null) json_err('Your role cannot use the QR tool. Ask an administrator.', 403);
$code = \Qr\Sso::mint((int)$_SESSION['admin_id']);
header('Cache-Control: no-store');
json_out(['url' => \Qr\Sso::url($code), 'expires_in' => \Qr\Sso::TTL]);

<?php
// public/api/qr_sso_verify.php: called by the QR tool, server to server, to find out who a one-time code belongs to. POST t=<code>.
// Answers {ok:true, username, role} once for a genuine, unused, unexpired code, and a plain 404 for everything else.

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
rate_limit('qr_sso_verify', 120);
$who = \Qr\Sso::redeem((string)($_POST['t'] ?? ''));
if ($who === null) { header('Content-Type: application/json'); http_response_code(404); echo '{"ok":false}'; exit; }
json_out(['ok' => true] + $who);

<?php
// public/api/admin/file_download.php - POST {csrf, id, public (bool), title?, keywords?}
// Marks a knowledge file as a customer download Max can offer.
require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::requireRole('admin', 'editor');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
$b = json_body();
\Auth\Admin::verifyCsrf($b['csrf'] ?? '');
$r = \Knowledge\Downloads::set((int)($b['id'] ?? 0), !empty($b['public']), $b['title'] ?? null, $b['keywords'] ?? null);
if (!$r['ok']) json_err($r['error']);
db()->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], !empty($b['public']) ? 'file_download_on' : 'file_download_off', (string)(int)$b['id']]);
json_out(['ok' => true, 'url' => \Knowledge\Downloads::baseUrl() . '/api/chat/file.php?t=' . $r['token']]);

<?php
// public/api/admin/settings.php — GET/POST for key-value settings (model selection etc.)

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[$r['key']] = $r['value'];
    }
    json_out($out);
}

if ($method === 'POST') {
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');

    $allowed = ['gemini_chat_model', 'gemini_extract_model', 'site_sitemap_urls', 'site_refresh_days'];
    foreach ($allowed as $k) {
        if (isset($body[$k])) {
            $pdo->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
                ->execute([$k, $body[$k], time()]);
        }
    }
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

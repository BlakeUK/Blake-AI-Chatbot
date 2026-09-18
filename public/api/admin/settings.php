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

    $allowed = ['gemini_chat_model', 'gemini_extract_model', 'site_sitemap_urls', 'site_refresh_days', 'voice_enabled', 'tts_model', 'tts_voice', 'tts_style'];
    if (isset($body['tts_voice']) && !in_array($body['tts_voice'], \Speech\Speaker::MALE_VOICES, true)) {
        json_err('Unknown voice');
    }
    if (isset($body['voice_enabled'])) {
        $body['voice_enabled'] = $body['voice_enabled'] ? '1' : '0';
    }
    if (isset($body['tts_style']) && mb_strlen((string)$body['tts_style']) > 600) {
        json_err('Voice style too long (max 600 characters)');
    }
    foreach ($allowed as $k) {
        if (isset($body[$k])) {
            $pdo->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
                ->execute([$k, $body[$k], time()]);
        }
    }
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

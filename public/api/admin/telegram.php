<?php
// public/api/admin/telegram.php — GET: current alert config | POST: save/test/detect_chat_id
//
// Bot token reuses the existing api_keys table (service='telegram') with the
// same AES-256-GCM scheme as apikeys.php - it's a real credential. Chat id
// and the enabled flag go in the generic settings table instead, the same
// pattern product_template.php uses: not secrets, just small config values.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $cfg = \Telegram\Notifier::getConfig();
    json_out([
        'configured'        => $cfg['bot_token'] !== null && $cfg['chat_id'] !== null,
        'has_token'         => $cfg['bot_token'] !== null,
        'chat_id'           => $cfg['chat_id'],
        'chat_id_sales'     => $cfg['dept_chat_ids']['sales'],
        'chat_id_technical' => $cfg['dept_chat_ids']['technical'],
        'chat_id_accounts'  => $cfg['dept_chat_ids']['accounts'],
        'enabled'           => $cfg['enabled'],
    ]);
}

if ($method !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$action = $body['action'] ?? 'save';

// ── Save ──────────────────────────────────────────────────────────────────────
// Bot token is write-only in the UI (password-style input, never pre-filled),
// so a blank submission means "leave it alone", not "clear it" - mirrors how
// saveApiKey() already behaves for Gemini/carrier keys. Chat id IS round-tripped
// to the form on load, so an explicit blank there really does mean "clear it".
if ($action === 'save') {
    $botToken = array_key_exists('bot_token', $body) ? trim((string)$body['bot_token']) : null;
    $chatId   = array_key_exists('chat_id', $body)   ? trim((string)$body['chat_id'])   : null;
    $enabled  = array_key_exists('enabled', $body)   ? (bool)$body['enabled']           : null;

    if ($botToken !== null && $botToken !== '') {
        $encKey = hex2bin(CFG['encrypt_key']);
        $iv     = random_bytes(12);
        $tag    = '';
        $enc    = openssl_encrypt($botToken, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);

        $pdo->prepare('
            INSERT INTO api_keys (service, key_enc, iv, tag, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(service) DO UPDATE SET key_enc=excluded.key_enc, iv=excluded.iv, tag=excluded.tag, updated_at=excluded.updated_at
        ')->execute(['telegram', bin2hex($enc), bin2hex($iv), bin2hex($tag), time()]);
    }

    if ($chatId !== null) {
        $pdo->prepare('
            INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
        ')->execute(['telegram_chat_id', $chatId, time()]);
    }

    // Same "present in body -> set it (blank clears it)" convention as
    // chat_id above, one settings row per department. All optional - a
    // department with nothing saved here just uses the shared chat_id.
    foreach (['sales', 'technical', 'accounts'] as $dept) {
        $field = 'chat_id_' . $dept;
        if (array_key_exists($field, $body)) {
            $value = trim((string)$body[$field]);
            $pdo->prepare('
                INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
                ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
            ')->execute(['telegram_chat_id_' . $dept, $value, time()]);
        }
    }

    if ($enabled !== null) {
        $pdo->prepare('
            INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
        ')->execute(['telegram_alerts_enabled', $enabled ? '1' : '0', time()]);
    }

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'telegram_settings_saved', null]);

    json_out(['ok' => true]);
}

// ── Test ──────────────────────────────────────────────────────────────────────
// Accepts optional overrides so the admin can test a token/chat id they've
// just typed in, before committing to Save - avoids a save-test-resave loop.
if ($action === 'test') {
    $tokenOverride  = !empty($body['bot_token']) ? trim((string)$body['bot_token']) : null;
    $chatIdOverride = !empty($body['chat_id'])   ? trim((string)$body['chat_id'])   : null;

    $result = \Telegram\Notifier::send(
        "✅ Test message from the Blake UK chatbot admin panel.\nIf you can see this, staff alerts are wired up correctly.",
        $tokenOverride,
        $chatIdOverride
    );
    json_out($result);
}

// ── Detect chat ID ────────────────────────────────────────────────────────────
// Telegram has no lookup-by-name API - the only way to find a chat id is to
// have that chat message the bot at least once, then read it back via
// getUpdates. Same override reasoning as test: works with an unsaved token.
if ($action === 'detect_chat_id') {
    $tokenOverride = !empty($body['bot_token']) ? trim((string)$body['bot_token']) : null;
    $chats = \Telegram\Notifier::getRecentChats($tokenOverride);
    json_out(['chats' => $chats]);
}

json_err('Unknown action', 400);

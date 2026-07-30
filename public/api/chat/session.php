<?php
// public/api/chat/session.php — POST: create or resume a chat session

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('chat', CFG['rate_limit_chat']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
$id   = $body['session_id'] ?? null;
$pdo  = db();

// ── External widget token validation ──────────────────────────────────────────
// If a token is supplied (external embed), it must be valid, unused and unexpired.
// Same-origin first-party widget (blake-uk.com) sends no token and is allowed via CORS.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isFirstParty = in_array($origin, CFG['cors_origins'], true) || $origin === '';

if (!empty($body['token'])) {
    $tok = $pdo->prepare('SELECT * FROM widget_tokens WHERE token = ?');
    $tok->execute([$body['token']]);
    $t = $tok->fetch();
    if (!$t || $t['used'] || $t['expires_at'] < time()) {
        json_err('Invalid or expired widget token', 403);
    }
    // Single-use: mark consumed
    $pdo->prepare('UPDATE widget_tokens SET used = 1 WHERE token = ?')->execute([$body['token']]);
} elseif (!$isFirstParty) {
    // No token AND not a recognised first-party origin → reject
    json_err('Widget token required for external origins', 403);
}

// ── Resume existing session ───────────────────────────────────────────────────
if ($id) {
    $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetch()) {
        json_out(['session_id' => $id]);
    }
}

// ── Create new session ────────────────────────────────────────────────────────
$id = bin2hex(random_bytes(16));
$pdo->prepare('
    INSERT INTO chat_sessions (id, page_url, product_code, category, ip_hash)
    VALUES (?, ?, ?, ?, ?)
')->execute([
    $id,
    $body['page_url']     ?? null,
    $body['product_code'] ?? null,
    $body['category']     ?? null,
    hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
]);

json_out(['session_id' => $id]);

<?php
// public/api/chat/send.php — POST: main chat endpoint

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('chat', CFG['rate_limit_chat']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body       = json_body();
$session_id = $body['session_id'] ?? '';
$message    = trim($body['message'] ?? '');

if (!$session_id || !$message) {
    json_err('session_id and message required');
}

$pdo = db();

// Verify session
$sess = $pdo->prepare('SELECT * FROM chat_sessions WHERE id = ?');
$sess->execute([$session_id]);
$session = $sess->fetch();
if (!$session) {
    json_err('Invalid session', 404);
}

// Refresh page context if the widget sent updated values — the customer may
// have navigated to a different product page without starting a new session.
// Only touches fields the caller actually sent, so callers that omit them
// (e.g. the mobile app) don't accidentally wipe out existing context.
$ctx_changed = false;
foreach (['page_url', 'product_code', 'category'] as $f) {
    if (array_key_exists($f, $body) && $body[$f] !== $session[$f]) {
        $session[$f] = $body[$f];
        $ctx_changed = true;
    }
}
if ($ctx_changed) {
    $pdo->prepare('UPDATE chat_sessions SET page_url=?, product_code=?, category=? WHERE id=?')
        ->execute([$session['page_url'], $session['product_code'], $session['category'], $session_id]);
}

// Save user message
$pdo->prepare('INSERT INTO chat_messages (session_id, role, content) VALUES (?, ?, ?)')
    ->execute([$session_id, 'user', $message]);
$user_msg_id = $pdo->lastInsertId();

// ── Tracking intent ────────────────────────────────────────────────────────────
// Short-circuit before calling Gemini — hand off to the tracking form/API instead.
$tracking = \Tracking\Detector::analyse($message);
if ($tracking['is_tracking']) {
    $answer = 'I can help track that. Please confirm your tracking number and delivery postcode below.';
    $pdo->prepare('INSERT INTO chat_messages (session_id, role, content, confidence) VALUES (?, ?, ?, ?)')
        ->execute([$session_id, 'assistant', $answer, 1.0]);
    $pdo->prepare('UPDATE chat_sessions SET updated_at=? WHERE id=?')->execute([time(), $session_id]);

    json_out([
        'answer'      => $answer,
        'escalate'    => false,
        'confidence'  => 1.0,
        'products'    => [],
        'action'      => 'show_tracking_form',
        'tracking_no' => $tracking['tracking_no'],
        'carrier'     => $tracking['carrier'],
    ]);
}

// ── Retrieve context + build prompt ─────────────────────────────────────────
// Shared with tests/eval/run.php via \Chat\Responder so the RAG pipeline
// under test is the exact code path production runs, not a reimplementation.

$ctx = \Chat\Responder::buildContext($message, $session['product_code']);
$knowledge_hits    = $ctx['knowledge_hits'];
$product_hits      = $ctx['product_hits'];
$context_products  = $ctx['context_products'];

$full_prompt = \Chat\Responder::buildPrompt($ctx, $session['product_code'], $session['page_url']);

// Load previous messages (last 6)
$hist = $pdo->prepare('
    SELECT role, content FROM chat_messages
    WHERE session_id = ? AND role IN (\'user\',\'assistant\')
    ORDER BY created_at DESC LIMIT 6
');
$hist->execute([$session_id]);
$history = array_reverse($hist->fetchAll());
// Remove current message from history (it's the last user entry)
$history = array_filter($history, fn($m) => !($m['role'] === 'user' && $m['content'] === $message));

// ── Call Gemini ───────────────────────────────────────────────────────────────

$api_key = getApiKey('gemini');
if (!$api_key) {
    json_err('Gemini API key not configured', 503);
}

$gemini   = new \Gemini\Client($api_key);
$messages = array_values(array_map(
    fn($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'content' => $m['content']],
    $history
));
$messages[] = ['role' => 'user', 'content' => $message];

try {
    $answer = $gemini->chat(CFG['gemini_flash'], $messages, $full_prompt);
} catch (\Throwable $e) {
    error_log('Gemini error: ' . $e->getMessage());
    json_err('AI service unavailable', 503);
}

// ── Confidence heuristic ──────────────────────────────────────────────────────
$confidence = \Chat\Responder::confidence($knowledge_hits, $product_hits);
$escalate   = \Chat\Responder::shouldEscalate($confidence);

// Save assistant message
$pdo->prepare('INSERT INTO chat_messages (session_id, role, content, confidence, escalated) VALUES (?, ?, ?, ?, ?)')
    ->execute([$session_id, 'assistant', $answer, $confidence, (int)$escalate]);
$bot_msg_id = $pdo->lastInsertId();

// Save sources
foreach ($knowledge_hits as $h) {
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url, snippet) VALUES (?,?,?,?,?)')
        ->execute([$bot_msg_id, $h['source_type'], $h['source_id'], $h['url'], substr($h['chunk_text'], 0, 200)]);
}
foreach ($context_products as $p) {
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url) VALUES (?,?,?,?)')
        ->execute([$bot_msg_id, 'product', null, $p['url']]);
}

// Update session timestamp
$pdo->prepare('UPDATE chat_sessions SET updated_at=? WHERE id=?')->execute([time(), $session_id]);

json_out([
    'answer'    => $answer,
    'escalate'  => $escalate,
    'confidence'=> $confidence,
    'products'  => array_map(fn($p) => [
        'code'  => $p['product_code'],
        'name'  => $p['name'],
        'url'   => $p['url'],
        'price' => $p['price_inc_vat'],
        'image' => $p['image_url'],
    ], $context_products),
]);

// ── Helper: decrypt API key from DB ──────────────────────────────────────────
function getApiKey(string $service): ?string
{
    $row = db()->prepare('SELECT key_enc, iv, tag FROM api_keys WHERE service = ?');
    $row->execute([$service]);
    $r = $row->fetch();
    if (!$r) return null;

    $key = hex2bin(CFG['encrypt_key']);
    $dec = openssl_decrypt(hex2bin($r['key_enc']), 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag']));
    return $dec ?: null;
}

<?php
// public/api/chat/send.php — POST: main chat endpoint

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('chat', CFG['rate_limit_chat']);
// Site-wide ceiling on chat messages per minute (all visitors),
// bounding Gemini spend when abuse comes from many IP addresses.
rate_limit('chat_gemini_global', (int)(CFG['rate_limit_chat_global'] ?? 240), true);


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

// The widget's own <input maxlength="500"> (public/widget/chat.js) is a
// client-side convenience only - any direct API call can otherwise submit
// an arbitrarily large message, which gets stored in SQLite and forwarded
// to Gemini on every request. rate_limit() below caps request count per
// minute, not payload size, so this is the only thing bounding that cost.
// Generous relative to the widget's own limit since a pasted order number,
// address, or product description is legitimate and shouldn't get clipped.
const MAX_MESSAGE_LENGTH = 4000;

$body       = json_body();
$session_id = $body['session_id'] ?? '';
$message    = trim($body['message'] ?? '');

if (!$session_id || !$message) {
    json_err('session_id and message required');
}
if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
    json_err('Message too long (max ' . MAX_MESSAGE_LENGTH . ' characters)');
}

$pdo = db();

// Verify session
$sess = $pdo->prepare('SELECT * FROM chat_sessions WHERE id = ?');
$sess->execute([$session_id]);
$session = $sess->fetch();
if (!$session) {
    json_err('Invalid session', 404);
}

// Once a session has asked for (or is in) a live chat, the AI must never
// also answer - the widget switches to live_send.php/live_poll.php once
// it sees this, but this is the actual enforcement, not just a hint.
if (!in_array($session['mode'], ['ai', 'live_ended'], true)) {
    json_out(['error' => 'This chat is live - use live_send.php instead', 'mode' => $session['mode']], 409);
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

// ── Customer asks for a person ─────────────────────────────────────────────────
// Max hands the chat to the right department (or, out of hours / nobody
// online, starts taking ticket details). The widget switches to live mode
// and picks up the notices via live_poll.php.
if (\Chat\Handoff::wantsHuman($message)) {
    $h = \Chat\Handoff::start($session_id, 'customer_request');
    json_out(['answer' => null, 'handoff' => true, 'mode' => $h['mode'] ?? 'ai', 'department' => $h['department'] ?? null, 'escalate' => false, 'products' => []]);
}

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

// Load previous messages (last 6), excluding the one just inserted above.
// Excluded by id rather than by matching role+content against $message -
// the old content-match approach also stripped any earlier message that
// happened to share the same text (e.g. a customer repeating "hi" or
// "thanks" a few turns later silently lost that earlier turn from context).
$hist = $pdo->prepare('
    SELECT role, content FROM chat_messages
    WHERE session_id = ? AND role IN (\'user\',\'assistant\') AND id != ?
    ORDER BY id DESC LIMIT 6
');
$hist->execute([$session_id, $user_msg_id]);
$history = array_reverse($hist->fetchAll());

// Recent customer messages only - lets a bare postcode reply ("S3 9PT")
// be recognised as part of an earlier TV-aerial question.
$recent_text = implode("\n", array_map(fn($m) => $m['content'], array_filter($history, fn($m) => $m['role'] === 'user')));

$ctx = \Chat\Responder::buildContext($message, $session['product_code'], $recent_text);
$knowledge_hits    = $ctx['knowledge_hits'];
$product_hits      = $ctx['product_hits'];
$context_products  = $ctx['context_products'];
$keyword_links     = $ctx['keyword_links'];
$reception         = $ctx['reception'];

$full_prompt = \Chat\Responder::buildPrompt($ctx, $session['product_code'], $session['page_url']);

// ── Call Gemini ───────────────────────────────────────────────────────────────

$api_key = getApiKey('gemini');
if (!$api_key) {
    $pdo->prepare('DELETE FROM chat_messages WHERE id = ?')->execute([$user_msg_id]);
    json_err('Gemini API key not configured', 503);
}

$gemini   = new \Gemini\Client($api_key);
// Customer text reaches the model with emails, phone and card numbers
// masked (stored unmasked for staff). Nothing in answering needs them.
$messages = array_values(array_map(
    fn($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'content' => $m['role'] === 'user' ? \Support\Pii::mask($m['content']) : $m['content']],
    $history
));
$messages[] = ['role' => 'user', 'content' => \Support\Pii::mask($message)];

try {
    $answer = $gemini->chat(\Gemini\Client::getModel('gemini_chat_model', 'gemini_flash'), $messages, $full_prompt);
} catch (\Throwable $e) {
    error_log('Gemini error: ' . $e->getMessage());
    // Drop the unanswered turn so a retry doesn't leave two consecutive
    // user messages in the history sent to Gemini. Best effort: a locked
    // database here must still return the 503 below, not a fatal error.
    try {
        $pdo->prepare('DELETE FROM chat_messages WHERE id = ?')->execute([$user_msg_id]);
    } catch (\Throwable $e2) {
        error_log('send.php: could not remove unanswered turn: ' . $e2->getMessage());
    }
    json_err('AI service unavailable', 503);
}

$answer = \Chat\Responder::sanitiseLinks($answer, $full_prompt);
$answer = \Chat\Responder::verifyBlakeLinks($answer, $full_prompt, $removedLinks);
if ($removedLinks) {
    error_log('send.php: removed unverified Blake UK link(s) from answer: ' . implode(', ', $removedLinks));
}

// ── Confidence heuristic ──────────────────────────────────────────────────────
$smallTalk  = \Chat\Responder::isSmallTalk($message);
$confidence = $smallTalk ? 0.75 : \Chat\Responder::confidence($knowledge_hits, $product_hits, $keyword_links, $reception);
$escalate   = \Chat\Responder::shouldEscalate($confidence);

// Save assistant message
$pdo->prepare('INSERT INTO chat_messages (session_id, role, content, confidence, escalated) VALUES (?, ?, ?, ?, ?)')
    ->execute([$session_id, 'assistant', $answer, $confidence, (int)$escalate]);
$bot_msg_id = $pdo->lastInsertId();

// Auto-build the FAQ list from grounded exchanges only - an escalated or
// low-confidence answer isn't something we want surfacing to other
// customers. See src/Faq/Builder.php for the dedup/matching logic.
if (!$escalate && !$smallTalk) {
    \Faq\Builder::capture($message, $answer, $bot_msg_id);
}

// Save sources
foreach ($knowledge_hits as $h) {
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url, snippet) VALUES (?,?,?,?,?)')
        ->execute([$bot_msg_id, $h['source_type'], $h['source_id'], $h['url'], mb_substr($h['chunk_text'], 0, 200)]);
}
foreach ($context_products as $p) {
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url) VALUES (?,?,?,?)')
        ->execute([$bot_msg_id, 'product', null, $p['url']]);
}
foreach ($keyword_links as $k) {
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url, snippet) VALUES (?,?,?,?,?)')
        ->execute([$bot_msg_id, 'keyword_link', $k['id'], $k['url'], $k['title']]);
}

if (!empty($reception['found']) && !empty($reception['recommendation'])) {
    $t = $reception['recommendation']['transmitter'];
    $pdo->prepare('INSERT INTO answer_sources (message_id, source_type, source_id, url, snippet) VALUES (?,?,?,?,?)')
        ->execute([$bot_msg_id, 'reception', null, \Reception\Advisor::FREEVIEW_CHECKER,
            mb_substr("{$reception['postcode']}: {$t['name']} {$t['distance_km']}km {$t['bearing_deg']}deg {$reception['recommendation']['aerial']['type']}", 0, 200)]);
}

// Update session timestamp
$pdo->prepare('UPDATE chat_sessions SET updated_at=? WHERE id=?')->execute([time(), $session_id]);

// Max couldn't ground an answer: pass the chat to the relevant department
// straight away (the customer still sees the answer above first).
$handoff = null;
if ($escalate) {
    try {
        $handoff = \Chat\Handoff::start($session_id, 'ai_unsure');
    } catch (\Throwable $e) {
        error_log('send.php: handoff failed: ' . $e->getMessage());
    }
}

json_out([
    'answer'          => $answer,
    'message_id'      => $bot_msg_id,
    'escalate'        => $escalate,
    'handoff'         => !empty($handoff['ok']) && ($handoff['mode'] ?? 'ai') !== 'ai',
    'mode'            => $handoff['mode'] ?? 'ai',
    'department'      => $handoff['department'] ?? null,
    'agent_available' => $escalate ? \Chat\LiveChat::isAgentAvailable() : false,
    'confidence'      => $confidence,
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

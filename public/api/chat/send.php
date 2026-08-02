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

// ── Retrieve context ──────────────────────────────────────────────────────────

$knowledge_hits  = \Knowledge\Search::query($message, 5);
$product_hits    = \Knowledge\Search::products($message, 3);
$current_product = $session['product_code'] ? \Knowledge\Search::byCode($session['product_code']) : null;

// The product the customer is currently on gets included in context/cards
// even if their message text doesn't happen to match it via FTS — but it
// does NOT by itself count toward confidence below, since being on a product
// page doesn't mean an unrelated question ("what are your hours?") is
// actually answerable from that product's data.
$context_products = \Knowledge\Search::withCurrentFirst($product_hits, $current_product);

// Cross-sell: if the current product lists related codes, pull them in too
// (also not a confidence signal - same reasoning as above).
$related_codes = [];
$alternative_codes = [];
if ($current_product) {
    $related_codes = json_decode($current_product['related_product_codes'] ?? '[]', true) ?: [];
    $related       = \Knowledge\Search::byCodes($related_codes, 3);
    $context_products = \Knowledge\Search::addRelated($context_products, $related);

    $alternative_codes = json_decode($current_product['alternative_product_codes'] ?? '[]', true) ?: [];
    $alternatives      = \Knowledge\Search::byCodes($alternative_codes, 3);
    $context_products  = \Knowledge\Search::addRelated($context_products, $alternatives);
}

// ── Build Gemini prompt ───────────────────────────────────────────────────────

$context_parts = [];

if ($knowledge_hits) {
    $context_parts[] = "KNOWLEDGE BASE:\n" . implode("\n---\n", array_map(
        fn($h) => $h['chunk_text'] . ($h['url'] ? "\nSource: " . $h['url'] : ''),
        $knowledge_hits
    ));
}

if ($context_products) {
    $context_parts[] = "PRODUCTS:\n" . implode("\n---\n", array_map(
        fn($p) => \Knowledge\Search::formatForPrompt($p, $session['product_code'], $related_codes, $alternative_codes),
        $context_products
    ));
}

$page_ctx = $session['page_url'] ? "Customer is viewing: {$session['page_url']}\n" : '';

$system = <<<PROMPT
You are the Blake UK customer support assistant. Blake UK sells aerials, IRS, CCTV, networking, fibre, satellite and installation products.

RULES:
- Answer ONLY using the context provided below. Do not invent products, prices or specifications.
- Keep answers concise and helpful.
- Always include direct Blake UK URLs when recommending products or support pages.
- Products tagged [Related product] are cross-sell/accessory suggestions for what the customer is viewing — mention one only if it's naturally relevant to their question, don't force it into every reply.
- Products tagged [Alternative product] are substitutes for what the customer is viewing (e.g. if it's out of stock or they want a different spec) — mention one if the customer asks about alternatives, other options, or if the current product is out of stock.
- If you cannot answer from the context, say: "I don't have enough information to answer that. Please contact Blake UK support at https://www.blake-uk.com/support.html"
- Never make up product codes, prices or specifications.

{$page_ctx}
PROMPT;

$context_block = implode("\n\n", $context_parts);
$full_prompt   = $context_block ? $system . "\n\n" . $context_block : $system;

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
// Simple: if context was found, confidence is higher. Deliberately based on
// $product_hits (organic matches for this message), not $context_products
// (which always includes the current product regardless of relevance).
$confidence = count($knowledge_hits) + count($product_hits) > 0 ? 0.75 : 0.3;
$escalate   = $confidence < CFG['escalate_threshold'];

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

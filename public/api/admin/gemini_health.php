<?php
// public/api/admin/gemini_health.php
// GET: makes one minimal, real generateContent call per configured model
// (chat + extraction) to confirm each one actually works right now - not
// just that a model string is saved, but that Gemini accepts it. This is
// the fastest way to tell "model retired" (404), "temporarily overloaded"
// (503), "bad API key", etc. apart from the admin UI, without having to
// wait for a customer chat or a Scan All run to hit the same thing.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$apiKey = \Gemini\Client::getStoredApiKey();
if (!$apiKey) {
    json_out([
        'chat'    => ['ok' => false, 'model' => null, 'error' => 'Gemini API key not configured'],
        'extract' => ['ok' => false, 'model' => null, 'error' => 'Gemini API key not configured'],
    ]);
}

$gemini = new \Gemini\Client($apiKey);

$checkModel = function (string $model) use ($gemini): array {
    $start = microtime(true);
    try {
        $gemini->chat($model, [['role' => 'user', 'content' => 'Reply with just the word: OK']], '');
        return ['ok' => true, 'model' => $model, 'error' => null, 'latency_ms' => (int)((microtime(true) - $start) * 1000)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'model' => $model, 'error' => $e->getMessage(), 'latency_ms' => (int)((microtime(true) - $start) * 1000)];
    }
};

$chatModel    = \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash');
$extractModel = \Gemini\Client::getModel('gemini_extract_model', 'gemini_pro');

// Same model configured for both is the common case - no need to spend a
// second real API call confirming a model we just confirmed a moment ago.
$chatResult    = $checkModel($chatModel);
$extractResult = ($extractModel === $chatModel) ? $chatResult : $checkModel($extractModel);

json_out(['chat' => $chatResult, 'extract' => $extractResult]);

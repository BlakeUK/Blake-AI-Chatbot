<?php
// public/api/admin/product_template_preview.php — POST: fetch one example
// product page and extract it against a target JSON shape, for review.
//
// Nothing is saved here - this is the "try it and see" step. The admin
// reviews/corrects the result and only product_template.php's POST
// actually confirms a shape for reuse.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$url   = trim($body['url'] ?? '');
$shape = $body['shape'] ?? null;

if (!preg_match('#^https?://#i', $url)) {
    json_err('Not a valid http(s) URL');
}
if (!is_array($shape) || !$shape) {
    json_err('Target JSON shape required');
}

$apiKey = \Gemini\Client::getStoredApiKey();
if (!$apiKey) {
    json_err('Gemini API key not configured — set it under API Keys first');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'BlakeUKChatbotImporter/1.0',
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($html === false || $code !== 200) {
    json_err('Could not fetch page: ' . ($err ?: "HTTP {$code}"), 502);
}

$text = \Html\TextCleaner::toReadableText($html);
if (trim($text) === '') {
    json_err('No readable text found on that page');
}

$shapeJson = json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$prompt = <<<PROMPT
You extract structured product data from e-commerce page content.

Below is the target JSON shape - the field names and their expected types,
shown with example/placeholder values. Extract those SAME fields from the
page content that follows, using values actually found on that page.

- If a field genuinely isn't present on the page, use null for it - do not
  guess or invent a value.
- If this page is not a product page at all (e.g. an FAQ, guide, or other
  informational page with no product to describe), respond with exactly:
  {"_not_a_product_page": true}
- Respond with ONLY the JSON object, matching the target shape's fields.
  No markdown code fences, no commentary, no explanation.

TARGET SHAPE:
{$shapeJson}
PROMPT;

$modelRow = db()->prepare('SELECT value FROM settings WHERE key=?');
$modelRow->execute(['gemini_extract_model']);
$model = $modelRow->fetchColumn() ?: CFG['gemini_flash']; // structured field extraction is a short-output task - flash is the sensible default, same setting an admin can already override for document extraction

try {
    $client = new \Gemini\Client($apiKey);
    $raw = $client->extractStructured($model, $text, $prompt);
} catch (\Throwable $e) {
    json_err('Gemini request failed: ' . $e->getMessage(), 502);
}

$cleaned = trim($raw);
$cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
$cleaned = preg_replace('/```\s*$/', '', $cleaned) ?? $cleaned;
$cleaned = trim($cleaned);

$parsed = json_decode($cleaned, true);
if (!is_array($parsed)) {
    // Deliberately no 'error' key here - the frontend checks that first for
    // hard failures (bad URL, fetch timeout, etc.) and would otherwise flash
    // a generic toast and never reach the raw-response review path this is
    // actually meant to show.
    json_out([
        'ok'  => false,
        'raw' => $raw,
    ]);
}

if (!empty($parsed['_not_a_product_page'])) {
    json_out(['ok' => true, 'is_product_page' => false]);
}

json_out(['ok' => true, 'is_product_page' => true, 'extracted' => $parsed]);

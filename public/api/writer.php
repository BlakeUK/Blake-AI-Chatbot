<?php
// public/api/writer.php: the staff writing assistant. POST {text, audience, csrf} -> {improved, level, changes[], warnings[]}
// Any signed-in staff member may use it. The message is not stored or logged; only the usual API usage record
// (model, tokens, cost) is kept, with no message text in it.

require dirname(__DIR__, 2) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
rate_limit('writer', 12);                       // per visitor, per minute: this calls a paid API

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$text     = is_string($body['text'] ?? null) ? $body['text'] : '';
$audience = is_string($body['audience'] ?? null) ? $body['audience'] : '';
if (($problem = \Writer\Editor::validate($text, $audience)) !== null) json_err($problem, 422);   // before any paid call

$key = \Gemini\Client::getStoredApiKey();
if (!$key) json_err('The writing assistant is not set up yet (no Gemini key).', 503);
$client = new \Gemini\Client($key);
$model  = \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash');

try {
    json_out(\Writer\Editor::improve($text, $audience, fn(string $sys, string $usr) => $client->editJson($model, $sys, $usr)));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    error_log('writer: ' . $e->getMessage() . ($e->getPrevious() ? ' / ' . $e->getPrevious()->getMessage() : ''));
    json_err('The writing assistant could not finish that. Please try again in a moment.', 502);
}

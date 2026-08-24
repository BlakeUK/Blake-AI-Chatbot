<?php
// src/Chat/DepartmentClassifier.php
//
// Classifies an escalated conversation into sales / technical / accounts
// for operator-console routing. Unsure - or literally anything going wrong
// (no API key, Gemini error, unparseable reply) - resolves to Sales with
// $confident=false, never left blank: "if unsure, pass on to sales and ask
// for help" means every ticket lands somewhere a human will actually see it.

declare(strict_types=1);

namespace Chat;

class DepartmentClassifier
{
    private const VALID = ['sales', 'technical', 'accounts'];

    private const SYSTEM = <<<PROMPT
You are routing a customer support conversation for Blake UK, a TV aerial, CCTV and networking retailer.

Classify the conversation into exactly ONE of these departments:
- sales: product questions, pricing, availability, what to buy, order placement
- technical: installation help, troubleshooting, RF/signal/wiring questions, "how do I", product not working
- accounts: order status, invoices, payments, refunds, returns, existing-order changes

Reply with ONLY one lowercase word: sales, technical, or accounts.
If the conversation genuinely doesn't fit any of these clearly, reply with exactly: unsure

Do not explain your answer. Do not add punctuation. One word only.
PROMPT;

    // $messages: chat_messages rows (role/content), oldest first.
    // Returns ['department' => 'sales'|'technical'|'accounts', 'confident' => bool].
    public static function classify(array $messages): array
    {
        $fallback = ['department' => 'sales', 'confident' => false];
        if (!$messages) return $fallback;

        $apiKey = \Gemini\Client::getStoredApiKey();
        if (!$apiKey) return $fallback;

        $geminiMessages = array_values(array_map(
            fn($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'content' => $m['content']],
            // Cap context: classification needs the gist, not the whole
            // history, and keeps this a small/cheap call on the escalation
            // path rather than something that can meaningfully slow it down.
            array_slice($messages, -10)
        ));
        if (!$geminiMessages) return $fallback;

        try {
            $gemini = new \Gemini\Client($apiKey);
            $raw    = $gemini->chat(\Gemini\Client::getModel('gemini_chat_model', 'gemini_flash'), $geminiMessages, self::SYSTEM);
        } catch (\Throwable $e) {
            error_log('DepartmentClassifier: Gemini error: ' . $e->getMessage());
            return $fallback;
        }

        $word = strtolower(trim($raw));
        $word = preg_replace('/[^a-z]/', '', $word); // strip stray punctuation/newlines

        if (in_array($word, self::VALID, true)) {
            return ['department' => $word, 'confident' => true];
        }

        return $fallback; // 'unsure', empty, or anything unparseable
    }
}

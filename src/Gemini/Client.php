<?php
// src/Gemini/Client.php

declare(strict_types=1);

namespace Gemini;

class Client
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    // Shared by every call site that needs the stored Gemini key decrypted -
    // previously duplicated in FileExtractor and chat/send.php separately;
    // unified here rather than adding a third copy for this new feature.
    public static function getStoredApiKey(): ?string
    {
        $row = db()->prepare('SELECT key_enc, iv, tag FROM api_keys WHERE service = ?');
        $row->execute(['gemini']);
        $r = $row->fetch();
        if (!$r) return null;

        $key = hex2bin(CFG['encrypt_key']);
        $dec = openssl_decrypt(
            hex2bin($r['key_enc']), 'aes-256-gcm', $key,
            OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag'])
        );
        return $dec ?: null;
    }

    // Resolves which model string to use for a given purpose: the DB
    // settings row (admin-editable via the Model Settings tab) wins when
    // present and non-empty, otherwise falls back to the config.php
    // default. Unifies four previously-separate copies of this same
    // "DB setting, else config fallback" lookup (FileExtractor,
    // PageExtractor, chat/send.php, DepartmentClassifier) - the divergence
    // meant editing a model in the admin UI silently didn't affect chat/
    // classification at all, since those two read CFG directly and never
    // consulted the settings table.
    public static function getModel(string $settingKey, string $cfgFallbackKey): string
    {
        $row = db()->prepare('SELECT value FROM settings WHERE key = ?');
        $row->execute([$settingKey]);
        $value = $row->fetchColumn();
        return ($value !== false && $value !== '') ? $value : CFG[$cfgFallbackKey];
    }

    // ── Chat completion (flash) ───────────────────────────────────────────────

    public function chat(string $model, array $messages, string $system = ''): string
    {
        $contents = [];
        if ($system !== '') {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $system]]];
            $contents[] = ['role' => 'model', 'parts' => [['text' => 'Understood.']]];
        }
        foreach ($messages as $m) {
            $contents[] = ['role' => $m['role'], 'parts' => [['text' => $m['content']]]];
        }

        $body = json_encode([
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => 0.2,
                'maxOutputTokens' => 1024,
            ],
        ]);

        return $this->post("models/{$model}:generateContent", $body);
    }

    // ── File extraction (pro, multimodal) ─────────────────────────────────────

    public function extractFile(string $model, string $base64Data, string $mimeType, string $prompt): string
    {
        $body = json_encode([
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Data]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 4096],
        ]);

        return $this->post("models/{$model}:generateContent", $body);
    }

    // ── Structured extraction from plain text (page content -> target JSON shape) ──

    public function extractStructured(string $model, string $text, string $prompt): string
    {
        $body = json_encode([
            'contents' => [[
                'parts' => [
                    ['text' => $prompt . "\n\n---PAGE CONTENT---\n" . $text],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 2048],
        ]);

        return $this->post("models/{$model}:generateContent", $body);
    }

    // ── Raw POST ──────────────────────────────────────────────────────────────

    private function post(string $path, string $body): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/{$path}?key={$this->apiKey}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            throw new \RuntimeException("Gemini API error {$code}: {$resp}");
        }

        return self::extractText(json_decode($resp, true) ?: []);
    }

    // Pulled out of post() so this parsing/validation can be unit tested
    // without a live API call - the curl round-trip above can't be.
    //
    // A 200 response with no candidate text isn't a "the model said
    // nothing" case - it means the response was blocked (safety filter,
    // recitation, etc.) or came back in a shape this code doesn't
    // recognise. Silently falling back to '' would let chat/send.php store
    // and show that as a real (empty) assistant answer with no error
    // signal anywhere; throwing surfaces it to the caller's existing error
    // handling instead.
    public static function extractText(array $data): string
    {
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null) {
            $reason = $data['promptFeedback']['blockReason']
                ?? $data['candidates'][0]['finishReason']
                ?? 'unknown';
            throw new \RuntimeException("Gemini returned no answer text (reason: {$reason})");
        }
        return $text;
    }
}

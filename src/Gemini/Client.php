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

        $data = json_decode($resp, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}

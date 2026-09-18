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

    // Gemini's thinking-config parameter changed shape between generations,
    // and sending the wrong shape - or any shape at all to a model that
    // doesn't support it - is a hard 400 error, not a silent no-op (widely
    // reported: gemini-cli, Cline, KiloCode, big-AGI all hit this exact
    // failure mode swapping between Gemini 2.5 and 3.x). This is
    // deliberately conservative: only apply an override for model families
    // with confirmed behaviour, and leave everything else (2.5 Pro, which
    // can't disable thinking at all; anything not yet released when this
    // was written) to the API's own default. generateWithThinkingFallback()
    // below retries without this if the heuristic ever guesses wrong.
    private static function thinkingConfigFor(string $model): ?array
    {
        if (str_starts_with($model, 'gemini-3')) {
            // 3.x models use the newer thinkingLevel enum in place of a
            // numeric budget. "minimal" is Google's own recommendation for
            // high-volume extraction, routing, and classification work -
            // which is what both chat (answering only from retrieved
            // knowledge, not open-ended reasoning) and document extraction
            // are here, not multi-step agentic tasks that benefit from
            // deeper thinking.
            return ['thinkingLevel' => 'minimal'];
        }
        if (str_starts_with($model, 'gemini-2.5-flash')) {
            // Covers both 2.5 Flash and 2.5 Flash-Lite, which can disable
            // thinking outright via budget 0. 2.5 Pro deliberately excluded:
            // thinking cannot be turned off for it at all, so there's
            // nothing useful to send.
            return ['thinkingBudget' => 0];
        }
        return null;
    }

    // Shared by chat()/extractFile()/extractStructured(): builds the
    // request with a thinking-config override applied where the model
    // family is known to support it, and transparently retries once
    // without that override if Google rejects it anyway (a stale
    // heuristic here should degrade to "a bit slower/pricier", never to
    // "broken").
    private function generateWithThinkingFallback(string $model, array $contents, array $extraGenConfig, int $timeoutSeconds): string
    {
        $thinkingConfig = self::thinkingConfigFor($model);
        $genConfig      = $extraGenConfig;
        if ($thinkingConfig !== null) {
            $genConfig['thinkingConfig'] = $thinkingConfig;
        }
        $body = self::encode(['contents' => $contents, 'generationConfig' => $genConfig]);

        try {
            return $this->post("models/{$model}:generateContent", $body, $model, $timeoutSeconds);
        } catch (\RuntimeException $e) {
            if ($thinkingConfig !== null && stripos($e->getMessage(), 'thinking') !== false) {
                $bodyRetry = self::encode(['contents' => $contents, 'generationConfig' => $extraGenConfig]);
                return $this->post("models/{$model}:generateContent", $bodyRetry, $model, $timeoutSeconds);
            }
            throw $e;
        }
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

        return $this->generateWithThinkingFallback($model, $contents, [
            'temperature'     => 0.2,
            'maxOutputTokens' => 1024,
        ], 30);
    }

    // ── File extraction (multimodal - PDF/DOCX/image) ──────────────────────────

    public function extractFile(string $model, string $base64Data, string $mimeType, string $prompt): string
    {
        $contents = [[
            'parts' => [
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Data]],
                ['text' => $prompt],
            ],
        ]];

        return $this->generateWithThinkingFallback($model, $contents, [
            'temperature'     => 0.0,
            'maxOutputTokens' => 4096,
        ], 60);
    }

    // ── Structured extraction from plain text (page content -> target JSON shape) ──

    public function extractStructured(string $model, string $text, string $prompt): string
    {
        $contents = [[
            'parts' => [
                ['text' => $prompt . "\n\n---PAGE CONTENT---\n" . $text],
            ],
        ]];

        return $this->generateWithThinkingFallback($model, $contents, [
            'temperature'     => 0.0,
            'maxOutputTokens' => 2048,
        ], 30);
    }

    // ── Raw POST ──────────────────────────────────────────────────────────────

    // 429 (rate limited) and 503 (temporarily overloaded) are Gemini's own
    // signal that the request is worth retrying - its 503 body literally
    // says "please try again later". A short, bounded backoff clears most
    // of these without the caller (chat, extraction, classification - every
    // Gemini call in the system goes through here) needing to handle it
    // separately. Genuinely permanent errors (404 model-not-found, bad
    // request, etc.) are not retried.
    private const RETRYABLE_CODES = [429, 503];
    private const MAX_ATTEMPTS    = 3;
    private const BACKOFF_SECONDS = [2, 4]; // pause before attempt 2 and 3

    private function post(string $path, string $body, string $model, int $timeoutSeconds): string
    {
        return self::extractText($this->request($path, $body, $model, $timeoutSeconds));
    }

    // ── Text-to-speech ─────────────────────────────────────────────────────────

    // Returns ['pcm' => raw 16-bit mono PCM bytes, 'rate' => sample rate].
    // $prompt carries the style direction (accent, pace, tone) plus the
    // words to speak - Gemini TTS models take delivery instructions in
    // natural language. No thinking config: TTS models do not accept it.
    public function speak(string $model, string $prompt, string $voice): array
    {
        $body = self::encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig'       => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
            ],
        ]);
        $data = $this->request("models/{$model}:generateContent", $body, $model, 60);
        return self::extractAudio($data);
    }

    public static function extractAudio(array $data): array
    {
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!empty($inline['data'])) {
                $pcm  = base64_decode($inline['data'], true);
                $mime = (string)($inline['mimeType'] ?? $inline['mime_type'] ?? '');
                $rate = preg_match('/rate=(\d+)/', $mime, $m) ? (int)$m[1] : 24000;
                if ($pcm !== false && $pcm !== '') {
                    return ['pcm' => $pcm, 'rate' => $rate];
                }
            }
        }
        $reason = $data['promptFeedback']['blockReason'] ?? $data['candidates'][0]['finishReason'] ?? 'unknown';
        throw new \RuntimeException("Gemini returned no audio (reason: {$reason})");
    }

    // Invalid UTF-8 (e.g. text extracted from a PDF, or a byte-truncated
    // snippet) makes plain json_encode() return false, which under
    // strict_types then fails request()'s string parameter. Substitute
    // instead so one bad byte can't take the chat down.
    public static function encode(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Raw request with retry/backoff and usage logging; returns the decoded
    // 200 response body.
    private function request(string $path, string $body, string $model, int $timeoutSeconds): array
    {
        // The retry loop below can take several seconds beyond a single
        // request's own timeout - make sure PHP's own execution limit
        // (30s by default under php-fpm) doesn't kill the request before
        // those retries get a chance to run.
        @set_time_limit(max(200, $timeoutSeconds * self::MAX_ATTEMPTS + 20));

        $url = "https://generativelanguage.googleapis.com/v1beta/{$path}";

        $lastCode = 0;
        $lastResp = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                // Key in a header, not the query string, so it never appears in
                // proxy/access logs or curl error output.
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeoutSeconds,
            ]);
            $t0   = microtime(true);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            $ms   = (int)((microtime(true) - $t0) * 1000);

            $data = ($resp !== false) ? (json_decode($resp, true) ?: []) : [];
            $u    = $data['usageMetadata'] ?? [];
            $in   = (int)($u['promptTokenCount'] ?? 0);
            $out  = (int)($u['candidatesTokenCount'] ?? 0);
            $th   = (int)($u['thoughtsTokenCount'] ?? 0);
            \ApiUsage\Logger::log('gemini', [
                'operation'       => \ApiUsage\Logger::callerOperation(),
                'model'           => $model,
                'http_code'       => $code,
                'ok'              => $resp !== false && $code === 200,
                'error'           => $code === 200 ? null : ($data['error']['message'] ?? ($cerr ?: 'no response')),
                'input_tokens'    => $in,
                'output_tokens'   => $out,
                'thinking_tokens' => $th,
                'latency_ms'      => $ms,
                // Google only bills 200 responses.
                'cost_usd'        => $code === 200 ? \ApiUsage\Logger::geminiCost($model, $in, $out, $th) : 0,
            ]);

            if ($resp !== false && $code === 200) {
                return $data;
            }

            $lastCode = $code;
            $lastResp = $resp;

            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;
            if (!in_array($code, self::RETRYABLE_CODES, true) || $isLastAttempt) {
                break;
            }
            sleep(self::BACKOFF_SECONDS[$attempt - 1]);
        }

        // Surface Gemini's actual error text (and which model was asked
        // for) instead of just the bare HTTP code - a 404 could mean
        // "model retired", "wrong API version", or something else
        // entirely, and the bare code alone isn't enough to tell those
        // apart.
        $detail = null;
        if ($lastResp) {
            $errData = json_decode($lastResp, true);
            $detail  = $errData['error']['message'] ?? null;
        }
        $detail = $detail !== null ? $detail : ($lastResp !== false && $lastResp !== null ? $lastResp : 'no response from Gemini');
        $detail = mb_substr($detail, 0, 600);

        throw new \RuntimeException("Gemini API error {$lastCode} (model: {$model}): {$detail}");
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

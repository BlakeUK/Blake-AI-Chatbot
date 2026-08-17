<?php
// src/Knowledge/FileExtractor.php
// Sends file to Gemini Pro for text extraction, then chunks and indexes result.

declare(strict_types=1);

namespace Knowledge;

class FileExtractor
{
    // Returns null on success, error string on failure
    public static function extract(int $fileId, string $path, string $mime): ?string
    {
        try {
            // extractViaGemini() can retry a transient 429/503 with a short
            // backoff (see below) - make sure PHP's own execution limit
            // (30s by default under php-fpm) doesn't kill the request
            // before those retries get a chance to run.
            @set_time_limit(200);

            $apiKey = self::getApiKey();
            if (!$apiKey) {
                return 'Gemini API key not configured';
            }

            $raw  = file_get_contents($path);
            if ($raw === false) {
                return 'Cannot read file';
            }

            $b64  = base64_encode($raw);
            $text = self::extractViaGemini($apiKey, $b64, $mime);

            if (trim($text) === '') {
                return 'No text extracted from file';
            }

            // Chunk into ~500-word pieces
            $chunks = self::chunk($text, 500);
            $pdo    = db();

            // Read back the category files.php stored on this row at upload
            // time (rather than taking it as a param here) - one less thing
            // for every future caller of extract() to have to pass through.
            $catRow   = $pdo->prepare('SELECT category FROM knowledge_files WHERE id = ?');
            $catRow->execute([$fileId]);
            $category = $catRow->fetchColumn() ?: null;

            foreach ($chunks as $chunk) {
                // FTS index updated automatically by trigger on knowledge_chunks
                $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, category) VALUES (?,?,?,?)')
                    ->execute(['file', $fileId, $chunk, $category]);
            }

            $pdo->prepare('UPDATE knowledge_files SET status=? WHERE id=?')
                ->execute(['indexed', $fileId]);

            return null;

        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    // 429 (rate limited) and 503 (temporarily overloaded) are Gemini's own
    // signal that the request is worth retrying - its 503 body literally
    // says "please try again later". A short, bounded backoff clears most
    // of these without a human needing to click Retry repeatedly, which
    // matters a lot when Scan All is firing off a large batch of files in
    // quick succession (exactly the pattern that provokes a 503 in the
    // first place). Genuinely permanent errors (404 model-not-found, bad
    // request, etc.) are not retried - retrying those just burns time for
    // an outcome that was never going to change.
    private const RETRYABLE_CODES = [429, 503];
    private const MAX_ATTEMPTS    = 3;
    private const BACKOFF_SECONDS = [2, 4]; // pause before attempt 2 and 3

    private static function extractViaGemini(string $apiKey, string $b64, string $mime): string
    {
        $prompt = 'Extract all readable text from this document. Return plain text only, preserving structure where helpful. No commentary.';

        $body = json_encode([
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 8192],
        ]);

        // Use model stored in DB setting, fall back to pro
        $model = \Gemini\Client::getModel('gemini_extract_model', 'gemini_pro');

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $lastCode   = 0;
        $lastDetail = 'no response from Gemini';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 60,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp && $code === 200) {
                $data = json_decode($resp, true);
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            }

            // Surface Gemini's actual error text (and which model we asked
            // for) instead of just the bare HTTP code - a 404 could mean
            // "model retired", "wrong API version", or something else
            // entirely, and the bare code alone isn't enough to tell those
            // apart from the admin Files tab.
            $detail = null;
            if ($resp) {
                $errData = json_decode($resp, true);
                $detail  = $errData['error']['message'] ?? null;
            }
            $lastCode   = $code;
            $lastDetail = mb_substr($detail !== null ? $detail : ($resp !== false ? $resp : 'no response from Gemini'), 0, 300);

            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;
            if (!in_array($code, self::RETRYABLE_CODES, true) || $isLastAttempt) {
                break;
            }
            sleep(self::BACKOFF_SECONDS[$attempt - 1]);
        }

        throw new \RuntimeException("Gemini extract error {$lastCode} (model: {$model}): {$lastDetail}");
    }

    // Splits text into ~$maxWords-word chunks, repeating $overlapWords
    // words at the start of each chunk after the first. Non-overlapping
    // chunking means a fact sitting right at a boundary gets split across
    // two chunks and can end up unretrievable in either (neither one
    // contains the whole sentence/fact) - the overlap guarantees anything
    // near a boundary appears intact in at least one chunk. Public: also
    // used by Knowledge\PageIndexer for imported page content, so both
    // ingestion paths chunk the same way.
    public static function chunk(string $text, int $maxWords, int $overlapWords = 50): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return [];
        }

        $chunks = [];
        $step   = max(1, $maxWords - $overlapWords);
        $total  = count($words);

        for ($start = 0; $start < $total; $start += $step) {
            $chunks[] = implode(' ', array_slice($words, $start, $maxWords));
            if ($start + $maxWords >= $total) {
                break;
            }
        }

        return $chunks;
    }

    private static function getApiKey(): ?string
    {
        return \Gemini\Client::getStoredApiKey();
    }
}

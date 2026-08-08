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
        $model = self::getSetting('gemini_extract_model') ?? CFG['gemini_pro'];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

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

        if (!$resp || $code !== 200) {
            throw new \RuntimeException("Gemini extract error {$code}");
        }

        $data = json_decode($resp, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // Split text into word-limited chunks
    private static function chunk(string $text, int $maxWords): array
    {
        $words  = preg_split('/\s+/', trim($text));
        $chunks = [];
        $buf    = [];

        foreach ($words as $word) {
            $buf[] = $word;
            if (count($buf) >= $maxWords) {
                $chunks[] = implode(' ', $buf);
                $buf      = [];
            }
        }
        if ($buf) {
            $chunks[] = implode(' ', $buf);
        }

        return $chunks;
    }

    private static function getApiKey(): ?string
    {
        return \Gemini\Client::getStoredApiKey();
    }

    private static function getSetting(string $key): ?string
    {
        $row = db()->prepare('SELECT value FROM settings WHERE key=?');
        $row->execute([$key]);
        return $row->fetchColumn() ?: null;
    }
}

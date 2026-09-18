<?php
// src/Knowledge/FileExtractor.php
// Extracts text (PDF text layer locally, otherwise Gemini), then chunks and indexes it.

declare(strict_types=1);

namespace Knowledge;

class FileExtractor
{
    public const PROMPT_VERBATIM = 'Extract all readable text from this document. Return plain text only, preserving structure where helpful. No commentary.';
    public const PROMPT_NOTES    = 'Rewrite the full content of this document as detailed technical notes in your own words. Keep every specification, model and part number, measurement, setting, step, warning and table value exactly. Plain text only, no commentary.';

    // Minimum text-layer size (non-whitespace characters) for a PDF to be
    // treated as a real text PDF rather than a scan with stray OCR noise.
    public const MIN_TEXT_LAYER_CHARS = 200;

    public static function isRecitation(string $err): bool
    {
        return str_contains($err, 'RECITATION');
    }

    // Plain text from a PDF's embedded text layer, or null when there is
    // none worth using (scanned PDF) or pdftotext is not installed.
    public static function pdfTextLayer(string $path): ?string
    {
        // shell_exec can be listed in disable_functions under php-fpm; in
        // PHP 8 calling a disabled function is a fatal Error, not a warning.
        if (!function_exists('shell_exec')) {
            return null;
        }
        $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $out = @shell_exec(escapeshellcmd($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        if (!is_string($out)) {
            return null;
        }
        return self::usableText($out);
    }

    // Normalises pdftotext output; null if it is too thin to be a text PDF.
    public static function usableText(string $text): ?string
    {
        $text = str_replace("\f", "\n\n", $text);                 // page breaks
        $text = preg_replace('/[ \t]{3,}/', '  ', $text);            // -layout column padding
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        $chars = preg_match_all('/\S/u', $text);
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($chars < self::MIN_TEXT_LAYER_CHARS || $letters < $chars * 0.4) {
            return null;
        }
        return $text;
    }

    // Returns null on success, error string on failure
    public static function extract(int $fileId, string $path, string $mime): ?string
    {
        try {
            $raw  = file_get_contents($path);
            if ($raw === false) {
                return 'Cannot read file';
            }

            // PDFs with a real text layer are read locally (pdftotext): no
            // Gemini cost, no rate limit, no output-length cap on long
            // manuals, and no RECITATION refusals (Gemini declines to
            // reproduce text it recognises from published material, which
            // manufacturer manuals often are). Scanned/image-only PDFs have
            // no usable text layer and still go to Gemini.
            $text = ($mime === 'application/pdf') ? self::pdfTextLayer($path) : null;

            if ($text === null) {
                $apiKey = self::getApiKey();
                if (!$apiKey) {
                    return 'Gemini API key not configured';
                }
                $b64    = base64_encode($raw);
                $model  = \Gemini\Client::getModel('gemini_extract_model', 'gemini_pro');
                $gemini = new \Gemini\Client($apiKey);
                try {
                    $text = $gemini->extractFile($model, $b64, $mime, self::PROMPT_VERBATIM);
                } catch (\RuntimeException $e) {
                    if (!self::isRecitation($e->getMessage())) {
                        throw $e;
                    }
                    // Verbatim output was blocked; ask for the same content
                    // rewritten as notes, which the recitation check allows.
                    $text = $gemini->extractFile($model, $b64, $mime, self::PROMPT_NOTES);
                }
            }

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

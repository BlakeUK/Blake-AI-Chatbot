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
        // Page breaks (\f) are kept: chunkDocument() uses them to keep
        // chunks inside page boundaries and to strip running headers.
        $text = preg_replace('/[ \t]*\f[ \t]*/', "\f", $text);
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

            $pdo    = db();
            $nameRow = $pdo->prepare('SELECT filename FROM knowledge_files WHERE id = ?');
            $nameRow->execute([$fileId]);
            $chunks = self::chunkDocument($text, (string)($nameRow->fetchColumn() ?: basename($path)));

            // Read back the category files.php stored on this row at upload
            // time (rather than taking it as a param here) - one less thing
            // for every future caller of extract() to have to pass through.
            $catRow   = $pdo->prepare('SELECT category FROM knowledge_files WHERE id = ?');
            $catRow->execute([$fileId]);
            $category = $catRow->fetchColumn() ?: null;

            // One transaction, replacing any chunks from an earlier run, so a
            // failure part-way can't leave a half-indexed file and a re-run
            // can't duplicate chunks.
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type = ? AND source_id = ?')->execute(['file', $fileId]);
                $ins = $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, category) VALUES (?,?,?,?)');
                foreach ($chunks as $chunk) {
                    // FTS index updated automatically by trigger on knowledge_chunks
                    $ins->execute(['file', $fileId, $chunk, $category]);
                }
                $pdo->prepare('UPDATE knowledge_files SET status=? WHERE id=?')->execute(['indexed', $fileId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

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

    public const DOC_CHUNK_WORDS  = 350;
    public const DOC_CHUNK_CHARS  = 4000;
    private const MIN_PAGE_WORDS  = 25;

    // Document-aware chunking for uploaded files.
    //  - Works page by page (\f from pdftotext): a chunk never mixes the end
    //    of one page with the start of the next, so a data sheet with one
    //    product per page can't pair one model's name with another's specs.
    //    Very short pages are merged forward; long pages are split with
    //    chunk()'s overlap.
    //  - Drops running headers/footers: a line in the top/bottom two lines
    //    of at least half the pages (page numbers ignored) that also looks
    //    like a header/footer (page number, |, (c), web address, issue/rev,
    //    or no lower-case letters). Spec rows that happen to repeat, such as
    //    "Rack format 19-inch", are kept.
    //  - Drops pages that repeat an earlier page exactly.
    //  - Rejoins words split by a line-end hyphen ("self-\nassembly").
    //  - Prefixes every chunk with "[Document name, page N]" so search can
    //    match the document/product name and the answer can say which
    //    manual it came from.
    public static function chunkDocument(string $text, string $docName): array
    {
        $name  = trim(preg_replace('/[_\s]+/', ' ', preg_replace('/\.[a-z0-9]{2,5}$/i', '', $docName)));
        $pages = array_values(array_filter(array_map('trim', explode("\f", $text)), fn($p) => $p !== ''));
        if (!$pages) {
            return [];
        }

        $pageLines = array_map(fn($p) => array_values(array_filter(array_map('trim', explode("\n", $p)), fn($l) => $l !== '')), $pages);
        $running = [];
        if (count($pages) >= 3) {
            $counts = [];
            foreach ($pageLines as $lines) {
                $edge = array_merge(array_slice($lines, 0, 2), array_slice($lines, -2));
                $keys = [];
                foreach ($edge as $l) {
                    if (mb_strlen($l) <= 160 && self::looksLikeRunningLine($l)) $keys[self::lineKey($l)] = true;
                }
                foreach (array_keys($keys) as $k) {
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
            }
            foreach ($counts as $k => $c) {
                if ($c >= max(2, (int)ceil(count($pages) * 0.5))) $running[$k] = true;
            }
        }

        $units = [];   // [pageFrom, pageTo, text]
        $seen  = [];
        foreach ($pageLines as $i => $lines) {
            $n = count($lines);
            $keep = [];
            foreach ($lines as $j => $l) {
                $isEdge = $j < 2 || $j >= $n - 2;
                if ($isEdge && isset($running[self::lineKey($l)])) continue;
                $keep[] = $l;
            }
            $body = implode("\n", $keep);
            $body = preg_replace('/(\p{L})-\n(\p{Ll})/u', '$1-$2', $body);
            $body = trim(preg_replace('/\s+/u', ' ', $body));
            if ($body === '') continue;
            $hash = md5(mb_strtolower($body));
            if (isset($seen[$hash])) continue;
            $seen[$hash] = true;

            $last = count($units) - 1;
            if ($last >= 0 && str_word_count($units[$last][2]) < self::MIN_PAGE_WORDS
                && str_word_count($units[$last][2]) + str_word_count($body) <= self::DOC_CHUNK_WORDS) {
                $units[$last][1] = $i + 1;
                $units[$last][2] .= ' ' . $body;
            } else {
                $units[] = [$i + 1, $i + 1, $body];
            }
        }

        $multiPage = count($pages) > 1;
        $out = [];
        foreach ($units as [$from, $to, $body]) {
            $label = $name . ($multiPage ? ($from === $to ? ", page {$from}" : ", pages {$from}-{$to}") : '');
            foreach (self::chunk($body, self::DOC_CHUNK_WORDS, 40) as $piece) {
                // Word count doesn't bound size for tables of long tokens.
                foreach (self::splitByChars($piece, self::DOC_CHUNK_CHARS) as $part) {
                    $out[] = "[{$label}] " . $part;
                }
            }
        }
        return $out;
    }

    private static function lineKey(string $line): string
    {
        $l = mb_strtolower($line);
        $l = preg_replace('/\bpage\s*\d+(\s*(of|\/)\s*\d+)?/u', 'page #', $l);
        $l = preg_replace('/^\d+(\s*(of|\/)\s*\d+)?$/u', '#', trim($l));
        return trim(preg_replace('/\s+/u', ' ', $l));
    }

    private static function looksLikeRunningLine(string $line): bool
    {
        if (!preg_match('/\p{Ll}/u', $line)) return true;   // all caps / numbers only
        return (bool)preg_match('/\bpage\s*\d|\||©|\(c\)|copyright|www\.|\.co\.uk|\.com\b|\bissue\s*\d|\brev(ision)?\.?\s*\d|e&oe/iu', $line);
    }

    private static function splitByChars(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) return [$text];
        $parts = [];
        while (mb_strlen($text) > $max) {
            $cut = mb_strrpos(mb_substr($text, 0, $max), ' ');
            $cut = ($cut === false || $cut < $max / 2) ? $max : $cut;
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') $parts[] = $text;
        return $parts;
    }

    private static function getApiKey(): ?string
    {
        return \Gemini\Client::getStoredApiKey();
    }
}

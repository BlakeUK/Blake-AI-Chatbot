<?php
// src/Knowledge/Dedup.php
// Duplicate-content detection for the knowledge base. Two tiers,
// deliberately handled differently:
//
// - Exact duplicates (byte-identical file, or identical normalised entry
//   text) are unambiguous, so callers block/skip them automatically -
//   see findExactFileDuplicate()/findExactEntryDuplicate().
// - Near-duplicates (similar but not identical - e.g. the same content
//   re-worded on two pages, or a PDF re-uploaded with minor formatting
//   changes) are only ever flagged for admin review via flag(), never
//   auto-deleted - a wrong fuzzy match would silently remove real,
//   distinct information with no way to notice.

declare(strict_types=1);

namespace Knowledge;

class Dedup
{
    private const NEAR_DUPLICATE_THRESHOLD = 0.6;
    private const CANDIDATE_LIMIT          = 8;

    public static function hashBytes(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function hashText(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return hash('sha256', trim($text));
    }

    // Finds an existing file with byte-identical content that's already
    // indexed OR still queued (pending), if any - checked before spending
    // a Gemini extraction call on an upload. Including 'pending' matters
    // for bulk URL imports (import_urls.php queues many files in one
    // request without extracting inline): two URLs in the same batch
    // pointing at the same bytes must not both queue, even though neither
    // is 'indexed' yet at queue time. Excludes 'error' - a failed
    // extraction shouldn't block a fresh attempt at the same content.
    public static function findExactFileDuplicate(string $bytesHash): ?array
    {
        $stmt = db()->prepare("
            SELECT id, filename FROM knowledge_files
            WHERE content_hash = ? AND status IN ('indexed', 'pending')
            LIMIT 1
        ");
        $stmt->execute([$bytesHash]);
        return $stmt->fetch() ?: null;
    }

    // Finds an existing active knowledge_entries row with identical
    // normalised text, if any.
    public static function findExactEntryDuplicate(string $textHash, ?int $excludeId = null): ?array
    {
        $sql    = "SELECT id, title, url FROM knowledge_entries WHERE content_hash = ? AND active = 1";
        $params = [$textHash];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    // Concatenates every chunk belonging to a source (a file's or a
    // manual/page-import entry's knowledge_chunks rows) as an
    // approximation of its full text - good enough for a word-set
    // comparison even with chunk overlap (FileExtractor::chunk())
    // inflating a few words' apparent frequency, since Jaccard similarity
    // only cares about set membership, not counts.
    public static function reconstructText(string $sourceType, int $sourceId): string
    {
        $stmt = db()->prepare('SELECT chunk_text FROM knowledge_chunks WHERE source_type = ? AND source_id = ?');
        $stmt->execute([$sourceType, $sourceId]);
        return implode(' ', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // Finds existing files/entries whose reconstructed text substantially
    // overlaps with $text, for admin review - never auto-deleted. Uses
    // FTS5 to cheaply find a small candidate set sharing vocabulary with
    // $text first, then computes real Jaccard similarity only on those
    // candidates, rather than comparing against every indexed document.
    public static function findNearDuplicates(
        string $text,
        string $excludeSourceType,
        int $excludeSourceId,
        float $threshold = self::NEAR_DUPLICATE_THRESHOLD
    ): array {
        $words = self::significantWords($text);
        if (count($words) < 5) {
            return []; // too short to compare meaningfully
        }

        $sample = array_slice($words, 0, 25); // keeps the FTS query bounded
        $quoted = implode(' OR ', array_map(fn($w) => '"' . str_replace('"', '""', $w) . '"', $sample));

        $stmt = db()->prepare("
            SELECT DISTINCT kc.source_type, kc.source_id
            FROM knowledge_fts
            JOIN knowledge_chunks kc ON kc.id = knowledge_fts.rowid
            WHERE knowledge_fts MATCH ?
            ORDER BY rank
            LIMIT 40
        ");
        $stmt->execute([$quoted]);

        $candidates = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['source_type'] === $excludeSourceType && (int)$row['source_id'] === $excludeSourceId) {
                continue;
            }
            $key = $row['source_type'] . ':' . $row['source_id'];
            $candidates[$key] = ['source_type' => $row['source_type'], 'source_id' => (int)$row['source_id']];
            if (count($candidates) >= self::CANDIDATE_LIMIT) {
                break;
            }
        }

        $matches = [];
        foreach ($candidates as $c) {
            $candidateWords = self::significantWords(self::reconstructText($c['source_type'], $c['source_id']));
            $score          = self::jaccard($words, $candidateWords);
            if ($score >= $threshold) {
                $matches[] = $c + ['similarity' => $score];
            }
        }

        usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return $matches;
    }

    // Records near-duplicate flags for admin review. Skips a pair that's
    // already flagged and pending, so re-processing the same content
    // twice doesn't pile up duplicate flags for the same duplicate.
    public static function flag(string $sourceType, int $sourceId, array $matches): void
    {
        $pdo = db();
        foreach ($matches as $m) {
            $existing = $pdo->prepare("
                SELECT id FROM knowledge_duplicate_flags
                WHERE source_type = ? AND source_id = ? AND similar_source_type = ? AND similar_source_id = ? AND status = 'pending'
            ");
            $existing->execute([$sourceType, $sourceId, $m['source_type'], $m['source_id']]);
            if ($existing->fetchColumn()) {
                continue;
            }

            $pdo->prepare('
                INSERT INTO knowledge_duplicate_flags (source_type, source_id, similar_source_type, similar_source_id, similarity)
                VALUES (?,?,?,?,?)
            ')->execute([$sourceType, $sourceId, $m['source_type'], $m['source_id'], $m['similarity']]);
        }
    }

    private static function jaccard(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    // Words carrying subject-matter signal, deduplicated to a set -
    // stopwords excluded so similarity reflects shared TOPIC, not shared
    // filler words every document has (see Knowledge\Search's own
    // stopword handling for the same reasoning applied to retrieval).
    private static function significantWords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => mb_strlen($w) > 2);
        return array_values(array_unique($words));
    }
}

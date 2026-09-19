<?php
// src/Knowledge/Embeddings.php
// Semantic (dense-vector) retrieval alongside FTS5/BM25 keyword search.
// Keyword search only matches shared words, so "how do I boost my signal"
// misses a document that only says "masthead amplifier" (the vocabulary
// mismatch problem, SLP ch.11; semantic search in the Confluent RAG guide).
// Each knowledge chunk and product gets a Gemini embedding (stored in
// SQLite, unit length); a customer question is embedded once and compared
// by cosine similarity in PHP - brute force is fine at a few thousand
// items. Search::query()/products() fuse both rankings (reciprocal rank
// fusion). Everything degrades to plain BM25 if embeddings are missing,
// disabled or the API fails.

declare(strict_types=1);

namespace Knowledge;

class Embeddings
{
    public const DEFAULT_MODEL = 'gemini-embedding-001';
    public const DIMS = 768;
    // Vector-only results must be at least this similar to be used at all,
    // so an unrelated question still finds nothing (and escalates) instead
    // of always getting the "nearest" chunk. Tunable: setting embed_min_score.
    public const DEFAULT_MIN_SCORE = 0.62;

    private static array $queryCache = [];
    private static array $matrixCache = [];

    public static function model(): string
    {
        return self::setting('embed_model', self::DEFAULT_MODEL);
    }

    public static function enabled(): bool
    {
        return self::setting('embed_enabled', '1') === '1';
    }

    public static function minScore(): float
    {
        return (float)self::setting('embed_min_score', (string)self::DEFAULT_MIN_SCORE);
    }

    private static function setting(string $k, string $d): string
    {
        try {
            $s = db()->prepare('SELECT value FROM settings WHERE key = ?');
            $s->execute([$k]);
            $v = $s->fetchColumn();
            return ($v === false || $v === '') ? $d : (string)$v;
        } catch (\Throwable $e) { return $d; }
    }

    public static function pack(array $v): string
    {
        $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
        return pack('g*', ...array_map(fn($x) => $x / $n, $v));
    }

    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob));
    }

    // Text that represents a product for embedding.
    public static function productText(array $p): string
    {
        $cat = implode(' > ', json_decode((string)($p['category_path'] ?? '[]'), true) ?: []);
        $bul = implode('; ', json_decode((string)($p['summary_bullets'] ?? '[]'), true) ?: []);
        $desc = mb_substr(trim(strip_tags((string)($p['description'] ?? ''))), 0, 1200);
        return trim(($p['title'] ?: $p['name']) . "\n" . $cat . "\n" . $bul . "\n" . $desc);
    }

    // Embed new/changed chunks and products, drop rows for deleted ones.
    // $embedder is injectable for tests. Returns counts.
    public static function sync(int $limit = 150, ?callable $embedder = null, float $budgetSeconds = 45): array
    {
        $pdo = db();
        $model = self::model();
        if ($embedder === null) {
            $key = \Gemini\Client::getStoredApiKey();
            if (!$key || !self::enabled()) return ['embedded' => 0, 'skipped' => 'disabled or no key'];
            $client = new \Gemini\Client($key);
            $embedder = fn(string $t) => $client->embed($model, $t, 'RETRIEVAL_DOCUMENT', self::DIMS);
        }
        $pdo->exec("DELETE FROM embeddings WHERE source_type = 'chunk' AND CAST(source_id AS INTEGER) NOT IN (SELECT id FROM knowledge_chunks)");
        $pdo->exec("DELETE FROM embeddings WHERE source_type = 'product' AND source_id NOT IN (SELECT product_code FROM products WHERE active = 1)");

        $todo = [];
        $chunks = $pdo->query("SELECT kc.id, kc.chunk_text, e.text_hash, e.model FROM knowledge_chunks kc
                               LEFT JOIN embeddings e ON e.source_type = 'chunk' AND e.source_id = CAST(kc.id AS TEXT)");
        foreach ($chunks as $c) {
            $h = sha1($c['chunk_text']);
            if ($c['text_hash'] !== $h || $c['model'] !== $model) $todo[] = ['chunk', (string)$c['id'], $c['chunk_text'], $h];
            if (count($todo) >= $limit) break;
        }
        if (count($todo) < $limit) {
            $prods = $pdo->query("SELECT p.*, e.text_hash AS e_hash, e.model AS e_model FROM products p
                                  LEFT JOIN embeddings e ON e.source_type = 'product' AND e.source_id = p.product_code WHERE p.active = 1");
            foreach ($prods as $p) {
                $t = self::productText($p);
                $h = sha1($t);
                if ($p['e_hash'] !== $h || $p['e_model'] !== $model) $todo[] = ['product', $p['product_code'], $t, $h];
                if (count($todo) >= $limit) break;
            }
        }
        $t0 = microtime(true); $n = 0; $err = 0; $lastErr = null;
        $ins = $pdo->prepare('INSERT INTO embeddings (source_type, source_id, model, text_hash, vec, updated_at) VALUES (?,?,?,?,?,?)
                              ON CONFLICT(source_type, source_id) DO UPDATE SET model=excluded.model, text_hash=excluded.text_hash, vec=excluded.vec, updated_at=excluded.updated_at');
        foreach ($todo as [$type, $id, $text, $hash]) {
            if (microtime(true) - $t0 > $budgetSeconds) break;
            try {
                $vec = $embedder($text);
                $ins->execute([$type, $id, $model, $hash, self::pack($vec), time()]);
                $n++;
            } catch (\Throwable $e) {
                $err++; $lastErr = $e->getMessage();
                if (str_contains($lastErr, '429')) break;   // rate limited: continue next run
            }
        }
        self::$matrixCache = [];
        return ['embedded' => $n, 'errors' => $err, 'remaining_estimate' => max(0, count($todo) - $n), 'last_error' => $lastErr];
    }

    // Embedding of a customer question (cached for the request).
    public static function queryVector(string $text, ?callable $embedder = null): ?array
    {
        $text = trim($text);
        if ($text === '') return null;
        if (array_key_exists($text, self::$queryCache)) return self::$queryCache[$text];
        try {
            if ($embedder === null) {
                if (!self::enabled()) return self::$queryCache[$text] = null;
                $key = \Gemini\Client::getStoredApiKey();
                if (!$key) return self::$queryCache[$text] = null;
                $has = db()->query('SELECT 1 FROM embeddings LIMIT 1')->fetchColumn();
                if (!$has) return self::$queryCache[$text] = null;
                $embedder = fn(string $t) => (new \Gemini\Client($key))->embed(self::model(), $t, 'RETRIEVAL_QUERY', self::DIMS);
            }
            $v = $embedder($text);
            return self::$queryCache[$text] = self::unpack(self::pack($v));
        } catch (\Throwable $e) {
            error_log('Embeddings::queryVector failed, using keyword search only: ' . $e->getMessage());
            return self::$queryCache[$text] = null;
        }
    }

    // Top-$k [id => cosine] for $type ('chunk'|'product'), above $minScore.
    public static function nearest(array $qv, string $type, int $k = 20, ?float $minScore = null): array
    {
        $minScore = $minScore ?? self::minScore();
        if (!isset(self::$matrixCache[$type])) {
            $rows = db()->prepare('SELECT source_id, vec FROM embeddings WHERE source_type = ? AND model = ?');
            $rows->execute([$type, self::model()]);
            self::$matrixCache[$type] = $rows->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        $dims = count($qv);
        $scores = [];
        foreach (self::$matrixCache[$type] as $id => $blob) {
            $v = unpack('g*', $blob);
            if (count($v) !== $dims) continue;
            $dot = 0.0;
            for ($i = 0; $i < $dims; $i++) $dot += $qv[$i] * $v[$i + 1];
            if ($dot >= $minScore) $scores[(string)$id] = $dot;
        }
        arsort($scores);
        return array_slice($scores, 0, $k, true);
    }

    // Reciprocal rank fusion of ranked id lists (k=60, the usual constant).
    public static function fuse(array ...$rankings): array
    {
        $score = [];
        foreach ($rankings as $list) {
            foreach (array_values($list) as $rank => $id) {
                $score[(string)$id] = ($score[(string)$id] ?? 0) + 1 / (60 + $rank + 1);
            }
        }
        arsort($score);
        return array_keys($score);
    }

    public static function resetCaches(): void
    {
        self::$queryCache = [];
        self::$matrixCache = [];
    }
}

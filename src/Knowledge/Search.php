<?php
// src/Knowledge/Search.php

declare(strict_types=1);

namespace Knowledge;

class Search
{
    // Returns top $limit chunks matching $query via FTS5. $categoryHint (the
    // customer's current product's category_path, when known) re-prioritises
    // same-category chunks ahead of others without excluding anything -
    // see prioritiseByCategory().
    public static function query(string $query, int $limit = 5, array $categoryHint = []): array
    {
        $clean = self::naturalLanguageMatch($query);
        if ($clean === '') {
            return [];
        }

        // Widen the candidate pool when a category hint is in play, so
        // there's actually something for it to re-prioritise beyond
        // whatever BM25 alone would have returned in the top $limit.
        $pool = $categoryHint ? max($limit * 3, 15) : $limit;

        $stmt = db()->prepare('
            SELECT kc.id, kc.source_type, kc.source_id, kc.chunk_text, kc.url, kc.category,
                   rank
            FROM knowledge_fts
            JOIN knowledge_chunks kc ON kc.id = knowledge_fts.rowid
            WHERE knowledge_fts MATCH ?
            ORDER BY rank
            LIMIT ?
        ');
        $stmt->execute([$clean, $pool]);
        $rows = $stmt->fetchAll();

        return self::prioritiseByCategory($rows, $categoryHint, $limit, fn($row) =>
            $row['category'] !== null && $row['category'] !== ''
                && in_array(strtolower($row['category']), array_map('strtolower', $categoryHint), true)
        );
    }

    // Search products via FTS5. $categoryHint works the same as in query()
    // above, matched against the candidate's own category_path.
    public static function products(string $query, int $limit = 5, array $categoryHint = []): array
    {
        $clean = self::naturalLanguageMatch($query);
        if ($clean === '') {
            return [];
        }

        $pool = $categoryHint ? max($limit * 3, 15) : $limit;

        $stmt = db()->prepare('
            SELECT p.product_code, p.name, p.title, p.url, p.category_path,
                   p.price_inc_vat, p.price_exc_vat, p.image_url,
                   p.summary_bullets, p.description, p.tech_specs, p.stock_status,
                   p.related_product_codes, p.alternative_product_codes, p.brand,
                   rank
            FROM products_fts
            JOIN products p ON p.id = products_fts.rowid
            WHERE products_fts MATCH ?
            ORDER BY rank
            LIMIT ?
        ');
        $stmt->execute([$clean, $pool]);
        $rows = $stmt->fetchAll();

        return self::prioritiseByCategory($rows, $categoryHint, $limit, fn($row) =>
            self::categoryPathOverlaps($row['category_path'] ?? null, $categoryHint)
        );
    }

    // Exact product lookup by code — used when we already know which product
    // the customer is looking at (product-aware chat), instead of hoping a
    // keyword search on their message happens to surface it. Includes
    // category_path so callers (Chat\Responder) can use it as a category
    // hint for query()/products() above.
    public static function byCode(string $code): ?array
    {
        if ($code === '') return null;

        $stmt = db()->prepare('
            SELECT product_code, name, title, url, category_path,
                   price_inc_vat, price_exc_vat, image_url,
                   summary_bullets, description, tech_specs, stock_status,
                   related_product_codes, alternative_product_codes, brand
            FROM products
            WHERE product_code = ? AND active = 1
        ');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Batch lookup for a set of product codes, e.g. a product's
    // related_product_codes. Preserves the order $codes was given in (SQL's
    // IN() doesn't), skips codes that don't exist or aren't active, and caps
    // the result so a feed with dozens of related codes can't balloon the
    // prompt.
    public static function byCodes(array $codes, int $limit = 3): array
    {
        $codes = array_slice(array_values(array_unique(array_filter($codes))), 0, $limit);
        if (!$codes) return [];

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = db()->prepare("
            SELECT product_code, name, title, url,
                   price_inc_vat, price_exc_vat, image_url,
                   summary_bullets, description, tech_specs, stock_status,
                   related_product_codes, alternative_product_codes, brand
            FROM products
            WHERE product_code IN ($placeholders) AND active = 1
        ");
        $stmt->execute($codes);

        $byCode = [];
        foreach ($stmt->fetchAll() as $row) {
            $byCode[$row['product_code']] = $row;
        }
        $ordered = [];
        foreach ($codes as $c) {
            if (isset($byCode[$c])) $ordered[] = $byCode[$c];
        }
        return $ordered;
    }

    // Appends products not already present (by product_code) to a context
    // list - used to layer related/cross-sell products in after the current
    // product and organic search hits, without duplicating either.
    public static function addRelated(array $hits, array $related): array
    {
        $codes = array_column($hits, 'product_code');
        foreach ($related as $r) {
            if (!in_array($r['product_code'], $codes, true)) {
                $hits[]  = $r;
                $codes[] = $r['product_code'];
            }
        }
        return $hits;
    }

    // Ensures the customer's current product (if any) is listed first among
    // a set of search hits, replacing any organic duplicate rather than
    // leaving it wherever the search happened to rank it.
    public static function withCurrentFirst(array $hits, ?array $current): array
    {
        if (!$current) return $hits;
        $hits = array_values(array_filter(
            $hits,
            fn($h) => $h['product_code'] !== $current['product_code']
        ));
        array_unshift($hits, $current);
        return $hits;
    }

    // Re-orders $rows so ones matching $categoryHint (per $matches) come
    // first, preserving each group's existing relative order (BM25 rank)
    // rather than re-ranking within it - then trims to $limit. Never drops
    // a row for having no/a different category: with no hint, or nothing
    // in the pool matching it, this is just array_slice($rows, 0, $limit),
    // identical to behaviour before category-awareness existed. That's
    // deliberate - a category hint should reorder toward relevance, never
    // reduce recall by excluding a genuinely relevant but uncategorised or
    // differently-categorised result.
    private static function prioritiseByCategory(array $rows, array $categoryHint, int $limit, callable $matches): array
    {
        if (!$categoryHint) {
            return array_slice($rows, 0, $limit);
        }

        $matching = [];
        $rest     = [];
        foreach ($rows as $row) {
            if ($matches($row)) {
                $matching[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return array_slice(array_merge($matching, $rest), 0, $limit);
    }

    // Does a product's category_path (JSON array, e.g. ["Aerials & Reception",
    // "TV Aerials"]) share any segment with $categoryHint, case-insensitive?
    private static function categoryPathOverlaps(?string $categoryPathJson, array $categoryHint): bool
    {
        if (!$categoryPathJson || !$categoryHint) return false;
        $path = json_decode($categoryPathJson, true) ?: [];
        if (!$path) return false;

        $hint = array_map('strtolower', $categoryHint);
        foreach ($path as $segment) {
            if (in_array(strtolower((string)$segment), $hint, true)) return true;
        }
        return false;
    }

    // Strips everything but word characters/hyphens and splits on
    // whitespace. Shared by sanitiseFts() (AND semantics, admin search box)
    // and naturalLanguageMatch() (OR + stopwords, customer chat) so both
    // agree on what counts as a "word" instead of drifting apart.
    private static function tokenize(string $q): array
    {
        $q = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', trim($q));
        return preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
    }

    // Escape special FTS5 chars to prevent query errors. Stripping symbols
    // isn't enough on its own: FTS5 reserved words (AND/OR/NOT/NEAR) and a
    // trailing/standalone "-" are still valid ASCII letters/hyphens, so they
    // survive the character filter and get parsed as query operators rather
    // than literal search terms - "cable AND connector" or a message that
    // happens to sanitise down to a trailing "--" both throw an uncaught
    // FTS5 syntax error otherwise. Quoting each word individually makes
    // every token literal regardless of content. Public: also used directly
    // by admin/products.php, which builds its own FTS5 query - deliberately
    // AND semantics there, since an admin typing several words into a search
    // box expects them to narrow the result, not broaden it.
    public static function sanitiseFts(string $q): string
    {
        $words = self::tokenize($q);
        if (!$words) {
            return '';
        }
        $quoted = array_map(fn($w) => '"' . str_replace('"', '""', $w) . '"', $words);
        return implode(' ', $quoted);
    }

    // Words with no retrieval signal on their own - filtered out of
    // customer chat queries only (see naturalLanguageMatch()), not out of
    // sanitiseFts()/the admin search box, where they're rare in a short
    // deliberate keyword search anyway.
    private const STOPWORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'am', 'be', 'been', 'being',
        'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having',
        'i', 'me', 'my', 'we', 'our', 'us', 'you', 'your', 'yours',
        'it', 'its', 'this', 'that', 'these', 'those',
        'what', 'who', 'whom', 'which', 'when', 'where', 'why', 'how',
        'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with', 'about', 'from', 'into', 'as', 'than',
        'and', 'or', 'but', 'if', 'so', 'not', 'no',
        'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'must',
        'please', 'hi', 'hello', 'hey', 'thanks', 'thank',
    ];

    // Builds a customer chat FTS5 query: unlike sanitiseFts()'s implicit
    // AND (FTS5's default for space-separated terms), this ORs the terms
    // together after dropping stopwords. A real question like "what is your
    // returns policy" ANDed word-for-word requires a chunk to literally
    // contain "what" and "your" too, which almost none will, even when it
    // plainly answers the question - natural language carries filler words
    // an exact-match KB chunk never will. Dropping stopwords first keeps OR
    // from matching on "is"/"the"/etc. against nearly everything.
    private static function naturalLanguageMatch(string $q): string
    {
        $words = array_filter(
            self::tokenize($q),
            fn($w) => !in_array(strtolower($w), self::STOPWORDS, true)
        );
        if (!$words) {
            return '';
        }
        $quoted = array_map(fn($w) => '"' . str_replace('"', '""', $w) . '"', $words);
        return implode(' OR ', $quoted);
    }

    // Formats a single product row into the text block used in the Gemini
    // prompt, tagging it as the one the customer is currently viewing, a
    // related (cross-sell) suggestion, or an alternative (substitute) for it.
    public static function formatForPrompt(
        array $p,
        ?string $currentCode,
        array $relatedCodes = [],
        array $alternativeCodes = []
    ): string {
        $bullets  = json_decode($p['summary_bullets'] ?? '[]', true) ?: [];
        $specs    = json_decode($p['tech_specs'] ?? '{}', true) ?: [];
        $brand    = json_decode($p['brand'] ?? 'null', true);
        $isViewed = $currentCode && $p['product_code'] === $currentCode;
        $isAlt    = !$isViewed && in_array($p['product_code'], $alternativeCodes, true);
        $isRelated = !$isViewed && !$isAlt && in_array($p['product_code'], $relatedCodes, true);

        $tag = $isViewed
            ? '[Customer is currently viewing this product] '
            : ($isAlt
                ? '[Alternative product — a substitute for the one being viewed, mention only if relevant] '
                : ($isRelated ? '[Related product — a cross-sell/accessory suggestion, mention only if relevant] ' : ''));

        $line = $tag . "Product: {$p['name']} (Code: {$p['product_code']})";
        if (!empty($brand['name'])) {
            $line .= " — Brand: {$brand['name']}";
        }
        if (!empty($p['price_inc_vat'])) {
            $line .= " — £{$p['price_inc_vat']} inc VAT";
        }
        if (!empty($p['stock_status'])) {
            $line .= " — Stock: {$p['stock_status']}";
        }
        $line .= "\nURL: {$p['url']}";
        if (!empty($p['description'])) {
            $desc = mb_strlen($p['description']) > 200
                ? mb_substr($p['description'], 0, 200) . '…'
                : $p['description'];
            $line .= "\n" . $desc;
        }
        if ($bullets) {
            $line .= "\n• " . implode("\n• ", $bullets);
        }
        if ($specs) {
            $line .= "\nSpecs: " . implode(', ', array_map(
                fn($k, $v) => "$k: $v", array_keys($specs), array_values($specs)
            ));
        }
        return $line;
    }
}

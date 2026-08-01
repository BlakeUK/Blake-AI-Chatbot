<?php
// src/Knowledge/Search.php

declare(strict_types=1);

namespace Knowledge;

class Search
{
    // Returns top $limit chunks matching $query via FTS5
    public static function query(string $query, int $limit = 5): array
    {
        $clean = self::sanitiseFts($query);
        if ($clean === '') {
            return [];
        }

        $stmt = db()->prepare('
            SELECT kc.id, kc.source_type, kc.source_id, kc.chunk_text, kc.url,
                   rank
            FROM knowledge_fts
            JOIN knowledge_chunks kc ON kc.id = knowledge_fts.rowid
            WHERE knowledge_fts MATCH ?
            ORDER BY rank
            LIMIT ?
        ');
        $stmt->execute([$clean, $limit]);
        return $stmt->fetchAll();
    }

    // Search products via FTS5
    public static function products(string $query, int $limit = 5): array
    {
        $clean = self::sanitiseFts($query);
        if ($clean === '') {
            return [];
        }

        $stmt = db()->prepare('
            SELECT p.product_code, p.name, p.title, p.url,
                   p.price_inc_vat, p.price_exc_vat, p.image_url,
                   p.summary_bullets, p.description, p.tech_specs, p.stock_status,
                   rank
            FROM products_fts
            JOIN products p ON p.id = products_fts.rowid
            WHERE products_fts MATCH ?
            ORDER BY rank
            LIMIT ?
        ');
        $stmt->execute([$clean, $limit]);
        return $stmt->fetchAll();
    }

    // Exact product lookup by code — used when we already know which product
    // the customer is looking at (product-aware chat), instead of hoping a
    // keyword search on their message happens to surface it.
    public static function byCode(string $code): ?array
    {
        if ($code === '') return null;

        $stmt = db()->prepare('
            SELECT product_code, name, title, url,
                   price_inc_vat, price_exc_vat, image_url,
                   summary_bullets, description, tech_specs, stock_status
            FROM products
            WHERE product_code = ? AND active = 1
        ');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
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

    // Escape special FTS5 chars to prevent query errors. Stripping symbols
    // isn't enough on its own: FTS5 reserved words (AND/OR/NOT/NEAR) and a
    // trailing/standalone "-" are still valid ASCII letters/hyphens, so they
    // survive the character filter and get parsed as query operators rather
    // than literal search terms - "cable AND connector" or a message that
    // happens to sanitise down to a trailing "--" both throw an uncaught
    // FTS5 syntax error otherwise. Quoting each word individually makes
    // every token literal regardless of content.
    private static function sanitiseFts(string $q): string
    {
        $q = trim($q);
        $q = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', $q);
        $words = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return '';
        }
        $quoted = array_map(fn($w) => '"' . str_replace('"', '""', $w) . '"', $words);
        return implode(' ', $quoted);
    }

    // Formats a single product row into the text block used in the Gemini
    // prompt, tagging it when it's the one the customer is currently viewing.
    public static function formatForPrompt(array $p, ?string $currentCode): string
    {
        $bullets  = json_decode($p['summary_bullets'] ?? '[]', true) ?: [];
        $specs    = json_decode($p['tech_specs'] ?? '{}', true) ?: [];
        $isViewed = $currentCode && $p['product_code'] === $currentCode;

        $line = ($isViewed ? '[Customer is currently viewing this product] ' : '')
            . "Product: {$p['name']} (Code: {$p['product_code']})";
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

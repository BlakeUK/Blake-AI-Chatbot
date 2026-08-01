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

    // Ensures the customer's current product (if any) is included and listed
    // first among a set of search hits, without duplicating it if the search
    // already found it on its own merits.
    public static function withCurrentFirst(array $hits, ?array $current): array
    {
        if (!$current) return $hits;
        if (in_array($current['product_code'], array_column($hits, 'product_code'), true)) {
            return $hits;
        }
        array_unshift($hits, $current);
        return $hits;
    }

    // Escape special FTS5 chars to prevent query errors
    private static function sanitiseFts(string $q): string
    {
        $q = trim($q);
        $q = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', $q);
        $q = preg_replace('/\s+/', ' ', $q);
        return trim($q);
    }
}

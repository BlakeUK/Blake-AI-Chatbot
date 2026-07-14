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
                   p.price_inc_vat, p.image_url, p.summary_bullets,
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

    // Escape special FTS5 chars to prevent query errors
    private static function sanitiseFts(string $q): string
    {
        $q = trim($q);
        $q = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', $q);
        $q = preg_replace('/\s+/', ' ', $q);
        return trim($q);
    }
}

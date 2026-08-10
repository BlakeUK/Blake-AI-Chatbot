<?php
// src/Knowledge/KeywordLinks.php
// Deterministic keyword/phrase -> page matching, managed via
// public/api/admin/keyword_links.php. Unlike FTS5 retrieval (which finds
// what's probably relevant), this is for pages an admin wants guaranteed
// to be offered whenever a specific word comes up - see
// Chat\Responder::buildContext(), which folds the result into the RAG
// context alongside knowledge/product hits.

declare(strict_types=1);

namespace Knowledge;

class KeywordLinks
{
    // Returns every active keyword_links row that has at least one keyword
    // appearing in $message, word-boundary matched (so "warranty" matches
    // "what's your warranty policy" but not "warrantying") and
    // case-insensitive. One entry per matching row, even if several of its
    // keywords match.
    public static function match(string $message): array
    {
        if (trim($message) === '') {
            return [];
        }

        $rows = db()->query('SELECT id, keywords, title, url FROM keyword_links WHERE active = 1')->fetchAll();
        $hits = [];

        foreach ($rows as $row) {
            $keywords = json_decode($row['keywords'] ?? '[]', true) ?: [];
            foreach ($keywords as $kw) {
                $kw = trim((string)$kw);
                if ($kw === '') continue;
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $message)) {
                    $hits[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'url' => $row['url']];
                    break; // this row already matched, no need to check its other keywords
                }
            }
        }

        return $hits;
    }
}

<?php
// src/Knowledge/PageIndexer.php
// Indexes a live website page's actual body content into the knowledge
// base, replacing the title+meta-description stub that
// public/api/admin/import_page_links.php and scripts/process_product_pages.php
// used to store for anything that wasn't a PDF: a category page, FAQ, or
// service description previously contributed one searchable line. This
// chunks the real page text the same way file uploads are (see
// FileExtractor::chunk()), so it's genuinely retrievable.
//
// Shared by three callers: import_page_links.php (interactive/batch,
// admin-picked URLs), process_product_pages.php (a scanned URL that turned
// out not to be a product), and scripts/refresh_site_pages.php (scheduled
// sitemap re-crawl) - one implementation instead of three copies that
// could drift.

declare(strict_types=1);

namespace Knowledge;

class PageIndexer
{
    // Pure: given a URL and its already-fetched HTML, decides what to
    // store - no I/O, no DB. Kept separate from indexPage() so it's
    // testable with fixture HTML, without a network call.
    public static function buildEntry(string $url, string $html): array
    {
        $title = \Html\TextCleaner::extractTitle($html) ?: $url;
        $body  = \Html\TextCleaner::toReadableText($html);

        if (trim($body) === '') {
            // A JS-rendered SPA shell or similar can yield no readable
            // body text at all - fall back to the meta description so
            // this page contributes something searchable rather than
            // nothing, same as the old behaviour did for every page.
            $body = trim((string)\Html\TextCleaner::extractMetaDescription($html));
        }

        $chunks = FileExtractor::chunk(trim($title . "\n\n" . $body), 500);

        return ['title' => $title, 'body' => $body, 'chunks' => $chunks];
    }

    // Fetches (unless $html is already supplied - process_product_pages.php
    // has already fetched the page once for its own product-shape check
    // and shouldn't need to fetch it again) and upserts by URL: re-indexing
    // an already-known page replaces its old chunks rather than
    // accumulating duplicates.
    public static function indexPage(string $url, ?string $html = null, ?string $category = null): array
    {
        if ($html === null) {
            $html = \Products\PageExtractor::fetch($url);
        }

        $entry = self::buildEntry($url, $html);
        $pdo   = db();

        $existing = $pdo->prepare('SELECT id FROM knowledge_entries WHERE url = ?');
        $existing->execute([$url]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $id = (int)$existingId;
            $pdo->prepare('UPDATE knowledge_entries SET title=?, body=?, source=\'page_import\', updated_at=unixepoch() WHERE id=?')
                ->execute([$entry['title'], $entry['body'], $id]);
            $status = 'updated';
        } else {
            $pdo->prepare('
                INSERT INTO knowledge_entries (title, body, category, product_codes, url, active, source)
                VALUES (?, ?, ?, NULL, ?, 1, \'page_import\')
            ')->execute([$entry['title'], $entry['body'], $category, $url]);
            $id     = (int)$pdo->lastInsertId();
            $status = 'imported';
        }

        // Re-chunk from scratch every time (delete then insert) rather than
        // diffing - simpler, and a re-crawled page's content may have
        // changed shape entirely (different heading structure, more/fewer
        // sections), so there's nothing meaningful to diff against anyway.
        $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')
            ->execute(['manual', $id]);
        $chunkStmt = $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url, category) VALUES (?,?,?,?,?)');
        foreach ($entry['chunks'] as $chunk) {
            $chunkStmt->execute(['manual', $id, $chunk, $url, $category]);
        }

        return ['id' => $id, 'status' => $status, 'title' => $entry['title'], 'chunk_count' => count($entry['chunks'])];
    }

    // Pure: parses a sitemap XML document into page URLs and, for a
    // <sitemapindex>, the child sitemap URLs it references (one level -
    // matches the common case of a single index fanning out to a handful
    // of per-section sitemaps, without building a fully general recursive
    // crawler for the rare deeper nesting).
    public static function parseSitemapXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if (!$parsed) {
            return ['urls' => [], 'child_sitemaps' => []];
        }

        $urls = [];
        foreach ($parsed->url ?? [] as $u) {
            $loc = trim((string)$u->loc);
            if ($loc !== '') $urls[] = $loc;
        }

        $childSitemaps = [];
        foreach ($parsed->sitemap ?? [] as $s) {
            $loc = trim((string)$s->loc);
            if ($loc !== '') $childSitemaps[] = $loc;
        }

        return ['urls' => array_values(array_unique($urls)), 'child_sitemaps' => array_values(array_unique($childSitemaps))];
    }
}

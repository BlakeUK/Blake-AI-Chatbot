<?php
// tests/cases/page_indexer_test.php
// Regression tests for src/Knowledge/PageIndexer.php - indexing a live
// website page's real body content (replacing the old title+meta-
// description-only stub) and sitemap discovery. No network calls: HTML/
// XML fixtures are passed in directly (indexPage()'s $html param and
// buildEntry()/parseSitemapXml() are both pure once given content), so
// this exercises the real ingestion logic without needing a live server.

declare(strict_types=1);

function page_indexer_fixture_html(): string
{
    return <<<HTML
    <html>
    <head>
      <title>TV Aerial Installation | Blake UK</title>
      <meta name="description" content="Professional TV aerial installation across the UK.">
    </head>
    <body>
      <nav><a href="/">Home</a> <a href="/services">Services</a></nav>
      <h1>TV Aerial Installation</h1>
      <p>Our engineers install TV aerials, satellite dishes and IRS communal systems across the UK.</p>
      <p>Every installation includes a full signal strength test before the engineer leaves, and comes with a two year workmanship guarantee.</p>
      <footer>Copyright Blake UK. <a href="/privacy">Privacy</a></footer>
    </body>
    </html>
    HTML;
}

suite('Knowledge\PageIndexer — buildEntry()');

test('extracts the page title', function () {
    $entry = \Knowledge\PageIndexer::buildEntry('https://www.blake-uk.com/services/aerial-installation', page_indexer_fixture_html());
    assert_equal('TV Aerial Installation | Blake UK', $entry['title']);
});

test('body contains real page content, not just the meta description', function () {
    $entry = \Knowledge\PageIndexer::buildEntry('https://www.blake-uk.com/services/aerial-installation', page_indexer_fixture_html());
    assert_str_contains('signal strength test', $entry['body']);
    assert_str_contains('workmanship guarantee', $entry['body']);
});

test('body strips script/style/tag markup', function () {
    $html = '<html><head><title>T</title><style>.x{color:red}</style></head><body><script>alert(1)</script><p>Real content here.</p></body></html>';
    $entry = \Knowledge\PageIndexer::buildEntry('https://example.com/x', $html);
    assert_false(str_contains($entry['body'], 'alert('));
    assert_false(str_contains($entry['body'], 'color:red'));
    assert_str_contains('Real content here.', $entry['body']);
});

test('falls back to the meta description when the page has no readable body text at all', function () {
    // No <title> deliberately - TextCleaner::toReadableText() doesn't
    // strip <head>, so a <title> tag's text would itself count as
    // "readable body text" and this fallback would never trigger.
    $html = '<html><head><meta name="description" content="A JS app with no server-rendered content."></head><body><div id="app"></div></body></html>';
    $entry = \Knowledge\PageIndexer::buildEntry('https://example.com/spa', $html);
    assert_str_contains('JS app with no server-rendered content', $entry['body']);
});

test('produces multiple chunks for a long page, one for a short page', function () {
    $short = \Knowledge\PageIndexer::buildEntry('https://example.com/short', page_indexer_fixture_html());
    assert_count(1, $short['chunks']);

    $longBody = '<html><head><title>Long Page</title></head><body><p>' . implode(' ', array_fill(0, 1200, 'word')) . '</p></body></html>';
    $long = \Knowledge\PageIndexer::buildEntry('https://example.com/long', $longBody);
    assert_true(count($long['chunks']) > 1, 'expected a 1200-word page to produce more than one chunk');
});

suite('Knowledge\PageIndexer — indexPage() (DB, no network - $html supplied directly)');

test('a new page creates a knowledge_entries row and matching chunks', function () {
    $url    = 'https://www.blake-uk.com/services/aerial-installation-test-1';
    $result = \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());

    assert_equal('imported', $result['status']);
    assert_true($result['chunk_count'] > 0);

    $row = db()->prepare('SELECT title, url FROM knowledge_entries WHERE id = ?');
    $row->execute([$result['id']]);
    $entry = $row->fetch();
    assert_equal($url, $entry['url']);

    $chunks = db()->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE source_type=? AND source_id=?');
    $chunks->execute(['manual', $result['id']]);
    assert_equal($result['chunk_count'], (int)$chunks->fetchColumn());
});

test('re-indexing an existing URL updates it in place rather than duplicating', function () {
    $url = 'https://www.blake-uk.com/services/aerial-installation-test-2';
    $first  = \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());
    $second = \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());

    assert_equal('imported', $first['status']);
    assert_equal('updated', $second['status']);
    assert_equal($first['id'], $second['id']);

    $count = db()->prepare('SELECT COUNT(*) FROM knowledge_entries WHERE url = ?');
    $count->execute([$url]);
    assert_equal(1, (int)$count->fetchColumn());
});

test('re-indexing replaces old chunks rather than accumulating them', function () {
    $url = 'https://www.blake-uk.com/services/aerial-installation-test-3';
    $result = \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());
    \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());

    $count = db()->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE source_type=? AND source_id=?');
    $count->execute(['manual', $result['id']]);
    assert_equal($result['chunk_count'], (int)$count->fetchColumn());
});

test('indexed page content is immediately findable via FTS search', function () {
    $url = 'https://www.blake-uk.com/services/aerial-installation-test-4';
    \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html());

    $hits = \Knowledge\Search::query('signal strength test', 5);
    $found = false;
    foreach ($hits as $h) {
        if ($h['url'] === $url) $found = true;
    }
    assert_true($found, 'expected the imported page to be searchable via Knowledge\Search::query()');
});

test('a category is stored on every chunk when given', function () {
    $url = 'https://www.blake-uk.com/services/aerial-installation-test-5';
    $result = \Knowledge\PageIndexer::indexPage($url, page_indexer_fixture_html(), 'TV Aerials & Reception');

    $cats = db()->prepare('SELECT DISTINCT category FROM knowledge_chunks WHERE source_type=? AND source_id=?');
    $cats->execute(['manual', $result['id']]);
    assert_equal(['TV Aerials & Reception'], $cats->fetchAll(PDO::FETCH_COLUMN));
});

suite('Knowledge\PageIndexer — parseSitemapXml()');

test('parses <url><loc> entries from a plain sitemap', function () {
    $xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';
    $parsed = \Knowledge\PageIndexer::parseSitemapXml($xml);
    assert_equal(['https://example.com/a', 'https://example.com/b'], $parsed['urls']);
    assert_equal([], $parsed['child_sitemaps']);
});

test('parses <sitemap><loc> entries from a sitemap index', function () {
    $xml = '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap><sitemap><loc>https://example.com/sitemap-products.xml</loc></sitemap></sitemapindex>';
    $parsed = \Knowledge\PageIndexer::parseSitemapXml($xml);
    assert_equal([], $parsed['urls']);
    assert_equal(['https://example.com/sitemap-pages.xml', 'https://example.com/sitemap-products.xml'], $parsed['child_sitemaps']);
});

test('malformed XML returns empty arrays rather than throwing', function () {
    $parsed = \Knowledge\PageIndexer::parseSitemapXml('not xml at all <<<');
    assert_equal(['urls' => [], 'child_sitemaps' => []], $parsed);
});

test('duplicate <loc> entries are deduplicated', function () {
    $xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/a</loc></url></urlset>';
    $parsed = \Knowledge\PageIndexer::parseSitemapXml($xml);
    assert_equal(['https://example.com/a'], $parsed['urls']);
});

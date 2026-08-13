<?php
// tests/cases/sitemap_parser_test.php
// Regression tests for Sitemap\Parser - pure XML-in, array-out, so these
// run against fixture strings with no network involved.

declare(strict_types=1);

suite('Sitemap\Parser — parse');

test('parses a plain urlset with lastmod/changefreq/priority', function () {
    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
      <url><loc>https://www.blake-uk.com/</loc><lastmod>2026-01-01</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>
      <url><loc>https://www.blake-uk.com/about</loc></url>
    </urlset>
    XML;

    $result = \Sitemap\Parser::parse($xml);
    assert_equal('urlset', $result['type']);
    assert_count(2, $result['entries']);
    assert_equal('https://www.blake-uk.com/', $result['entries'][0]['loc']);
    assert_equal('2026-01-01', $result['entries'][0]['lastmod']);
    assert_equal('daily', $result['entries'][0]['changefreq']);
    assert_null($result['entries'][1]['lastmod']);
});

test('parses a sitemap index into child sitemap entries', function () {
    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
      <sitemap><loc>https://www.blake-uk.com/page-sitemap.xml</loc><lastmod>2026-02-01</lastmod></sitemap>
      <sitemap><loc>https://www.blake-uk.com/post-sitemap.xml</loc></sitemap>
    </sitemapindex>
    XML;

    $result = \Sitemap\Parser::parse($xml);
    assert_equal('sitemapindex', $result['type']);
    assert_count(2, $result['entries']);
    assert_equal('https://www.blake-uk.com/page-sitemap.xml', $result['entries'][0]['loc']);
});

test('reports an error for empty input', function () {
    $result = \Sitemap\Parser::parse('');
    assert_equal('error', $result['type']);
});

test('reports an error for malformed XML rather than throwing', function () {
    $result = \Sitemap\Parser::parse('<urlset><url><loc>unterminated');
    assert_equal('error', $result['type']);
});

test('reports an error for an unrecognized root element', function () {
    $result = \Sitemap\Parser::parse('<?xml version="1.0"?><rss></rss>');
    assert_equal('error', $result['type']);
});

test('skips entries with an empty <loc>', function () {
    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
      <url><loc></loc></url>
      <url><loc>https://www.blake-uk.com/ok</loc></url>
    </urlset>
    XML;
    $result = \Sitemap\Parser::parse($xml);
    assert_count(1, $result['entries']);
});

test('does not expand an external SYSTEM entity (XXE) into the parsed output', function () {
    // If entity substitution were enabled, <loc> would contain this
    // process's own /etc/passwd contents instead of the literal entity
    // reference - that's the vulnerability this test guards against.
    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE urlset [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
    <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
      <url><loc>https://www.blake-uk.com/&xxe;</loc></url>
    </urlset>
    XML;

    $result = \Sitemap\Parser::parse($xml);
    if ($result['type'] === 'urlset' && count($result['entries']) === 1) {
        assert_false(str_contains($result['entries'][0]['loc'], 'root:'), 'external entity should not have been expanded into <loc>');
    } else {
        // libxml refusing to parse the DOCTYPE at all is an equally
        // acceptable safe outcome.
        assert_equal('error', $result['type']);
    }
});

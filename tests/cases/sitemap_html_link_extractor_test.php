<?php
// tests/cases/sitemap_html_link_extractor_test.php

declare(strict_types=1);

suite('Sitemap\HtmlLinkExtractor — extractLinks');

test('extracts same-host links and resolves relative hrefs', function () {
    $html = '<html><body>'
        . '<a href="/page-a">A</a>'
        . '<a href="page-b">B</a>'
        . '<a href="https://www.solwise.co.uk/page-c">C</a>'
        . '</body></html>';
    $entries = \Sitemap\HtmlLinkExtractor::extractLinks($html, 'https://www.solwise.co.uk/sitemap.htm');
    $locs = array_column($entries, 'loc');
    assert_contains('https://www.solwise.co.uk/page-a', $locs);
    assert_contains('https://www.solwise.co.uk/page-b', $locs);
    assert_contains('https://www.solwise.co.uk/page-c', $locs);
});

test('excludes links to a different host', function () {
    $html = '<a href="https://www.solwise.co.uk/page-a">A</a><a href="https://example.com/external">External</a>';
    $entries = \Sitemap\HtmlLinkExtractor::extractLinks($html, 'https://www.solwise.co.uk/sitemap.htm');
    $locs = array_column($entries, 'loc');
    assert_contains('https://www.solwise.co.uk/page-a', $locs);
    assert_not_contains('https://example.com/external', $locs);
});

test('excludes fragment-only, mailto, tel and javascript links', function () {
    $html = '<a href="#top">Top</a><a href="mailto:x@example.com">Mail</a>'
        . '<a href="tel:+441234567890">Tel</a><a href="javascript:void(0)">JS</a>';
    $entries = \Sitemap\HtmlLinkExtractor::extractLinks($html, 'https://www.solwise.co.uk/sitemap.htm');
    assert_count(0, $entries);
});

test('deduplicates links that resolve to the same normalized URL', function () {
    $html = '<a href="/page-a">A</a><a href="/page-a/">A again</a><a href="https://www.solwise.co.uk/page-a">A yet again</a>';
    $entries = \Sitemap\HtmlLinkExtractor::extractLinks($html, 'https://www.solwise.co.uk/sitemap.htm');
    assert_count(1, $entries);
});

test('returns an empty array for a page with no links', function () {
    $entries = \Sitemap\HtmlLinkExtractor::extractLinks('<html><body>No links here.</body></html>', 'https://www.solwise.co.uk/sitemap.htm');
    assert_count(0, $entries);
});

test('returns an empty array for blank input', function () {
    assert_count(0, \Sitemap\HtmlLinkExtractor::extractLinks('', 'https://www.solwise.co.uk/sitemap.htm'));
});

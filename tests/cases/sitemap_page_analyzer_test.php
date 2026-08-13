<?php
// tests/cases/sitemap_page_analyzer_test.php
// Regression tests for Sitemap\PageAnalyzer - pure HTML-in, array-out
// analysis used by public/check/probe.php.

declare(strict_types=1);

suite('Sitemap\PageAnalyzer — analyze');

test('extracts title, meta description, canonical, and lang', function () {
    $html = '<html lang="en-GB"><head><title>Widgets | Blake UK</title>'
        . '<meta name="description" content="Buy widgets from Blake UK.">'
        . '<link rel="canonical" href="https://www.blake-uk.com/widgets">'
        . '</head><body><h1>Widgets</h1></body></html>';

    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/widgets');
    assert_equal('Widgets | Blake UK', $r['title']);
    assert_equal('Buy widgets from Blake UK.', $r['meta_description']);
    assert_equal('https://www.blake-uk.com/widgets', $r['canonical']);
    assert_false($r['canonical_mismatch']);
    assert_equal('en-GB', $r['lang']);
    assert_equal(1, $r['h1_count']);
});

test('flags a canonical that points at a different page', function () {
    $html = '<html><head><title>x</title><link rel="canonical" href="https://www.blake-uk.com/other-page"></head><body></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/this-page');
    assert_true($r['canonical_mismatch']);
});

test('does not flag a canonical that only differs by trailing slash', function () {
    $html = '<html><head><title>x</title><link rel="canonical" href="https://www.blake-uk.com/this-page/"></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/this-page');
    assert_false($r['canonical_mismatch']);
});

test('detects a noindex robots meta tag', function () {
    $html = '<html><head><meta name="robots" content="noindex, follow"></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['noindex_meta']);
});

test('does not flag noindex when robots meta only says index,follow', function () {
    $html = '<html><head><meta name="robots" content="index, follow"></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_false($r['noindex_meta']);
});

test('counts multiple H1 tags', function () {
    $html = '<html><body><h1>One</h1><h1>Two</h1></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_equal(2, $r['h1_count']);
});

test('flags a soft-404 style page that answers with a normal 200', function () {
    $html = '<html><head><title>Page Not Found | Blake UK</title></head>'
        . '<body><h1>Sorry, we can\'t find that page</h1></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/gone');
    assert_true($r['soft_404']);
});

test('does not flag an ordinary page as a soft 404', function () {
    $html = '<html><head><title>Contact Us | Blake UK</title></head><body><h1>Get in touch</h1><p>Call us today.</p></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/contact');
    assert_false($r['soft_404']);
});

test('flags mixed content: an http:// script on an https page', function () {
    $html = '<html><head><script src="http://insecure.example.com/a.js"></script></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['mixed_content']);
});

test('does not flag a plain http:// anchor link as mixed content', function () {
    $html = '<html><body><a href="http://external-partner.example.com">Partner site</a></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_false($r['mixed_content']);
});

test('returns the empty shape for a blank body without erroring', function () {
    $r = \Sitemap\PageAnalyzer::analyze('', 'https://www.blake-uk.com/x');
    assert_null($r['title']);
    assert_false($r['noindex_meta']);
    assert_equal(0, $r['h1_count']);
});

test('empty() matches analyze() on blank input', function () {
    assert_equal(\Sitemap\PageAnalyzer::empty(), \Sitemap\PageAnalyzer::analyze('', 'https://www.blake-uk.com/x'));
});

test('collects absolute image URLs, resolving relative src', function () {
    $html = '<html><body><img src="/a.jpg"><img src="https://cdn.example.com/b.jpg"></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_contains('https://www.blake-uk.com/a.jpg', $r['image_urls']);
    assert_contains('https://cdn.example.com/b.jpg', $r['image_urls']);
});

test('collects both internal and external links', function () {
    $html = '<a href="/internal">In</a><a href="https://partner.example.com/page">Out</a>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_contains('https://www.blake-uk.com/internal', $r['links']);
    assert_contains('https://partner.example.com/page', $r['links']);
});

test('excludes fragment-only, mailto, and javascript links from the link list', function () {
    $html = '<a href="#top">Top</a><a href="mailto:x@example.com">Mail</a><a href="javascript:void(0)">JS</a>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_count(0, $r['links']);
});

test('deduplicates repeated links and images', function () {
    $html = '<a href="/x">1</a><a href="/x">2</a><img src="/y.jpg"><img src="/y.jpg">';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_count(1, $r['links']);
    assert_count(1, $r['image_urls']);
});

test('extracts a JSON-LD @type', function () {
    $html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Widget"}</script></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['has_structured_data']);
    assert_contains('Product', $r['structured_data_types']);
});

test('extracts JSON-LD types nested inside @graph', function () {
    $html = '<html><head><script type="application/ld+json">{"@graph":[{"@type":"Organization"},{"@type":"WebSite"}]}</script></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_contains('Organization', $r['structured_data_types']);
    assert_contains('WebSite', $r['structured_data_types']);
});

test('does not error on invalid JSON-LD', function () {
    $html = '<html><head><script type="application/ld+json">{not valid json</script></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_false($r['has_structured_data']);
});

test('detects Open Graph and Twitter Card tags independently', function () {
    $html = '<html><head><meta property="og:title" content="x"></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['has_open_graph']);
    assert_false($r['has_twitter_card']);
});

test('collects hreflang values', function () {
    $html = '<html><head><link rel="alternate" hreflang="en-GB" href="https://www.blake-uk.com/x">'
        . '<link rel="alternate" hreflang="en-US" href="https://www.blake-uk.com/us/x"></head></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_equal(2, $r['hreflang_count']);
    assert_contains('en-GB', $r['hreflang_langs']);
});

test('counts images missing an alt attribute', function () {
    $html = '<html><body><img src="a.jpg" alt="A widget"><img src="b.jpg"><img src="c.jpg" alt=""></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_equal(3, $r['images_total']);
    // alt="" (empty) is a deliberate "decorative image" marker, not
    // missing - only img.b has no alt attribute at all.
    assert_equal(1, $r['images_missing_alt']);
});

test('counts visible words', function () {
    $html = '<html><body><p>One two three four five</p></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_equal(5, $r['word_count']);
});

test('does not count <script> or <style> text as visible words', function () {
    $html = '<html><head><script>' . str_repeat('var x = 1; ', 100) . '</script>'
        . '<style>' . str_repeat('.a{color:red} ', 100) . '</style></head>'
        . '<body><p>One two three four five</p></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_equal(5, $r['word_count']);
});

test('a script/style-heavy but text-light page is still flagged as JS-dependent', function () {
    $html = '<html><head><script>' . str_repeat('var x = 1; ', 300) . '</script></head>'
        . '<body><p>Loading...</p></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['js_dependent']);
});

test('flags a skipped heading level', function () {
    $html = '<html><body><h1>Title</h1><h4>Too deep too soon</h4></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['heading_skips']);
});

test('does not flag normal sequential heading levels', function () {
    $html = '<html><body><h1>Title</h1><h2>Section</h2><h3>Subsection</h3></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_false($r['heading_skips']);
});

test('flags a likely JS-rendered shell (little text, lots of markup)', function () {
    $html = '<html><head><script src="app.js"></script></head><body><div id="root"></div>'
        . str_repeat('<div class="x"></div>', 200) . '</body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_true($r['js_dependent']);
});

test('does not flag a normal content-heavy page as JS-dependent', function () {
    $html = '<html><body><p>' . str_repeat('word ', 100) . '</p></body></html>';
    $r = \Sitemap\PageAnalyzer::analyze($html, 'https://www.blake-uk.com/x');
    assert_false($r['js_dependent']);
});

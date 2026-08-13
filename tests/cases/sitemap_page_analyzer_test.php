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

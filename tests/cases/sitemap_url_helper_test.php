<?php
// tests/cases/sitemap_url_helper_test.php
// Regression tests for Sitemap\UrlHelper - resolving redirect Location
// headers against the URL that produced them, and normalizing URLs for
// "same page" comparisons.

declare(strict_types=1);

suite('Sitemap\UrlHelper — resolve');

test('leaves an already-absolute Location untouched', function () {
    $r = \Sitemap\UrlHelper::resolve('https://www.blake-uk.com/old', 'https://www.blake-uk.com/new');
    assert_equal('https://www.blake-uk.com/new', $r);
});

test('resolves a root-relative Location against the base host', function () {
    $r = \Sitemap\UrlHelper::resolve('https://www.blake-uk.com/blog/post-1', '/new-location');
    assert_equal('https://www.blake-uk.com/new-location', $r);
});

test('resolves a path-relative Location against the base directory', function () {
    $r = \Sitemap\UrlHelper::resolve('https://www.blake-uk.com/blog/post-1', 'post-2');
    assert_equal('https://www.blake-uk.com/blog/post-2', $r);
});

test('resolves a protocol-relative Location using the base scheme', function () {
    $r = \Sitemap\UrlHelper::resolve('https://www.blake-uk.com/x', '//cdn.example.com/y');
    assert_equal('https://cdn.example.com/y', $r);
});

test('collapses .. segments in a relative Location', function () {
    $r = \Sitemap\UrlHelper::resolve('https://www.blake-uk.com/a/b/c', '../d');
    assert_equal('https://www.blake-uk.com/a/d', $r);
});

suite('Sitemap\UrlHelper — normalize');

test('treats http and https as the same page', function () {
    assert_equal(\Sitemap\UrlHelper::normalize('http://www.blake-uk.com/page'), \Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page'));
});

test('treats a trailing slash as the same page', function () {
    assert_equal(\Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page'), \Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page/'));
});

test('strips a fragment before comparing', function () {
    assert_equal(\Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page'), \Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page#section'));
});

test('treats different paths as different pages', function () {
    $a = \Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page-a');
    $b = \Sitemap\UrlHelper::normalize('https://www.blake-uk.com/page-b');
    assert_false($a === $b);
});

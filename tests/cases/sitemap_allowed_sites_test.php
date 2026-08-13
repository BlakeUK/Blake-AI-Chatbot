<?php
// tests/cases/sitemap_allowed_sites_test.php

declare(strict_types=1);

suite('Sitemap\AllowedSites');

test('allows apex and www for every configured site', function () {
    assert_true(\Sitemap\AllowedSites::isHostAllowed('blake-uk.com'));
    assert_true(\Sitemap\AllowedSites::isHostAllowed('www.blake-uk.com'));
    assert_true(\Sitemap\AllowedSites::isHostAllowed('visionplus.co.uk'));
    assert_true(\Sitemap\AllowedSites::isHostAllowed('www.visionplus.co.uk'));
    assert_true(\Sitemap\AllowedSites::isHostAllowed('solwise.co.uk'));
    assert_true(\Sitemap\AllowedSites::isHostAllowed('www.solwise.co.uk'));
});

test('host matching is case-insensitive', function () {
    assert_true(\Sitemap\AllowedSites::isHostAllowed('WWW.Blake-UK.com'));
});

test('rejects a host not on the list', function () {
    assert_false(\Sitemap\AllowedSites::isHostAllowed('example.com'));
});

test('rejects a null/empty host', function () {
    assert_false(\Sitemap\AllowedSites::isHostAllowed(null));
    assert_false(\Sitemap\AllowedSites::isHostAllowed(''));
});

test('isUrlAllowed derives the host from a full URL', function () {
    assert_true(\Sitemap\AllowedSites::isUrlAllowed('https://www.solwise.co.uk/sitemap.htm'));
    assert_false(\Sitemap\AllowedSites::isUrlAllowed('https://evil.example.com/sitemap.xml'));
});

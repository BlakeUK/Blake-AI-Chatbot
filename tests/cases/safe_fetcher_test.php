<?php
// tests/cases/safe_fetcher_test.php
// Regression tests for Http\SafeFetcher's SSRF guard. Uses IP literals
// throughout (not hostnames) so these stay fast and deterministic - an IP
// literal is validated directly with no DNS lookup involved, unlike a
// hostname, which this suite deliberately doesn't exercise (that would
// need real network access or a mocked resolver).

declare(strict_types=1);

suite('Http\SafeFetcher — assertPublicUrl');

test('accepts a URL whose host is a public IP literal', function () {
    // 8.8.8.8 (Google public DNS) - a real, definitely-public address that
    // needs no network access to classify as such.
    \Http\SafeFetcher::assertPublicUrl('http://8.8.8.8/sitemap.xml');
    assert_true(true); // reaching here means it didn't throw
});

test('rejects a loopback address', function () {
    $threw = false;
    try {
        \Http\SafeFetcher::assertPublicUrl('http://127.0.0.1/');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected loopback address to be rejected');
});

test('rejects the cloud metadata link-local address', function () {
    $threw = false;
    try {
        \Http\SafeFetcher::assertPublicUrl('http://169.254.169.254/latest/meta-data/');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected 169.254.169.254 to be rejected');
});

test('rejects an RFC1918 private address', function () {
    $threw = false;
    try {
        \Http\SafeFetcher::assertPublicUrl('http://10.0.0.5/internal');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected a 10.0.0.0/8 address to be rejected');
});

test('rejects an IPv6 loopback address', function () {
    $threw = false;
    try {
        \Http\SafeFetcher::assertPublicUrl('http://[::1]/');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected ::1 to be rejected');
});

test('rejects a non-http(s) scheme', function () {
    $threw = false;
    try {
        \Http\SafeFetcher::assertPublicUrl('file:///etc/passwd');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected a file:// URL to be rejected');
});

test('get() surfaces the block as a failed fetch rather than throwing', function () {
    $result = \Http\SafeFetcher::get('http://127.0.0.1/');
    assert_false($result['ok']);
    assert_false($result['body']);
});

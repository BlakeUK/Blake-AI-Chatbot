<?php
// tests/cases/sitemap_robots_txt_test.php
// Regression tests for Sitemap\RobotsTxt - the bot/AI-access parser
// backing public/check/robots.php. Pure text-in, structure-out, so these
// run against fixture robots.txt strings with no network involved.

declare(strict_types=1);

suite('Sitemap\RobotsTxt — parse');

test('parses a single wildcard group with disallow rules', function () {
    $txt = "User-agent: *\nDisallow: /admin/\nDisallow: /cart\n\nSitemap: https://www.blake-uk.com/sitemap.xml";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_count(1, $r['groups']);
    assert_equal(['*'], $r['groups'][0]['agents']);
    assert_count(2, $r['groups'][0]['rules']);
    assert_equal(['https://www.blake-uk.com/sitemap.xml'], $r['sitemaps']);
});

test('groups consecutive User-agent lines together', function () {
    $txt = "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_count(1, $r['groups']);
    assert_equal(['GPTBot', 'CCBot'], $r['groups'][0]['agents']);
});

test('starts a new group when User-agent reappears after rules', function () {
    $txt = "User-agent: *\nDisallow: /private/\n\nUser-agent: GPTBot\nDisallow: /\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_count(2, $r['groups']);
    assert_equal(['*'], $r['groups'][0]['agents']);
    assert_equal(['GPTBot'], $r['groups'][1]['agents']);
});

test('ignores comments and blank lines', function () {
    $txt = "# this is a comment\nUser-agent: *\n\n# another\nDisallow: /x\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_count(1, $r['groups']);
    assert_count(1, $r['groups'][0]['rules']);
});

suite('Sitemap\RobotsTxt — isAllowed');

test('allows everything when robots.txt has no matching rule', function () {
    $r = \Sitemap\RobotsTxt::parse("User-agent: *\nDisallow:\n");
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/anything'));
});

test('a bare Disallow: / blocks the wildcard group', function () {
    $r = \Sitemap\RobotsTxt::parse("User-agent: *\nDisallow: /\n");
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/'));
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'AnyRandomBot', '/some/page'));
});

test('an explicitly named bot uses its own group instead of the wildcard', function () {
    $txt = "User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/'));
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'GPTBot', '/'));
});

test('an unnamed bot with no wildcard group is allowed by default', function () {
    $r = \Sitemap\RobotsTxt::parse("User-agent: Googlebot\nDisallow: /\n");
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'ClaudeBot', '/'));
});

test('longest matching path wins over a shorter one', function () {
    $txt = "User-agent: *\nDisallow: /blog/\nAllow: /blog/public-post\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/blog/private-post'));
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/blog/public-post'));
});

test('a tie between Allow and Disallow of equal length favours Allow', function () {
    $txt = "User-agent: *\nDisallow: /x\nAllow: /x\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/x'));
});

test('supports a wildcard within a path pattern', function () {
    $txt = "User-agent: *\nDisallow: /*.pdf\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/downloads/file.pdf'));
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/downloads/file.html'));
});

test('supports the $ end-of-path anchor', function () {
    $txt = "User-agent: *\nDisallow: /page\$\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/page'));
    assert_true(\Sitemap\RobotsTxt::isAllowed($r, 'Googlebot', '/page-two'));
});

test('bot name matching is case-insensitive', function () {
    $txt = "User-agent: gptbot\nDisallow: /\n";
    $r = \Sitemap\RobotsTxt::parse($txt);
    assert_false(\Sitemap\RobotsTxt::isAllowed($r, 'GPTBot', '/'));
});

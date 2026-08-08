<?php
// tests/cases/keyword_links_test.php
// Regression tests for src/Knowledge/KeywordLinks.php and its wiring into
// Chat\Responder - the deterministic word/phrase -> page pin feature
// (public/api/admin/keyword_links.php manages the data these tests seed
// directly, bypassing the API since that's just plain CRUD around the
// same table).

declare(strict_types=1);

suite('Knowledge\KeywordLinks — matching');

test('matches a single-word keyword inside a longer message', function () {
    $hits = \Knowledge\KeywordLinks::match('What is your warranty on this?');
    assert_contains('Warranty Policy', array_column($hits, 'title'));
});

test('matches a multi-word phrase keyword', function () {
    $hits = \Knowledge\KeywordLinks::match('I would like to book an installation please');
    assert_contains('Installation Booking', array_column($hits, 'title'));
});

test('matches case-insensitively', function () {
    $hits = \Knowledge\KeywordLinks::match('WARRANTY info please');
    assert_contains('Warranty Policy', array_column($hits, 'title'));
});

test('does not match a keyword that is only a substring of a different word', function () {
    // "warranty" must not match inside "warrantying" or similar - word
    // boundaries, not substring search.
    $hits = \Knowledge\KeywordLinks::match('The warrantying process for these differs by brand.');
    assert_not_contains('Warranty Policy', array_column($hits, 'title'));
});

test('a row can have several keyword aliases, any one of which matches', function () {
    $hits = \Knowledge\KeywordLinks::match('Do you offer a guarantee on parts?');
    assert_contains('Warranty Policy', array_column($hits, 'title'));
});

test('returns an empty array when nothing matches', function () {
    assert_equal([], \Knowledge\KeywordLinks::match('What time do you open on Saturdays?'));
});

test('returns an empty array for an empty message', function () {
    assert_equal([], \Knowledge\KeywordLinks::match(''));
});

test('an inactive row is never matched', function () {
    db()->prepare('INSERT INTO keyword_links (id, keywords, title, url, active) VALUES (?,?,?,?,0)')
        ->execute([8099, json_encode(['zorbing']), 'Inactive Zorbing Page', 'https://example.com/zorbing']);

    $hits = \Knowledge\KeywordLinks::match('Tell me about zorbing');
    assert_not_contains('Inactive Zorbing Page', array_column($hits, 'title'));

    db()->exec('DELETE FROM keyword_links WHERE id = 8099');
});

suite('Chat\Responder — keyword link integration');

test('buildContext includes matched keyword links', function () {
    $ctx = \Chat\Responder::buildContext('what is the warranty like', null);
    assert_contains('Warranty Policy', array_column($ctx['keyword_links'], 'title'));
});

test('buildPrompt surfaces a matched keyword link under RELEVANT PAGES with its exact URL', function () {
    $ctx    = \Chat\Responder::buildContext('what is the warranty like', null);
    $prompt = \Chat\Responder::buildPrompt($ctx, null, null);
    assert_str_contains('RELEVANT PAGES:', $prompt);
    assert_str_contains('https://www.blake-uk.com/support/warranty', $prompt);
});

test('a keyword link match alone is enough to raise confidence, even with no organic hits', function () {
    // "flibbertigibbetry" isn't in any fixture knowledge/product content
    // (unlike "zorbing", reused as a product name by importer_test.php in
    // the same shared fixture DB), so this isolates the keyword-link
    // contribution specifically.
    db()->prepare('INSERT INTO keyword_links (id, keywords, title, url, active) VALUES (?,?,?,?,1)')
        ->execute([8098, json_encode(['flibbertigibbetry']), 'Flibbertigibbetry Info', 'https://example.com/flibbertigibbetry']);

    $ctx = \Chat\Responder::buildContext('do you know anything about flibbertigibbetry', null);
    assert_count(0, $ctx['knowledge_hits']);
    assert_count(0, $ctx['product_hits']);
    assert_equal(0.75, \Chat\Responder::confidence($ctx['knowledge_hits'], $ctx['product_hits'], $ctx['keyword_links']));

    db()->exec('DELETE FROM keyword_links WHERE id = 8098');
});

test('confidence() without a keyword_link_hits argument still works (backwards compatible)', function () {
    assert_equal(0.3, \Chat\Responder::confidence([], []));
});

<?php
// tests/cases/search_test.php
// Regression tests for the retrieval layer (src/Knowledge/Search.php).
// These are the ones most likely to break silently: a change to
// sanitiseFts(), the FTS5 queries, or the ranking logic won't throw an
// error, it'll just quietly start returning worse (or no) results.

declare(strict_types=1);

suite('Knowledge\Search — retrieval');

test('sanitiseFts does not throw on FTS5 reserved words', function () {
    // "AND"/"OR"/"NOT"/"NEAR" are valid identifiers here but FTS5 operators
    // in a query - this must not surface as a syntax error to the customer.
    $hits = \Knowledge\Search::query('cable AND connector', 5);
    assert_true(is_array($hits));
});

test('sanitiseFts does not throw on a trailing/standalone hyphen', function () {
    $hits = \Knowledge\Search::query('test --', 5);
    assert_true(is_array($hits));
});

test('sanitiseFts strips punctuation but keeps words quoted individually', function () {
    $clean = \Knowledge\Search::sanitiseFts('returns policy?!');
    assert_equal('"returns" "policy"', $clean);
});

test('sanitiseFts returns empty string for a query with no usable words', function () {
    assert_equal('', \Knowledge\Search::sanitiseFts('???...'));
});

test('query() finds the correct chunk for a returns question', function () {
    $hits = \Knowledge\Search::query('what is your returns policy', 5);
    assert_true(count($hits) > 0, 'expected at least one hit');
    assert_equal(9001, (int)$hits[0]['id']);
});

test('query() finds the correct chunk for a delivery question', function () {
    $hits = \Knowledge\Search::query('how long does delivery take', 5);
    assert_true(count($hits) > 0, 'expected at least one hit');
    assert_equal(9004, (int)$hits[0]['id']);
});

test('query() returns an empty array (not an error) for an unmatched query', function () {
    assert_equal([], \Knowledge\Search::query('zzxxqqjjbbnn nonexistent gibberish', 5));
});

test('query() matches on content words alone, not requiring every filler word too', function () {
    // Regression guard: FTS5's default query syntax ANDs space-separated
    // terms together. A naive sanitiser would require the chunk to contain
    // "what"/"is"/"your" literally, which real KB prose almost never does -
    // this would silently fail on ordinary phrasing and wrongly escalate.
    $hits = \Knowledge\Search::query('what is your policy on returns please', 5);
    assert_true(count($hits) > 0, 'expected a hit even with filler words in the question');
    assert_equal(9001, (int)$hits[0]['id']);
});

test('query() still finds nothing for a genuinely unrelated question, even with common words', function () {
    // Guards against the OR-matching fix above becoming too permissive -
    // stopword filtering must still leave enough specificity that an
    // off-topic question doesn't spuriously match everything.
    assert_equal([], \Knowledge\Search::query('do you sell bicycles or garden furniture', 5));
});

test('query() respects the limit parameter', function () {
    $hits = \Knowledge\Search::query('the a is for', 2); // common words, should match broadly
    assert_true(count($hits) <= 2);
});

test('products() finds a product by search term', function () {
    $hits = \Knowledge\Search::products('coaxial cable', 5);
    $codes = array_column($hits, 'product_code');
    assert_contains('BLA-CBL-001', $codes);
});

test('products() finds a product by name text', function () {
    $hits = \Knowledge\Search::products('CCTV camera', 5);
    $codes = array_column($hits, 'product_code');
    assert_contains('BLA-CCTV-100', $codes);
});

test('byCode() returns the matching active product', function () {
    $p = \Knowledge\Search::byCode('BLA-CBL-001');
    assert_true($p !== null);
    assert_equal('Coaxial Cable 25m', $p['name']);
});

test('byCode() returns null for an unknown code', function () {
    assert_null(\Knowledge\Search::byCode('DOES-NOT-EXIST'));
});

test('byCode() returns null for an empty code', function () {
    assert_null(\Knowledge\Search::byCode(''));
});

test('byCodes() preserves the requested order, not DB/rank order', function () {
    $rows = \Knowledge\Search::byCodes(['BLA-CCTV-100', 'BLA-CBL-001'], 5);
    assert_equal('BLA-CCTV-100', $rows[0]['product_code']);
    assert_equal('BLA-CBL-001', $rows[1]['product_code']);
});

test('byCodes() silently skips codes that do not exist', function () {
    $rows = \Knowledge\Search::byCodes(['BLA-CBL-001', 'GHOST-CODE'], 5);
    assert_count(1, $rows);
    assert_equal('BLA-CBL-001', $rows[0]['product_code']);
});

test('byCodes() respects the limit even with more codes given', function () {
    $rows = \Knowledge\Search::byCodes(['BLA-CBL-001', 'BLA-CON-002', 'BLA-CBL-003'], 2);
    assert_count(2, $rows);
});

test('withCurrentFirst() puts the current product first and removes any organic duplicate', function () {
    $current = \Knowledge\Search::byCode('BLA-CBL-003');
    $organic = \Knowledge\Search::byCodes(['BLA-CBL-001', 'BLA-CBL-003'], 5); // includes a duplicate of current
    $merged  = \Knowledge\Search::withCurrentFirst($organic, $current);

    assert_equal('BLA-CBL-003', $merged[0]['product_code']);
    $codes = array_column($merged, 'product_code');
    assert_count(1, array_keys($codes, 'BLA-CBL-003', true));
});

test('withCurrentFirst() is a no-op when there is no current product', function () {
    $organic = \Knowledge\Search::byCodes(['BLA-CBL-001'], 5);
    assert_equal($organic, \Knowledge\Search::withCurrentFirst($organic, null));
});

test('addRelated() does not duplicate a product already present', function () {
    $hits    = \Knowledge\Search::byCodes(['BLA-CBL-001'], 5);
    $related = \Knowledge\Search::byCodes(['BLA-CBL-001', 'BLA-CON-002'], 5);
    $merged  = \Knowledge\Search::addRelated($hits, $related);

    $codes = array_column($merged, 'product_code');
    assert_count(1, array_keys($codes, 'BLA-CBL-001', true));
    assert_contains('BLA-CON-002', $codes);
});

test('formatForPrompt() tags the currently-viewed product', function () {
    $p    = \Knowledge\Search::byCode('BLA-CBL-001');
    $line = \Knowledge\Search::formatForPrompt($p, 'BLA-CBL-001', [], []);
    assert_str_contains('[Customer is currently viewing this product]', $line);
});

test('formatForPrompt() tags a related product', function () {
    $p    = \Knowledge\Search::byCode('BLA-CON-002');
    $line = \Knowledge\Search::formatForPrompt($p, 'BLA-CBL-001', ['BLA-CON-002'], []);
    assert_str_contains('[Related product', $line);
});

test('formatForPrompt() tags an alternative product, not related, when a code is in both roles is ambiguous', function () {
    $p    = \Knowledge\Search::byCode('BLA-CBL-003');
    $line = \Knowledge\Search::formatForPrompt($p, 'BLA-CBL-001', [], ['BLA-CBL-003']);
    assert_str_contains('[Alternative product', $line);
});

test('formatForPrompt() leaves an unrelated product untagged', function () {
    $p    = \Knowledge\Search::byCode('BLA-CCTV-100');
    $line = \Knowledge\Search::formatForPrompt($p, 'BLA-CBL-001', [], []);
    assert_false(str_contains($line, '['));
});

test('formatForPrompt() includes price and stock status', function () {
    $p    = \Knowledge\Search::byCode('BLA-CBL-001');
    $line = \Knowledge\Search::formatForPrompt($p, null, [], []);
    assert_str_contains('£12.99', $line);
    assert_str_contains('in_stock', $line);
});

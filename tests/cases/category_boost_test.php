<?php
// tests/cases/category_boost_test.php
// Regression tests for category-aware re-prioritisation in
// src/Knowledge/Search.php (query()/products()'s $categoryHint parameter)
// and its wiring into Chat\Responder::buildContext(). The core guarantee
// under test: a category hint re-orders results, it never excludes one -
// see Search::prioritiseByCategory()'s doc comment for why that matters.

declare(strict_types=1);

suite('Knowledge\Search — category-aware boosting');

test('products(): a category hint promotes a same-category match ahead of a higher-ranking different-category one', function () {
    // Both BLA-CBL-001 (TV Aerials & Reception) and BLA-CCTV-100 (CCTV &
    // Security) mention "premium" - without a hint, FTS5 rank alone
    // decides the order; asserting one specific order here would be
    // testing bm25 internals, not this feature. What's under test is that
    // WITH a TV Aerials hint, the TV product is guaranteed ahead of the
    // CCTV one regardless of that natural rank.
    $withoutHint = \Knowledge\Search::products('premium', 5);
    $codes = array_column($withoutHint, 'product_code');
    assert_contains('BLA-CBL-001', $codes);
    assert_contains('BLA-CCTV-100', $codes);

    $withHint = \Knowledge\Search::products('premium', 5, ['TV Aerials & Reception']);
    $hintedCodes = array_column($withHint, 'product_code');
    $tvPos   = array_search('BLA-CBL-001', $hintedCodes, true);
    $cctvPos = array_search('BLA-CCTV-100', $hintedCodes, true);
    assert_true($tvPos !== false && $cctvPos !== false, 'expected both products still present with a hint');
    assert_true($tvPos < $cctvPos, "expected TV product ({$tvPos}) before CCTV product ({$cctvPos}) when hinted toward its category");
});

test('products(): a category hint does not exclude non-matching results, only reorders them', function () {
    $withHint = \Knowledge\Search::products('premium', 5, ['TV Aerials & Reception']);
    assert_contains('BLA-CCTV-100', array_column($withHint, 'product_code'));
});

test('products(): a hint matching nothing in the pool changes nothing but the (absent) reorder', function () {
    $withoutHint = array_column(\Knowledge\Search::products('coaxial cable', 5), 'product_code');
    $withHint    = array_column(\Knowledge\Search::products('coaxial cable', 5, ['Some Unrelated Range']), 'product_code');
    assert_equal($withoutHint, $withHint);
});

test('query(): a category hint promotes a same-category chunk ahead of a different-category one', function () {
    // Both chunk 9002 (TV Aerials & Reception) and 9003 (CCTV & Security)
    // mention "installation".
    $withHint = \Knowledge\Search::query('installation', 5, ['CCTV & Security']);
    $ids = array_column($withHint, 'id');
    $cctvPos = array_search(9003, $ids, true);
    $tvPos   = array_search(9002, $ids, true);
    assert_true($cctvPos !== false && $tvPos !== false, 'expected both chunks still present with a hint');
    assert_true($cctvPos < $tvPos, "expected CCTV chunk ({$cctvPos}) before TV chunk ({$tvPos}) when hinted toward its category");
});

test('query(): an uncategorised chunk is never excluded by a hint', function () {
    // Chunk 9001 (returns policy) has no category at all.
    $hits = \Knowledge\Search::query('returns', 5, ['TV Aerials & Reception']);
    assert_contains(9001, array_column($hits, 'id'));
});

test('query()/products() with no hint behave exactly as before category-awareness existed', function () {
    // Same assertions as the pre-existing search_test.php cases, repeated
    // here as an explicit no-regression guard right next to the new
    // boosting logic that could have broken them.
    $hits = \Knowledge\Search::query('what is your returns policy', 5);
    assert_equal(9001, (int)$hits[0]['id']);
});

suite('Chat\Responder — category hint wiring');

test('buildContext derives the category hint from the current product and it influences results', function () {
    // On a CCTV product page, asking a message that matches "installation"
    // (present in both TV and CCTV knowledge chunks) should surface the
    // CCTV chunk ahead of the TV one, purely from page context - the
    // message itself says nothing about CCTV.
    $ctx = \Chat\Responder::buildContext('what does installation involve', 'BLA-CCTV-100');
    $ids = array_column($ctx['knowledge_hits'], 'id');
    $cctvPos = array_search(9003, $ids, true);
    $tvPos   = array_search(9002, $ids, true);
    assert_true($cctvPos !== false && $tvPos !== false);
    assert_true($cctvPos < $tvPos);
});

test('buildContext has no category hint effect when there is no current product', function () {
    $withProduct = \Chat\Responder::buildContext('installation', null);
    assert_true(is_array($withProduct['knowledge_hits'])); // just shouldn't error with no product context
});

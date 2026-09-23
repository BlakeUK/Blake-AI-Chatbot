<?php
// tests/cases/terms_test.php - trade terminology: customer words -> our words.
declare(strict_types=1);

suite('Knowledge\Terms — trade terminology');

function terms_seed(): void
{
    db()->exec('DELETE FROM term_aliases');
    \Knowledge\Terms::save(['term' => 'External cable entry cover (brick cover)', 'aliases' => 'roman nose, blast plate, hole tidy',
        'search_terms' => 'cable entry cover, brick cover', 'note' => 'Covers brick damage where cable passes through a wall', 'department' => 'sales', 'active' => 1]);
    \Knowledge\Terms::save(['term' => 'Amplifier (active, powered)', 'aliases' => 'amp, booster, amplified splitter',
        'search_terms' => 'amplifier, masthead amplifier', 'product_codes' => 'BLATLA11',
        'note' => 'An amplifier is powered and adds gain. A passive splitter (SPL204, SPL408) only divides the signal and loses level', 'department' => 'technical', 'active' => 1]);
    \Knowledge\Terms::save(['term' => 'Passive splitter', 'aliases' => 'splitter, 2 way splitter', 'search_terms' => 'passive splitter',
        'note' => 'Passive: no power, no gain', 'product_codes' => 'SPL204, SPL408', 'department' => 'technical', 'active' => 1]);
}

test('customer words are matched, whole-word only', function () {
    terms_seed();
    assert_equal(['External cable entry cover (brick cover)'], array_column(\Knowledge\Terms::match('do you sell roman nose covers?'), 'term'));
    assert_equal(['Amplifier (active, powered)'], array_column(\Knowledge\Terms::match('I need an amp for my aerial'), 'term'));
    assert_equal([], \Knowledge\Terms::match('do you have any ramps or campers'), 'no partial-word matches');
    assert_equal(2, count(\Knowledge\Terms::match('is an amp the same as a splitter')));
});

test('the search is widened with our own words', function () {
    terms_seed();
    $m = \Knowledge\Terms::match('roman nose');
    $q = \Knowledge\Terms::expand('roman nose', $m);
    assert_str_contains('cable entry cover', $q);
    assert_str_contains('brick cover', $q);
    assert_str_contains('roman nose', $q);
});

test('the prompt explains the term and the amp/splitter difference', function () {
    terms_seed();
    $b = \Knowledge\Terms::promptBlock(\Knowledge\Terms::match('is an amp the same as a splitter'));
    assert_str_contains('TRADE TERMS', $b);
    assert_str_contains('"amp"', $b);
    assert_str_contains('A passive splitter (SPL204, SPL408) only divides the signal and loses level', $b);
    assert_str_contains('SPL204, SPL408', $b);
});

test('an unanswered question goes to the department that owns the term', function () {
    terms_seed();
    assert_equal('technical', \Knowledge\Terms::department(\Knowledge\Terms::match('what amp do I need')));
    assert_equal('sales', \Knowledge\Terms::department(\Knowledge\Terms::match('price for a roman nose')));
    assert_equal(null, \Knowledge\Terms::department([]));
});

test('inactive terms are ignored, and terms can be edited and deleted', function () {
    terms_seed();
    $r = \Knowledge\Terms::save(['term' => 'Test thing', 'aliases' => 'widget', 'active' => 1]);
    assert_true($r['ok']);
    assert_equal(1, count(\Knowledge\Terms::match('do you sell a widget')));
    \Knowledge\Terms::save(['id' => $r['id'], 'term' => 'Test thing', 'aliases' => 'widget', 'active' => 0]);
    assert_equal(0, count(\Knowledge\Terms::match('do you sell a widget')));
    \Knowledge\Terms::delete($r['id']);
    assert_true(!\Knowledge\Terms::save(['term' => '', 'aliases' => ''])['ok']);
});

test('buildContext widens the search and passes the terms to the prompt', function () {
    terms_seed();
    \Knowledge\Embeddings::resetCaches();
    $ctx = \Chat\Responder::buildContext('do you sell roman nose', null, '');
    assert_equal('External cable entry cover (brick cover)', $ctx['terms'][0]['term'] ?? null);
    assert_equal('sales', $ctx['term_department']);
    assert_str_contains('TRADE TERMS', \Chat\Responder::buildPrompt($ctx, null, null));
    db()->exec('DELETE FROM term_aliases');
});

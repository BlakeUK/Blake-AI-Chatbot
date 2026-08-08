<?php
// tests/cases/responder_test.php
// Regression tests for src/Chat/Responder.php - the confidence/escalation
// heuristic and context assembly that decide whether a customer gets an AI
// answer or gets handed to a human. CFG['escalate_threshold'] is 0.4 in the
// test fixture config (tests/bootstrap.php), same as config.example.php.

declare(strict_types=1);

suite('Chat\Responder — confidence & escalation');

test('confidence is high when knowledge chunks matched', function () {
    assert_equal(0.75, \Chat\Responder::confidence([['id' => 1]], []));
});

test('confidence is high when products matched', function () {
    assert_equal(0.75, \Chat\Responder::confidence([], [['product_code' => 'X']]));
});

test('confidence is low when nothing matched', function () {
    assert_equal(0.3, \Chat\Responder::confidence([], []));
});

test('shouldEscalate is false above the configured threshold', function () {
    assert_false(\Chat\Responder::shouldEscalate(0.75));
});

test('shouldEscalate is true below the configured threshold', function () {
    assert_true(\Chat\Responder::shouldEscalate(0.3));
});

suite('Chat\Responder — context assembly');

test('buildContext includes the current product even if the message text does not match it', function () {
    $ctx = \Chat\Responder::buildContext('hello, are you open on bank holidays', 'BLA-CBL-001');
    $codes = array_column($ctx['context_products'], 'product_code');
    assert_contains('BLA-CBL-001', $codes);
});

test('buildContext does NOT count the current-product context toward confidence-relevant product_hits', function () {
    // Message text has nothing to do with cables; only the page context
    // does. product_hits (the confidence signal) must stay empty even
    // though context_products (what's shown/prompted) includes the product.
    $ctx = \Chat\Responder::buildContext('are you open on bank holidays', 'BLA-CBL-001');
    assert_count(0, $ctx['product_hits']);
    assert_true(count($ctx['context_products']) > 0);
    assert_equal(0.3, \Chat\Responder::confidence($ctx['knowledge_hits'], $ctx['product_hits']));
});

test('buildContext pulls in related and alternative products for the current product', function () {
    $ctx = \Chat\Responder::buildContext('anything', 'BLA-CBL-001');
    $codes = array_column($ctx['context_products'], 'product_code');
    assert_contains('BLA-CON-002', $codes);  // related
    assert_contains('BLA-CBL-003', $codes);  // alternative
});

test('buildContext has no current product when none is given', function () {
    $ctx = \Chat\Responder::buildContext('anything', null);
    assert_null($ctx['current_product']);
});

test('buildPrompt includes the knowledge base section when there are hits', function () {
    $ctx    = \Chat\Responder::buildContext('what is your returns policy', null);
    $prompt = \Chat\Responder::buildPrompt($ctx, null, null);
    assert_str_contains('KNOWLEDGE BASE:', $prompt);
    assert_str_contains('30 days', $prompt);
});

test('buildPrompt includes the page-context line when a page_url is given', function () {
    $ctx    = \Chat\Responder::buildContext('anything', null);
    $prompt = \Chat\Responder::buildPrompt($ctx, null, 'https://www.blake-uk.com/products/bla-cbl-001');
    assert_str_contains('Customer is viewing: https://www.blake-uk.com/products/bla-cbl-001', $prompt);
});

test('buildPrompt always carries the "do not invent" and "never make up" guardrails', function () {
    // These two lines are the whole hallucination defence - a regression
    // here is a real, costly bug, not just cosmetic.
    $ctx    = \Chat\Responder::buildContext('anything', null);
    $prompt = \Chat\Responder::buildPrompt($ctx, null, null);
    assert_str_contains('Answer ONLY using the context provided below', $prompt);
    assert_str_contains('Never make up product codes, prices or specifications', $prompt);
});

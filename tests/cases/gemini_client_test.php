<?php
// tests/cases/gemini_client_test.php
// Regression tests for Gemini\Client::extractText() - the response-shape
// validation pulled out of post() specifically so it's testable without a
// live API call. The behaviour under test: a 200 response with no
// candidate text (safety block, unrecognised shape, etc.) must throw
// rather than silently returning '', which previously let chat/send.php
// store and show an empty assistant answer with no error signal anywhere.

declare(strict_types=1);

suite('Gemini\Client::extractText');

test('returns the candidate text when present', function () {
    $data = ['candidates' => [['content' => ['parts' => [['text' => 'Hello there']]]]]];
    assert_equal('Hello there', \Gemini\Client::extractText($data));
});

test('throws when candidates is missing entirely', function () {
    $threw = false;
    try {
        \Gemini\Client::extractText([]);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected a missing candidates array to throw');
});

test('throws and reports the block reason when a response is safety-blocked', function () {
    $data = ['promptFeedback' => ['blockReason' => 'SAFETY']];
    $threw = false;
    try {
        \Gemini\Client::extractText($data);
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_str_contains('SAFETY', $e->getMessage());
    }
    assert_true($threw, 'expected a safety-blocked response to throw');
});

test('throws and reports the finish reason when a candidate has no parts', function () {
    $data = ['candidates' => [['finishReason' => 'RECITATION']]];
    $threw = false;
    try {
        \Gemini\Client::extractText($data);
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_str_contains('RECITATION', $e->getMessage());
    }
    assert_true($threw, 'expected a no-parts candidate to throw');
});

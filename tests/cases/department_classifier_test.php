<?php
// tests/cases/department_classifier_test.php
// Regression test for a bug where classify() called a global getApiKey()
// that only ever existed inside send.php/models.php's own file scope. From
// its real call path (escalate.php), that function was undefined, so every
// classification attempt threw and was silently swallowed by escalate.php's
// try/catch - every ticket landed in Sales as "not confident" regardless of
// what it was actually about. Fixed by calling
// \Gemini\Client::getStoredApiKey() directly, same as every other call site.

declare(strict_types=1);

suite('Chat\DepartmentClassifier::classify');

test('returns the sales fallback for an empty message list, without throwing', function () {
    $result = \Chat\DepartmentClassifier::classify([]);
    assert_equal(['department' => 'sales', 'confident' => false], $result);
});

test('returns the sales fallback (not confident) when no Gemini key is configured, without throwing', function () {
    // The fixture DB never seeds an api_keys row, so this exercises exactly
    // the path that used to hit the undefined-function error: classify()
    // must resolve the key via Gemini\Client and fail closed to the
    // fallback, not throw.
    $result = \Chat\DepartmentClassifier::classify([
        ['role' => 'user', 'content' => 'my router keeps dropping the wifi signal, how do I fix it'],
    ]);
    assert_equal(['department' => 'sales', 'confident' => false], $result);
});

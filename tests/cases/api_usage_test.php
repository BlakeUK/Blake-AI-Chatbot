<?php
// tests/cases/api_usage_test.php
// ApiUsage\Logger (pricing, cost, logging) and ApiUsage\Stats (tab data).

declare(strict_types=1);

suite('ApiUsage — logging, pricing and stats');

test('rateFor(): longest prefix wins, lite keeps its own rate, unknown model is unpriced', function () {
    \ApiUsage\Logger::resetPricingCache();
    assert_equal(['in' => 1.50, 'out' => 7.50], \ApiUsage\Logger::rateFor('gemini-3.6-flash'));
    assert_equal(['in' => 1.50, 'out' => 7.50], \ApiUsage\Logger::rateFor('gemini-3.6-flash-preview-0901'));
    assert_equal(['in' => 0.30, 'out' => 2.50], \ApiUsage\Logger::rateFor('gemini-3.5-flash-lite'));
    assert_equal(null, \ApiUsage\Logger::rateFor('some-unknown-model'));
});

test('geminiCost(): thinking tokens billed at the output rate', function () {
    \ApiUsage\Logger::resetPricingCache();
    // 1M in @1.50 + (0.5M out + 0.5M thinking) @7.50 = 9.00
    assert_equal(9.0, round(\ApiUsage\Logger::geminiCost('gemini-3.6-flash', 1_000_000, 500_000, 500_000), 6));
    assert_equal(0.0, \ApiUsage\Logger::geminiCost('unknown', 1000, 1000, 0));
});

test('pricing(): admin overrides replace defaults and add new models', function () {
    $pdo = db();
    \ApiUsage\Stats::saveSetting($pdo, 'api_pricing', json_encode(['gemini-3.6-flash' => ['in' => 0.75, 'out' => 3.75], 'gemini-9-new' => ['in' => 2, 'out' => 4]]));
    \ApiUsage\Logger::resetPricingCache();
    assert_equal(['in' => 0.75, 'out' => 3.75], \ApiUsage\Logger::rateFor('gemini-3.6-flash'));
    assert_equal(['in' => 2.0, 'out' => 4.0], \ApiUsage\Logger::rateFor('gemini-9-new'));
    $pdo->exec("DELETE FROM settings WHERE key = 'api_pricing'");
    \ApiUsage\Logger::resetPricingCache();
});

test('summary(): totals, per-service/model breakdowns, errors, 429s and budget', function () {
    $pdo = db();
    $pdo->exec('DELETE FROM api_usage_log');
    \ApiUsage\Stats::saveSetting($pdo, 'api_monthly_budget_usd', '10');
    \ApiUsage\Logger::log('gemini', ['operation' => 'chat_answer', 'model' => 'gemini-3.5-flash-lite', 'http_code' => 200, 'ok' => true, 'input_tokens' => 1000, 'output_tokens' => 200, 'cost_usd' => 0.5, 'latency_ms' => 100]);
    \ApiUsage\Logger::log('gemini', ['operation' => 'file_extract', 'model' => 'gemini-3.6-flash', 'http_code' => 429, 'ok' => false, 'error' => 'quota', 'latency_ms' => 300]);
    \ApiUsage\Logger::log('telegram', ['operation' => 'sendMessage', 'http_code' => 200, 'ok' => true]);
    // Outside a 7-day window
    $pdo->exec("INSERT INTO api_usage_log (created_at, service, ok, cost_usd) VALUES (unixepoch() - 20*86400, 'gemini', 1, 99)");

    $s = \ApiUsage\Stats::summary($pdo, 7);
    assert_equal(3, $s['totals']['calls']);
    assert_equal(1, $s['totals']['errors']);
    assert_equal(1, $s['totals']['rate_limited']);
    assert_equal(0.5, round((float)$s['totals']['cost_usd'], 6));
    assert_equal(['gemini', 'telegram'], array_column($s['by_service'], 'service'));
    assert_equal('gemini-3.5-flash-lite', $s['by_model'][0]['model']);
    assert_equal('quota', $s['recent_errors'][0]['error']);
    assert_equal(10.0, $s['month']['budget_usd']);
    assert_true($s['month']['cost_usd'] >= 0.5);
    $pdo->exec('DELETE FROM api_usage_log');
    $pdo->exec("DELETE FROM settings WHERE key = 'api_monthly_budget_usd'");
});

test('log(): never throws, even with the table missing', function () {
    $pdo = db();
    $pdo->exec('ALTER TABLE api_usage_log RENAME TO api_usage_log_tmp');
    \ApiUsage\Logger::log('gemini', ['ok' => true]);
    $pdo->exec('ALTER TABLE api_usage_log_tmp RENAME TO api_usage_log');
    assert_true(true);
});

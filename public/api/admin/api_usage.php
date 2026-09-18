<?php
// public/api/admin/api_usage.php
// GET  ?days=1|7|30|90  - usage, estimated cost, errors and budget for
//                         every external API the system calls.
// POST { csrf, budget_usd?, gbp_rate?, pricing? } - save budget, USD->GBP
//                         display rate and per-model price overrides.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [1, 7, 30, 90], true)) $days = 30;
    \ApiUsage\Logger::prune();
    json_out(\ApiUsage\Stats::summary($pdo, $days));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    \Auth\Admin::verifyCsrf($body['csrf'] ?? '');

    if (isset($body['budget_usd'])) {
        if (!is_numeric($body['budget_usd']) || $body['budget_usd'] < 0) json_err('Budget must be a positive number');
        \ApiUsage\Stats::saveSetting($pdo, 'api_monthly_budget_usd', (string)(float)$body['budget_usd']);
    }
    if (isset($body['gbp_rate'])) {
        if (!is_numeric($body['gbp_rate']) || $body['gbp_rate'] <= 0 || $body['gbp_rate'] > 5) json_err('GBP rate must be between 0 and 5');
        \ApiUsage\Stats::saveSetting($pdo, 'usd_gbp_rate', (string)(float)$body['gbp_rate']);
    }
    if (isset($body['pricing'])) {
        if (!is_array($body['pricing'])) json_err('pricing must be an object');
        $clean = [];
        foreach ($body['pricing'] as $model => $r) {
            $model = trim((string)$model);
            if ($model === '' || !preg_match('/^[a-z0-9.\-]+$/i', $model)) json_err("Invalid model name: {$model}");
            if (!is_numeric($r['in'] ?? null) || !is_numeric($r['out'] ?? null) || $r['in'] < 0 || $r['out'] < 0) json_err("Invalid prices for {$model}");
            $clean[$model] = ['in' => (float)$r['in'], 'out' => (float)$r['out']];
        }
        \ApiUsage\Stats::saveSetting($pdo, 'api_pricing', json_encode($clean));
    }

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'api_usage_settings', null]);
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

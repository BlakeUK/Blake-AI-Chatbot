<?php
// public/api/admin/google_billing.php - real Google Cloud billing (BigQuery export).
// GET  [?refresh=1]                    status + summary (cached 30 min)
// POST {csrf, sa_json?, table?}        save the service account key / export table
require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sa = \Google\ServiceAccount::json();
    $status = [
        'has_key'      => $sa !== null,
        'client_email' => $sa['client_email'] ?? null,
        'table'        => \Google\Billing::table(),
        'configured'   => \Google\Billing::configured(),
    ];
    if (!$status['configured']) json_out(['status' => $status]);
    try {
        json_out(['status' => $status, 'summary' => \Google\Billing::summary(!empty($_GET['refresh']))]);
    } catch (\Throwable $e) {
        json_out(['status' => $status, 'error' => $e->getMessage()]);
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
$b = json_body();
\Auth\Admin::verifyCsrf($b['csrf'] ?? '');
if (!empty($b['sa_json'])) {
    $r = \Google\ServiceAccount::save((string)$b['sa_json']);
    if (!$r['ok']) json_err($r['error']);
}
if (isset($b['table'])) {
    $t = trim((string)$b['table']);
    if ($t !== '' && !\Google\Billing::validTable($t)) json_err('The table should look like project-id.dataset_name.gcp_billing_export_v1_XXXXXX');
    \Google\Billing::saveSetting('gcp_billing_table', $t);
}
\Google\Billing::saveSetting('gcp_billing_cache', '');
db()->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], 'google_billing_settings', null]);
json_out(['ok' => true]);

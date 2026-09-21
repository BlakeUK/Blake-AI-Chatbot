<?php
// tests/cases/google_billing_test.php - service account JWT and the
// BigQuery billing-export reader, with Google's HTTP endpoints mocked.
declare(strict_types=1);

suite('Google Billing — service account and BigQuery export');

function gb_key(): array
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);
    return [$pem, openssl_pkey_get_details($k)['key']];
}

test('rejects a non service-account JSON; saves a real one encrypted', function () {
    assert_true(!\Google\ServiceAccount::save('{"type":"authorized_user"}')['ok']);
    [$pem] = gb_key();
    $r = \Google\ServiceAccount::save(json_encode(['type' => 'service_account', 'client_email' => 'billing-reader@proj.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'proj']));
    assert_true($r['ok']);
    $raw = db()->query("SELECT key_enc FROM api_keys WHERE service = 'gcp_sa'")->fetchColumn();
    assert_true(!str_contains((string)$raw, 'PRIVATE KEY'), 'stored encrypted');
    assert_equal('billing-reader@proj.iam.gserviceaccount.com', \Google\ServiceAccount::json()['client_email']);
});

test('JWT is RS256-signed with the service account key and has the right claims', function () {
    [$pem, $pub] = gb_key();
    $jwt = \Google\ServiceAccount::jwt(['client_email' => 'x@y.iam.gserviceaccount.com', 'private_key' => $pem], \Google\ServiceAccount::SCOPE_BIGQUERY, 1000);
    [$h, $c, $sig] = explode('.', $jwt);
    $dec = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    assert_equal(1, openssl_verify("$h.$c", $dec($sig), $pub, OPENSSL_ALGO_SHA256));
    $claims = json_decode($dec($c), true);
    assert_equal('x@y.iam.gserviceaccount.com', $claims['iss']);
    assert_equal(4600, $claims['exp']);
    assert_equal('https://oauth2.googleapis.com/token', $claims['aud']);
});

test('summary reads cost, credits, daily and Gemini items from the export', function () {
    [$pem] = gb_key();
    \Google\ServiceAccount::save(json_encode(['type' => 'service_account', 'client_email' => 'r@p.iam.gserviceaccount.com', 'private_key' => $pem]));
    \Google\Billing::saveSetting('gcp_billing_table', 'gen-lang-client-0307665503.billing.gcp_billing_export_v1_ABC');
    assert_true(\Google\Billing::configured());
    $rows = fn(array $names, array $data) => ['jobComplete' => true, 'schema' => ['fields' => array_map(fn($n) => ['name' => $n], $names)],
        'rows' => array_map(fn($r) => ['f' => array_map(fn($v) => ['v' => $v], $r)], $data)];
    $sqls = [];
    \Google\ServiceAccount::$http = function (string $url, array $data) use ($rows, &$sqls) {
        if (str_contains($url, 'oauth2')) return ['access_token' => 'tok', 'expires_in' => 3600];
        $sql = $data['query']; $sqls[] = $sql;
        assert_str_contains('/projects/gen-lang-client-0307665503/queries', $url);
        if (str_contains($sql, 'GROUP BY service ORDER BY cost DESC')) return $rows(['service', 'cost', 'credits', 'currency'], [['Gemini API', '12.40', '-2.00', 'GBP'], ['Cloud Storage', '0.10', '0', 'GBP']]);
        if (str_contains($sql, 'AS day')) return $rows(['day', 'cost', 'credits'], [['2026-09-20', '1.50', '0'], ['2026-09-21', '2.25', '-0.25']]);
        if (str_contains($sql, 'sku.description')) return $rows(['service', 'sku', 'usage_amount', 'unit', 'cost', 'credits'], [['Gemini API', 'Gemini 3.6 Flash output tokens', '1200000', 'count', '9.00', '-2.00']]);
        return $rows(['last_export'], [['2026-09-21 12:00:00 UTC']]);
    };
    try {
        $s = \Google\Billing::summary(true);
        assert_equal(12.5, $s['month_cost']);
        assert_equal(-2.0, $s['month_credits']);
        assert_equal(10.5, $s['month_net']);
        assert_equal(7.0, $s['gemini_net']);
        assert_equal('GBP', $s['currency']);
        assert_equal(2, count($s['daily']));
        assert_equal('Gemini 3.6 Flash output tokens', $s['gemini_skus'][0]['sku']);
        assert_str_contains('`gen-lang-client-0307665503.billing.gcp_billing_export_v1_ABC`', $sqls[0]);
        // cached second time: no new queries
        $n = count($sqls);
        assert_true(\Google\Billing::summary(false)['cached']);
        assert_equal($n, count($sqls));
    } finally {
        \Google\ServiceAccount::$http = null;
        db()->exec("DELETE FROM api_keys WHERE service = 'gcp_sa'");
        db()->exec("DELETE FROM settings WHERE key IN ('gcp_billing_table','gcp_billing_cache')");
    }
});

test('table name validation', function () {
    assert_true(\Google\Billing::validTable('gen-lang-client-0307665503.billing_export.gcp_billing_export_v1_01A2B3_C4D5E6_F7G8H9'));
    assert_true(!\Google\Billing::validTable('drop table; --'));
    assert_true(!\Google\Billing::validTable('only.two'));
});

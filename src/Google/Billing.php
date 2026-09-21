<?php
// src/Google/Billing.php
// Real Google Cloud billing figures for the admin "Google Billing" page.
// Google has no API that returns spend directly: the supported route is
// Cloud Billing export to BigQuery, which this reads with a read-only
// service account (BigQuery Data Viewer on the dataset + BigQuery Job User
// on the project). Results are cached for 30 minutes.

declare(strict_types=1);

namespace Google;

class Billing
{
    public const CACHE_SECONDS = 1800;

    public static function setting(string $k, string $d = ''): string
    {
        try {
            $s = db()->prepare('SELECT value FROM settings WHERE key = ?');
            $s->execute([$k]);
            $v = $s->fetchColumn();
            return $v === false || $v === null ? $d : (string)$v;
        } catch (\Throwable $e) { return $d; }
    }

    public static function saveSetting(string $k, string $v): void
    {
        db()->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
            ->execute([$k, $v, time()]);
    }

    // "project.dataset.gcp_billing_export_v1_XXXXXX"
    public static function table(): string
    {
        return self::setting('gcp_billing_table');
    }

    public static function validTable(string $t): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9\-]{4,61}[a-z0-9]\.[A-Za-z0-9_]{1,1024}\.[A-Za-z0-9_\-]{1,1024}$/', $t);
    }

    public static function configured(): bool
    {
        return self::validTable(self::table()) && ServiceAccount::json() !== null;
    }

    // Runs a standard-SQL query in the billing export's project; returns rows as assoc arrays.
    public static function query(string $sql): array
    {
        $table = self::table();
        $project = explode('.', $table)[0];
        $token = ServiceAccount::accessToken();
        $r = ServiceAccount::post("https://bigquery.googleapis.com/bigquery/v2/projects/{$project}/queries",
            ['query' => $sql, 'useLegacySql' => false, 'timeoutMs' => 30000, 'maxResults' => 1000], $token);
        if (isset($r['error'])) throw new \RuntimeException('BigQuery: ' . ($r['error']['message'] ?? 'error'));
        if (empty($r['jobComplete'])) throw new \RuntimeException('BigQuery query did not finish in time; try again');
        $fields = array_map(fn($f) => $f['name'], $r['schema']['fields'] ?? []);
        $rows = [];
        foreach ($r['rows'] ?? [] as $row) {
            $vals = array_map(fn($c) => $c['v'] ?? null, $row['f'] ?? []);
            $rows[] = array_combine($fields, $vals);
        }
        return $rows;
    }

    // Everything the admin page shows, cached.
    public static function summary(bool $refresh = false, ?int $now = null): array
    {
        if (!$refresh) {
            $c = json_decode(self::setting('gcp_billing_cache', ''), true);
            if (is_array($c) && time() - (int)($c['fetched_at'] ?? 0) < self::CACHE_SECONDS) return $c + ['cached' => true];
        }
        $now = $now ?? time();
        $t = '`' . str_replace('`', '', self::table()) . '`';
        $month = gmdate('Ym', $now);
        $credits = "IFNULL((SELECT SUM(c.amount) FROM UNNEST(credits) c), 0)";

        $byService = self::query("SELECT service.description AS service, ROUND(SUM(cost), 4) AS cost, ROUND(SUM({$credits}), 4) AS credits, ANY_VALUE(currency) AS currency
                                  FROM {$t} WHERE invoice.month = '{$month}' GROUP BY service ORDER BY cost DESC");
        $daily = self::query("SELECT CAST(DATE(usage_start_time, 'Europe/London') AS STRING) AS day, ROUND(SUM(cost), 4) AS cost, ROUND(SUM({$credits}), 4) AS credits
                              FROM {$t} WHERE usage_start_time >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY day ORDER BY day");
        $skus = self::query("SELECT service.description AS service, sku.description AS sku, ROUND(SUM(usage.amount_in_pricing_units), 2) AS usage_amount,
                                    ANY_VALUE(usage.pricing_unit) AS unit, ROUND(SUM(cost), 4) AS cost, ROUND(SUM({$credits}), 4) AS credits
                             FROM {$t} WHERE invoice.month = '{$month}'
                               AND (LOWER(service.description) LIKE '%gemini%' OR LOWER(service.description) LIKE '%generative language%' OR LOWER(service.description) LIKE '%vertex%')
                             GROUP BY service, sku ORDER BY cost DESC LIMIT 50");
        $last = self::query("SELECT CAST(MAX(export_time) AS STRING) AS last_export FROM {$t} WHERE invoice.month = '{$month}'");

        $cost = array_sum(array_map(fn($r) => (float)$r['cost'], $byService));
        $cred = array_sum(array_map(fn($r) => (float)$r['credits'], $byService));
        $gem = array_sum(array_map(fn($r) => (float)$r['cost'] + (float)$r['credits'], $skus));
        $out = [
            'fetched_at'   => time(),
            'month'        => gmdate('F Y', $now),
            'currency'     => $byService[0]['currency'] ?? 'GBP',
            'month_cost'   => round($cost, 2),
            'month_credits'=> round($cred, 2),
            'month_net'    => round($cost + $cred, 2),
            'gemini_net'   => round($gem, 2),
            'by_service'   => $byService,
            'daily'        => $daily,
            'gemini_skus'  => $skus,
            'last_export'  => $last[0]['last_export'] ?? null,
        ];
        self::saveSetting('gcp_billing_cache', json_encode($out));
        return $out + ['cached' => false];
    }
}

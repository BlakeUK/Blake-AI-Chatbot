<?php
namespace ApiUsage;

// Aggregations over api_usage_log for the admin "API Usage" tab.
class Stats
{
    public static function setting(\PDO $pdo, string $key, $default = null)
    {
        $s = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function saveSetting(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
            ->execute([$key, $value, time()]);
    }

    public static function summary(\PDO $pdo, int $days, ?int $now = null): array
    {
        $now   = $now ?? time();
        $since = $now - $days * 86400;

        $q = function (string $sql, array $args = []) use ($pdo) {
            $s = $pdo->prepare($sql);
            $s->execute($args);
            return $s->fetchAll(\PDO::FETCH_ASSOC);
        };

        $tot = $q('SELECT COUNT(*) calls, COALESCE(SUM(ok = 0),0) errors,
                          COALESCE(SUM(input_tokens),0) input_tokens, COALESCE(SUM(output_tokens),0) output_tokens,
                          COALESCE(SUM(thinking_tokens),0) thinking_tokens, COALESCE(SUM(cost_usd),0) cost_usd,
                          CAST(COALESCE(AVG(latency_ms),0) AS INTEGER) avg_latency_ms,
                          COALESCE(SUM(http_code = 429),0) rate_limited
                   FROM api_usage_log WHERE created_at >= ?', [$since])[0];

        $byService = $q('SELECT service, COUNT(*) calls, SUM(ok = 0) errors, SUM(http_code = 429) rate_limited,
                                SUM(cost_usd) cost_usd, CAST(AVG(latency_ms) AS INTEGER) avg_latency_ms, MAX(created_at) last_call
                         FROM api_usage_log WHERE created_at >= ? GROUP BY service ORDER BY calls DESC', [$since]);

        $byModel = $q("SELECT model, COUNT(*) calls, SUM(ok = 0) errors, SUM(http_code = 429) rate_limited,
                              SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens,
                              SUM(thinking_tokens) thinking_tokens, SUM(cost_usd) cost_usd
                       FROM api_usage_log WHERE service = 'gemini' AND created_at >= ?
                       GROUP BY model ORDER BY cost_usd DESC", [$since]);

        $byOperation = $q("SELECT operation, COUNT(*) calls, SUM(ok = 0) errors,
                                  SUM(input_tokens + output_tokens + thinking_tokens) tokens, SUM(cost_usd) cost_usd
                           FROM api_usage_log WHERE service = 'gemini' AND created_at >= ?
                           GROUP BY operation ORDER BY cost_usd DESC", [$since]);

        $daily = $q("SELECT date(created_at, 'unixepoch') day, COUNT(*) calls, SUM(ok = 0) errors, SUM(cost_usd) cost_usd
                     FROM api_usage_log WHERE created_at >= ? GROUP BY day ORDER BY day", [$since]);

        $errors = $q('SELECT created_at, service, operation, model, http_code, error
                      FROM api_usage_log WHERE ok = 0 AND created_at >= ? ORDER BY id DESC LIMIT 50', [$since]);

        // Month to date + straight-line projection, against the admin budget.
        $monthStart = strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC');
        $mtd = (float)$q('SELECT COALESCE(SUM(cost_usd),0) c FROM api_usage_log WHERE created_at >= ?', [$monthStart])[0]['c'];
        $daysInMonth = (int)gmdate('t', $now);
        $elapsedDays = max(($now - $monthStart) / 86400, 1 / 24);
        $projected   = $mtd / $elapsedDays * $daysInMonth;
        $budget      = (float)self::setting($pdo, 'api_monthly_budget_usd', 0);

        foreach ([&$byService, &$byModel, &$byOperation, &$daily] as &$rows) {
            foreach ($rows as &$r) {
                foreach ($r as $k => $v) {
                    if ($k === 'cost_usd') $r[$k] = round((float)$v, 6);
                    elseif (is_numeric($v) && !in_array($k, ['day', 'model', 'service', 'operation'], true)) $r[$k] = (int)$v;
                }
            }
        }
        unset($rows, $r);

        return [
            'days'     => $days,
            'totals'   => array_map(fn($v) => is_numeric($v) ? $v + 0 : $v, $tot),
            'by_service'   => $byService,
            'by_model'     => $byModel,
            'by_operation' => $byOperation,
            'daily'        => $daily,
            'recent_errors'=> $errors,
            'month' => [
                'month'          => gmdate('F Y', $now),
                'cost_usd'       => round($mtd, 4),
                'projected_usd'  => round($projected, 4),
                'budget_usd'     => $budget,
                'remaining_usd'  => $budget > 0 ? round($budget - $mtd, 4) : null,
                'used_pct'       => $budget > 0 ? round($mtd / $budget * 100, 1) : null,
            ],
            'gbp_rate' => (float)self::setting($pdo, 'usd_gbp_rate', 0.75),
            'pricing'  => Logger::pricing(),
        ];
    }
}

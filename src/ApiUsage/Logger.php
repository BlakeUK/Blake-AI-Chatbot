<?php
namespace ApiUsage;

// Records every outbound API call for the admin "API Usage" tab. Logging
// must never break the caller: any failure here (missing table on an
// un-migrated DB, locked DB, ...) is swallowed.
class Logger
{
    // USD per 1M tokens. Defaults are Google's published Gemini API paid-tier
    // rates at the time of writing; admins can override them in the API
    // Usage tab (settings key api_pricing) as Google changes prices.
    // Thinking tokens are billed at the output rate.
    public const DEFAULT_PRICING = [
        'gemini-3.6-flash'      => ['in' => 1.50, 'out' => 7.50],
        'gemini-3.7-flash'      => ['in' => 0.75, 'out' => 3.75],
        'gemini-3.8-flash'      => ['in' => 0.75, 'out' => 3.75],
        'gemini-3.5-flash'      => ['in' => 1.50, 'out' => 9.00],
        'gemini-3.5-flash-lite' => ['in' => 0.30, 'out' => 2.50],
        // TTS: text in, audio out (25 audio tokens per second of speech)
        'gemini-3.1-flash-tts'  => ['in' => 1.00, 'out' => 20.00],
    ];

    private static ?array $pricingCache = null;

    public static function pricing(): array
    {
        if (self::$pricingCache !== null) return self::$pricingCache;
        $p = self::DEFAULT_PRICING;
        try {
            $s = db()->prepare("SELECT value FROM settings WHERE key = 'api_pricing'");
            $s->execute();
            $o = json_decode((string)$s->fetchColumn(), true);
            if (is_array($o)) {
                foreach ($o as $model => $r) {
                    if (is_array($r) && is_numeric($r['in'] ?? null) && is_numeric($r['out'] ?? null)) {
                        $p[$model] = ['in' => (float)$r['in'], 'out' => (float)$r['out']];
                    }
                }
            }
        } catch (\Throwable $e) {}
        return self::$pricingCache = $p;
    }

    public static function resetPricingCache(): void { self::$pricingCache = null; }

    // Longest matching prefix wins, so "gemini-3.6-flash-preview-0901"
    // prices as gemini-3.6-flash but "-lite" variants keep their own rate.
    public static function rateFor(?string $model): ?array
    {
        if (!$model) return null;
        $best = null; $bestLen = 0;
        foreach (self::pricing() as $m => $r) {
            if (str_starts_with($model, $m) && strlen($m) > $bestLen) { $best = $r; $bestLen = strlen($m); }
        }
        return $best;
    }

    public static function geminiCost(?string $model, int $in, int $out, int $thinking): float
    {
        $r = self::rateFor($model);
        if (!$r) return 0.0;
        return ($in * $r['in'] + ($out + $thinking) * $r['out']) / 1_000_000;
    }

    public static function log(string $service, array $f): void
    {
        try {
            db()->prepare('INSERT INTO api_usage_log
                (service, operation, model, http_code, ok, error, input_tokens, output_tokens, thinking_tokens, latency_ms, cost_usd)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $service,
                    $f['operation'] ?? null,
                    $f['model'] ?? null,
                    $f['http_code'] ?? null,
                    !empty($f['ok']) ? 1 : 0,
                    isset($f['error']) ? mb_substr((string)$f['error'], 0, 1000) : null,
                    (int)($f['input_tokens'] ?? 0),
                    (int)($f['output_tokens'] ?? 0),
                    (int)($f['thinking_tokens'] ?? 0),
                    isset($f['latency_ms']) ? (int)$f['latency_ms'] : null,
                    (float)($f['cost_usd'] ?? 0),
                ]);
        } catch (\Throwable $e) {}
    }

    // Which part of the app made a Gemini call, from the first stack frame
    // outside Gemini\Client - avoids threading a label through every caller.
    public static function callerOperation(): string
    {
        $map = [
            'Chat\\Responder'            => 'chat_answer',
            'Chat\\DepartmentClassifier' => 'classify',
            'Chat\\LiveChat'             => 'live_chat',
            'Knowledge\\FileExtractor'   => 'file_extract',
            'Products\\PageExtractor'    => 'product_page_extract',
            'Products\\Importer'         => 'product_import',
            'Faq\\Builder'               => 'faq_build',
            'Knowledge\\Dedup'           => 'dedup',
            'Html\\TextCleaner'          => 'text_clean',
            'Speech\\Speaker'            => 'voice',
        ];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $fr) {
            $c = $fr['class'] ?? null;
            if ($c && $c !== 'Gemini\\Client' && $c !== self::class) {
                return $map[$c] ?? strtolower(str_replace('\\', '_', $c));
            }
            if (!$c && isset($fr['file']) && !str_contains($fr['file'], 'Gemini/Client.php') && !str_contains($fr['file'], 'ApiUsage/Logger.php')) {
                return basename($fr['file'], '.php');
            }
        }
        return 'other';
    }

    // Keep the log bounded; called opportunistically from the stats API.
    public static function prune(int $days = 180): void
    {
        try {
            db()->prepare('DELETE FROM api_usage_log WHERE created_at < ?')->execute([time() - $days * 86400]);
        } catch (\Throwable $e) {}
    }
}

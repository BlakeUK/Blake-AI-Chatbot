<?php
// tests/eval/run.php — live RAG answer-quality eval.
//
//   GEMINI_API_KEY=... php tests/eval/run.php
//
// Runs every case in tests/eval/cases.php through the exact same
// \Chat\Responder code path public/api/chat/send.php uses, but with a real
// Gemini call - unlike tests/run.php, this exercises actual answer
// quality, not just retrieval plumbing. Costs real API calls and takes
// noticeably longer, so it's wired into a separate, nightly/on-demand
// workflow (.github/workflows/rag-eval.yml) rather than running on every
// pull request.
//
// Skips (exit 0) rather than fails when no API key is available, so this
// never blocks local/CI runs that don't have one configured.

declare(strict_types=1);

require __DIR__ . '/../harness.php';
require __DIR__ . '/../bootstrap.php';

$apiKey = getenv('GEMINI_API_KEY') ?: null;
if (!$apiKey) {
    echo "GEMINI_API_KEY not set - skipping live RAG eval.\n";
    exit(0);
}

seed_fixtures();

$cases  = require __DIR__ . '/cases.php';
$gemini = new \Gemini\Client($apiKey);

$pass = 0;
$fail = 0;

foreach ($cases as $case) {
    echo "── {$case['name']}\n";
    echo "   Q: {$case['message']}\n";

    try {
        $ctx    = \Chat\Responder::buildContext($case['message'], $case['product_code'] ?? null);
        $prompt = \Chat\Responder::buildPrompt($ctx, $case['product_code'] ?? null, $case['page_url'] ?? null);

        $answer = $gemini->chat(CFG['gemini_flash'], [
            ['role' => 'user', 'content' => $case['message']],
        ], $prompt);

        $confidence = \Chat\Responder::confidence($ctx['knowledge_hits'], $ctx['product_hits'], $ctx['keyword_links']);
        $escalate   = \Chat\Responder::shouldEscalate($confidence);

        $problems = grade_case($case, $answer, $escalate);

        if ($problems) {
            $fail++;
            echo "   \033[31m✗ FAIL\033[0m\n";
            foreach ($problems as $p) echo "     - $p\n";
            echo '   answer: ' . truncate($answer, 300) . "\n";
        } else {
            $pass++;
            echo "   \033[32m✓ pass\033[0m (confidence={$confidence}, escalate=" . ($escalate ? 'true' : 'false') . ")\n";
        }
    } catch (\Throwable $e) {
        $fail++;
        echo "   \033[31m✗ ERROR\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

$total = $pass + $fail;
echo ($fail === 0 ? "\033[32m" : "\033[31m") . "{$total} eval cases, {$pass} passed, {$fail} failed\033[0m\n";
exit($fail === 0 ? 0 : 1);

// ── Grading ──────────────────────────────────────────────────────────────

function grade_case(array $case, string $answer, bool $escalate): array
{
    $problems = [];
    $lower    = strtolower($answer);

    foreach ($case['must_contain'] ?? [] as $needle) {
        if (!str_contains($lower, strtolower($needle))) {
            $problems[] = "expected to contain '$needle'";
        }
    }

    if (!empty($case['must_contain_any'])) {
        $any = false;
        foreach ($case['must_contain_any'] as $needle) {
            if (str_contains($lower, strtolower($needle))) { $any = true; break; }
        }
        if (!$any) {
            $problems[] = 'expected at least one of: ' . implode(', ', $case['must_contain_any']);
        }
    }

    foreach ($case['must_not_contain'] ?? [] as $needle) {
        if (str_contains($lower, strtolower($needle))) {
            $problems[] = "must NOT contain '$needle' but did";
        }
    }

    if (array_key_exists('expect_escalate', $case) && $case['expect_escalate'] !== null) {
        if ($case['expect_escalate'] !== $escalate) {
            $problems[] = 'expected escalate=' . ($case['expect_escalate'] ? 'true' : 'false') . ", got={$escalate}";
        }
    }

    return $problems;
}

function truncate(string $s, int $len): string
{
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

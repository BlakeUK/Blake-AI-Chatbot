<?php
// tests/harness.php
// A minimal, dependency-free test runner. Deliberately hand-rolled instead
// of pulling in Composer/PHPUnit - this project has no build step and no
// other dependencies (see README: "No Node.js, no React, no pip"); a single
// small file that runs with plain `php` fits that better than a new
// package manager + framework for a still-small test suite.

declare(strict_types=1);

$GLOBALS['__tests'] = [];
$GLOBALS['__current_suite'] = '';

function suite(string $name): void
{
    $GLOBALS['__current_suite'] = $name;
}

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['suite' => $GLOBALS['__current_suite'], 'name' => $name, 'fn' => $fn];
}

function assert_true($cond, string $msg = 'expected true, got false'): void
{
    if (!$cond) throw new \RuntimeException($msg);
}

function assert_false($cond, string $msg = 'expected false, got true'): void
{
    if ($cond) throw new \RuntimeException($msg);
}

function assert_null($val, string $msg = ''): void
{
    if ($val !== null) throw new \RuntimeException($msg ?: 'expected null, got ' . var_export($val, true));
}

function assert_equal($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($msg ?: 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains($needle, array $haystack, string $msg = ''): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new \RuntimeException($msg ?: var_export($needle, true) . ' not found in ' . json_encode($haystack));
    }
}

function assert_not_contains($needle, array $haystack, string $msg = ''): void
{
    if (in_array($needle, $haystack, true)) {
        throw new \RuntimeException($msg ?: var_export($needle, true) . ' unexpectedly found in ' . json_encode($haystack));
    }
}

function assert_str_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($msg ?: "expected string to contain '$needle'");
    }
}

function assert_count(int $expected, array $actual, string $msg = ''): void
{
    if (count($actual) !== $expected) {
        throw new \RuntimeException($msg ?: "expected count $expected, got " . count($actual) . ': ' . json_encode($actual));
    }
}

// Runs every registered test() and prints a pass/fail report. Returns a
// process exit code (0 = all passed).
function run_registered_tests(): int
{
    $pass = 0;
    $fail = 0;
    $lastSuite = null;

    foreach ($GLOBALS['__tests'] as $t) {
        if ($t['suite'] !== $lastSuite) {
            echo "\n{$t['suite']}\n";
            $lastSuite = $t['suite'];
        }
        try {
            ($t['fn'])();
            $pass++;
            echo "  \033[32m✓\033[0m {$t['name']}\n";
        } catch (\Throwable $e) {
            $fail++;
            echo "  \033[31m✗ {$t['name']}\033[0m\n";
            echo '    ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
    }

    $total = $pass + $fail;
    echo "\n" . ($fail === 0 ? "\033[32m" : "\033[31m") . "{$total} tests, {$pass} passed, {$fail} failed\033[0m\n";

    return $fail === 0 ? 0 : 1;
}

<?php
// tests/run.php — fast regression suite. No network, no LLM calls, no
// external dependencies: just PHP + a throwaway SQLite fixture DB.
//
//   php tests/run.php
//
// Exit code is 0 iff every test passed - wired into
// .github/workflows/test.yml so this runs on every pull request.

declare(strict_types=1);

require __DIR__ . '/harness.php';
require __DIR__ . '/bootstrap.php';

seed_fixtures();

foreach (glob(__DIR__ . '/cases/*.php') as $case) {
    require $case;
}

exit(run_registered_tests());

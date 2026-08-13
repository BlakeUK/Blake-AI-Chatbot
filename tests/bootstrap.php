<?php
// tests/bootstrap.php
// Builds a throwaway SQLite fixture database from the real schema files -
// the same ones scripts/deploy_remote.sh applies in production - so tests
// run against the actual schema instead of a hand-maintained copy that
// could silently drift from it. Points the app at that fixture DB via
// BLAKE_UK_CONFIG (see src/bootstrap.php), then loads the app normally.

declare(strict_types=1);

$root   = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/blake-uk-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Could not create temp dir $tmpDir\n");
    exit(1);
}
@mkdir("$tmpDir/uploads");
@mkdir("$tmpDir/logs");

$dbPath = "$tmpDir/fixture.db";
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fixed bootstrap order (mirrors scripts/deploy_remote.sh's fresh-install
// path), then every other schema_*.sql migration found in scripts/ - so a
// migration added later is picked up automatically without this file
// needing to be updated.
$first = ['schema.sql', 'schema_widget.sql', 'schema_append.sql', 'schema_fts_triggers.sql'];
foreach ($first as $f) {
    $pdo->exec(file_get_contents("$root/scripts/$f"));
}
// The remaining migrations are mostly independent ALTER TABLEs, but a few
// depend on a table another migration creates (e.g. schema_channel_dm.sql
// needs the "channels" table from schema_channels.sql) - alphabetical order
// doesn't guarantee that. Retry-to-fixpoint instead of hardcoding an order,
// so a newly added migration with its own dependency is handled without
// this file needing to know about it.
$rest = array_values(array_diff(
    array_map('basename', glob("$root/scripts/schema_*.sql") ?: []),
    $first
));
sort($rest);
$lastError = null;
while ($rest) {
    $progressed = false;
    $stillFailing = [];
    foreach ($rest as $f) {
        // Transaction per file: a file with more than one statement that
        // fails partway through must not leave its earlier statements
        // applied, or retrying it whole would hit "duplicate column" for
        // those instead of a clean re-attempt.
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents("$root/scripts/$f"));
            $pdo->commit();
            $progressed = true;
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $stillFailing[] = $f;
            $lastError = $e;
        }
    }
    if (!$progressed) {
        throw new \RuntimeException(
            'Could not apply schema migrations (unresolvable dependency among: ' . implode(', ', $stillFailing) . '): ' . $lastError->getMessage()
        );
    }
    $rest = $stillFailing;
}
$pdo = null; // close before src/bootstrap.php opens its own handle via db()

// Fixture config: no real secrets, points at the throwaway DB above. The
// Gemini model is overridable so tests/eval/run.php can use a real key
// against a real model without editing this file.
$configPath = "$tmpDir/config.php";
file_put_contents($configPath, '<?php return ' . var_export([
    'db_path'            => $dbPath,
    'upload_path'        => "$tmpDir/uploads/",
    'log_path'           => "$tmpDir/logs/",
    'encrypt_key'        => bin2hex(random_bytes(32)),
    'mobile_app_key'     => bin2hex(random_bytes(24)),
    'gemini_flash'       => getenv('BLAKE_UK_TEST_GEMINI_MODEL') ?: 'gemini-2.0-flash',
    'gemini_pro'         => getenv('BLAKE_UK_TEST_GEMINI_MODEL') ?: 'gemini-2.0-flash',
    'cors_origins'       => [],
    'rate_limit_chat'    => 999999,
    'rate_limit_admin'   => 999999,
    'session_lifetime'   => 3600,
    'escalate_threshold' => 0.4,
    // Auth\CheckTool test fixture - not the real production credential.
    'check_tool_username'      => 'testuser',
    'check_tool_password_hash' => password_hash('testpass', PASSWORD_DEFAULT),
], true) . ";\n");

putenv("BLAKE_UK_CONFIG=$configPath");
require "$root/src/bootstrap.php";

register_shutdown_function(function () use ($tmpDir) {
    foreach (glob("$tmpDir/*") ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir("$tmpDir/uploads");
    @rmdir("$tmpDir/logs");
    @rmdir($tmpDir);
});

require __DIR__ . '/fixtures/seed.php';

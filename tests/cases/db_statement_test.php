<?php
// tests/cases/db_statement_test.php
// Regression: a write after another process has written, while this
// connection still has a half-read SELECT open, failed immediately with
// "database is locked" (SQLITE_BUSY_SNAPSHOT). Db\Statement recovers.

suite('Db\Statement — stale read snapshot');

function db_statement_external_write(string $sql): void {
    $code = 'new PDO("sqlite:" . $argv[1]); $p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);';
    $cmd  = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(CFG['db_path']) . ' ' . escapeshellarg($sql);
    exec($cmd, $o, $rc);
    if ($rc !== 0) throw new RuntimeException('external write failed');
}

test('write succeeds after a concurrent external write with an open cursor', function () {
    $pdo = db();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('snap_a','1'), ('snap_b','2'), ('snap_c','3')");
    $st = $pdo->prepare("SELECT key FROM settings WHERE key LIKE 'snap_%' ORDER BY key");
    $st->execute();
    $first = $st->fetch();                       // cursor left open, snapshot held
    assert_equal('snap_a', $first['key']);
    db_statement_external_write("INSERT INTO settings (key, value) VALUES ('snap_ext','x')");
    $pdo->prepare("INSERT INTO settings (key, value) VALUES ('snap_after','y')")->execute();
    assert_equal('y', $pdo->query("SELECT value FROM settings WHERE key='snap_after'")->fetchColumn());
    assert_equal('x', $pdo->query("SELECT value FROM settings WHERE key='snap_ext'")->fetchColumn());
    $pdo->exec("DELETE FROM settings WHERE key LIKE 'snap_%'");
});

test('db() uses the recovering statement class', function () {
    assert_true(db()->prepare('SELECT 1') instanceof \Db\Statement);
});

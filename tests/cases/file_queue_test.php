<?php
// tests/cases/file_queue_test.php
// Knowledge\FileQueue: rate-limit detection, failure recording and the
// bulk "Retry all failed" requeue used by files_retry_failed.php.

declare(strict_types=1);

suite('Knowledge\FileQueue — retry failed / rate-limit requeue');

function fq_insert(string $status, ?string $path, ?string $err = null): int {
    $pdo = db();
    $pdo->prepare('INSERT INTO knowledge_files (filename, mime_type, stored_path, status, error) VALUES (?,?,?,?,?)')
        ->execute(['fq_' . uniqid() . '.pdf', 'application/pdf', $path, $status, $err]);
    return (int)$pdo->lastInsertId();
}
function fq_row(int $id): array {
    $s = db()->prepare('SELECT status, error FROM knowledge_files WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch();
}

test('isRateLimited(): matches Gemini 429 errors only', function () {
    assert_true(\Knowledge\FileQueue::isRateLimited('Gemini API error 429 (model: gemini-3.6-flash): You exceeded your current quota'));
    assert_true(!\Knowledge\FileQueue::isRateLimited('Gemini API error 400 (model: x): bad request'));
    assert_true(!\Knowledge\FileQueue::isRateLimited('No text extracted from file'));
    assert_true(!\Knowledge\FileQueue::isRateLimited(null));
});

test('recordFailure(): a 429 leaves the file queued with a note, other errors mark error', function () {
    $pdo = db();
    $a = fq_insert('pending', '/tmp/x');
    assert_equal('pending', \Knowledge\FileQueue::recordFailure($pdo, $a, 'Gemini API error 429 (model: m): quota'));
    assert_equal(['status' => 'pending', 'error' => \Knowledge\FileQueue::RATE_LIMIT_NOTE], fq_row($a));
    $b = fq_insert('pending', '/tmp/x');
    assert_equal('error', \Knowledge\FileQueue::recordFailure($pdo, $b, 'No text extracted from file'));
    assert_equal(['status' => 'error', 'error' => 'No text extracted from file'], fq_row($b));
});

test('requeueFailed(): queues every failed file whose stored file exists, skips missing, leaves others alone', function () {
    $pdo  = db();
    $pdo->exec("UPDATE knowledge_files SET status = 'indexed' WHERE status = 'error'");
    $file = tempnam(sys_get_temp_dir(), 'fq');
    file_put_contents($file, 'x');
    $e1 = fq_insert('error', $file, 'Gemini API error 429 (model: m): quota');
    $e2 = fq_insert('error', $file, 'Gemini API error 503 (model: m): overloaded');
    $gone = fq_insert('error', '/nonexistent/fq.pdf', 'Gemini API error 429');
    $ok = fq_insert('indexed', $file);
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text) VALUES (?,?,?)')->execute(['file', $e1, 'stale partial chunk']);

    $res = \Knowledge\FileQueue::requeueFailed($pdo);
    assert_equal(['queued' => 2, 'skipped_missing' => 1], $res);
    assert_equal(['status' => 'pending', 'error' => null], fq_row($e1));
    assert_equal(['status' => 'pending', 'error' => null], fq_row($e2));
    assert_equal('error', fq_row($gone)['status']);
    assert_equal('indexed', fq_row($ok)['status']);
    $c = $pdo->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE source_type = ? AND source_id = ?');
    $c->execute(['file', $e1]);
    assert_equal(0, (int)$c->fetchColumn());
    unlink($file);
});

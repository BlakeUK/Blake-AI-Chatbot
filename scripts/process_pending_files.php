#!/usr/bin/env php
<?php
// scripts/process_pending_files.php — run on a schedule (e.g. cron every
// minute) to run Gemini extraction on any knowledge_files row left in
// 'pending' status: bulk URL imports (import_urls.php) intentionally
// leave every row pending rather than extracting inline, so batches
// aren't gated by per-file Gemini latency. A crashed-mid-request regular
// upload would also show up here and get retried.
//
// Safe to run concurrently/frequently: each invocation only claims a
// bounded batch and only touches rows still in 'pending' status.

require dirname(__DIR__) . '/src/bootstrap.php';

const BATCH_LIMIT   = 20;   // files per run
const TIME_BUDGET_S = 240;  // stop picking up new files after this long

$pdo   = db();
$start = time();
$done  = 0;

$stmt = $pdo->prepare('
    SELECT id, stored_path, mime_type
    FROM knowledge_files
    WHERE status = ?
    ORDER BY created_at ASC
    LIMIT ?
');
$stmt->execute(['pending', BATCH_LIMIT]);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No pending files.\n";
    exit(0);
}

foreach ($rows as $row) {
    if (time() - $start > TIME_BUDGET_S) {
        echo "Time budget reached, stopping — remaining files will run next invocation.\n";
        break;
    }

    if (!file_exists($row['stored_path'])) {
        $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')
            ->execute(['error', 'Stored file missing', $row['id']]);
        echo "File {$row['id']}: stored file missing, marked error.\n";
        continue;
    }

    $err = \Knowledge\FileExtractor::extract((int)$row['id'], $row['stored_path'], $row['mime_type']);
    if ($err) {
        $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')
            ->execute(['error', $err, $row['id']]);
        echo "File {$row['id']}: error - {$err}\n";
    } else {
        echo "File {$row['id']}: indexed.\n";
    }
    $done++;
}

echo "Processed {$done} file(s).\n";

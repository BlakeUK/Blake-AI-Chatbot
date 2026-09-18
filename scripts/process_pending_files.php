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
const PACE_SECONDS  = 2;    // gap between Gemini calls to stay under per-minute limits

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

    if ($done > 0) {
        sleep(PACE_SECONDS);
    }
    $err = \Knowledge\FileExtractor::extract((int)$row['id'], $row['stored_path'], $row['mime_type']);
    if ($err) {
        $status = \Knowledge\FileQueue::recordFailure($pdo, (int)$row['id'], $err);
        if ($status === 'pending') {
            // Rate limited: the file stays queued. Stop this run so the
            // rest of the batch doesn't hammer the limit; next minute's
            // run picks up where this one left off.
            echo "File {$row['id']}: Gemini rate limit, left queued; stopping this run.\n";
            break;
        }
        echo "File {$row['id']}: error - {$err}\n";
    } else {
        // Near-duplicate check (flagged for review, never auto-deleted) -
        // exact duplicates were already caught before this file was
        // queued (see import_urls.php/files.php), so only fuzzy overlap
        // is left to check now that the real extracted text exists.
        $matches = \Knowledge\Dedup::findNearDuplicates(
            \Knowledge\Dedup::reconstructText('file', (int)$row['id']), 'file', (int)$row['id']
        );
        if ($matches) {
            \Knowledge\Dedup::flag('file', (int)$row['id'], $matches);
        }
        echo "File {$row['id']}: indexed.\n";
    }
    $done++;
}

echo "Processed {$done} file(s).\n";

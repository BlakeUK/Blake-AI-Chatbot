#!/usr/bin/env php
<?php
// scripts/process_product_pages.php — run on a schedule (e.g. cron every
// minute) to apply the confirmed product-page template to any
// product_page_extractions row left in 'pending' status.
//
// Same reasoning as process_pending_files.php: queuing a batch of pages
// shouldn't be gated by per-page Gemini latency in one blocking request,
// so product_page_queue.php just inserts pending rows and this script
// works through them a batch at a time, safe to run concurrently/
// frequently since each invocation only claims rows still pending.

require dirname(__DIR__) . '/src/bootstrap.php';

const BATCH_LIMIT   = 20;   // pages per run - same as the file-processing cron
const TIME_BUDGET_S = 240;  // stop picking up new pages after this long

$pdo   = db();
$start = time();
$done  = 0;

$templateRow = $pdo->prepare('SELECT value FROM settings WHERE key=?');
$templateRow->execute(['product_extract_template']);
$templateJson = $templateRow->fetchColumn();

if (!$templateJson) {
    echo "No confirmed template — nothing to apply. Confirm one under Products first.\n";
    exit(0);
}

$template = json_decode($templateJson, true);
$shape     = $template['shape'] ?? null;
$reference = $template['example_extracted'] ?? null;

if (!is_array($shape) || !$shape) {
    echo "Confirmed template is malformed (no shape) — skipping this run.\n";
    exit(1);
}

$stmt = $pdo->prepare('
    SELECT id, url
    FROM product_page_extractions
    WHERE status = ?
    ORDER BY created_at ASC
    LIMIT ?
');
$stmt->execute(['pending', BATCH_LIMIT]);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No pending product pages.\n";
    exit(0);
}

foreach ($rows as $row) {
    if (time() - $start > TIME_BUDGET_S) {
        echo "Time budget reached, stopping — remaining pages will run next invocation.\n";
        break;
    }

    $id  = (int)$row['id'];
    $url = $row['url'];

    try {
        $result = \Products\PageExtractor::extract($url, $shape, $reference);
    } catch (\Throwable $e) {
        $pdo->prepare('UPDATE product_page_extractions SET status=?, error=?, processed_at=? WHERE id=?')
            ->execute(['error', $e->getMessage(), time(), $id]);
        echo "Page {$id} ({$url}): error - {$e->getMessage()}\n";
        $done++;
        continue;
    }

    if (!$result['ok']) {
        $pdo->prepare('UPDATE product_page_extractions SET status=?, error=?, extracted_json=?, processed_at=? WHERE id=?')
            ->execute(['error', "Gemini didn't return valid JSON", $result['raw'] ?? null, time(), $id]);
        echo "Page {$id} ({$url}): Gemini response wasn't valid JSON.\n";
        $done++;
        continue;
    }

    if (!$result['is_product_page']) {
        // Info-only page - not an error, just doesn't fit the product
        // shape. Falls back to the same lightweight title+description
        // knowledge entry the Pages tab's direct import already builds,
        // upserted by URL the same way, so this scan still gets some use
        // out of the page rather than nothing.
        try {
            $html  = \Products\PageExtractor::fetch($url);
            $title = \Html\TextCleaner::extractTitle($html) ?: $url;
            $desc  = \Html\TextCleaner::extractMetaDescription($html);
            $body  = trim($title . ($desc ? "\n\n{$desc}" : ''));

            $existing = $pdo->prepare('SELECT id FROM knowledge_entries WHERE url = ?');
            $existing->execute([$url]);
            $existingId = $existing->fetchColumn();

            if ($existingId) {
                $keId = (int)$existingId;
                $pdo->prepare('UPDATE knowledge_entries SET title=?, body=?, source=\'page_import\', updated_at=unixepoch() WHERE id=?')
                    ->execute([$title, $body, $keId]);
                $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_type=? AND source_id=?')
                    ->execute(['manual', $keId]);
            } else {
                $pdo->prepare('
                    INSERT INTO knowledge_entries (title, body, category, product_codes, url, active, source)
                    VALUES (?, ?, NULL, NULL, ?, 1, \'page_import\')
                ')->execute([$title, $body, $url]);
                $keId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url) VALUES (?,?,?,?)')
                ->execute(['manual', $keId, $title . ' ' . $body, $url]);
        } catch (\Throwable $e) {
            // The knowledge-entry fallback failing shouldn't block marking
            // this row done - the page was still correctly identified as
            // not a product, which is the useful information here.
        }

        $pdo->prepare('UPDATE product_page_extractions SET status=?, processed_at=? WHERE id=?')
            ->execute(['not_a_product', time(), $id]);
        echo "Page {$id} ({$url}): not a product page, indexed as knowledge instead.\n";
        $done++;
        continue;
    }

    $extracted = $result['extracted'];
    $code = trim((string)($extracted['product_code'] ?? ''));
    $name = trim((string)($extracted['name'] ?? ''));

    if (!$code || !$name) {
        $pdo->prepare('UPDATE product_page_extractions SET status=?, error=?, extracted_json=?, processed_at=? WHERE id=?')
            ->execute(['error', 'Extracted data is missing product_code or name — cannot import', json_encode($extracted), time(), $id]);
        echo "Page {$id} ({$url}): missing product_code/name in extraction.\n";
        $done++;
        continue;
    }

    // The page actually fetched is the authoritative URL for this product -
    // don't trust whatever (if anything) Gemini put in that field itself.
    $extracted['url'] = $url;

    try {
        \Products\Importer::import([$extracted]);
    } catch (\Throwable $e) {
        $pdo->prepare('UPDATE product_page_extractions SET status=?, error=?, extracted_json=?, processed_at=? WHERE id=?')
            ->execute(['error', 'Import failed: ' . $e->getMessage(), json_encode($extracted), time(), $id]);
        echo "Page {$id} ({$url}): import failed - {$e->getMessage()}\n";
        $done++;
        continue;
    }

    $pdo->prepare('UPDATE product_page_extractions SET status=?, product_code=?, extracted_json=?, processed_at=? WHERE id=?')
        ->execute(['product', $code, json_encode($extracted), time(), $id]);
    echo "Page {$id} ({$url}): imported as {$code}.\n";
    $done++;
}

echo "Processed {$done} page(s).\n";

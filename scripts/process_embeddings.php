<?php
// scripts/process_embeddings.php - cron (every minute): keeps the semantic
// search index (Knowledge\Embeddings) in step with knowledge chunks and
// products. Incremental: only new or changed items are embedded.
require dirname(__DIR__) . '/src/bootstrap.php';
$r = \Knowledge\Embeddings::sync(200);
if (($r['embedded'] ?? 0) || ($r['errors'] ?? 0)) {
    echo date('c') . ' embedded ' . $r['embedded'] . ', errors ' . ($r['errors'] ?? 0) . ($r['last_error'] ? ' (' . substr($r['last_error'], 0, 200) . ')' : '') . "\n";
}

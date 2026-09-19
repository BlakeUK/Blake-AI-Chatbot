<?php
// Re-chunks indexed PDF knowledge files that have a text layer, using the
// page-aware FileExtractor::chunkDocument(). Local pdftotext only - no
// Gemini calls. Scanned PDFs and non-PDF files are left untouched.
// Dry run by default; pass --apply to write.
require dirname(__DIR__) . '/src/bootstrap.php';
$apply = in_array('--apply', $argv ?? [], true);
$pdo   = db();
$rows  = $pdo->query("SELECT id, filename, stored_path FROM knowledge_files WHERE status='indexed' AND mime_type='application/pdf' ORDER BY id")->fetchAll();
$cnt   = $pdo->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE source_type='file' AND source_id=?");
$done = $skipped = $failed = 0; $before = $after = 0;
foreach ($rows as $r) {
    $path = is_file($r['stored_path']) ? $r['stored_path'] : rtrim(CFG['upload_path'], '/') . '/' . basename($r['stored_path']);
    if (!is_file($path)) { $skipped++; echo "skip (file missing) #{$r['id']} {$r['filename']}\n"; continue; }
    $text = \Knowledge\FileExtractor::pdfTextLayer($path);
    if ($text === null) { $skipped++; echo "skip (no text layer) #{$r['id']} {$r['filename']}\n"; continue; }
    $cnt->execute([$r['id']]); $b = (int)$cnt->fetchColumn();
    $new = count(\Knowledge\FileExtractor::chunkDocument($text, $r['filename']));
    $before += $b; $after += $new;
    if ($apply) {
        $err = \Knowledge\FileExtractor::extract((int)$r['id'], $path, 'application/pdf');
        if ($err !== null) { $failed++; echo "FAIL #{$r['id']} {$r['filename']}: {$err}\n"; continue; }
    }
    $done++;
    echo ($apply ? 'rechunked' : 'would rechunk') . " #{$r['id']} {$r['filename']}: {$b} -> {$new} chunks\n";
}
echo "\n" . ($apply ? 'APPLIED' : 'DRY RUN') . ": {$done} files, chunks {$before} -> {$after}, skipped {$skipped}, failed {$failed}\n";

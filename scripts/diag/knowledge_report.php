<?php
// Read-only knowledge-base health report. Run on the VPS via the
// "Diagnose knowledge base" workflow. Prints counts, recent files, chunk
// quality metrics, duplicates, orphans, FTS integrity and a scan for
// instruction-like text (possible prompt injection) in indexed content.
// Writes nothing.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$db = db();
$recentN = (int)($argv[1] ?? 8);
function q(PDO $db, string $sql, array $p = []): array { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
function one(PDO $db, string $sql, array $p = []) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function metrics(array $texts): array {
    $n = count($texts); if (!$n) return ['chunks' => 0];
    $lens = array_map('mb_strlen', $texts); sort($lens);
    $all = implode("\n", $texts);
    $chars = max(1, mb_strlen($all));
    $letters = preg_match_all('/\p{L}/u', $all);
    $repl = substr_count($all, "\u{FFFD}");
    $hyph = preg_match_all('/\p{L}- \p{Ll}/u', $all);
    $pageLines = preg_match_all('/\b(page \d+( of \d+)?|\d+ ?\/ ?\d+)\b/i', $all);
    return ['chunks' => $n, 'chars' => $chars, 'min' => $lens[0], 'median' => $lens[intdiv($n, 2)], 'max' => end($lens),
        'letter_ratio' => round($letters / $chars, 2), 'replacement_chars' => $repl,
        'split_hyphenations' => $hyph, 'page_number_marks' => $pageLines];
}
echo "== Totals\n";
foreach (q($db, "SELECT source_type, COUNT(*) c, SUM(LENGTH(chunk_text)) b FROM knowledge_chunks GROUP BY source_type") as $r) echo "chunks {$r['source_type']}: {$r['c']} ({$r['b']} bytes)\n";
foreach (q($db, "SELECT status, COUNT(*) c FROM knowledge_files GROUP BY status") as $r) echo "files {$r['status']}: {$r['c']}\n";
echo "manual entries: " . one($db, "SELECT COUNT(*) FROM knowledge_entries") . " (inactive " . one($db, "SELECT COUNT(*) FROM knowledge_entries WHERE active=0") . ")\n";
echo "products: " . one($db, "SELECT COUNT(*) FROM products") . "\n";
echo "db size: " . round(filesize(CFG['db_path']) / 1048576, 1) . " MB\n";

echo "\n== Recent files\n";
foreach (q($db, "SELECT id, filename, mime_type, status, error, category, source_url, created_at FROM knowledge_files ORDER BY id DESC LIMIT ?", [$recentN]) as $f) {
    $texts = array_column(q($db, "SELECT chunk_text FROM knowledge_chunks WHERE source_type='file' AND source_id=? ORDER BY id", [$f['id']]), 'chunk_text');
    echo "#{$f['id']} {$f['filename']} [{$f['mime_type']}] {$f['status']} cat=" . ($f['category'] ?: '-') . " " . gmdate('Y-m-d H:i', (int)$f['created_at'])
        . ($f['error'] ? " ERR=" . mb_substr($f['error'], 0, 160) : '') . "\n   " . json_encode(metrics($texts)) . "\n";
}

$pdf = q($db, "SELECT id, filename FROM knowledge_files WHERE mime_type='application/pdf' ORDER BY id DESC LIMIT 1");
if ($pdf) {
    $pid = (int)$pdf[0]['id'];
    echo "\n== Most recent PDF #{$pid} {$pdf[0]['filename']}\n";
    $texts = array_column(q($db, "SELECT chunk_text FROM knowledge_chunks WHERE source_type='file' AND source_id=? ORDER BY id", [$pid]), 'chunk_text');
    foreach (array_slice($texts, 0, 3) as $i => $t) echo "-- chunk " . ($i + 1) . " head: " . mb_substr($t, 0, 500) . "\n-- tail: " . mb_substr($t, -200) . "\n";
    if (count($texts) > 3) echo "-- last chunk head: " . mb_substr(end($texts), 0, 400) . "\n";
    // Lines repeated across the document (page headers/footers).
    $stored = one($db, "SELECT stored_path FROM knowledge_files WHERE id=?", [$pid]);
    $path = rtrim(CFG['upload_path'], '/') . '/' . basename((string)$stored);
    if (is_file($path) && trim((string)@shell_exec('command -v pdftotext')) !== '') {
        $raw = (string)shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        $pages = substr_count($raw, "\f") + 1;
        $counts = array_count_values(array_filter(array_map(fn($l) => trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '#', $l))), explode("\n", $raw)), fn($l) => mb_strlen($l) >= 4));
        arsort($counts);
        echo "pages: {$pages}\nrepeated lines (>= 30% of pages):\n";
        foreach (array_slice($counts, 0, 10, true) as $l => $c) if ($c >= max(3, $pages * 0.3)) echo "  {$c}x {$l}\n";
        $noLayout = (string)shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        echo "layout vs reading-order sample (page 2, first 600 chars):\n[layout] " . mb_substr(explode("\f", $raw)[1] ?? '', 0, 600) . "\n[reading] " . mb_substr(explode("\f", $noLayout)[1] ?? '', 0, 600) . "\n";
    } else {
        echo "stored file not available for re-analysis ({$path})\n";
    }
}

echo "\n== Quality issues\n";
echo "files indexed with 0 chunks: " . one($db, "SELECT COUNT(*) FROM knowledge_files f WHERE status='indexed' AND NOT EXISTS (SELECT 1 FROM knowledge_chunks c WHERE c.source_type='file' AND c.source_id=f.id)") . "\n";
echo "orphan file chunks: " . one($db, "SELECT COUNT(*) FROM knowledge_chunks c WHERE source_type='file' AND NOT EXISTS (SELECT 1 FROM knowledge_files f WHERE f.id=c.source_id)") . "\n";
echo "orphan manual chunks: " . one($db, "SELECT COUNT(*) FROM knowledge_chunks c WHERE source_type='manual' AND NOT EXISTS (SELECT 1 FROM knowledge_entries e WHERE e.id=c.source_id)") . "\n";
echo "chunks of inactive entries: " . one($db, "SELECT COUNT(*) FROM knowledge_chunks c JOIN knowledge_entries e ON e.id=c.source_id WHERE c.source_type='manual' AND e.active=0") . "\n";
echo "file chunks with no url: " . one($db, "SELECT COUNT(*) FROM knowledge_chunks WHERE source_type='file' AND (url IS NULL OR url='')") . "\n";
echo "tiny chunks (<80 chars): " . one($db, "SELECT COUNT(*) FROM knowledge_chunks WHERE LENGTH(chunk_text) < 80") . "\n";
echo "huge chunks (>6000 chars): " . one($db, "SELECT COUNT(*) FROM knowledge_chunks WHERE LENGTH(chunk_text) > 6000") . "\n";
$dups = q($db, "SELECT COUNT(*) n, MIN(id) a, GROUP_CONCAT(source_type||':'||source_id) src FROM knowledge_chunks GROUP BY chunk_text HAVING n > 1 ORDER BY n DESC LIMIT 10");
echo "exact duplicate chunk groups (top 10): " . count($dups) . "\n";
foreach ($dups as $d) echo "  {$d['n']}x first#{$d['a']} from " . mb_substr($d['src'], 0, 120) . "\n";
try { $db->exec("INSERT INTO knowledge_fts(knowledge_fts) VALUES('integrity-check')"); echo "FTS integrity: ok\n"; }
catch (Throwable $e) { echo "FTS integrity: FAILED " . $e->getMessage() . "\n"; }
echo "FTS rows vs chunks: " . one($db, "SELECT COUNT(*) FROM knowledge_fts") . " / " . one($db, "SELECT COUNT(*) FROM knowledge_chunks") . "\n";

echo "\n== Instruction-like text in indexed content (possible prompt injection)\n";
$pat = "(ignore (all |any )?(previous|prior|above) instructions|disregard .{0,20}instructions|system prompt|you are now|act as|new instructions|do not tell|assistant must|as an ai)";
$hits = 0;
foreach ($db->query("SELECT id, source_type, source_id, chunk_text FROM knowledge_chunks") as $r) {
    if (preg_match('/' . $pat . '/i', $r['chunk_text'], $m, PREG_OFFSET_CAPTURE)) {
        $hits++;
        if ($hits <= 10) echo "  chunk#{$r['id']} {$r['source_type']}:{$r['source_id']} … " . mb_substr(substr($r['chunk_text'], max(0, $m[0][1] - 80), 240), 0, 200) . "\n";
    }
}
echo "total: {$hits}\n";
$ph = 0; foreach ($db->query("SELECT description FROM products") as $r) if (preg_match('/' . $pat . '/i', (string)$r['description'])) $ph++;
echo "products with instruction-like description: {$ph}\n";

echo "\n== Top unanswered/low-confidence questions (last 30 days, text only)\n";
foreach (q($db, "SELECT u.content, COUNT(*) n FROM chat_messages a JOIN chat_messages u ON u.id = (SELECT MAX(id) FROM chat_messages x WHERE x.session_id=a.session_id AND x.role='user' AND x.id < a.id)
    WHERE a.role='assistant' AND a.confidence < 0.5 AND a.created_at > unixepoch()-2592000 GROUP BY LOWER(u.content) ORDER BY n DESC LIMIT 15") as $r)
    echo "  {$r['n']}x " . mb_substr(preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.]+\b|\b[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}\b|\b\d{7,}\b/i', '[redacted]', $r['content']), 0, 140) . "\n";

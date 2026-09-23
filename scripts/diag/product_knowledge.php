<?php
// scripts/diag/product_knowledge.php <CODE> - what the knowledge base holds
// for a product code (files and matching chunk text).
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$code = strtoupper($argv[1] ?? '');
$like = '%' . $code . '%';
echo "--- files whose name contains {$code}\n";
$s = db()->prepare("SELECT id, filename, status FROM knowledge_files WHERE upper(filename) LIKE ?");
$s->execute([$like]);
foreach ($s->fetchAll() as $r) echo "  #{$r['id']} {$r['filename']} ({$r['status']})\n";
echo "--- chunks mentioning {$code}\n";
$s = db()->prepare("SELECT kc.id, kc.source_type, kc.source_id, kf.filename, substr(kc.chunk_text,1,1400) t
                    FROM knowledge_chunks kc LEFT JOIN knowledge_files kf ON kf.id = kc.source_id AND kc.source_type='file'
                    WHERE kc.source_type = 'file' AND (upper(kc.chunk_text) LIKE ? OR kf.id IN (SELECT id FROM knowledge_files WHERE upper(filename) LIKE ?)) LIMIT 6");
$s->execute([$like, $like]);
foreach ($s->fetchAll() as $r) {
    echo "  [{$r['source_type']} #{$r['source_id']} " . ($r['filename'] ?? '') . "]\n";
    echo '      ' . str_replace("\n", "\n      ", trim($r['t'])) . "\n\n";
}

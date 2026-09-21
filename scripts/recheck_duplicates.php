<?php
// scripts/recheck_duplicates.php - re-checks pending near-duplicate flags
// with the variant rules (different size/colour/type/product code = not a
// duplicate) and auto-dismisses the false ones. Idempotent; run on deploy.
require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = db();
$rows = $pdo->query("SELECT id, source_type, source_id, similar_source_type, similar_source_id FROM knowledge_duplicate_flags WHERE status = 'pending'")->fetchAll();
$title = function (string $t, int $id) use ($pdo): string {
    $s = $t === 'manual' ? $pdo->prepare('SELECT title FROM knowledge_entries WHERE id = ?') : $pdo->prepare('SELECT filename FROM knowledge_files WHERE id = ?');
    $s->execute([$id]);
    return (string)($s->fetchColumn() ?: '');
};
$n = 0;
$upd = $pdo->prepare("UPDATE knowledge_duplicate_flags SET status = 'auto_dismissed' WHERE id = ?");
foreach ($rows as $r) {
    $ta = $title($r['source_type'], (int)$r['source_id']);
    $tb = $title($r['similar_source_type'], (int)$r['similar_source_id']);
    if ($ta === '' || $tb === '') continue;
    $xa = \Knowledge\Dedup::reconstructText($r['source_type'], (int)$r['source_id']);
    $xb = \Knowledge\Dedup::reconstructText($r['similar_source_type'], (int)$r['similar_source_id']);
    if (\Knowledge\Dedup::isVariantPair($ta, $xa, $tb, $xb)) { $upd->execute([$r['id']]); $n++; }
}
echo "Duplicate flags re-checked: " . count($rows) . " pending, {$n} auto-dismissed as product variants.\n";

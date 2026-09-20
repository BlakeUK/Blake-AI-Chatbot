<?php
// public/api/admin/standards.php - technical standards tier (DVB/ETSI).
// GET: documents with chunk and embedding counts
// POST {csrf, action: 'reimport'}: reload the bundled clause chunks
// POST {csrf, action: 'test', q}: is it "very technical", and which clauses match
require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::requireRole('admin', 'editor');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $docs = \Knowledge\Standards::documents();
    $emb = [];
    try {
        foreach ($pdo->query("SELECT CAST(kc.source_id AS INTEGER) d, COUNT(e.source_id) n FROM knowledge_chunks kc
                              LEFT JOIN embeddings e ON e.source_type = 'standard' AND e.source_id = CAST(kc.id AS TEXT)
                              WHERE kc.source_type = 'standard' GROUP BY kc.source_id") as $r) $emb[(int)$r['d']] = (int)$r['n'];
    } catch (\Throwable $e) {}
    foreach ($docs as &$d) { $d['embedded'] = $emb[(int)$d['id']] ?? 0; }
    unset($d);
    json_out(['documents' => $docs]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');
if (($body['action'] ?? '') === 'reimport') {
    \Auth\Admin::requireRole('admin');
    try { $r = \Knowledge\Standards::import(); } catch (\Throwable $e) { json_err($e->getMessage(), 500); }
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], 'standards_reimport', (string)$r['chunks']]);
    json_out(['ok' => true] + $r);
}
if (($body['action'] ?? '') === 'test') {
    $q = trim((string)($body['q'] ?? ''));
    if ($q === '') json_err('Enter a question');
    $tech = \Knowledge\Standards::isVeryTechnical($q);
    $hits = \Knowledge\Standards::search($q, 3);
    json_out(['ok' => true, 'very_technical' => $tech, 'hits' => array_map(fn($h) => ['text' => mb_substr($h['chunk_text'], 0, 600), 'url' => $h['url']], $hits)]);
}
json_err('Unknown action');

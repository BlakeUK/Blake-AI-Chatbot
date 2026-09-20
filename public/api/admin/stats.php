<?php
// public/api/admin/stats.php - real totals for the admin dashboard. The
// dashboard used to count the rows returned by the list endpoints, which
// are capped (50), so it under-reported (e.g. "50 products" with 913).
require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();
$pdo = db();
$n = function (string $sql) use ($pdo): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; }
};
json_out([
    'knowledge_entries' => $n('SELECT COUNT(*) FROM knowledge_entries WHERE active = 1'),
    'files_indexed'     => $n("SELECT COUNT(*) FROM knowledge_files WHERE status = 'indexed'"),
    'files_pending'     => $n("SELECT COUNT(*) FROM knowledge_files WHERE status = 'pending'"),
    'files_error'       => $n("SELECT COUNT(*) FROM knowledge_files WHERE status = 'error'"),
    'products'          => $n('SELECT COUNT(*) FROM products WHERE active = 1'),
    'chat_sessions'     => $n('SELECT COUNT(*) FROM chat_sessions'),
    'chat_sessions_7d'  => $n('SELECT COUNT(*) FROM chat_sessions WHERE created_at > unixepoch() - 7*86400'),
    'knowledge_chunks'  => $n("SELECT COUNT(*) FROM knowledge_chunks WHERE source_type != 'standard'"),
    'standard_chunks'   => $n("SELECT COUNT(*) FROM knowledge_chunks WHERE source_type = 'standard'"),
    'embeddings'        => $n('SELECT COUNT(*) FROM embeddings'),
    'open_tickets'      => $n("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')"),
]);

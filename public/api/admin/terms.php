<?php
// public/api/admin/terms.php - trade terminology (Knowledge\Terms).
// GET                         list
// POST {csrf, ...}            add or update (id to update)
// POST {csrf, action:'test', q} what a phrase matches
// DELETE ?id=                 remove
require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::requireRole('admin', 'editor');
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') json_out(['terms' => \Knowledge\Terms::all(false), 'departments' => \Chat\Handoff::DEPARTMENTS]);

if ($m === 'DELETE') {
    \Auth\Admin::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    \Knowledge\Terms::delete((int)($_GET['id'] ?? 0));
    db()->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], 'term_delete', (string)($_GET['id'] ?? '')]);
    json_out(['ok' => true]);
}
if ($m !== 'POST') json_err('Method not allowed', 405);
$b = json_body();
\Auth\Admin::verifyCsrf($b['csrf'] ?? '');
if (($b['action'] ?? '') === 'test') {
    $q = trim((string)($b['q'] ?? ''));
    $matches = \Knowledge\Terms::match($q);
    json_out(['ok' => true, 'matches' => array_map(fn($r) => ['term' => $r['term'], 'note' => $r['note'], 'department' => $r['department']], $matches),
              'search' => \Knowledge\Terms::expand($q, $matches), 'department' => \Knowledge\Terms::department($matches)]);
}
$r = \Knowledge\Terms::save($b);
if (!$r['ok']) json_err($r['error']);
db()->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')->execute([$_SESSION['admin_id'], empty($b['id']) ? 'term_add' : 'term_edit', (string)$r['id']]);
json_out($r);

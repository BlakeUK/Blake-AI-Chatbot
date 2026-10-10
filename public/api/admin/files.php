<?php
// public/api/admin/files.php: the file manager's API. Any signed-in staff member has their own files; colleagues' shares appear under
// "Shared with me". An administrator may also see and withdraw anyone's shares.
//   GET  ?action=list&parent=ID      folder contents          GET ?action=tree          my folders (for "move to")
//   GET  ?action=shares[&all=1]      my shares                GET ?action=shared_with_me, shared_view&share=ID&parent=ID
//   GET  ?action=links&id=ID         link shares for an item  GET ?action=staff         colleagues
//   GET  ?action=download&id=ID[&share=ID]   one file, or a folder as a zip;  ?action=download&ids=1,2,3  several of my items as one zip
//   POST (JSON, with csrf) mkdir, rename, delete, move, zip, unzip, upload_begin, upload_finish,
//                          share_create, share_update, share_revoke, share_reset, share_email
//   POST ?action=upload_chunk&upload=ID&offset=N  (raw bytes, token in X-CSRF-Token)

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();
rate_limit('files', 900);

use Files\Shares;
use Files\SharePage;
use Files\Store;
use Files\UploadOutOfStep;

$uid     = (int)$_SESSION['admin_id'];
$isAdmin = \Auth\Admin::role() === 'admin';
$method  = $_SERVER['REQUEST_METHOD'];
$action  = (string)($_GET['action'] ?? '');
$qint    = static fn(string $k): ?int => isset($_GET[$k]) && $_GET[$k] !== '' && (int)$_GET[$k] > 0 ? (int)$_GET[$k] : null;

$nodeOut = static function (array $n, array $sharedMap = []): array {
    return ['id' => (int)$n['id'], 'kind' => $n['kind'], 'name' => $n['name'], 'size' => (int)$n['size'], 'mime' => $n['mime'], 'updated_at' => (int)$n['updated_at'],
            'link_shares' => $sharedMap[(int)$n['id']]['link'] ?? 0, 'staff_shares' => $sharedMap[(int)$n['id']]['staff'] ?? 0];
};

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'list': {
                $parent = $qint('parent');
                if ($parent !== null && !Store::mine($parent, $uid)) json_err('That folder was not found.', 404);
                $q = db()->prepare("SELECT i.node_id, s.kind, COUNT(*) c FROM fs_share_items i JOIN fs_shares s ON s.id = i.share_id WHERE s.owner_id = ? AND s.revoked_at IS NULL GROUP BY i.node_id, s.kind");
                $q->execute([$uid]);
                $map = [];
                foreach ($q->fetchAll() as $r) $map[(int)$r['node_id']][$r['kind']] = (int)$r['c'];
                json_out(['path' => Store::path($parent), 'items' => array_map(fn($n) => $nodeOut($n, $map), Store::children($uid, $parent)),
                          'usage' => ['mine' => Store::usedBy($uid), 'total' => Store::totalUsed(), 'quota' => Store::quotaBytes()]]);
            }
            case 'tree': {
                $q = db()->prepare("SELECT id, parent_id, name FROM fs_nodes WHERE owner_id = ? AND kind = 'folder' ORDER BY LOWER(name)");
                $q->execute([$uid]);
                json_out(['folders' => array_map(fn($r) => ['id' => (int)$r['id'], 'parent' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null, 'name' => $r['name']], $q->fetchAll())]);
            }
            case 'shares':
                json_out(['shares' => Shares::listMine($uid, $isAdmin && !empty($_GET['all']))]);
            case 'links': {
                $id = $qint('id');
                if (!$id || !Store::mine($id, $uid)) json_err('That item was not found.', 404);
                json_out(['shares' => Shares::linksForNode($uid, $id)]);
            }
            case 'shared_with_me':
                json_out(['shares' => Shares::sharedWith($uid)]);
            case 'shared_view': {
                $s = Shares::get((int)($_GET['share'] ?? 0));
                if (!$s || !Shares::canStaffView($s, $uid)) json_err('That share was not found.', 404);
                $parent = $qint('parent'); $items = Shares::items((int)$s['id']); $roots = array_map(fn($n) => (int)$n['id'], $items);
                $crumbs = [];
                if ($parent !== null) {
                    $f = Store::get($parent);
                    if (!$f || $f['kind'] !== 'folder' || !Shares::covers((int)$s['id'], $parent)) json_err('That folder was not found.', 404);
                    $on = false;
                    foreach (Store::path($parent) as $p) { if (in_array($p['id'], $roots, true)) $on = true; if ($on) $crumbs[] = $p; }
                }
                $list = $parent !== null ? Store::contents($parent) : $items;
                json_out(['share' => Shares::decorate($s + ['owner_name' => (string)db()->query('SELECT username FROM admin_users WHERE id = ' . (int)$s['owner_id'])->fetchColumn()]), 'path' => $crumbs, 'items' => array_map($nodeOut, $list)]);
            }
            case 'staff': {
                $q = db()->prepare('SELECT id, username FROM admin_users WHERE id != ? ORDER BY LOWER(username)');
                $q->execute([$uid]);
                json_out(['staff' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['username']], $q->fetchAll())]);
            }
            case 'download': {
                if (isset($_GET['ids'])) {                                         // several of my own items, as one zip
                    $nodes = [];
                    foreach (array_slice(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))), 0, 200) as $i) $nodes[] = Store::mine($i, $uid) ?? json_err('An item was not found.', 404);
                    if (!$nodes) json_err('Choose something to download first.', 400);
                    SharePage::send(['kind' => 'zip', 'nodes' => $nodes, 'name' => 'Files.zip']);
                    exit;
                }
                $n = Store::get((int)($_GET['id'] ?? 0));
                if (!$n) json_err('That item was not found.', 404);
                if ((int)$n['owner_id'] !== $uid) {                                  // a colleague's file: only through a share made to this person
                    $s = Shares::get((int)($_GET['share'] ?? 0));
                    if (!$s || !Shares::canStaffView($s, $uid) || !Shares::covers((int)$s['id'], (int)$n['id'])) json_err('That item was not found.', 404);
                    Shares::log((int)$s['id'], $n['kind'] === 'file' ? 'download' : 'zip', (string)$n['name'], $_SERVER['REMOTE_ADDR'] ?? '');
                }
                if ($n['kind'] === 'file') SharePage::send(['kind' => 'file', 'node' => $n]);
                else SharePage::send(['kind' => 'zip', 'nodes' => [$n], 'name' => Store::cleanName((string)$n['name']) . '.zip']);
                exit;
            }
        }
        json_err('Unknown action', 400);
    }
    if ($method !== 'POST') json_err('Method not allowed', 405);

    // ---- uploading a piece (raw bytes) ----
    if ($action === 'upload_chunk') {
        \Auth\Admin::verifyCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $data = (string)file_get_contents('php://input');
        $got = Store::chunk((string)($_GET['upload'] ?? ''), $uid, (int)($_GET['offset'] ?? -1), $data);
        json_out(['received' => $got]);
    }

    $b = json_body();
    \Auth\Admin::verifyCsrf((string)($b['csrf'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($i) => $i > 0));
    $parent = isset($b['parent']) && (int)$b['parent'] > 0 ? (int)$b['parent'] : null;
    @set_time_limit(180);

    switch ($b['action'] ?? '') {
        case 'mkdir':
            $id = Store::mkdir($uid, $parent, (string)($b['name'] ?? ''), !empty($b['reuse']));
            json_out(['id' => $id, 'node' => $nodeOut(Store::get($id))]);
        case 'rename':
            json_out(['name' => Store::rename($uid, (int)($b['id'] ?? 0), (string)($b['name'] ?? ''))]);
        case 'delete':
            json_out(['deleted' => Store::delete($uid, $ids)]);
        case 'move':
            Store::move($uid, $ids, $parent);
            json_out(['ok' => true]);
        case 'zip':
            json_out(['node' => $nodeOut(Store::zip($uid, $ids, $parent))]);
        case 'unzip':
            json_out(['node' => $nodeOut(Store::unzip($uid, (int)($b['id'] ?? 0)))]);
        case 'upload_begin':
            json_out(['upload' => Store::beginUpload($uid, $parent, (string)($b['name'] ?? ''), (int)($b['size'] ?? -1))]);
        case 'upload_finish':
            json_out(['node' => $nodeOut(Store::finishUpload((string)($b['upload'] ?? ''), $uid))]);
        case 'share_create': {
            $s = Shares::create($uid, (string)($b['kind'] ?? ''), $ids, $b);
            json_out(['share' => Shares::decorate($s)]);
        }
        case 'share_update':
            json_out(['share' => Shares::decorate(Shares::update($uid, (int)($b['id'] ?? 0), $b, $isAdmin))]);
        case 'share_revoke':
            Shares::revoke($uid, (int)($b['id'] ?? 0), $isAdmin);
            json_out(['ok' => true]);
        case 'share_reset':
            json_out(['share' => Shares::decorate(Shares::reset($uid, (int)($b['id'] ?? 0), $isAdmin))]);
        case 'share_email': {
            rate_limit('files_mail', 12);
            $s = Shares::get((int)($b['id'] ?? 0));
            if (!$s || (!$isAdmin && (int)$s['owner_id'] !== $uid)) json_err('That share was not found.', 404);
            $to = \Files\ShareMail::addresses((string)($b['to'] ?? ''));
            $who = (string)db()->query('SELECT username FROM admin_users WHERE id = ' . $uid)->fetchColumn();
            json_out(['sent' => \Files\ShareMail::send($s, $to, $who ?: 'A colleague', (string)($b['note'] ?? ''))]);
        }
    }
    json_err('Unknown action', 400);
} catch (UploadOutOfStep $e) {
    json_out(['error' => $e->getMessage(), 'received' => $e->received], 409);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('files: ' . get_class($e) . ': ' . $e->getMessage());
    json_err('Something went wrong. Please try again.', 500);
}

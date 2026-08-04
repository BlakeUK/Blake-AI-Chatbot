<?php
// public/api/admin/users.php — manage admin panel users and roles (admin only)
// GET: list | POST: create | PUT: update (role and/or password) | DELETE: remove

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('
        SELECT id, username, role, created_at, last_login, totp_enabled, failed_attempts, locked_until
        FROM admin_users
        ORDER BY created_at ASC
    ')->fetchAll();

    $deptRows = $pdo->query('SELECT admin_id, department FROM admin_user_departments')->fetchAll();
    $byAdmin = [];
    foreach ($deptRows as $d) {
        $byAdmin[$d['admin_id']][] = $d['department'];
    }
    foreach ($rows as &$r) {
        $r['departments'] = $byAdmin[$r['id']] ?? [];
    }
    unset($r);

    json_out($rows);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $role     = $body['role'] ?? 'user';

    if (!$username || !$password) json_err('username and password required');
    if (!in_array($role, \Auth\Admin::ROLES, true)) json_err('Invalid role');
    if (strlen($password) < 8) json_err('Password must be at least 8 characters');

    // Login matches usernames case-insensitively, so a case-variant of an
    // existing username (e.g. "John" vs "john") must be rejected here too -
    // otherwise it'd create an ambiguous duplicate that ties at login.
    $clash = $pdo->prepare('SELECT 1 FROM admin_users WHERE username = ? COLLATE NOCASE');
    $clash->execute([$username]);
    if ($clash->fetch()) json_err('Username already exists', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    try {
        $pdo->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')
            ->execute([$username, $hash, $role]);
    } catch (\PDOException $e) {
        json_err('Username already exists', 409);
    }

    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'user_created', $username, $role]);

    if (!empty($body['departments']) && is_array($body['departments'])) {
        _set_departments($pdo, (int)$id, $body['departments']);
    }

    json_out(['id' => $id, 'username' => $username, 'role' => $role], 201);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');

    $target = $pdo->prepare('SELECT id, role FROM admin_users WHERE id = ?');
    $target->execute([$id]);
    $target = $target->fetch();
    if (!$target) json_err('User not found', 404);

    if (!empty($body['reset_2fa'])) {
        $pdo->prepare('
            UPDATE admin_users
            SET totp_secret_enc=NULL, totp_secret_iv=NULL, totp_secret_tag=NULL, totp_enabled=0, backup_codes=NULL
            WHERE id=?
        ')->execute([$id]);
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
            ->execute([$_SESSION['admin_id'], '2fa_reset_by_admin', $id]);
        json_out(['ok' => true]);
    }

    if (!empty($body['unlock'])) {
        $pdo->prepare('UPDATE admin_users SET failed_attempts=0, locked_until=NULL WHERE id=?')
            ->execute([$id]);
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
            ->execute([$_SESSION['admin_id'], 'login_unlocked_by_admin', $id]);
        json_out(['ok' => true]);
    }

    $role = $body['role'] ?? $target['role'];
    if (!in_array($role, \Auth\Admin::ROLES, true)) json_err('Invalid role');

    if ($target['role'] === 'admin' && $role !== 'admin' && _admin_count($pdo) <= 1) {
        json_err('Cannot demote the last remaining admin');
    }

    if (!empty($body['password'])) {
        if (strlen($body['password']) < 8) json_err('Password must be at least 8 characters');
        $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE admin_users SET role=?, password=? WHERE id=?')->execute([$role, $hash, $id]);
    } else {
        $pdo->prepare('UPDATE admin_users SET role=? WHERE id=?')->execute([$role, $id]);
    }

    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
        ->execute([$_SESSION['admin_id'], 'user_updated', $id, $role]);

    if (array_key_exists('departments', $body) && is_array($body['departments'])) {
        _set_departments($pdo, $id, $body['departments']);
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$_SESSION['admin_id'], 'user_departments_updated', $id, implode(',', $body['departments']) ?: '(none)']);
    }

    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    if ($id === (int)$_SESSION['admin_id']) json_err('Cannot delete your own account');

    $target = $pdo->prepare('SELECT role FROM admin_users WHERE id = ?');
    $target->execute([$id]);
    $target = $target->fetch();
    if (!$target) json_err('User not found', 404);

    if ($target['role'] === 'admin' && _admin_count($pdo) <= 1) {
        json_err('Cannot delete the last remaining admin');
    }

    $pdo->prepare('DELETE FROM admin_users WHERE id=?')->execute([$id]);
    $pdo->prepare('INSERT INTO audit_log (admin_id, action, target) VALUES (?,?,?)')
        ->execute([$_SESSION['admin_id'], 'user_deleted', $id]);

    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

function _admin_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role='admin'")->fetchColumn();
}

function _set_departments(PDO $pdo, int $adminId, array $departments): void
{
    $valid = array_values(array_intersect(array_unique($departments), ['sales', 'technical', 'accounts']));

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM admin_user_departments WHERE admin_id=?')->execute([$adminId]);
    $ins = $pdo->prepare('INSERT INTO admin_user_departments (admin_id, department) VALUES (?,?)');
    foreach ($valid as $dept) {
        $ins->execute([$adminId, $dept]);
    }
    $pdo->commit();
}

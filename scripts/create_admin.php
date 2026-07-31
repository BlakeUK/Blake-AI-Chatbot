#!/usr/bin/env php
<?php
// scripts/create_admin.php — run once to create the first admin user
// Usage: php scripts/create_admin.php <username> <password> [role]
// role: admin (default) | editor | user

if ($argc < 3) {
    echo "Usage: php create_admin.php <username> <password> [role]\n";
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$username = $argv[1];
$password = password_hash($argv[2], PASSWORD_BCRYPT, ['cost' => 12]);
$role     = $argv[3] ?? 'admin';

if (!in_array($role, \Auth\Admin::ROLES, true)) {
    echo "Invalid role '{$role}'. Must be one of: " . implode(', ', \Auth\Admin::ROLES) . "\n";
    exit(1);
}

db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')
   ->execute([$username, $password, $role]);

echo "Admin user '{$username}' created with role '{$role}'.\n";

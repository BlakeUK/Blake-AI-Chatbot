#!/usr/bin/env php
<?php
// scripts/create_admin.php — run once to create the first admin user
// Usage: php scripts/create_admin.php <username> <password>

if ($argc < 3) {
    echo "Usage: php create_admin.php <username> <password>\n";
    exit(1);
}

define('ROOT', dirname(__DIR__));
$cfg = require ROOT . '/config/config.php';
define('CFG', $cfg);
require ROOT . '/src/bootstrap.php';

$username = $argv[1];
$password = password_hash($argv[2], PASSWORD_BCRYPT, ['cost' => 12]);

db()->prepare('INSERT INTO admin_users (username, password) VALUES (?, ?)')
   ->execute([$username, $password]);

echo "Admin user '{$username}' created.\n";

<?php
// scripts/diag/newsroom_qr_check.php: for the "Diagnose Newsroom and QR sign-in" workflow. Run as the web user.
//   prepare : makes three throw-away staff accounts and prints a Newsroom pass and two QR sign-in codes (an admin's and a read-only user's)
//   cleanup : removes them and everything made for them
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$names = ['diag-news', 'diag-sso', 'diag-ro'];
$wipe = function () use ($names) {
    foreach ($names as $n) {
        $id = db()->prepare('SELECT id FROM admin_users WHERE username = ?'); $id->execute([$n]);
        if ($i = $id->fetchColumn()) { foreach (['qr_sso_tokens' => 'admin_id', 'news_prefs' => 'admin_id'] as $t => $c) db()->prepare("DELETE FROM {$t} WHERE {$c} = ?")->execute([$i]); }
        db()->prepare('DELETE FROM admin_users WHERE username = ?')->execute([$n]);
    }
};
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $wipe();
    $mk = function (string $name, string $role): int { db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')->execute([$name, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $role]); return (int)db()->lastInsertId(); };
    $n = $mk('diag-news', 'user'); $s = $mk('diag-sso', 'admin'); $r = $mk('diag-ro', 'user');
    echo "NEWS_PASS=" . \Newsroom\Pass::mint($n) . "\nSSO_CODE=" . \Qr\Sso::mint($s) . "\nSSO_CODE_RO=" . \Qr\Sso::mint($r) . "\n";
    exit(0);
}
if ($mode === 'cleanup') { $wipe(); echo "temporary accounts removed\n"; exit(0); }
fwrite(STDERR, "usage: php newsroom_qr_check.php prepare|cleanup\n"); exit(1);

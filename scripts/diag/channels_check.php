<?php
// scripts/diag/channels_check.php: team chat on the LIVE server. Run as the web user. Three throw-away staff accounts go through the whole life of a group
// (make, message, mute, mention, add, remove, leave, owner passes on, delete) against the real database, then everything is removed.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
use Chat\Channels as C;

$bad = 0; $out = static function (string $what, bool $ok, string $extra = '') use (&$bad) { echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . ($ok ? '' : "   -> {$extra}") . "\n"; if (!$ok) $bad++; };
$names = ['diag-ch-a' => 'admin', 'diag-ch-b' => 'editor', 'diag-ch-c' => 'user']; $ids = [];
$wipe = function () use ($names) {
    foreach (array_keys($names) as $n) {
        $q = db()->prepare('SELECT id FROM admin_users WHERE username = ?'); $q->execute([$n]); if (!($i = $q->fetchColumn())) continue;
        foreach (db()->query("SELECT channel_id FROM channel_members WHERE admin_id = {$i}")->fetchAll(\PDO::FETCH_COLUMN) as $ch) foreach (['channel_messages', 'channel_members'] as $t) db()->exec("DELETE FROM {$t} WHERE channel_id = " . (int)$ch);
        db()->exec('DELETE FROM channels WHERE id NOT IN (SELECT channel_id FROM channel_members)');
        db()->exec("DELETE FROM channel_events WHERE recipient_id = {$i}"); db()->exec("DELETE FROM admin_users WHERE id = {$i}");
    }
};
$wipe();
echo "== the live database\n";
$cols = array_column(db()->query('PRAGMA table_info(channel_members)')->fetchAll(), 'name'); $out('the group columns exist (muted, last_read_id)', in_array('muted', $cols) && in_array('last_read_id', $cols), implode(',', $cols));
$out('the notices column exists on messages', in_array('kind', array_column(db()->query('PRAGMA table_info(channel_messages)')->fetchAll(), 'name'))); $out('the description column exists on channels', in_array('description', array_column(db()->query('PRAGMA table_info(channels)')->fetchAll(), 'name')));
$out('the events table exists', (bool)db()->query("SELECT 1 FROM sqlite_master WHERE name = 'channel_events'")->fetchColumn());
foreach ($names as $n => $role) { db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')->execute([$n, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $role]); $ids[$n] = (int)db()->lastInsertId(); }
[$a, $b, $c] = array_values($ids);
try {
    echo "== a group's life\n";
    $base = C::notifications($c, 0, 0, true); $g = C::create($b, 'Diag group', [$c], 'live check');
    $out('a group is made, with a notice of who made it and who was added', str_contains(end(C::detail($g, $b)['messages'])['content'], 'diag-ch-b created the group and added diag-ch-c'));
    $out('the person added is told', array_column(C::notifications($c, $base['max_id'], $base['event_max_id'])['events'], 'kind') === ['added']);
    $refused = static function (callable $f) { try { $f(); return false; } catch (\DomainException $e) { return true; } };
    $out('an ordinary member cannot add, rename or delete', $refused(fn() => C::addMembers($g, $c, [$a])) && $refused(fn() => C::rename($g, $c, 'x')) && $refused(fn() => C::delete($g, $c)));
    $out('someone outside it cannot read it', $refused(fn() => C::detail($g, $a)));
    $m0 = C::notifications($c, 0, 0, true)['max_id']; C::post($g, $b, 'hello group'); $n = C::notifications($c, $m0, 0)['notifications'];
    $out('a new message is announced to the other member (and not to the sender)', count($n) === 1 && $n[0]['reason'] === 'message' && C::notifications($b, $m0, 0)['notifications'] === []);
    C::mute($g, $c, true); $m1 = C::notifications($c, 0, 0, true)['max_id']; C::post($g, $b, 'plain chatter'); C::post($g, $b, 'hi @diag-ch-c look');
    $out('muted: ordinary chatter is silent, a mention still gets through', array_column(C::notifications($c, $m1, 0)['notifications'], 'reason') === ['mention']);
    $out('unread is counted by message, and reading clears it', array_values(array_filter(C::listFor($c), fn($r) => $r['id'] === $g))[0]['unread_count'] === 3 && (C::markRead($g, $c) ?: true) && array_values(array_filter(C::listFor($c), fn($r) => $r['id'] === $g))[0]['unread_count'] === 0);
    $out('an administrator who is not a member can still manage it', C::addMembers($g, $a, [$a]) === [$a]);
    C::removeMember($g, $b, $c); $out('removing tells the person and records it', end(C::detail($g, $b)['messages'])['content'] === 'diag-ch-b removed diag-ch-c' && in_array('removed', array_column(C::notifications($c, 0, 0)['events'], 'kind')));
    C::leave($g, $b); $out('the owner leaving passes the group to the earliest member', C::detail($g, $a)['owner_id'] === $a && str_contains(end(C::detail($g, $a)['messages'])['content'], 'is now the owner'));
    $g2 = C::create($a, 'Diag two', [$b]); C::delete($g2, $a); $out('deleting removes it and tells the other member', C::get($g2) === null && in_array('deleted', array_column(C::notifications($b, 0, 0)['events'], 'kind')));
    $dm = C::dm($a, $b); $out('a one-to-one chat is found again, not duplicated', C::dm($b, $a) === $dm && C::dm($a, $b) === $dm); $out('and cannot be changed', $refused(fn() => C::rename($dm, $a, 'x')) && $refused(fn() => C::delete($dm, $a)));
    $row = C::listFor($a)[0]; $out('the chat list carries what the screen and older apps need', !array_diff(['id', 'name', 'is_dm', 'display_name', 'unread_count', 'last_message', 'muted', 'member_count'], array_keys($row)), implode(',', array_keys($row)));
} catch (\Throwable $e) { $out('no unexpected error', false, get_class($e) . ': ' . $e->getMessage()); }
$wipe(); $left = (int)db()->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'diag-ch-%'")->fetchColumn() + (int)db()->query("SELECT COUNT(*) FROM channels WHERE name LIKE 'Diag %'")->fetchColumn();
$out('everything made by this check is removed', $left === 0, (string)$left);
echo "\nRESULT: " . ($bad ? "{$bad} FAILED" : 'ALL PASSED') . "\n"; exit($bad ? 1 : 0);

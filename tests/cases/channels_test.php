<?php
// Staff team chat: groups, one-to-one chats, who may do what, and who gets told.

declare(strict_types=1);

use Chat\Channels as C;

/** Runs $fn and checks it was refused with the given kind of exception and a message containing $contains. */
function tch_refused(callable $fn, string $class, string $contains = ''): void
{
    try { $fn(); } catch (\Throwable $e) {
        assert_true($e instanceof $class, 'expected ' . $class . ' but got ' . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== '') assert_str_contains($contains, $e->getMessage());
        return;
    }
    assert_true(false, "expected a refusal ({$class}" . ($contains !== '' ? ", {$contains}" : '') . ')');
}
function tch_no(callable $fn, string $contains = ''): void { tch_refused($fn, \DomainException::class, $contains); }
function tch_bad(callable $fn, string $contains = ''): void { tch_refused($fn, \InvalidArgumentException::class, $contains); }

/** @return array{ann:int,bob:int,cat:int,dan:int,eve:int} ann is a staff administrator, bob and dan are editors, cat and eve are read-only. */
function tch_reset(): array
{
    foreach (['channel_events', 'channel_messages', 'channel_members', 'channels'] as $t) db()->exec("DELETE FROM {$t}");
    db()->exec("DELETE FROM admin_users WHERE username LIKE 'tc-%'");
    $ids = [];
    foreach (['ann' => 'admin', 'bob' => 'editor', 'cat' => 'user', 'dan' => 'editor', 'eve' => 'user'] as $n => $role) {
        db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')->execute(["tc-{$n}", 'x', $role]); $ids[$n] = (int)db()->lastInsertId();
    }
    return $ids;
}
function tch_msgs(int $ch, int $as): array { return C::detail($ch, $as)['messages']; }
function tch_last(int $ch, int $as): array { $m = tch_msgs($ch, $as); return end($m); }
function tch_events(int $who): array { $q = db()->prepare('SELECT kind, actor_name, channel_name, channel_id FROM channel_events WHERE recipient_id = ? ORDER BY id'); $q->execute([$who]); return $q->fetchAll(); }
function tch_joined(int $ch, int $who, int $at): void { db()->prepare('UPDATE channel_members SET joined_at = ? WHERE channel_id = ? AND admin_id = ?')->execute([$at, $ch, $who]); }

suite('Team chat: making groups and one-to-one chats');

test('a group has an owner, the people chosen, a notice saying who made it, and tells the others they were added', function () {
    $u = tch_reset(); $id = C::create($u['bob'], "  Parts \n  team \x01 ", [$u['cat'], $u['dan'], $u['cat'], 99999, 0, -3, 'x'], 'For orders and stock');
    $d = C::detail($id, $u['bob']);
    assert_equal('Parts team', $d['name']); assert_equal('For orders and stock', $d['description']); assert_equal($u['bob'], $d['owner_id']); assert_equal(0, $d['is_dm']);
    assert_equal(['tc-bob', 'tc-cat', 'tc-dan'], array_column($d['members'], 'username'), 'once each, the unknown ones ignored, the maker included');
    assert_true($d['can_manage']); assert_false(C::detail($id, $u['cat'])['can_manage']);
    assert_equal(['system', 'tc-bob created the group and added tc-cat and tc-dan'], [$d['messages'][0]['kind'], $d['messages'][0]['content']]);
    assert_equal([['kind' => 'added', 'actor_name' => 'tc-bob', 'channel_name' => 'Parts team', 'channel_id' => $id]], tch_events($u['cat'])); assert_count(1, tch_events($u['dan'])); assert_equal([], tch_events($u['bob']), 'the maker is not told they were added');
    $solo = C::create($u['bob'], 'Notes to self', []); assert_equal('tc-bob created the group', tch_last($solo, $u['bob'])['content']);
});

test('a group needs a sensible name and no more than a hundred people', function () {
    $u = tch_reset();
    foreach (['', '   ', "\n\t", "\x01\x02"] as $bad) tch_bad(fn() => C::create($u['bob'], $bad, []), 'name');
    assert_equal(C::MAX_NAME, mb_strlen(C::get(C::create($u['bob'], str_repeat('é', 200), []))['name'])); assert_equal(C::MAX_DESCRIPTION, mb_strlen(C::get(C::create($u['bob'], 'x', [], str_repeat('d', 999)))['description']));
    $many = []; for ($i = 0; $i < 105; $i++) { db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')->execute(["tc-m{$i}", 'x', 'user']); $many[] = (int)db()->lastInsertId(); }
    tch_bad(fn() => C::create($u['bob'], 'Big', $many), 'at most 100');
    $ok = C::create($u['bob'], 'Exactly 100', array_slice($many, 0, 99)); assert_count(100, C::detail($ok, $u['bob'])['members']);
    tch_bad(fn() => C::addMembers($ok, $u['bob'], [$many[100]]), 'at most 100');
});

test('a one-to-one chat is found again from either side, can be with yourself, and refuses a stranger', function () {
    $u = tch_reset(); $a = C::dm($u['bob'], $u['cat']);
    assert_equal($a, C::dm($u['bob'], $u['cat'])); assert_equal($a, C::dm($u['cat'], $u['bob']), 'the same chat from the other side');
    assert_true($a !== C::dm($u['bob'], $u['dan'])); $self = C::dm($u['bob'], $u['bob']); assert_equal($self, C::dm($u['bob'], $u['bob'])); assert_count(1, C::detail($self, $u['bob'])['members']);
    tch_bad(fn() => C::dm($u['bob'], 99999), 'Unknown');
    $d = C::detail($a, $u['bob']); assert_equal(['tc-cat', 1, $u['cat']], [$d['display_name'], $d['is_dm'], $d['other_id']]); assert_equal('tc-bob', C::detail($a, $u['cat'])['display_name'], 'each side sees the other person');
    $g = C::create($u['bob'], 'Group of two', [$u['cat']]); assert_true($g !== $a, 'a two-person group is not a one-to-one chat');
});

suite('Team chat: who may change a group');

test('the owner and a staff administrator can manage a group; other members and outsiders cannot', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Sales', [$u['cat'], $u['dan']]);
    foreach (['bob' => true, 'ann' => true, 'cat' => false, 'dan' => false, 'eve' => false] as $who => $may) {
        $can = fn() => C::canManage(C::get($g), $u[$who]); assert_equal($may, $can(), "{$who} manage");
    }
    foreach ([fn($as) => C::addMembers($g, $as, [$u['eve']]), fn($as) => C::removeMember($g, $as, $u['dan']), fn($as) => C::rename($g, $as, 'New'), fn($as) => C::setDescription($g, $as, 'x'), fn($as) => C::delete($g, $as)] as $i => $act) {
        tch_no(fn() => $act($u['cat']), 'owner or an administrator'); tch_no(fn() => $act($u['eve']));      // a member who is not the owner; someone not in it at all
        assert_true(C::get($g) !== null && C::get($g)['name'] === 'Sales', "refused action {$i} changed nothing");
    }
    assert_equal(['tc-bob', 'tc-cat', 'tc-dan'], array_column(C::detail($g, $u['bob'])['members'], 'username'));
    assert_equal([$u['eve']], C::addMembers($g, $u['ann'], [$u['eve']]), 'a staff administrator who is not in the group can still add to it');
    tch_no(fn() => C::detail($g, $u['ann']) && C::detail($g, 99999), 'not in that chat');
    C::rename($g, $u['ann'], 'Sales team'); assert_equal('Sales team', C::get($g)['name']);
});

test('a one-to-one chat cannot be changed by anyone, not even an administrator', function () {
    $u = tch_reset(); $dm = C::dm($u['bob'], $u['cat']);
    foreach (['bob', 'cat', 'ann'] as $who) foreach ([fn() => C::addMembers($dm, $u[$who], [$u['dan']]), fn() => C::removeMember($dm, $u[$who], $u['cat']), fn() => C::rename($dm, $u[$who], 'x'), fn() => C::setDescription($dm, $u[$who], 'x'), fn() => C::delete($dm, $u[$who]), fn() => C::leave($dm, $u[$who])] as $act) tch_no($act);
    assert_count(2, C::detail($dm, $u['bob'])['members']); assert_false(C::detail($dm, $u['bob'])['can_manage']);
    C::mute($dm, $u['cat'], true); assert_equal(1, C::detail($dm, $u['cat'])['muted'], 'muting your side of it is allowed');
});

suite('Team chat: adding, removing, leaving, renaming, deleting');

test('adding people tells them, records it in the chat, and ignores people already in it and people who do not exist', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Aerials', [$u['cat']]);
    assert_equal([$u['dan'], $u['eve']], C::addMembers($g, $u['bob'], [$u['dan'], $u['cat'], $u['eve'], 99999, $u['dan']]));
    assert_equal('tc-bob added tc-dan and tc-eve', tch_last($g, $u['bob'])['content']); assert_equal('added', tch_events($u['dan'])[0]['kind']); assert_equal('tc-bob', tch_events($u['eve'])[0]['actor_name']);
    $n = count(tch_msgs($g, $u['bob'])); assert_equal([], C::addMembers($g, $u['bob'], [$u['cat'], $u['dan']])); assert_equal([], C::addMembers($g, $u['bob'], [])); assert_count($n, tch_msgs($g, $u['bob']), 'adding nobody leaves no notice');
    assert_count(1, tch_events($u['cat']), 'someone already in it is not told again');
});

test('removing someone tells them, records it, and never removes the owner; removing yourself is leaving', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Aerials', [$u['cat'], $u['dan']]);
    C::removeMember($g, $u['bob'], $u['cat']);
    assert_false(C::isMember($g, $u['cat'])); assert_equal('tc-bob removed tc-cat', tch_last($g, $u['bob'])['content']); assert_equal(['added', 'removed'], array_column(tch_events($u['cat']), 'kind')); assert_equal('Aerials', tch_events($u['cat'])[1]['channel_name']);
    tch_no(fn() => C::detail($g, $u['cat']), 'not in that chat'); tch_no(fn() => C::post($g, $u['cat'], 'hello'), 'not in that chat');
    tch_bad(fn() => C::removeMember($g, $u['bob'], $u['cat']), 'not in the group');
    tch_no(fn() => C::removeMember($g, $u['ann'], $u['bob']), 'owner cannot be removed');
    C::removeMember($g, $u['dan'], $u['dan']); assert_false(C::isMember($g, $u['dan'])); assert_equal('tc-dan left', tch_last($g, $u['bob'])['content'], 'removing yourself is leaving');
});

test('leaving passes ownership to whoever joined first, and the last person out deletes the group', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Rota', [$u['cat'], $u['dan'], $u['eve']]);
    tch_joined($g, $u['bob'], 100); tch_joined($g, $u['eve'], 200); tch_joined($g, $u['cat'], 300); tch_joined($g, $u['dan'], 400);
    C::leave($g, $u['cat']); assert_equal($u['bob'], C::detail($g, $u['bob'])['owner_id'], 'a member leaving changes nothing about the owner'); assert_equal('tc-cat left', tch_last($g, $u['bob'])['content']);
    C::leave($g, $u['bob']);
    $d = C::detail($g, $u['eve']); assert_equal($u['eve'], $d['owner_id'], 'the earliest remaining member is the new owner'); assert_true($d['can_manage']); assert_equal('tc-bob left. tc-eve is now the owner', tch_last($g, $u['eve'])['content']);
    assert_equal([], array_filter(C::listFor($u['bob']), fn($r) => $r['id'] === $g), 'the one who left no longer sees it');
    C::leave($g, $u['dan']); C::leave($g, $u['eve']); assert_null(C::get($g), 'when nobody is left the group goes');
    assert_equal(0, (int)db()->query("SELECT COUNT(*) FROM channel_messages WHERE channel_id = {$g}")->fetchColumn()); assert_equal(0, (int)db()->query("SELECT COUNT(*) FROM channel_members WHERE channel_id = {$g}")->fetchColumn());
    tch_bad(fn() => C::leave($g, $u['bob']), 'no longer exists'); $g2 = C::create($u['bob'], 'Other', []); tch_no(fn() => C::leave($g2, $u['cat']), 'not in that chat');
});

test('renaming and changing the description are recorded; an empty name, an unchanged name and over-long text are handled', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Old name', [$u['cat']]); $n = count(tch_msgs($g, $u['bob']));
    C::rename($g, $u['bob'], "  New \n name  "); assert_equal('New name', C::get($g)['name']); assert_equal('tc-bob renamed the group to New name', tch_last($g, $u['cat'])['content']);
    C::rename($g, $u['bob'], 'New name'); assert_count($n + 1, tch_msgs($g, $u['bob']), 'the same name again leaves no second notice');
    tch_bad(fn() => C::rename($g, $u['bob'], '   '), 'name'); assert_equal('New name', C::get($g)['name']);
    assert_equal(C::MAX_NAME, mb_strlen((function () use ($g, $u) { C::rename($g, $u['bob'], str_repeat('n', 500)); return C::get($g)['name']; })()));
    C::setDescription($g, $u['bob'], "Orders\x00 and  stock"); assert_equal('Orders and stock', C::get($g)['description']); assert_equal('tc-bob changed the group description', tch_last($g, $u['bob'])['content']);
    C::setDescription($g, $u['bob'], ''); assert_equal('', C::get($g)['description'], 'it can be cleared');
});

test('deleting a group removes it and everything in it, tells everyone else, and only for the owner or an administrator', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Temporary', [$u['cat'], $u['dan']]); C::post($g, $u['cat'], 'hello'); C::post($g, $u['dan'], 'hi');
    tch_no(fn() => C::delete($g, $u['cat']), 'owner or an administrator'); assert_true(C::get($g) !== null);
    C::delete($g, $u['bob']);
    assert_null(C::get($g)); assert_equal(0, (int)db()->query("SELECT COUNT(*) FROM channel_messages WHERE channel_id = {$g}")->fetchColumn()); assert_equal(0, (int)db()->query("SELECT COUNT(*) FROM channel_members WHERE channel_id = {$g}")->fetchColumn());
    assert_equal(['added', 'deleted'], array_column(tch_events($u['cat']), 'kind')); assert_equal(['kind' => 'deleted', 'actor_name' => 'tc-bob', 'channel_name' => 'Temporary', 'channel_id' => null], tch_events($u['dan'])[1]);
    assert_equal([], tch_events($u['bob']), 'the person who deleted it is not told');
    tch_bad(fn() => C::delete($g, $u['bob']), 'no longer exists'); tch_bad(fn() => C::rename($g, $u['bob'], 'x'), 'no longer exists');
    $g2 = C::create($u['bob'], 'By an admin', [$u['cat']]); C::delete($g2, $u['ann']); assert_null(C::get($g2), 'a staff administrator can delete any group');
});

suite('Team chat: messages and what has been read');

test('a message is tidied, must have something in it, must not be too long, and a reply must point at a message in the same chat', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Chat', [$u['cat']]); $other = C::create($u['bob'], 'Elsewhere', []);
    $id = C::post($g, $u['bob'], "  hello\r\nthere\x01  "); $m = tch_last($g, $u['cat']); assert_equal(["hello\nthere", 'message', $id, 'tc-bob'], [$m['content'], $m['kind'], $m['id'], $m['username']]);
    foreach (['', '   ', "\n\n", "\x01\x02"] as $bad) tch_bad(fn() => C::post($g, $u['bob'], $bad), 'something');
    assert_true(C::post($g, $u['bob'], str_repeat('é', C::MAX_MESSAGE)) > 0); tch_bad(fn() => C::post($g, $u['bob'], str_repeat('é', C::MAX_MESSAGE + 1)), 'too long');
    $theirs = C::post($other, $u['bob'], 'in the other chat'); tch_bad(fn() => C::post($g, $u['cat'], 'reply', $theirs), 'no longer there'); tch_bad(fn() => C::post($g, $u['cat'], 'reply', 999999), 'no longer there');
    $r = C::post($g, $u['cat'], 'I agree', $id); $rm = tch_last($g, $u['bob']); assert_equal(['id' => $id, 'username' => 'tc-bob', 'text' => "hello\nthere"], $rm['reply_to']); assert_equal($r, $rm['id']);
    assert_null(tch_msgs($g, $u['bob'])[1]['reply_to']);
    tch_no(fn() => C::post($g, $u['dan'], 'x'), 'not in that chat');
});

test('what you have not read counts other people\'s messages only, by order not by clock, and reading clears it', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Chat', [$u['cat']]); $unread = fn($as) => array_values(array_filter(C::listFor($as), fn($r) => $r['id'] === $g))[0]['unread_count'];
    assert_equal(0, $unread($u['cat']), 'the notice that it was created is not unread');
    C::post($g, $u['bob'], 'one'); C::post($g, $u['bob'], 'two'); C::post($g, $u['cat'], 'mine');
    assert_equal(2, $unread($u['cat']), 'her own message is not unread'); assert_equal(1, $unread($u['bob']));
    C::markRead($g, $u['cat']); assert_equal(0, $unread($u['cat']));
    C::post($g, $u['bob'], 'three in the same second as the reading'); assert_equal(1, $unread($u['cat']), 'a message sent in the same second as reading is still unread');
    C::addMembers($g, $u['bob'], [$u['dan']]); assert_equal(1, $unread($u['cat']), 'a notice is not a message to read');
    tch_no(fn() => C::markRead($g, $u['eve']), 'not in that chat');
    $d = C::detail($g, $u['bob']); $catRead = array_values(array_filter($d['members'], fn($m) => $m['id'] === $u['cat']))[0]['last_read_id']; assert_true($catRead > 0 && $catRead < $d['max_id'], 'what each person has read is visible, for the seen ticks');
});

test('only the messages after a point are returned when asked, and only the latest two hundred otherwise, oldest first', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Long', [$u['cat']]); $ids = []; for ($i = 1; $i <= 230; $i++) $ids[] = C::post($g, $u['bob'], "m{$i}");
    $all = tch_msgs($g, $u['cat']); assert_count(200, $all); assert_equal('m230', end($all)['content']); assert_equal('m31', $all[0]['content'], 'the latest 200 of 231, oldest first');
    assert_equal(['m229', 'm230'], array_column(C::detail($g, $u['cat'], $ids[227])['messages'], 'content')); assert_equal([], C::detail($g, $u['cat'], $ids[229])['messages']); assert_equal($ids[229], C::detail($g, $u['cat'])['max_id']);
});

test('the chat list is newest activity first and shows the other person for a one-to-one chat, the last message, muted and size', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Old group', [$u['cat']]); $dm = C::dm($u['bob'], $u['dan']); $g2 = C::create($u['bob'], 'New group', [$u['cat'], $u['dan']]);
    db()->exec("UPDATE channel_messages SET created_at = 1000 WHERE channel_id = {$g}"); db()->exec("UPDATE channel_messages SET created_at = 2000 WHERE channel_id = {$g2}");
    C::post($dm, $u['dan'], 'ping'); db()->exec("UPDATE channel_messages SET created_at = 3000 WHERE channel_id = {$dm}");
    $list = C::listFor($u['bob']); assert_equal([$dm, $g2, $g], array_column($list, 'id'));
    assert_equal(['tc-dan', 1, 'ping', 'tc-dan', false, $u['dan']], [$list[0]['display_name'], $list[0]['is_dm'], $list[0]['last_message']['text'], $list[0]['last_message']['user'], $list[0]['last_message']['mine'], $list[0]['other_id']]);
    assert_equal(['New group', 3, 'system'], [$list[1]['display_name'], $list[1]['member_count'], $list[1]['last_message']['kind']]); assert_null($list[1]['other_id']);
    C::mute($g2, $u['bob'], true); assert_equal(1, array_values(array_filter(C::listFor($u['bob']), fn($r) => $r['id'] === $g2))[0]['muted']); assert_equal(0, array_values(array_filter(C::listFor($u['cat']), fn($r) => $r['id'] === $g2))[0]['muted'], 'muting is personal');
    assert_equal([$g2, $g], array_column(C::listFor($u['cat']), 'id')); assert_equal([], C::listFor($u['eve']));
});

suite('Team chat: who gets told');

test('the first look of a session only sets a starting point, even with hundreds of old messages', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Busy', [$u['cat']]); for ($i = 0; $i < 260; $i++) C::post($g, $u['bob'], "old {$i}");
    $r = C::notifications($u['cat'], 0, 0, true); assert_equal([[], []], [$r['notifications'], $r['events']]); assert_equal(C::detail($g, $u['bob'])['max_id'], $r['max_id'], 'the starting point is the very latest message');
    assert_equal(['notifications' => [], 'max' => $r['max_id']], ['notifications' => C::notifications($u['cat'], $r['max_id'], $r['event_max_id'])['notifications'], 'max' => C::notifications($u['cat'], $r['max_id'], $r['event_max_id'])['max_id']], 'nothing old is replayed as new');
    $sys = db()->query("SELECT MAX(id) FROM channel_events")->fetchColumn(); assert_equal((int)$sys, $r['event_max_id']);
});

test('every new message pops up for the others, but never your own, never a notice, and never from a chat you are not in', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Team', [$u['cat'], $u['dan']]); $dm = C::dm($u['bob'], $u['cat']); $base = C::notifications($u['cat'], 0, 0, true); $w = $base['max_id']; $ew = $base['event_max_id'];
    C::post($g, $u['bob'], 'hello team'); C::post($dm, $u['bob'], 'just you'); C::post($g, $u['cat'], 'my own'); C::addMembers($g, $u['bob'], [$u['eve']]);
    $n = C::notifications($u['cat'], $w, $ew); assert_equal([['tc-bob', 'hello team', 'message', 'Team'], ['tc-bob', 'just you', 'dm', '']], array_map(fn($r) => [$r['username'], $r['content'], $r['reason'], $r['channel_name']], $n['notifications']));
    assert_equal([], C::notifications($u['eve'], $w, 0)['notifications'], 'someone who joined afterwards is not told about what was said before');
    $private = C::create($u['bob'], 'Private', [$u['dan']]); C::post($private, $u['bob'], 'not for eve'); assert_equal([], C::notifications($u['eve'], $w, 0)['notifications'], 'nor about a chat they are not in');
    assert_equal([], array_filter(C::notifications($u['bob'], $w, 0)['notifications'], fn($r) => $r['username'] === 'tc-bob'), 'the sender is never told about their own messages');
    $again = C::notifications($u['cat'], $n['max_id'], $n['event_max_id']); assert_equal([[], $n['max_id']], [$again['notifications'], $again['max_id']], 'the watermark moves on, so nothing is shown twice');
    C::post($g, $u['bob'], 'after she joined'); assert_equal(['after she joined'], array_map(fn($r) => $r['content'], C::notifications($u['eve'], $w, 0)['notifications']), 'but she is told about what is said from now on');
});

test('a muted chat is silent, except for a mention of you or a reply to you, and muting is personal', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Noisy', [$u['cat'], $u['dan']]); $dm = C::dm($u['bob'], $u['cat']); C::mute($g, $u['cat'], true); C::mute($dm, $u['cat'], true);
    $w = C::notifications($u['cat'], 0, 0, true)['max_id']; $mine = C::post($g, $u['cat'], 'a question');
    C::post($g, $u['bob'], 'plain chatter'); C::post($g, $u['bob'], 'hey @tc-cat can you see this'); C::post($g, $u['dan'], 'an answer', $mine); C::post($g, $u['bob'], 'about @tc-catherine, not you'); C::post($dm, $u['bob'], 'a muted private message');
    assert_equal([['mention', 'hey @tc-cat can you see this'], ['reply', 'an answer']], array_map(fn($r) => [$r['reason'], $r['content']], C::notifications($u['cat'], $w, 0)['notifications']));
    assert_equal(['plain chatter', 'hey @tc-cat can you see this', 'about @tc-catherine, not you'], array_map(fn($r) => $r['content'], array_values(array_filter(C::notifications($u['dan'], $w, 0)['notifications'], fn($r) => $r['username'] === 'tc-bob'))), 'dan has not muted it');
    C::mute($g, $u['cat'], false); $w2 = C::notifications($u['cat'], 0, 0, true)['max_id']; C::post($g, $u['bob'], 'audible again'); assert_equal(['audible again'], array_map(fn($r) => $r['content'], C::notifications($u['cat'], $w2, 0)['notifications']));
});

test('being added, removed, or having a group deleted is always announced, to the right person, once', function () {
    $u = tch_reset(); $base = C::notifications($u['cat'], 0, 0, true); $g = C::create($u['bob'], 'Announce', [$u['cat'], $u['dan']]); C::mute($g, $u['cat'], true);
    $n = C::notifications($u['cat'], 1, $base['event_max_id']); assert_equal([['added', 'tc-bob', 'Announce']], array_map(fn($e) => [$e['kind'], $e['actor_name'], $e['channel_name']], $n['events']), 'even in a chat they muted');
    assert_equal([], C::notifications($u['cat'], 1, $n['event_max_id'])['events'], 'once only'); assert_equal([], C::notifications($u['bob'], 1, 0)['events'], 'not the person who did it');
    C::removeMember($g, $u['bob'], $u['cat']); assert_equal(['removed'], array_column(C::notifications($u['cat'], 1, $n['event_max_id'])['events'], 'kind')); assert_equal([], array_column(C::notifications($u['dan'], 1, 999999)['events'], 'kind'));
    $d = C::notifications($u['dan'], 1, 0)['events']; assert_equal(['added'], array_column($d, 'kind')); C::delete($g, $u['bob']);
    assert_equal(['added', 'deleted'], array_column(C::notifications($u['dan'], 1, 0)['events'], 'kind')); assert_equal(['deleted'], array_column(C::notifications($u['dan'], 1, $d[0]['id'])['events'], 'kind'));
    assert_equal([], array_filter(C::notifications($u['eve'], 1, 0)['events']), 'a person who was never in it is told nothing');
});

test('on a system with no messages yet, the starting point is 0 and the very first message is still announced', function () {
    $u = tch_reset(); $g = C::dm($u['bob'], $u['cat']);          // a one-to-one chat writes no notice, so nothing at all has been said yet
    $base = C::notifications($u['cat'], 0, 0, true); assert_equal(0, $base['max_id'], 'nothing has been said, so the starting point is 0');
    C::post($g, $u['bob'], 'the very first message');
    $n = C::notifications($u['cat'], $base['max_id'], $base['event_max_id']); assert_equal(['the very first message'], array_map(fn($r) => $r['content'], $n['notifications']), 'a starting point of 0 is a real starting point, not a first look');
});

suite('Team chat: threads and the AI (@ai)');

test('only a standalone @ai asks the AI: not an address, not another name, not inside a word', function () {
    foreach (['@ai', '@AI what is a CAT6?', 'hi @ai can you help', 'thanks @ai.', '(@ai) please', "line one\n@ai line two"] as $yes) assert_true(C::mentionsAi($yes), $yes);
    foreach (['mail me at bob@ai.com', 'ask @aiden', 'ask @ai_bot', 'email@ai', 'no mention here', 'https://x.com/@ai'] as $no) assert_false(C::mentionsAi($no), $no);
});

test('replies are grouped into one thread whose root is the first message, however deep the replies go', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Parts', [$u['cat']]);
    $root = C::post($g, $u['bob'], 'which aerial for Sheffield?'); $r1 = C::post($g, $u['cat'], 'a wideband one', $root); $r2 = C::post($g, $u['bob'], 'how many elements?', $r1); $other = C::post($g, $u['cat'], 'unrelated');
    $m = array_column(tch_msgs($g, $u['bob']), null, 'id');
    assert_null($m[$root]['thread_root_id']); assert_equal($root, $m[$r1]['thread_root_id']); assert_equal($root, $m[$r2]['thread_root_id'], 'a reply to a reply stays in the same thread'); assert_null($m[$other]['thread_root_id']);
    assert_equal($root, C::threadRoot($root)); assert_equal($root, C::threadRoot($r2));
});

test('an AI answer is shown as Max, quotes the question, is in the thread, and counts as new for the person who asked', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Parts', [$u['cat']]); $base = C::notifications($u['bob'], 0, 0, true);
    $q = C::post($g, $u['bob'], '@ai what is a CAT6 patch lead?'); $a = C::postAi($g, $u['bob'], 'A short Ethernet cable.', $q);
    $m = array_column(tch_msgs($g, $u['cat']), null, 'id'); assert_equal('Max', $m[$a]['username']); assert_equal(1, $m[$a]['is_ai']); assert_equal(0, $m[$q]['is_ai']);
    assert_equal(['id' => $q, 'username' => 'tc-bob', 'text' => '@ai what is a CAT6 patch lead?'], $m[$a]['reply_to']); assert_equal($q, $m[$a]['thread_root_id']);
    $n = C::notifications($u['bob'], $base['max_id'], $base['event_max_id']); assert_equal([['Max', 'reply']], array_map(fn($r) => [$r['username'], $r['reason']], $n['notifications']), 'the asker is told, as a reply to them');
    $list = array_column(C::listFor($u['bob']), null, 'id'); assert_equal(1, $list[$g]['unread_count']); assert_equal(['text' => 'A short Ethernet cable.', 'kind' => 'message', 'user' => 'Max', 'mine' => false], $list[$g]['last_message']);
    C::post($g, $u['cat'], 'plain message'); assert_equal(0, C::mentionsAi('plain message') ? 1 : 0);
});

test('the AI answers only a message that asks for it, once, never answers itself, and says sorry when Gemini fails', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Parts', [$u['cat']]); $seen = [];
    \Chat\TeamAi::$llm = function (string $system, string $conversation) use (&$seen): string { $seen[] = [$system, $conversation]; return 'Use RG6 for that run.'; };
    try {
        $plain = C::post($g, $u['bob'], 'nobody asked the AI'); assert_null(\Chat\TeamAi::answer($g, $plain)); assert_equal([], $seen, 'no @ai, no call');
        C::post($g, $u['cat'], 'The customer has 40m of cable to the dish, card number 4111 1111 1111 1111');
        $q = C::post($g, $u['bob'], '@ai what cable should they use?'); $id = \Chat\TeamAi::answer($g, $q); assert_true($id > 0);
        assert_equal(1, count($seen)); assert_str_contains('tc-cat: The customer has 40m of cable', $seen[0][1], 'it reads the chat'); assert_str_contains('tc-bob: what cable should they use?', $seen[0][1], 'it sees the question without the @ai');
        assert_false(str_contains($seen[0][1], '4111 1111 1111 1111'), 'card numbers never leave');
        $last = tch_last($g, $u['bob']); assert_equal([$id, 'Max', 'Use RG6 for that run.', $q], [$last['id'], $last['username'], $last['content'], $last['reply_to']['id']]);
        assert_null(\Chat\TeamAi::answer($g, $id), 'the AI never answers the AI'); assert_equal(1, count($seen));
        \Chat\TeamAi::$llm = function () { throw new \RuntimeException('boom'); };
        $q2 = C::post($g, $u['cat'], '@ai again'); $id2 = \Chat\TeamAi::answer($g, $q2); assert_str_contains('could not answer', tch_last($g, $u['bob'])['content']); assert_true($id2 > $id);
    } finally { \Chat\TeamAi::$llm = null; }
});

test('asked inside a thread, the AI reads that thread and not the rest of the chat', function () {
    $u = tch_reset(); $g = C::create($u['bob'], 'Parts', [$u['cat']]); $seen = '';
    \Chat\TeamAi::$llm = function (string $s, string $c) use (&$seen): string { $seen = $c; return 'ok'; };
    try {
        $root = C::post($g, $u['bob'], 'thread topic about masts'); C::post($g, $u['cat'], 'completely different chat about vans'); $r = C::post($g, $u['cat'], 'it is a 3m mast', $root); $q = C::post($g, $u['bob'], '@ai is that tall enough?', $r);
        \Chat\TeamAi::answer($g, $q);
        assert_str_contains('thread topic about masts', $seen); assert_str_contains('it is a 3m mast', $seen); assert_false(str_contains($seen, 'vans'), 'other chat is left out');
        assert_equal($root, tch_last($g, $u['bob'])['thread_root_id']);
    } finally { \Chat\TeamAi::$llm = null; }
});

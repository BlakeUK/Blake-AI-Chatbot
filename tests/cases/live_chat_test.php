<?php
// tests/cases/live_chat_test.php
// Regression tests for src/Chat/LiveChat.php - the human live-chat handoff
// (request -> claim -> converse -> end), and the presence check that
// gates whether the widget offers it at all.

declare(strict_types=1);

function seed_live_chat_admin(int $id, string $presence = 'offline'): void
{
    db()->prepare('INSERT INTO admin_users (id, username, password, role, presence_status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, 'live-test-admin-' . $id, 'x', 'admin', $presence]);
}

function seed_live_chat_session(string $id, string $question = 'Do you have this in stock?'): void
{
    db()->prepare('INSERT INTO chat_sessions (id, page_url) VALUES (?, ?)')->execute([$id, 'https://www.blake-uk.com/']);
    db()->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)")->execute([$id, $question]);
    db()->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', 'I am not sure about that.')")->execute([$id]);
}

suite('Chat\LiveChat — isAgentAvailable()');

test('false when no admin is online', function () {
    db()->exec("UPDATE admin_users SET presence_status = 'offline'");
    assert_false(\Chat\LiveChat::isAgentAvailable());
});

test('true when at least one admin is online', function () {
    db()->exec("UPDATE admin_users SET presence_status = 'offline'");
    seed_live_chat_admin(8101, 'online');
    assert_true(\Chat\LiveChat::isAgentAvailable());
});

test('busy does not count as available', function () {
    db()->exec("UPDATE admin_users SET presence_status = 'offline'");
    seed_live_chat_admin(8102, 'busy');
    assert_false(\Chat\LiveChat::isAgentAvailable());
});

suite('Chat\LiveChat — requestLive()');

test('creates a live_chat ticket and moves the session to live_requested', function () {
    seed_live_chat_session('live-test-1');
    $r = \Chat\LiveChat::requestLive('live-test-1');
    assert_true($r['ok']);
    assert_true($r['ticket_id'] > 0);

    $ticket = db()->prepare("SELECT channel, status FROM support_tickets WHERE id = ?");
    $ticket->execute([$r['ticket_id']]);
    $ticket = $ticket->fetch();
    assert_equal('live_chat', $ticket['channel']);

    $mode = db()->query("SELECT mode FROM chat_sessions WHERE id = 'live-test-1'")->fetchColumn();
    assert_equal('live_requested', $mode);
});

test('rejects an invalid session', function () {
    $r = \Chat\LiveChat::requestLive('does-not-exist');
    assert_false($r['ok']);
});

test('rejects a session that is already live', function () {
    seed_live_chat_session('live-test-2');
    \Chat\LiveChat::requestLive('live-test-2');
    $r = \Chat\LiveChat::requestLive('live-test-2');
    assert_false($r['ok']);
});

suite('Chat\LiveChat — claim()');

test('claiming a requested chat sets it active and assigns the ticket', function () {
    seed_live_chat_admin(8110, 'online');
    seed_live_chat_session('live-test-3');
    $req = \Chat\LiveChat::requestLive('live-test-3');

    $r = \Chat\LiveChat::claim('live-test-3', 8110);
    assert_true($r['ok']);

    $row = db()->query("SELECT mode, claimed_by FROM chat_sessions WHERE id = 'live-test-3'")->fetch();
    assert_equal('live_active', $row['mode']);
    assert_equal(8110, (int)$row['claimed_by']);

    $ticket = db()->prepare('SELECT assigned_admin_id, status FROM support_tickets WHERE id = ?');
    $ticket->execute([$req['ticket_id']]);
    $ticket = $ticket->fetch();
    assert_equal(8110, (int)$ticket['assigned_admin_id']);
    assert_equal('in_progress', $ticket['status']);
});

test('a second claim on an already-claimed chat is rejected', function () {
    seed_live_chat_admin(8111, 'online');
    seed_live_chat_admin(8112, 'online');
    seed_live_chat_session('live-test-4');
    \Chat\LiveChat::requestLive('live-test-4');

    $first  = \Chat\LiveChat::claim('live-test-4', 8111);
    $second = \Chat\LiveChat::claim('live-test-4', 8112);
    assert_true($first['ok']);
    assert_false($second['ok']);
});

test('claiming a session that was never requested is rejected', function () {
    seed_live_chat_admin(8113, 'online');
    seed_live_chat_session('live-test-5');
    $r = \Chat\LiveChat::claim('live-test-5', 8113);
    assert_false($r['ok']);
});

suite('Chat\LiveChat — sendAgentMessage() / sendCustomerMessage()');

test('the claiming admin can send a message', function () {
    seed_live_chat_admin(8120, 'online');
    seed_live_chat_session('live-test-6');
    \Chat\LiveChat::requestLive('live-test-6');
    \Chat\LiveChat::claim('live-test-6', 8120);

    $r = \Chat\LiveChat::sendAgentMessage('live-test-6', 8120, 'Hi, how can I help?');
    assert_true($r['ok']);

    $row = db()->query("SELECT role, content FROM chat_messages WHERE session_id = 'live-test-6' AND role = 'agent' ORDER BY id DESC LIMIT 1")->fetch();
    assert_equal('Hi, how can I help?', $row['content']);
});

test('a different admin cannot send on someone else\'s claimed chat', function () {
    seed_live_chat_admin(8121, 'online');
    seed_live_chat_admin(8122, 'online');
    seed_live_chat_session('live-test-7');
    \Chat\LiveChat::requestLive('live-test-7');
    \Chat\LiveChat::claim('live-test-7', 8121);

    $r = \Chat\LiveChat::sendAgentMessage('live-test-7', 8122, 'Butting in');
    assert_false($r['ok']);
});

test('an agent message cannot be sent before anyone has claimed', function () {
    seed_live_chat_session('live-test-8');
    \Chat\LiveChat::requestLive('live-test-8');
    $r = \Chat\LiveChat::sendAgentMessage('live-test-8', 9999, 'Too early');
    assert_false($r['ok']);
});

test('the customer can message during live_requested and live_active', function () {
    seed_live_chat_admin(8123, 'online');
    seed_live_chat_session('live-test-9');
    \Chat\LiveChat::requestLive('live-test-9');

    $r1 = \Chat\LiveChat::sendCustomerMessage('live-test-9', 'Are you there?');
    assert_true($r1['ok']);

    \Chat\LiveChat::claim('live-test-9', 8123);
    $r2 = \Chat\LiveChat::sendCustomerMessage('live-test-9', 'Hello?');
    assert_true($r2['ok']);
});

test('the customer cannot message a session that was never made live', function () {
    seed_live_chat_session('live-test-10');
    $r = \Chat\LiveChat::sendCustomerMessage('live-test-10', 'Hello?');
    assert_false($r['ok']);
});

test('an empty message is rejected', function () {
    seed_live_chat_admin(8124, 'online');
    seed_live_chat_session('live-test-11');
    \Chat\LiveChat::requestLive('live-test-11');
    \Chat\LiveChat::claim('live-test-11', 8124);
    $r = \Chat\LiveChat::sendAgentMessage('live-test-11', 8124, '   ');
    assert_false($r['ok']);
});

suite('Chat\LiveChat — endLive()');

test('the claiming admin can end the chat, which resolves the ticket', function () {
    seed_live_chat_admin(8130, 'online');
    seed_live_chat_session('live-test-12');
    $req = \Chat\LiveChat::requestLive('live-test-12');
    \Chat\LiveChat::claim('live-test-12', 8130);

    $r = \Chat\LiveChat::endLive('live-test-12', 8130);
    assert_true($r['ok']);

    $mode = db()->query("SELECT mode FROM chat_sessions WHERE id = 'live-test-12'")->fetchColumn();
    assert_equal('live_ended', $mode);

    $status = db()->prepare('SELECT status FROM support_tickets WHERE id = ?');
    $status->execute([$req['ticket_id']]);
    assert_equal('resolved', $status->fetchColumn());
});

test('a session that is not live cannot be ended', function () {
    seed_live_chat_session('live-test-13');
    $r = \Chat\LiveChat::endLive('live-test-13', 1);
    assert_false($r['ok']);
});

test('only the claiming admin can end the chat', function () {
    seed_live_chat_admin(8131, 'online');
    seed_live_chat_admin(8132, 'online');
    seed_live_chat_session('live-test-14');
    \Chat\LiveChat::requestLive('live-test-14');
    \Chat\LiveChat::claim('live-test-14', 8131);

    $r = \Chat\LiveChat::endLive('live-test-14', 8132);
    assert_false($r['ok']);
});

suite('Chat\LiveChat — newMessagesForCustomer()');

test('returns only agent and system messages, never the customer\'s own', function () {
    seed_live_chat_admin(8140, 'online');
    seed_live_chat_session('live-test-15');
    \Chat\LiveChat::requestLive('live-test-15');
    \Chat\LiveChat::claim('live-test-15', 8140); // inserts a 'system' join message
    \Chat\LiveChat::sendCustomerMessage('live-test-15', 'Still there?');
    \Chat\LiveChat::sendAgentMessage('live-test-15', 8140, 'Yes, one moment.');

    $r = \Chat\LiveChat::newMessagesForCustomer('live-test-15', 0);
    assert_true($r['ok']);
    $roles = array_column($r['messages'], 'role');
    assert_not_contains('user', $roles);
    assert_contains('system', $roles);
    assert_contains('agent', $roles);
});

test('after_id only returns messages newer than that id', function () {
    seed_live_chat_admin(8141, 'online');
    seed_live_chat_session('live-test-16');
    \Chat\LiveChat::requestLive('live-test-16');
    \Chat\LiveChat::claim('live-test-16', 8141);

    $before = \Chat\LiveChat::newMessagesForCustomer('live-test-16', 0);
    $lastId = end($before['messages'])['id'];

    \Chat\LiveChat::sendAgentMessage('live-test-16', 8141, 'A new message');
    $after = \Chat\LiveChat::newMessagesForCustomer('live-test-16', $lastId);

    assert_count(1, $after['messages']);
    assert_equal('A new message', $after['messages'][0]['content']);
});

test('reports the session mode alongside the messages', function () {
    seed_live_chat_admin(8142, 'online');
    seed_live_chat_session('live-test-17');
    \Chat\LiveChat::requestLive('live-test-17');
    \Chat\LiveChat::claim('live-test-17', 8142);
    \Chat\LiveChat::endLive('live-test-17', 8142);

    $r = \Chat\LiveChat::newMessagesForCustomer('live-test-17', 0);
    assert_equal('live_ended', $r['mode']);
});

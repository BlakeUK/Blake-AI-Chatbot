<?php
// tests/cases/support_desk_test.php
// Support desk: opening hours, department handoff, transfer, the 3-minute
// no-answer timeout, AI ticket intake, staff notes, ticket emails, and the
// console queue's pop-up targeting.

declare(strict_types=1);

function sd_admin(int $id, string $presence = 'online', array $depts = [], string $role = 'editor'): void
{
    db()->prepare('INSERT INTO admin_users (id, username, password, role, presence_status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, 'sam.jones' . $id, 'x', $role, $presence]);
    foreach ($depts as $d) {
        db()->prepare('INSERT INTO admin_user_departments (admin_id, department) VALUES (?, ?)')->execute([$id, $d]);
    }
}
function sd_session(string $id, string $q = 'My aerial has no signal after the storm'): void
{
    db()->prepare('INSERT INTO chat_sessions (id, page_url) VALUES (?, ?)')->execute([$id, 'https://www.blake-uk.com/']);
    db()->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)")->execute([$id, $q]);
    db()->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', 'Let me help.')")->execute([$id]);
}
function sd_mode(string $id): string { return (string)db()->query("SELECT mode FROM chat_sessions WHERE id = " . db()->quote($id))->fetchColumn(); }
function sd_last(string $id, string $role): string
{
    $s = db()->prepare('SELECT content FROM chat_messages WHERE session_id = ? AND role = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$id, $role]);
    return (string)$s->fetchColumn();
}
function sd_reset_presence(): void { db()->exec("UPDATE admin_users SET presence_status = 'offline'"); }

suite('Support\Hours — opening times (UK)');

test('Mon-Thu 08:00-16:30, Fri 08:00-16:00, weekends closed', function () {
    \Support\Hours::$override = null;
    $t = fn(string $s) => (new DateTimeImmutable($s, new DateTimeZone('Europe/London')))->getTimestamp();
    assert_true(\Support\Hours::isOpen($t('2026-09-16 08:00')));   // Wed
    assert_true(\Support\Hours::isOpen($t('2026-09-16 16:29')));
    assert_true(!\Support\Hours::isOpen($t('2026-09-16 16:30')));
    assert_true(!\Support\Hours::isOpen($t('2026-09-16 07:59')));
    assert_true(\Support\Hours::isOpen($t('2026-09-18 15:59')));   // Fri
    assert_true(!\Support\Hours::isOpen($t('2026-09-18 16:00')));
    assert_true(!\Support\Hours::isOpen($t('2026-09-19 11:00')));   // Sat
    assert_true(!\Support\Hours::isOpen($t('2026-09-20 11:00')));   // Sun
    assert_equal('on Monday at 8:00am', \Support\Hours::nextOpening($t('2026-09-18 17:00')));
    assert_equal('tomorrow at 8:00am', \Support\Hours::nextOpening($t('2026-09-16 18:00')));
    assert_equal('today at 8:00am', \Support\Hours::nextOpening($t('2026-09-17 06:00')));
});

suite('Chat\Handoff — routing a chat to a department');

test('wantsHuman() spots requests for a person, not ordinary questions', function () {
    foreach (['Can I speak to a human please', 'I want to talk to someone', 'real person please', 'can i chat with a member of staff', 'customer service'] as $m) {
        assert_true(\Chat\Handoff::wantsHuman($m), $m);
    }
    foreach (['Which aerial do I need?', 'Do you sell CAT6 cable', 'person counting camera'] as $m) {
        assert_true(!\Chat\Handoff::wantsHuman($m), $m);
    }
});

test('open with staff online: waits for the department and tells the customer', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9001, 'online', ['sales']);
    sd_session('sd-1');
    $r = \Chat\Handoff::start('sd-1', 'customer_request');
    assert_equal('live_requested', $r['mode']);
    assert_equal('live_requested', sd_mode('sd-1'));
    assert_true(str_contains(sd_last('sd-1', 'system'), 'our Sales team'));
});

test('outside opening hours: apologises with the hours and starts taking ticket details', function () {
    \Support\Hours::$override = false;
    sd_session('sd-2');
    $r = \Chat\Handoff::start('sd-2', 'customer_request');
    assert_equal('intake', $r['mode']);
    $s = db()->query("SELECT content FROM chat_messages WHERE session_id = 'sd-2' AND role = 'bot' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(str_contains($s[0], 'closed') && str_contains($s[0], 'Monday to Thursday 8:00am to 4:30pm'));
    assert_equal('Could I take your name, please?', $s[1]);
    \Support\Hours::$override = true;
});

test('open but nobody online: goes straight to ticket intake with an apology', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_session('sd-3');
    $r = \Chat\Handoff::start('sd-3', 'ai_unsure');
    assert_equal('intake', $r['mode']);
    assert_true(str_contains(db()->query("SELECT content FROM chat_messages WHERE session_id = 'sd-3' AND role = 'bot' ORDER BY id LIMIT 1")->fetchColumn(), 'busy'));
});

suite('Chat\Handoff — 3-minute timeout, transfer, notes');

test('nobody accepts within 3 minutes: Max apologises and takes ticket details', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9002, 'online', ['technical']);
    sd_session('sd-4');
    $t0 = time();
    \Chat\Handoff::start('sd-4', 'customer_request', $t0);
    assert_equal(0, \Chat\Handoff::sweepTimeouts($t0 + 179, 'sd-4'));
    assert_equal('live_requested', sd_mode('sd-4'));
    assert_equal(1, \Chat\Handoff::sweepTimeouts($t0 + 181, 'sd-4'));
    assert_equal('intake', sd_mode('sd-4'));
    assert_equal('Could I take your name, please?', sd_last('sd-4', 'bot'));
});

test('a staff member can accept during intake, which stops the intake', function () {
    sd_admin(9003, 'online', ['sales']);
    assert_true(\Chat\Handoff::claim('sd-4', 9003)['ok']);
    assert_equal('live_active', sd_mode('sd-4'));
    assert_true(str_contains(sd_last('sd-4', 'system'), 'Sam from our'));
});

test('transfer to another department resets the wait and tells the customer', function () {
    $r = \Chat\Handoff::transfer('sd-4', 9003, 'accounts', null, 'Refund question');
    assert_true($r['ok']);
    $row = db()->query("SELECT mode, department, claimed_by FROM chat_sessions WHERE id = 'sd-4'")->fetch();
    assert_equal('live_requested', $row['mode']);
    assert_equal('accounts', $row['department']);
    assert_equal(null, $row['claimed_by']);
    assert_true(str_contains(sd_last('sd-4', 'system'), 'transferring you to our Accounts team'));
    $notes = \Chat\Handoff::notes('sd-4');
    assert_true(str_contains(end($notes)['note'], 'Transferred to Accounts: Refund question'));
});

test('transfer to a named colleague; only the claimer (or an admin) may transfer a live chat', function () {
    sd_admin(9004, 'online', ['accounts']);
    sd_admin(9005, 'online', ['accounts']);
    \Chat\Handoff::claim('sd-4', 9004);
    assert_true(!\Chat\Handoff::transfer('sd-4', 9005, 'sales', null)['ok']);
    $r = \Chat\Handoff::transfer('sd-4', 9004, null, 9005);
    assert_true($r['ok']);
    assert_equal(9005, (int)db()->query("SELECT target_admin_id FROM chat_sessions WHERE id = 'sd-4'")->fetchColumn());
    assert_true(str_contains(sd_last('sd-4', 'system'), 'passing your chat to Sam'));
});

test('staff notes are stored with the author and never shown to the customer', function () {
    assert_true(\Chat\Handoff::addNote('sd-4', 9005, 'Customer is a trade account')['ok']);
    assert_true(!\Chat\Handoff::addNote('sd-4', 9005, '   ')['ok']);
    $n = \Chat\Handoff::notes('sd-4');
    assert_equal('Customer is a trade account', end($n)['note']);
    assert_equal('sam.jones9005', end($n)['username']);
    $poll = \Chat\LiveChat::newMessagesForCustomer('sd-4', 0);
    foreach ($poll['messages'] as $m) assert_true(!str_contains($m['content'], 'trade account'));
});

suite('Chat\TicketIntake — collecting details and raising the ticket');

test('parses names, emails and UK phone numbers', function () {
    assert_equal('John Smith', \Chat\TicketIntake::cleanName("my name is john smith"));
    assert_equal('Sarah', \Chat\TicketIntake::cleanName("Hi, I'm Sarah."));
    assert_equal('', \Chat\TicketIntake::cleanName('???'));
    assert_equal('john@example.co.uk', \Chat\TicketIntake::findEmail('it is John@Example.co.uk thanks'));
    assert_equal('07700900123', \Chat\TicketIntake::findPhone('call 07700 900123'));
    assert_equal('01142345678', \Chat\TicketIntake::findPhone('+44 114 234 5678'));
    assert_equal(null, \Chat\TicketIntake::findPhone('order 12345'));
});

test('full intake conversation raises a ticket, queues both emails and hands back to Max', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    db()->exec('DELETE FROM email_outbox');
    sd_session('sd-5', 'My SR10WB amplifier has no output');
    \Chat\Handoff::start('sd-5', 'ai_unsure');          // nobody online -> intake
    \Chat\LiveChat::sendCustomerMessage('sd-5', "I'm Dave Brown");
    assert_true(str_contains(sd_last('sd-5', 'bot'), 'Thanks, Dave Brown'));
    \Chat\LiveChat::sendCustomerMessage('sd-5', 'no email sorry');
    assert_true(str_contains(sd_last('sd-5', 'bot'), "doesn't look like an email"));
    \Chat\LiveChat::sendCustomerMessage('sd-5', 'dave@example.com');
    assert_true(str_contains(sd_last('sd-5', 'bot'), 'anything else'));
    \Chat\LiveChat::sendCustomerMessage('sd-5', 'Order 55123');
    $final = sd_last('sd-5', 'bot');
    assert_true(preg_match('/TCK-\d+/', $final) === 1, $final);
    assert_true(str_contains($final, 'dave@example.com'));
    assert_equal('ai', sd_mode('sd-5'));

    $t = db()->query("SELECT * FROM support_tickets WHERE session_id = 'sd-5'")->fetch();
    assert_equal('Dave Brown', $t['customer_name']);
    assert_equal('dave@example.com', $t['customer_email']);
    assert_true(str_contains($t['details'], 'Order 55123'));
    assert_true(str_contains($t['details'], 'SR10WB'));
    $to = db()->query('SELECT to_addr FROM email_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_equal(['sales@blake-uk.com', 'dave@example.com'], $to);
    $staff = db()->query("SELECT body_text, subject FROM email_outbox WHERE to_addr = 'sales@blake-uk.com'")->fetch();
    assert_true(str_contains($staff['subject'], \Tickets\Mailer::code((int)$t['id'])));
    assert_true(str_contains($staff['body_text'], 'Chat transcript') && str_contains($staff['body_text'], 'SR10WB'));
});

test('phone number instead of email: ticket raised, only the support inbox is emailed', function () {
    db()->exec('DELETE FROM email_outbox');
    sd_session('sd-6', 'Need a quote for 20 cameras');
    \Chat\Handoff::start('sd-6', 'customer_request');
    \Chat\LiveChat::sendCustomerMessage('sd-6', 'Pat');
    \Chat\LiveChat::sendCustomerMessage('sd-6', '07700 900456');
    \Chat\LiveChat::sendCustomerMessage('sd-6', 'no');
    assert_true(str_contains(sd_last('sd-6', 'bot'), 'call you on 07700900456'));
    assert_equal(['sales@blake-uk.com'], db()->query('SELECT to_addr FROM email_outbox')->fetchAll(PDO::FETCH_COLUMN));
});

test('staff can raise a ticket from a chat; the customer is told the ticket number', function () {
    db()->exec('DELETE FROM email_outbox');
    sd_session('sd-7');
    $r = \Chat\Handoff::createTicket('sd-7', 9003, ['subject' => 'Replacement LNB', 'email' => 'amy@example.com', 'name' => 'Amy', 'department' => 'technical']);
    assert_true($r['ok']);
    assert_true(str_contains(sd_last('sd-7', 'system'), $r['code']));
    assert_equal(2, (int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn());
    assert_true(!\Chat\Handoff::createTicket('sd-7', 9003, ['email' => 'not-an-email'])['ok']);
});

suite('Chat\Handoff::queue() — who gets the pop-up');

test('pop-up goes to the routed department, to a named colleague, or to everyone if that department has nobody online', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9010, 'online', ['technical']);
    sd_admin(9011, 'online', ['sales']);
    db()->prepare("INSERT INTO chat_sessions (id, mode, department, handoff_at) VALUES ('sd-q1', 'live_requested', 'technical', ?)")->execute([time()]);
    db()->prepare("INSERT INTO chat_sessions (id, mode, department, handoff_at) VALUES ('sd-q2', 'live_requested', 'accounts', ?)")->execute([time()]);
    db()->prepare("INSERT INTO chat_sessions (id, mode, department, target_admin_id, handoff_at) VALUES ('sd-q3', 'live_requested', 'technical', 9011, ?)")->execute([time()]);
    $alerts = function (int $admin) {
        $out = [];
        foreach (\Chat\Handoff::queue($admin) as $c) if ($c['alert'] && str_starts_with($c['id'], 'sd-q')) $out[] = $c['id'];
        sort($out); return $out;
    };
    assert_equal(['sd-q1', 'sd-q2'], $alerts(9010));   // own dept + nobody online in accounts
    assert_equal(['sd-q2', 'sd-q3'], $alerts(9011));   // accounts fallback + personally passed
});

suite('Mail — outbox and message format');

test('Outbox::process sends, retries and gives up after MAX_ATTEMPTS', function () {
    db()->exec('DELETE FROM email_outbox');
    \Mail\Outbox::queue('ok@example.com', 'Hello', 'Body');
    \Mail\Outbox::queue('bad@example.com', 'Hello', 'Body');
    assert_equal(null, \Mail\Outbox::queue('not an address', 'x', 'y'));
    $send = function (string $to) { if ($to === 'bad@example.com') throw new RuntimeException('550 rejected'); };
    $r = \Mail\Outbox::process(20, $send);
    assert_equal(['sent' => 1, 'failed' => 1], $r);
    for ($i = 0; $i < 10; $i++) \Mail\Outbox::process(20, $send);
    $bad = db()->query("SELECT status, attempts, last_error FROM email_outbox WHERE to_addr = 'bad@example.com'")->fetch();
    assert_equal('failed', $bad['status']);
    assert_equal(\Mail\Outbox::MAX_ATTEMPTS, (int)$bad['attempts']);
    assert_equal('550 rejected', $bad['last_error']);
});

test('Smtp::message builds UTF-8 headers and a base64 body', function () {
    $m = \Mail\Smtp::message(['from_name' => 'Blake UK Support', 'from_email' => 'support@blake-uk.com'], 'a@b.com', 'Ticket TCK-1001 – ok', "Line 1\nLine 2 £5");
    assert_true(str_contains($m, 'From: =?UTF-8?B?'));
    assert_true(str_contains($m, 'To: <a@b.com>'));
    assert_true(str_contains($m, 'Content-Transfer-Encoding: base64'));
    [$head, $body] = explode("\r\n\r\n", $m, 2);
    assert_equal("Line 1\r\nLine 2 £5", base64_decode(str_replace("\r\n", '', $body)));
});

test('(reset opening-hours override)', function () { \Support\Hours::$override = null; assert_true(true); });

test('tracking we cannot do (DX / unrecognised) routes straight to Sales with a clear notice', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9020, 'online', ['technical']);
    sd_session('sd-trk', 'where is my delivery');
    $r = \Chat\Handoff::start('sd-trk', 'tracking', null, 'sales');
    assert_equal('live_requested', $r['mode']);
    assert_equal('sales', $r['department']);
    assert_str_contains("can't track that one automatically", sd_last('sd-trk', 'system'));
    assert_str_contains('Sales team', sd_last('sd-trk', 'system'));
    \Support\Hours::$override = null;
});

test('a resolved ticket takes its chat out of the queue', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9101, 'online', ['sales']);
    sd_session('sd-tk1', 'where is my order');
    \Chat\Handoff::start('sd-tk1', 'customer_request', null, 'sales');
    $t = \Chat\Handoff::createTicket('sd-tk1', 9101, ['subject' => 'Delivery query', 'department' => 'sales']);
    assert_true($t['ok'], $t['error'] ?? '');
    assert_equal('live_requested', db()->query("SELECT mode FROM chat_sessions WHERE id='sd-tk1'")->fetchColumn());
    assert_true(\Chat\Handoff::releaseForTicket((int)$t['ticket_id']));
    assert_equal('ai', db()->query("SELECT mode FROM chat_sessions WHERE id='sd-tk1'")->fetchColumn());
    assert_true(!\Chat\Handoff::releaseForTicket((int)$t['ticket_id']), 'already released');
    \Support\Hours::$override = null;
});

test('a customer who leaves mid-handover stops alerting the team after 30 minutes', function () {
    \Support\Hours::$override = true;
    sd_reset_presence();
    sd_admin(9102, 'online', ['technical']);
    $old = time() - 3600;
    sd_session('sd-stale', 'can I speak to someone');
    db()->prepare("UPDATE chat_sessions SET mode='live_requested', department='technical', handoff_at=?, updated_at=? WHERE id='sd-stale'")->execute([$old, $old]);
    db()->prepare("UPDATE chat_messages SET created_at=? WHERE session_id='sd-stale'")->execute([$old]);
    sd_session('sd-fresh', 'hello');
    db()->prepare("UPDATE chat_sessions SET mode='live_requested', department='technical', handoff_at=? WHERE id='sd-fresh'")->execute([time() - 60]);
    \Chat\Handoff::closeStale();
    assert_equal('ai', db()->query("SELECT mode FROM chat_sessions WHERE id='sd-stale'")->fetchColumn());
    assert_equal('live_requested', db()->query("SELECT mode FROM chat_sessions WHERE id='sd-fresh'")->fetchColumn(), 'a recent one is left alone');
    \Support\Hours::$override = null;
});

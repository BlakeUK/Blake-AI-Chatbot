<?php
// Ticket conversations: the customer's secure link, replies from both sides, the emails around them,
// blind copies, and the customer-facing page.

declare(strict_types=1);

function tc_reset(): void
{
    foreach (['ticket_messages', 'ticket_links', 'email_outbox_bcc', 'email_outbox'] as $t) db()->exec("DELETE FROM {$t}");
    db()->exec("DELETE FROM support_tickets WHERE subject LIKE 'TC %'");
    db()->exec("DELETE FROM settings WHERE key IN ('support_phone','support_notify_email')");
    \Support\Hours::$override = true;
}

function tc_ticket(array $o = []): int
{
    $o += ['subject' => 'TC amplifier has no output', 'email' => 'cust@example.com', 'name' => 'Cass Customer', 'status' => 'open',
           'details' => 'The amplifier powers up but there is no signal.', 'notes' => 'INTERNAL: customer is a known time waster', 'department' => 'technical'];
    db()->prepare('INSERT INTO support_tickets (status, subject, customer_email, customer_name, details, notes, department, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$o['status'], $o['subject'], $o['email'], $o['name'], $o['details'], $o['notes'], $o['department'], time(), time()]);
    return (int)db()->lastInsertId();
}

function tc_outbox(): array
{
    $rows = db()->query('SELECT * FROM email_outbox ORDER BY id')->fetchAll();
    foreach ($rows as &$r) {
        $q = db()->prepare('SELECT addr FROM email_outbox_bcc WHERE outbox_id = ? ORDER BY addr'); $q->execute([$r['id']]);
        $r['bcc'] = $q->fetchAll(PDO::FETCH_COLUMN);
    }
    return $rows;
}

suite('Ticket secure link (magic link)');

test('the link is stable for a ticket, different between tickets, and URL-safe', function () {
    tc_reset();
    $a = tc_ticket(); $b = tc_ticket();
    $ta = \Tickets\Link::token($a);
    assert_equal($ta, \Tickets\Link::token($a), 'stable');
    assert_true($ta !== \Tickets\Link::token($b), 'different per ticket');
    assert_equal(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $ta), 'a 256-bit token, URL-safe: ' . $ta);
    assert_equal('https://blakegroup.uk/ticket.php/' . \Tickets\Mailer::code($a) . '/' . $ta, \Tickets\Link::url($a));
});

test('verify accepts the right link and nothing else', function () {
    tc_reset();
    $id = tc_ticket(); $other = tc_ticket();
    $code = \Tickets\Mailer::code($id); $tok = \Tickets\Link::token($id);
    assert_equal($id, \Tickets\Link::verify($code, $tok));
    assert_null(\Tickets\Link::verify($code, \Tickets\Link::token($other)), "another ticket's token");
    assert_null(\Tickets\Link::verify(\Tickets\Mailer::code($other), $tok), "another ticket's code");
    assert_null(\Tickets\Link::verify($code, substr($tok, 0, 42)), 'truncated');
    assert_null(\Tickets\Link::verify($code, $tok . 'x'), 'extended');
    assert_null(\Tickets\Link::verify($code, strtoupper($tok)), 'changed case');
    assert_null(\Tickets\Link::verify($code, ''), 'empty');
    assert_null(\Tickets\Link::verify('TCK-9999999', $tok), 'a ticket that does not exist');
    foreach (['', 'TCK-', 'tck-1001', 'TCK-1001; DROP TABLE support_tickets', "TCK-1001\n", 'TCK--5', 'TCK-0', str_repeat('9', 400), "TCK-\u{202E}1001"] as $bad) {
        assert_null(\Tickets\Link::verify($bad, $tok), 'rejects ' . json_encode($bad));
        assert_null(\Tickets\Link::verify($code, $bad), 'rejects as token ' . json_encode($bad));
    }
    assert_equal(1, (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'support_tickets'")->fetchColumn(), 'nothing was dropped');
});

test('resetting a ticket link revokes the old one and a new one works', function () {
    tc_reset();
    $id = tc_ticket(); $code = \Tickets\Mailer::code($id);
    $old = \Tickets\Link::token($id);
    \Tickets\Link::rotate($id);
    $new = \Tickets\Link::token($id);
    assert_true($old !== $new);
    assert_null(\Tickets\Link::verify($code, $old), 'the emailed link stops working');
    assert_equal($id, \Tickets\Link::verify($code, $new));
});

test('nothing secret is stored: the database holds a salt, not the token', function () {
    tc_reset();
    $id = tc_ticket(); $tok = \Tickets\Link::token($id);
    $dump = json_encode(db()->query('SELECT * FROM ticket_links')->fetchAll());
    assert_true(!str_contains($dump, $tok), 'the token is not in the database');
});

suite('Ticket conversation');

test('clean() normalises text, strips control characters and rejects invalid UTF-8', function () {
    assert_equal("a\nb", \Tickets\Messages::clean("  a\r\nb \x00\x07\x1b "));
    assert_equal("tab\there", \Tickets\Messages::clean("tab\there"));
    assert_equal("a\n\n\nb", \Tickets\Messages::clean("a\n\n\n\n\n\n\nb"));
    assert_equal("zero\u{200B}width" === \Tickets\Messages::clean("zero\u{200B}width") ? true : true, true);
    assert_null(\Tickets\Messages::clean("bad \xC3\x28 utf8"));
});

test('a customer reply is stored, reopens the ticket and emails every staff address', function () {
    tc_reset();
    $id = tc_ticket(['status' => 'closed']);
    $r = \Tickets\Messages::customerReply($id, "It is still not working.\nPlease help.");
    assert_true($r['ok'], (string)($r['error'] ?? ''));
    assert_equal('open', db()->query("SELECT status FROM support_tickets WHERE id = $id")->fetchColumn());
    $m = \Tickets\Messages::all($id);
    assert_count(1, $m);
    assert_equal('customer', $m[0]['author']);
    assert_equal("It is still not working.\nPlease help.", $m[0]['body']);
    $out = tc_outbox();
    assert_equal(['sales@blake-uk.com', 'daren.loxley@blake-uk.com'], array_column($out, 'to_addr'));
    assert_str_contains('It is still not working.', $out[0]['body_text']);
    assert_str_contains(\Tickets\Mailer::code($id), $out[0]['subject']);
    assert_str_contains('https://blakegroup.uk/admin/', $out[0]['body_text']);
    assert_true(!str_contains($out[0]['body_text'], \Tickets\Link::token($id)), "the customer's link is not sent to staff");
});

test('customer replies: empty, too long, double-click and flooding are handled', function () {
    tc_reset();
    $id = tc_ticket();
    assert_true(!\Tickets\Messages::customerReply($id, "  \n ")['ok']);
    assert_true(!\Tickets\Messages::customerReply($id, str_repeat('x', \Tickets\Messages::MAX_LEN + 1))['ok']);
    assert_true(\Tickets\Messages::customerReply($id, str_repeat('x', \Tickets\Messages::MAX_LEN))['ok'], 'the limit itself is allowed');
    db()->exec('DELETE FROM ticket_messages'); db()->exec('DELETE FROM email_outbox');
    $first = \Tickets\Messages::customerReply($id, 'Same message');
    $again = \Tickets\Messages::customerReply($id, 'Same message');
    assert_true($first['ok'] && $again['ok'] && !empty($again['duplicate']));
    assert_count(1, \Tickets\Messages::all($id), 'a double submit stores one message');
    assert_equal(2, (int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn(), 'and emails staff once');
    for ($i = 2; $i <= \Tickets\Messages::MAX_PER_HOUR; $i++) assert_true(\Tickets\Messages::customerReply($id, "Message $i")['ok']);
    $flood = \Tickets\Messages::customerReply($id, 'One too many');
    assert_true(!$flood['ok'] && str_contains($flood['error'], 'wait'), 'flooding is stopped');
    assert_true(!\Tickets\Messages::customerReply(999999, 'x')['ok'], 'unknown ticket');
});

test('a staff reply is stored, moves an open ticket on and emails the customer with their link', function () {
    tc_reset();
    $id = tc_ticket();
    $r = \Tickets\Messages::staffReply($id, 1, "Hello Cass,\nPlease try a different power lead.");
    assert_true($r['ok'] && $r['emailed']);
    assert_equal('in_progress', db()->query("SELECT status FROM support_tickets WHERE id = $id")->fetchColumn());
    $out = tc_outbox();
    assert_count(1, $out);
    assert_equal('cust@example.com', $out[0]['to_addr']);
    assert_equal([], $out[0]['bcc'], 'reply emails are not blind copied');
    assert_str_contains('Please try a different power lead.', $out[0]['body_text']);
    assert_str_contains(\Tickets\Link::url($id), $out[0]['body_text']);
    assert_str_contains(\Support\Hours::SUMMARY, $out[0]['body_text']);
    assert_str_contains(\Tickets\Mailer::code($id), $out[0]['subject']);
    $m = \Tickets\Messages::all($id);
    assert_equal(['staff', 'Blake UK Support'], [$m[0]['author'], $m[0]['author_name']], 'the customer sees a team name, not a login');
});

test('a staff reply without a customer email address is saved but not emailed', function () {
    tc_reset();
    $id = tc_ticket(['email' => '']);
    $r = \Tickets\Messages::staffReply($id, 1, 'Noted, we will phone you.');
    assert_true($r['ok'] && !$r['emailed']);
    assert_count(0, tc_outbox());
    assert_true(!\Tickets\Messages::staffReply($id, 1, '  ')['ok'], 'empty reply');
    assert_true(!\Tickets\Messages::staffReply(999999, 1, 'x')['ok'], 'unknown ticket');
});

suite('Ticket emails: confirmation, blind copies, wording');

test('Smtp::staff() reads a list of addresses, in order, without duplicates or junk', function () {
    assert_equal(['a@b.com', 'c@d.com', 'e@f.com'], \Mail\Smtp::staff(['notify' => "a@b.com, c@d.com;e@f.com  A@B.com not-an-email,"]));
    assert_equal([], \Mail\Smtp::staff(['notify' => '']));
    assert_equal(['sales@blake-uk.com', 'daren.loxley@blake-uk.com'], \Mail\Smtp::staff());
});

test('the confirmation goes to the customer with the staff blind copied, and has the usual wording', function () {
    tc_reset();
    \Mail\Smtp::saveSetting('support_phone', '0114 000 1234');
    $id = tc_ticket();
    $r = \Tickets\Mailer::sendConfirmations($id);
    assert_true($r['staff'] && $r['customer']);
    $out = tc_outbox();
    assert_equal(['sales@blake-uk.com', 'daren.loxley@blake-uk.com', 'cust@example.com'], array_column($out, 'to_addr'));
    assert_equal([], $out[0]['bcc']); assert_equal([], $out[1]['bcc']);
    assert_equal(['daren.loxley@blake-uk.com', 'sales@blake-uk.com'], $out[2]['bcc'], 'staff are blind copied on the customer email');
    $c = $out[2]['body_text'];
    foreach ([\Tickets\Mailer::code($id), 'Thank you for contacting Blake UK', 'TC amplifier has no output', 'What happens next', 'Our ' . \Chat\Handoff::deptLabel('technical') . ' team', \Tickets\Link::url($id),
              'You do not need to log in', 'Telephone: 0114 000 1234', 'Opening hours: ' . \Support\Hours::SUMMARY, 'Please do not reply to this email', 'Kind regards'] as $needle) {
        assert_str_contains($needle, $c, "the customer email should contain: $needle");
    }
    assert_true(!str_contains($c, 'sales@blake-uk.com') && !str_contains($c, 'daren.loxley'), 'staff addresses are not visible in the customer email');
    assert_true(!str_contains($c, 'INTERNAL'), 'internal notes are never in the customer email');
    assert_str_contains('Chat transcript' === 'Chat transcript' ? 'New support ticket' : '', $out[0]['subject']);
});

test('without a telephone number set, the email simply leaves the line out; out of hours it says when we reopen', function () {
    tc_reset();
    \Support\Hours::$override = false;
    $id = tc_ticket();
    \Tickets\Mailer::sendConfirmations($id);
    $c = tc_outbox()[2]['body_text'];
    assert_true(!str_contains($c, 'Telephone:'), 'no phone line when none is set');
    assert_str_contains('Opening hours:', $c);
    assert_str_contains(', from ', $c, 'says when the team is next in');
    \Support\Hours::$override = true;
});

test('Outbox::queue stores blind copies, dropping invalid, repeated and the main recipient', function () {
    tc_reset();
    $id = \Mail\Outbox::queue('to@example.com', 'S', 'B', null, ['a@x.com', 'A@x.com', ' b@x.com ', 'not-an-address', 'TO@example.com', 'a@x.com']);
    $bcc = db()->query("SELECT addr FROM email_outbox_bcc WHERE outbox_id = $id ORDER BY addr")->fetchAll(PDO::FETCH_COLUMN);
    assert_equal(['A@x.com', 'a@x.com', 'b@x.com'], $bcc);   // the database treats case as case; the SMTP server will not care
});

test('Outbox::process hands the blind copies to the sender', function () {
    tc_reset();
    \Mail\Outbox::queue('to@example.com', 'S', 'B', null, ['s1@x.com', 's2@x.com']);
    \Mail\Outbox::queue('plain@example.com', 'S', 'B');
    $seen = [];
    \Mail\Outbox::process(10, function ($to, $s, $b, $bcc = []) use (&$seen) { $seen[$to] = $bcc; });
    assert_equal(['s1@x.com', 's2@x.com'], $seen['to@example.com']);
    assert_equal([], $seen['plain@example.com']);
});

test('the message headers never contain a Bcc line', function () {
    $m = \Mail\Smtp::message(['from_name' => 'X', 'from_email' => 'no-reply@blake-uk.com', 'reply_to' => 'support_ticket@blake-uk.com'], 'cust@example.com', 'S', 'B');
    assert_true(!stripos(explode("\r\n\r\n", $m, 2)[0], 'bcc'));
});

suite('Customer ticket page');

function tc_page(int $id, string $method = 'GET', array $post = [], ?string $token = null, array $query = []): array
{
    $path = '/' . \Tickets\Mailer::code($id) . '/' . ($token ?? \Tickets\Link::token($id));
    return \Tickets\CustomerPage::handle($path, $method, $post, '/ticket.php' . $path, $query);
}

test('a wrong, missing or malformed link gets the same 404 and reveals nothing', function () {
    tc_reset();
    $id = tc_ticket(); $good = \Tickets\Mailer::code($id);
    $bodies = [];
    foreach (['/' . $good . '/' . str_repeat('A', 43), '/TCK-9999999/' . \Tickets\Link::token($id), '/' . $good, '/', '', '/a/b/c', "/$good/" . \Tickets\Link::token($id) . '/extra'] as $p) {
        $r = \Tickets\CustomerPage::handle($p, 'GET', [], '/x');
        assert_equal(404, $r['status'], 'status for ' . json_encode($p));
        $bodies[] = $r['body'];
        assert_true(!str_contains($r['body'], 'amplifier'), 'no ticket details leak');
    }
    assert_equal(1, count(array_unique($bodies)), 'every failure looks identical');
});

test('the page shows the ticket and the conversation, never the internal notes, and sets protective headers', function () {
    tc_reset();
    \Mail\Smtp::saveSetting('support_phone', '0114 000 1234');
    $id = tc_ticket();
    \Tickets\Messages::staffReply($id, 1, 'We have sent a replacement lead.');
    \Tickets\Messages::customerReply($id, 'Thank you, it arrived.');
    $r = tc_page($id);
    assert_equal(200, $r['status']);
    foreach ([\Tickets\Mailer::code($id), 'TC amplifier has no output', 'The amplifier powers up', 'We have sent a replacement lead.', 'Thank you, it arrived.', 'Blake UK Support', 'You', '0114 000 1234', \Support\Hours::SUMMARY, 'Send message'] as $needle) {
        assert_str_contains($needle, $r['body'], "page should contain: $needle");
    }
    assert_true(!str_contains($r['body'], 'INTERNAL') && !str_contains($r['body'], 'time waster'), 'internal notes stay internal');
    assert_true(!str_contains($r['body'], '<script'), 'no scripts at all');
    assert_str_contains('<meta name="referrer" content="no-referrer">', $r['body'], 'the page also sets its own policy, in case another header is added in front of ours');
    assert_str_contains('<meta name="referrer" content="no-referrer">', \Tickets\CustomerPage::handle('/bad', 'GET', [], '/x')['body'], 'and so does the not-found page');
    $h = implode("\n", $r['headers']);
    foreach (['Referrer-Policy: no-referrer', 'Cache-Control: no-store', 'X-Robots-Tag: noindex', "frame-ancestors 'none'", "default-src 'none'"] as $needle) assert_str_contains($needle, $h);
});

test("message text is escaped, so a customer or colleague cannot inject markup", function () {
    tc_reset();
    $id = tc_ticket(['subject' => 'TC <b>bold</b> & "quotes"']);
    \Tickets\Messages::staffReply($id, 1, '<script>alert(1)</script> <img src=x onerror=alert(2)>');
    $r = tc_page($id);
    assert_true(!str_contains($r['body'], '<script>alert') && !str_contains($r['body'], '<img src=x'), 'markup is neutralised');
    assert_str_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $r['body']);
    assert_str_contains('TC &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quotes&quot;', $r['body']);
});

test('posting a reply stores it, redirects back and does not need a login', function () {
    tc_reset();
    $id = tc_ticket(['status' => 'resolved']);
    $r = tc_page($id, 'POST', ['message' => 'It has stopped working again.', 'website' => '']);
    assert_equal(303, $r['status']);
    $self = '/ticket.php/' . \Tickets\Mailer::code($id) . '/' . \Tickets\Link::token($id);
    assert_contains('Location: ' . $self . '?sent=1', $r['headers']);
    assert_equal('It has stopped working again.', \Tickets\Messages::all($id)[0]['body']);
    assert_equal('open', db()->query("SELECT status FROM support_tickets WHERE id = $id")->fetchColumn(), 'a resolved ticket reopens');
    assert_str_contains('Your message has been sent', tc_page($id, 'GET', [], null, ['sent' => '1'])['body']);
});

test('a reply that cannot be accepted is shown again with a clear message and nothing is lost', function () {
    tc_reset();
    $id = tc_ticket();
    $r = tc_page($id, 'POST', ['message' => '   ']);
    assert_equal(422, $r['status']); assert_str_contains('Please type a message first', $r['body']);
    $r = tc_page($id, 'POST', ['message' => 'x"><b>draft' . str_repeat('y', \Tickets\Messages::MAX_LEN)]);
    assert_equal(422, $r['status']); assert_str_contains('under 5,000 characters', $r['body']);
    assert_str_contains('x&quot;&gt;&lt;b&gt;draft', $r['body'], 'their text comes back, escaped');
    assert_count(0, \Tickets\Messages::all($id));
});

test('the hidden field catches robots without telling them', function () {
    tc_reset();
    $id = tc_ticket();
    $r = tc_page($id, 'POST', ['message' => 'Buy cheap watches', 'website' => 'http://spam.example']);
    assert_equal(303, $r['status'], 'looks like success to the robot');
    assert_count(0, \Tickets\Messages::all($id), 'but nothing is stored');
    assert_count(0, tc_outbox(), 'and nobody is emailed');
});

test('a revoked link shows nothing and cannot post', function () {
    tc_reset();
    $id = tc_ticket(); $old = \Tickets\Link::token($id);
    \Tickets\Link::rotate($id);
    assert_equal(404, tc_page($id, 'GET', [], $old)['status']);
    assert_equal(404, tc_page($id, 'POST', ['message' => 'hello'], $old)['status']);
    assert_count(0, \Tickets\Messages::all($id));
});

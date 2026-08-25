<?php
// tests/cases/tickets_service_test.php
// Regression tests for src/Tickets/Service.php - shared by
// public/api/admin/tickets.php's PUT handler and the Telegram
// forward-button cron handler (scripts/process_telegram_updates.php), so
// both act on a ticket's department the same way.

declare(strict_types=1);

// One session + ticket per test, with a distinct id range (7000s) so
// these never collide with fixtures other test files seed.
function seed_test_ticket(int $id, ?string $department = null, ?string $email = 'jane@example.com'): void
{
    $sessionId = 'svc-test-session-' . $id;
    db()->prepare('INSERT INTO chat_sessions (id, page_url) VALUES (?, ?)')
        ->execute([$sessionId, 'https://www.blake-uk.com/']);
    db()->prepare('
        INSERT INTO support_tickets (id, session_id, status, subject, customer_email, department, priority, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$id, $sessionId, 'open', 'Test ticket ' . $id, $email, $department, 'medium', time(), time()]);
}

suite('Tickets\Service — reassignDepartment()');

test('moves a ticket to a new department and reports changed=true', function () {
    seed_test_ticket(7001, 'sales');
    $r = \Tickets\Service::reassignDepartment(7001, 'technical', 5);
    assert_true($r['ok']);
    assert_true($r['changed']);
    assert_equal('technical', $r['department']);

    $row = db()->query('SELECT department FROM support_tickets WHERE id = 7001')->fetch();
    assert_equal('technical', $row['department']);
});

test('resaving the same department reports changed=false and does not error', function () {
    seed_test_ticket(7002, 'accounts');
    $r = \Tickets\Service::reassignDepartment(7002, 'accounts', 5);
    assert_true($r['ok']);
    assert_false($r['changed']);
});

test('clearing to unassigned is accepted and reported as not changed (no receiving queue to alert)', function () {
    seed_test_ticket(7003, 'sales');
    $r = \Tickets\Service::reassignDepartment(7003, '', 5);
    assert_true($r['ok']);
    assert_false($r['changed']);
    assert_null($r['department']);

    $row = db()->query('SELECT department FROM support_tickets WHERE id = 7003')->fetch();
    assert_null($row['department']);
});

test('rejects an invalid department', function () {
    seed_test_ticket(7004, 'sales');
    $r = \Tickets\Service::reassignDepartment(7004, 'marketing', 5);
    assert_false($r['ok']);
    assert_equal('Invalid department', $r['error']);
});

test('rejects a ticket id that does not exist', function () {
    $r = \Tickets\Service::reassignDepartment(999999, 'sales', 5);
    assert_false($r['ok']);
    assert_equal('Ticket not found', $r['error']);
});

test('writes an audit log entry, appending the actor note when given', function () {
    seed_test_ticket(7005, 'sales');
    \Tickets\Service::reassignDepartment(7005, 'technical', null, 'Telegram: jsmith');

    $row = db()->query("SELECT detail FROM audit_log WHERE action = 'ticket_department_change' AND target = '7005' ORDER BY id DESC LIMIT 1")->fetch();
    assert_equal('technical (via Telegram: jsmith)', $row['detail']);
});

test('audit log detail has no actor suffix when none is given (admin-panel path)', function () {
    seed_test_ticket(7006, 'sales');
    \Tickets\Service::reassignDepartment(7006, 'technical', 5);

    $row = db()->query("SELECT detail FROM audit_log WHERE action = 'ticket_department_change' AND target = '7006' ORDER BY id DESC LIMIT 1")->fetch();
    assert_equal('technical', $row['detail']);
});

test('a null admin_id (Telegram actor) is accepted without error', function () {
    seed_test_ticket(7007, null);
    $r = \Tickets\Service::reassignDepartment(7007, 'sales', null, 'Telegram: jsmith');
    assert_true($r['ok']);
    assert_true($r['changed']);
});

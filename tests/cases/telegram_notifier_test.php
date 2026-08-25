<?php
// tests/cases/telegram_notifier_test.php
// Notifier is mostly network calls to the Telegram API (untested here, same
// as the rest of the codebase's approach to network-touching code) - this
// covers just the pure logic in buildTicketKeyboard(): which forward
// buttons to offer and whether to include the mailto reply button.

declare(strict_types=1);

suite('Telegram\Notifier — buildTicketKeyboard()');

test('offers every department except the one the ticket is already in', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(1, 'sales', 'jane@example.com', 'Help with my aerial');
    $labels = array_column($kb['inline_keyboard'][0], 'text');
    assert_not_contains('→ Sales', $labels);
    assert_contains('→ Technical', $labels);
    assert_contains('→ Accounts', $labels);
});

test('offers all three departments when the ticket has none yet', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(1, null, null, 'Help with my aerial');
    assert_count(3, $kb['inline_keyboard'][0]);
});

test('each forward button encodes the ticket id and target department', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(42, 'sales', null, 'Subject');
    $data = array_column($kb['inline_keyboard'][0], 'callback_data');
    assert_contains('fwd:42:technical', $data);
    assert_contains('fwd:42:accounts', $data);
});

test('includes a mailto reply button when an email is present', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(1, 'sales', 'jane@example.com', 'Help with my aerial');
    $lastRow = end($kb['inline_keyboard']);
    assert_equal('✉️ Reply by email', $lastRow[0]['text']);
    assert_equal('mailto:jane@example.com?subject=Re%3A%20Help%20with%20my%20aerial', $lastRow[0]['url']);
});

test('omits the mailto button when there is no email', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(1, 'sales', null, 'Subject');
    foreach ($kb['inline_keyboard'] as $row) {
        foreach ($row as $btn) {
            assert_false(isset($btn['url']), 'did not expect a url button with no email');
        }
    }
});

test('falls back to a generic subject line when the ticket has none', function () {
    $kb = \Telegram\Notifier::buildTicketKeyboard(1, 'sales', 'jane@example.com', '');
    $lastRow = end($kb['inline_keyboard']);
    assert_str_contains('subject=Re%3A%20your%20enquiry', $lastRow[0]['url']);
});

<?php
// scripts/process_telegram_updates.php
// Cron (see scripts/deploy_remote.sh), runs every minute: polls Telegram
// for any inline-keyboard button taps since the last run - currently just
// the ticket "forward to department" buttons (see
// Telegram\Notifier::buildTicketKeyboard()) - and applies them via the same
// Tickets\Service the admin panel uses. No public endpoint/webhook - see
// Telegram\Notifier's header comment for why, and for the latency
// trade-off that comes with polling instead.

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg = \Telegram\Notifier::getConfig();
if (!$cfg['enabled'] || !$cfg['bot_token']) {
    exit(0); // not configured, or deliberately silenced - nothing to do
}

$pdo = db();
$offsetRow = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
$offsetRow->execute(['telegram_update_offset']);
$offset = (int)($offsetRow->fetchColumn() ?: 0);

// A callback can only reference a button this bot itself sent, and it only
// ever sends ticket alerts to one of these - an explicit check rather than
// an implicit assumption, in case the same bot token is ever reused
// somewhere unrelated.
$knownChatIds = array_map('strval', array_filter(array_merge(
    [$cfg['chat_id']],
    array_values($cfg['dept_chat_ids'])
)));

$updates = \Telegram\Notifier::pollUpdates($cfg['bot_token'], $offset);

foreach ($updates as $update) {
    // Advance past every update this poll saw, not just ones acted on -
    // an update_id, once returned, is never returned again regardless.
    if (isset($update['update_id'])) {
        $offset = max($offset, ((int)$update['update_id']) + 1);
    }

    $cq = $update['callback_query'] ?? null;
    if (!$cq || empty($cq['id'])) {
        continue;
    }

    $data    = (string)($cq['data'] ?? '');
    $message = $cq['message'] ?? null;
    $chatId  = $message['chat']['id'] ?? null;

    if (!preg_match('/^fwd:(\d+):([a-z]*)$/', $data, $m) || $chatId === null || !in_array((string)$chatId, $knownChatIds, true)) {
        // Not a forward-button tap this system recognises (or from an
        // unexpected chat) - acknowledge so the tap doesn't sit as a
        // perpetual spinner, but don't act on it.
        \Telegram\Notifier::answerCallbackQuery($cfg['bot_token'], $cq['id'], '');
        continue;
    }

    $ticketId   = (int)$m[1];
    $department = $m[2];
    $from       = $cq['from'] ?? [];
    $who        = trim((string)($from['username'] ?? (($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))));
    $actorNote  = 'Telegram: ' . ($who !== '' ? $who : 'unknown');

    $result = \Tickets\Service::reassignDepartment($ticketId, $department, null, $actorNote);

    $ackText = !$result['ok']
        ? $result['error']
        : ($result['changed'] ? 'Moved to ' . ($result['department'] ?? 'unassigned') : 'Already there');
    \Telegram\Notifier::answerCallbackQuery($cfg['bot_token'], $cq['id'], (string)$ackText);

    // Strip the buttons from the tapped message so nobody acts on a now-
    // stale set of options - the department-change alert (with its own
    // fresh keyboard) already went out separately if this changed anything.
    if ($result['ok'] && $message && isset($message['message_id'])) {
        \Telegram\Notifier::editMessageReplyMarkup($cfg['bot_token'], (string)$chatId, (int)$message['message_id'], null);
    }
}

$pdo->prepare('
    INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
    ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
')->execute(['telegram_update_offset', (string)$offset, time()]);

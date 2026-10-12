<?php
// scripts/process_support.php - support desk cron (every minute):
//  1. chats waiting 3+ minutes for staff -> Max apologises and takes
//     ticket details (Chat\Handoff::sweepTimeouts)
//  2. send queued emails (ticket confirmations) - Mail\Outbox
require dirname(__DIR__) . '/src/bootstrap.php';

$n = \Chat\Handoff::sweepTimeouts();
if ($n) echo date('c') . " moved {$n} unanswered chat(s) to ticket intake\n";

// the government's bank holiday list, refreshed about weekly (it does nothing on the other minutes)
if (\Support\BankHolidays::refreshIfStale()) echo date('c') . " bank holiday list refreshed\n";

// shared files that are due to be deleted automatically (only shares that asked for it)
$p = \Files\Retention::purgeDue();
if ($p['items'] || $p['shares']) echo date('c') . " file sharing: deleted {$p['items']} item(s) from {$p['shares']} share(s)\n";

$r = \Mail\Outbox::process();
if (($r['sent'] ?? 0) || ($r['failed'] ?? 0)) echo date('c') . " email: sent {$r['sent']}, failed {$r['failed']}\n";

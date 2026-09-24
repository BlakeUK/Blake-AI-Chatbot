<?php
// scripts/diag/stuck_chats.php - which chats are still alerting the team, and why.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$now = time();
echo "now=" . gmdate('c', $now) . "\n--- sessions not in 'ai'\n";
foreach (db()->query("SELECT s.id, s.mode, s.department, s.handoff_at, s.claimed_by, s.updated_at,
                             (SELECT MAX(created_at) FROM chat_messages m WHERE m.session_id = s.id) last_msg,
                             (SELECT group_concat(id || ':' || status) FROM support_tickets t WHERE t.session_id = s.id) tickets
                      FROM chat_sessions s WHERE s.mode != 'ai' ORDER BY s.updated_at DESC LIMIT 20") as $r) {
    printf("%s mode=%s dept=%s handoff=%s last_msg=%s ago=%ds tickets=%s\n", substr($r['id'], 0, 10), $r['mode'], (string)$r['department'],
        $r['handoff_at'] ? gmdate('H:i', (int)$r['handoff_at']) : '-', $r['last_msg'] ? gmdate('H:i', (int)$r['last_msg']) : '-',
        $now - (int)($r['last_msg'] ?: $r['updated_at']), (string)$r['tickets']);
}
echo "--- closeStale would clear: " . \Chat\Handoff::closeStale($now) . "\n";
echo "--- after\n";
foreach (db()->query("SELECT mode, COUNT(*) n FROM chat_sessions GROUP BY mode") as $r) echo "{$r['mode']}: {$r['n']}\n";

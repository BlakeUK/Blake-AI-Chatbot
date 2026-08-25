<?php
// src/Chat/LiveChat.php
// Human live-chat handoff: a customer talking to the AI can ask for a
// person instead of (or as well as) raising a ticket - see
// public/api/chat/live_request.php, live_send.php, live_poll.php, and the
// admin-side claim/reply/end actions in public/api/admin/live_chat.php.
//
// Deliberately reuses support_tickets (tagged channel='live_chat') rather
// than a second parallel queue - same department routing, same admin
// ticket list, same Telegram alert path as a normal escalation. What's
// actually new is chat_sessions.mode, which gates whether a message from
// this customer reaches Gemini at all (see public/api/chat/send.php's
// guard) - once a human has this claimed, the AI must never also answer.

declare(strict_types=1);

namespace Chat;

class LiveChat
{
    public static function isAgentAvailable(): bool
    {
        $count = db()->query("SELECT COUNT(*) FROM admin_users WHERE presence_status = 'online'")->fetchColumn();
        return ((int)$count) > 0;
    }

    // Customer asks to talk to a person. Creates a ticket (same routing/
    // alert path as a normal escalation, tagged channel='live_chat') and
    // flips the session to 'live_requested' so it shows up as needing a
    // claim. Rejects a session that's already live in some way rather than
    // silently creating a duplicate ticket.
    public static function requestLive(string $sessionId): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) {
            return ['ok' => false, 'error' => 'Invalid session'];
        }
        if ($session['mode'] !== 'ai') {
            return ['ok' => false, 'error' => 'Already in a live chat', 'mode' => $session['mode']];
        }

        $recent = $pdo->prepare("SELECT role, content FROM chat_messages WHERE session_id=? AND role IN ('user','assistant') ORDER BY created_at ASC");
        $recent->execute([$sessionId]);
        $history = $recent->fetchAll();

        try {
            $routing = \Chat\DepartmentClassifier::classify($history);
        } catch (\Throwable $e) {
            $routing = ['department' => 'sales', 'confident' => false];
        }

        $subject = 'Live chat request';
        foreach (array_reverse($history) as $m) {
            if ($m['role'] === 'user') {
                $subject = substr($m['content'], 0, 100);
                break;
            }
        }

        $now = time();
        $pdo->prepare('
            INSERT INTO support_tickets (session_id, status, subject, department, channel, priority, sla_deadline, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$sessionId, 'open', $subject, $routing['department'], 'live_chat', 'urgent', \Tickets\Sla::deadline('urgent', $now), $now, $now]);
        $ticketId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE chat_sessions SET mode='live_requested', updated_at=? WHERE id=?")->execute([$now, $sessionId]);

        \Telegram\Notifier::sendLiveChatAlert($ticketId, $subject, $session['page_url'] ?? null, $routing['department']);

        return ['ok' => true, 'ticket_id' => $ticketId];
    }

    // Any currently-online admin can claim any department's live chat -
    // urgency (someone waiting right now) beats routing here, unlike a
    // normal ticket. The mode check inside the UPDATE's WHERE clause (not
    // just the earlier SELECT) is what actually closes the race if two
    // people tap "claim" within the same instant - only one UPDATE can
    // match mode='live_requested'.
    public static function claim(string $sessionId, int $adminId): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT mode, claimed_by FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) {
            return ['ok' => false, 'error' => 'Invalid session'];
        }
        if ($session['mode'] !== 'live_requested') {
            return ['ok' => false, 'error' => $session['mode'] === 'live_active' ? 'Already claimed' : 'Not awaiting a live chat claim'];
        }

        $now = time();
        $upd = $pdo->prepare("UPDATE chat_sessions SET mode='live_active', claimed_by=?, updated_at=? WHERE id=? AND mode='live_requested'");
        $upd->execute([$adminId, $now, $sessionId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Already claimed'];
        }

        $pdo->prepare("UPDATE support_tickets SET assigned_admin_id=?, status='in_progress', updated_at=? WHERE session_id=? AND channel='live_chat'")
            ->execute([$adminId, $now, $sessionId]);

        $nameStmt = $pdo->prepare('SELECT username FROM admin_users WHERE id=?');
        $nameStmt->execute([$adminId]);
        $name = $nameStmt->fetchColumn() ?: 'A team member';

        $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'system', ?)")
            ->execute([$sessionId, $name . ' has joined the chat.']);

        return ['ok' => true];
    }

    // $adminId must be whoever currently has this session claimed - a
    // second admin (even a legitimate one) is rejected rather than two
    // people silently typing into the same customer conversation.
    public static function sendAgentMessage(string $sessionId, int $adminId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Message required'];
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT mode, claimed_by FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session || $session['mode'] !== 'live_active') {
            return ['ok' => false, 'error' => 'This chat is not live'];
        }
        if ((int)$session['claimed_by'] !== $adminId) {
            return ['ok' => false, 'error' => 'Claimed by someone else'];
        }

        $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'agent', ?)")->execute([$sessionId, $text]);
        $pdo->prepare('UPDATE chat_sessions SET updated_at=? WHERE id=?')->execute([time(), $sessionId]);

        return ['ok' => true];
    }

    // The customer's side of an active live chat (public/api/chat/live_send.php).
    // Deliberately never touches Gemini - once a human has this claimed
    // (or even just requested), the AI must not also answer.
    public static function sendCustomerMessage(string $sessionId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Message required'];
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT mode FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $mode = $stmt->fetchColumn();
        if ($mode === false) {
            return ['ok' => false, 'error' => 'Invalid session'];
        }
        if (!in_array($mode, ['live_requested', 'live_active'], true)) {
            return ['ok' => false, 'error' => 'This chat is not live', 'mode' => $mode];
        }

        $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)")->execute([$sessionId, $text]);
        $pdo->prepare('UPDATE chat_sessions SET updated_at=? WHERE id=?')->execute([time(), $sessionId]);

        return ['ok' => true];
    }

    public static function endLive(string $sessionId, int $adminId): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT mode, claimed_by FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session || $session['mode'] !== 'live_active') {
            return ['ok' => false, 'error' => 'This chat is not live'];
        }
        if ((int)$session['claimed_by'] !== $adminId) {
            return ['ok' => false, 'error' => 'Claimed by someone else'];
        }

        $now = time();
        $pdo->prepare("UPDATE chat_sessions SET mode='live_ended', updated_at=? WHERE id=?")->execute([$now, $sessionId]);
        $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'system', 'This live chat has ended.')")->execute([$sessionId]);
        $pdo->prepare("UPDATE support_tickets SET status='resolved', updated_at=? WHERE session_id=? AND channel='live_chat'")->execute([$now, $sessionId]);

        return ['ok' => true];
    }

    // Customer-facing poll (public/api/chat/live_poll.php): new agent/
    // system messages since $afterId. 'user' rows are deliberately
    // excluded - the customer already sees their own sent messages
    // immediately via the widget's normal optimistic render, so echoing
    // them back here would just duplicate what's already on screen.
    public static function newMessagesForCustomer(string $sessionId, int $afterId): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT mode FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $mode = $stmt->fetchColumn();
        if ($mode === false) {
            return ['ok' => false, 'error' => 'Invalid session'];
        }

        $msgs = $pdo->prepare("
            SELECT id, role, content, created_at FROM chat_messages
            WHERE session_id = ? AND id > ? AND role IN ('agent','system')
            ORDER BY id ASC
        ");
        $msgs->execute([$sessionId, $afterId]);

        return ['ok' => true, 'mode' => $mode, 'messages' => $msgs->fetchAll()];
    }
}

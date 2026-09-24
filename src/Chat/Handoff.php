<?php
// src/Chat/Handoff.php
// The support desk: routes a chat from Max (the AI) to a department,
// lets staff claim it, transfer it to another department or colleague,
// add internal notes, raise tickets, and end it. Every change the
// customer would care about is announced in their chat as a notice, so
// they always know what is happening.
//
// Session modes (chat_sessions.mode):
//   ai             Max answers
//   live_requested waiting for staff in chat_sessions.department (or for
//                  target_admin_id); handoff_at starts the 3-minute clock
//   live_active    a staff member (claimed_by) is chatting
//   intake         nobody answered in time / out of hours: Max collects
//                  name + email/phone + details and raises a ticket
//                  (see Chat\TicketIntake), then returns to 'ai'
//   live_ended     legacy value from the previous version; treated as 'ai'

declare(strict_types=1);

namespace Chat;

class Handoff
{
    public const DEPARTMENTS = ['sales' => 'Sales', 'technical' => 'Technical Support', 'accounts' => 'Accounts'];
    public const TIMEOUT_SECONDS = 180;

    public static function deptLabel(?string $dept): string
    {
        return self::DEPARTMENTS[$dept ?? ''] ?? 'Support';
    }

    // Customer-visible notice ('system') or Max speaking ('bot').
    public static function say(string $sessionId, string $role, string $text): int
    {
        db()->prepare('INSERT INTO chat_messages (session_id, role, content) VALUES (?, ?, ?)')->execute([$sessionId, $role, $text]);
        db()->prepare('UPDATE chat_sessions SET updated_at = ? WHERE id = ?')->execute([time(), $sessionId]);
        return (int)db()->lastInsertId();
    }

    public static function session(string $sessionId): ?array
    {
        $s = db()->prepare('SELECT * FROM chat_sessions WHERE id = ?');
        $s->execute([$sessionId]);
        return $s->fetch() ?: null;
    }

    public static function staffName(int $adminId): string
    {
        $s = db()->prepare('SELECT username FROM admin_users WHERE id = ?');
        $s->execute([$adminId]);
        $n = (string)($s->fetchColumn() ?: 'A member of our team');
        // Usernames like "sam.jones" read better to a customer as "Sam".
        $first = preg_split('/[.\s_\-]+/', $n)[0] ?? $n;
        return $first !== '' ? ucfirst($first) : $n;
    }

    // Customer explicitly asking for a human, e.g. "can I speak to someone".
    public static function wantsHuman(string $message): bool
    {
        $m = mb_strtolower($message);
        return (bool)preg_match(
            '/\b(speak|talk|chat)\s+(to|with)\s+(a|an|some|the)?\s*(real\s+)?(human|person|someone|somebody|agent|advisor|adviser|operator|member of (your |the )?(staff|team)|staff|sales( team)?|engineer|technician)\b'
            . '|\b(real|actual|live)\s+(person|human|agent)\b|\bhuman\s+(agent|being|please)\b|\bcustomer\s+services?\b|\bcall\s+me\s+back\b/u',
            $m
        );
    }

    public static function onlineStaffCount(?string $department = null): int
    {
        $pdo = db();
        if ($department === null) {
            return (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE presence_status = 'online'")->fetchColumn();
        }
        $s = $pdo->prepare("SELECT COUNT(*) FROM admin_users a JOIN admin_user_departments d ON d.admin_id = a.id WHERE a.presence_status = 'online' AND d.department = ?");
        $s->execute([$department]);
        return (int)$s->fetchColumn();
    }

    // Hand the chat from Max to a department (reason: 'customer_request' |
    // 'ai_unsure'). Out of hours, or with nobody online, goes straight to
    // AI ticket intake instead of leaving the customer waiting.
    // $department: route straight to this department (skips the AI
    // classifier), e.g. delivery queries we can't track go to Sales.
    public static function start(string $sessionId, string $reason = 'customer_request', ?int $now = null, ?string $department = null): array
    {
        $now = $now ?? time();
        $session = self::session($sessionId);
        if (!$session) return ['ok' => false, 'error' => 'Invalid session'];
        $mode = $session['mode'] === 'live_ended' ? 'ai' : $session['mode'];
        if ($mode !== 'ai') {
            return ['ok' => true, 'mode' => $mode, 'department' => $session['department'], 'already' => true];
        }

        $hist = db()->prepare("SELECT role, content FROM chat_messages WHERE session_id = ? AND role IN ('user','assistant') ORDER BY id");
        $hist->execute([$sessionId]);
        if ($department !== null && isset(self::DEPARTMENTS[$department])) {
            $routing = ['department' => $department, 'confident' => true];
        } else {
            try {
                $routing = DepartmentClassifier::classify($hist->fetchAll());
            } catch (\Throwable $e) {
                $routing = ['department' => 'sales', 'confident' => false];
            }
        }
        $dept = $routing['department'];
        db()->prepare('UPDATE chat_sessions SET department = ?, updated_at = ? WHERE id = ?')->execute([$dept, $now, $sessionId]);

        if (!\Support\Hours::isOpen($now)) {
            TicketIntake::begin($sessionId, 'closed', $now);
            return ['ok' => true, 'mode' => 'intake', 'department' => $dept];
        }
        if (self::onlineStaffCount() === 0) {
            TicketIntake::begin($sessionId, 'busy', $now);
            return ['ok' => true, 'mode' => 'intake', 'department' => $dept];
        }

        db()->prepare("UPDATE chat_sessions SET mode = 'live_requested', handoff_at = ?, claimed_by = NULL, target_admin_id = NULL, updated_at = ? WHERE id = ?")
            ->execute([$now, $now, $sessionId]);
        $label = self::deptLabel($dept);
        self::say($sessionId, 'system',
            ($reason === 'ai_unsure' ? "I'd like one of our specialists to help with this, so I'm passing you to our {$label} team now."
             : ($reason === 'tracking' ? "I can't track that one automatically, so I'm passing you to our {$label} team, who can check your delivery for you."
             : "I'm passing you to our {$label} team now."))
            . ' Someone will be with you shortly.');
        self::audit(null, 'chat_handoff', $sessionId, "{$dept} ({$reason})" . ($routing['confident'] ? '' : ', AI unsure'));
        if (!$routing['confident']) {
            self::addNote($sessionId, null, "Routed to {$label} by default: the AI wasn't sure which department this belongs to. Please transfer it if needed.");
        }
        try {
            \Telegram\Notifier::send("🔴 Live chat waiting for {$label}\n" . self::firstQuestion($sessionId) . (!empty($session['page_url']) ? "\nPage: {$session['page_url']}" : ''));
        } catch (\Throwable $e) {}
        return ['ok' => true, 'mode' => 'live_requested', 'department' => $dept];
    }

    public static function firstQuestion(string $sessionId): string
    {
        $s = db()->prepare("SELECT content FROM chat_messages WHERE session_id = ? AND role = 'user' ORDER BY id LIMIT 1");
        $s->execute([$sessionId]);
        return mb_substr((string)($s->fetchColumn() ?: 'Customer chat'), 0, 200);
    }

    // Any staff member can claim a waiting chat (or take over one Max is
    // handling). The mode condition inside the UPDATE closes the race when
    // two people click Accept at the same moment.
    public static function claim(string $sessionId, int $adminId): array
    {
        $session = self::session($sessionId);
        if (!$session) return ['ok' => false, 'error' => 'Invalid session'];
        if ($session['mode'] === 'live_active') {
            return ['ok' => false, 'error' => (int)$session['claimed_by'] === $adminId ? 'You already have this chat' : 'Already claimed by ' . self::staffName((int)$session['claimed_by'])];
        }
        $now = time();
        $upd = db()->prepare("UPDATE chat_sessions SET mode = 'live_active', claimed_by = ?, target_admin_id = NULL, intake = NULL, updated_at = ?
                              WHERE id = ? AND mode IN ('live_requested','intake','ai','live_ended')");
        $upd->execute([$adminId, $now, $sessionId]);
        if ($upd->rowCount() === 0) return ['ok' => false, 'error' => 'Already claimed'];

        $dept = $session['department'];
        if (!$dept) {
            $d = db()->prepare('SELECT department FROM admin_user_departments WHERE admin_id = ? ORDER BY department LIMIT 1');
            $d->execute([$adminId]);
            $dept = $d->fetchColumn() ?: null;
            if ($dept) db()->prepare('UPDATE chat_sessions SET department = ? WHERE id = ?')->execute([$dept, $sessionId]);
        }
        $name = self::staffName($adminId);
        self::say($sessionId, 'system', "{$name} from our " . self::deptLabel($dept) . ' team has joined the chat.');
        db()->prepare("UPDATE support_tickets SET assigned_admin_id = ?, status = 'in_progress', updated_at = ? WHERE session_id = ? AND channel = 'live_chat' AND status NOT IN ('resolved','closed')")
            ->execute([$adminId, $now, $sessionId]);
        self::audit($adminId, 'chat_claimed', $sessionId, $dept ?? '');
        return ['ok' => true];
    }

    // Pass a chat to another department and/or a specific colleague. The
    // chat goes back to waiting (fresh 3-minute clock) for the new target.
    public static function transfer(string $sessionId, int $adminId, ?string $department, ?int $toAdminId, string $note = ''): array
    {
        $session = self::session($sessionId);
        if (!$session) return ['ok' => false, 'error' => 'Invalid session'];
        if ($department !== null && $department !== '' && !isset(self::DEPARTMENTS[$department])) {
            return ['ok' => false, 'error' => 'Invalid department'];
        }
        if (!in_array($session['mode'], ['live_requested', 'live_active', 'intake'], true)) {
            return ['ok' => false, 'error' => 'Only a chat waiting for or with staff can be transferred'];
        }
        if ($session['mode'] === 'live_active' && (int)$session['claimed_by'] !== $adminId && !self::isAdmin($adminId)) {
            return ['ok' => false, 'error' => 'Only ' . self::staffName((int)$session['claimed_by']) . ' can transfer this chat'];
        }
        $department = ($department === '' ? null : $department);
        if ($toAdminId !== null) {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE id = ?');
            $chk->execute([$toAdminId]);
            if (!$chk->fetchColumn()) return ['ok' => false, 'error' => 'Unknown colleague'];
            if ($toAdminId === $adminId) return ['ok' => false, 'error' => 'You cannot transfer a chat to yourself'];
        }
        if ($department === null && $toAdminId === null) {
            return ['ok' => false, 'error' => 'Choose a department or a colleague'];
        }
        $newDept = $department ?? $session['department'];
        if ($department === null && $toAdminId !== null && !$session['department']) {
            $d = db()->prepare('SELECT department FROM admin_user_departments WHERE admin_id = ? ORDER BY department LIMIT 1');
            $d->execute([$toAdminId]);
            $newDept = $d->fetchColumn() ?: null;
        }
        $now = time();
        db()->prepare("UPDATE chat_sessions SET mode = 'live_requested', department = ?, target_admin_id = ?, claimed_by = NULL, intake = NULL, handoff_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$newDept, $toAdminId, $now, $now, $sessionId]);

        $by = self::staffName($adminId);
        if ($toAdminId !== null) {
            $to = self::staffName($toAdminId);
            self::say($sessionId, 'system', "{$by} is passing your chat to {$to}, who is best placed to help. {$to} will be with you shortly.");
            $target = $to;
        } else {
            $label = self::deptLabel($newDept);
            self::say($sessionId, 'system', "{$by} is transferring you to our {$label} team, who are best placed to help. Someone will be with you shortly.");
            $target = $label;
        }
        self::addNote($sessionId, $adminId, "Transferred to {$target}" . (trim($note) !== '' ? ': ' . trim($note) : '.'));
        self::audit($adminId, 'chat_transferred', $sessionId, $target);
        return ['ok' => true, 'department' => $newDept, 'target_admin_id' => $toAdminId];
    }

    // Staff member finishes the conversation; the customer goes back to Max.
    public static function end(string $sessionId, int $adminId): array
    {
        $session = self::session($sessionId);
        if (!$session || $session['mode'] !== 'live_active') return ['ok' => false, 'error' => 'This chat is not live'];
        if ((int)$session['claimed_by'] !== $adminId && !self::isAdmin($adminId)) return ['ok' => false, 'error' => 'Claimed by someone else'];
        $now = time();
        db()->prepare("UPDATE chat_sessions SET mode = 'ai', claimed_by = NULL, target_admin_id = NULL, handoff_at = NULL, updated_at = ? WHERE id = ?")->execute([$now, $sessionId]);
        self::say($sessionId, 'system', self::staffName($adminId) . ' has ended the chat. Thank you for contacting Blake UK. If you need anything else, just ask Max here.');
        db()->prepare("UPDATE support_tickets SET status = 'resolved', updated_at = ? WHERE session_id = ? AND channel = 'live_chat' AND status NOT IN ('resolved','closed')")->execute([$now, $sessionId]);
        self::audit($adminId, 'chat_ended', $sessionId, '');
        return ['ok' => true];
    }

    // 3 minutes waiting with nobody accepting: Max apologises and raises a ticket.
    public static function sweepTimeouts(?int $now = null, ?string $onlySession = null): int
    {
        $now = $now ?? time();
        $sql = "SELECT id FROM chat_sessions WHERE mode = 'live_requested' AND handoff_at IS NOT NULL AND handoff_at <= ?";
        $args = [$now - self::TIMEOUT_SECONDS];
        if ($onlySession !== null) { $sql .= ' AND id = ?'; $args[] = $onlySession; }
        $s = db()->prepare($sql);
        $s->execute($args);
        $n = 0;
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (TicketIntake::begin($id, \Support\Hours::isOpen($now) ? 'busy' : 'closed', $now)) $n++;
        }
        // Stale-queue cleanup is a whole-desk job, not a per-chat one.
        if ($onlySession === null) self::closeStale($now);
        return $n;
    }

    // A customer who left mid-handover would otherwise sit in the queue
    // flashing at staff for ever. After 30 minutes with nothing said, the
    // chat drops back to Max so the alert and the red entry clear.
    public const STALE_SECONDS = 1800;

    public static function closeStale(?int $now = null): int
    {
        $now = $now ?? time();
        $cut = $now - self::STALE_SECONDS;
        $sql = "SELECT s.id FROM chat_sessions s
                WHERE s.mode IN ('live_requested', 'intake')
                  AND COALESCE((SELECT MAX(created_at) FROM chat_messages m WHERE m.session_id = s.id), s.updated_at) <= CAST(? AS INTEGER)
                  AND COALESCE(s.handoff_at, 0) <= CAST(? AS INTEGER)";
        $q = db()->prepare($sql);
        $q->execute([$cut, $cut]);
        $n = 0;
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            db()->prepare("UPDATE chat_sessions SET mode = 'ai', intake = NULL, updated_at = ? WHERE id = ?")->execute([$now, $id]);
            self::addNote($id, null, 'Customer left before anyone joined; the chat was returned to Max after 30 minutes of silence.');
            $n++;
        }
        return $n;
    }

    // A ticket has been dealt with: if its chat is still queued or taking
    // details, it should stop alerting the team.
    public static function releaseForTicket(int $ticketId, ?int $now = null): bool
    {
        $now = $now ?? time();
        $q = db()->prepare('SELECT session_id FROM support_tickets WHERE id = ?');
        $q->execute([$ticketId]);
        $sessionId = (string)($q->fetchColumn() ?: '');
        if ($sessionId === '') return false;
        $s = self::session($sessionId);
        if (!$s || !in_array($s['mode'], ['live_requested', 'intake', 'live_active'], true)) return false;
        db()->prepare("UPDATE chat_sessions SET mode = 'ai', claimed_by = NULL, target_admin_id = NULL, intake = NULL, updated_at = ? WHERE id = ?")
            ->execute([$now, $sessionId]);
        self::addNote($sessionId, null, 'Ticket dealt with, so the chat was taken out of the queue.');
        return true;
    }

    public static function addNote(string $sessionId, ?int $adminId, string $note): array
    {
        $note = trim($note);
        if ($note === '') return ['ok' => false, 'error' => 'Note is empty'];
        if (mb_strlen($note) > 4000) return ['ok' => false, 'error' => 'Note is too long'];
        if (!self::session($sessionId)) return ['ok' => false, 'error' => 'Invalid session'];
        db()->prepare('INSERT INTO chat_notes (session_id, admin_id, note) VALUES (?, ?, ?)')->execute([$sessionId, $adminId, $note]);
        return ['ok' => true, 'id' => (int)db()->lastInsertId()];
    }

    public static function notes(string $sessionId): array
    {
        $s = db()->prepare('SELECT n.id, n.note, n.created_at, n.admin_id, a.username FROM chat_notes n LEFT JOIN admin_users a ON a.id = n.admin_id WHERE n.session_id = ? ORDER BY n.id');
        $s->execute([$sessionId]);
        return $s->fetchAll();
    }

    // Raise a ticket for a chat (staff-initiated, or by TicketIntake with
    // $adminId null). Emails the support inbox and the customer.
    public static function createTicket(string $sessionId, ?int $adminId, array $f): array
    {
        $session = self::session($sessionId);
        if (!$session) return ['ok' => false, 'error' => 'Invalid session'];
        $email = trim((string)($f['email'] ?? $session['customer_email'] ?? ''));
        $phone = trim((string)($f['phone'] ?? $session['customer_phone'] ?? ''));
        $name  = trim((string)($f['name'] ?? $session['customer_name'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid email address'];
        $dept = $f['department'] ?? $session['department'] ?? 'sales';
        if (!isset(self::DEPARTMENTS[$dept])) $dept = 'sales';
        $priority = in_array($f['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $f['priority'] : 'medium';
        $subject = mb_substr(trim((string)($f['subject'] ?? '')) ?: self::firstQuestion($sessionId), 0, 150);
        $details = trim((string)($f['details'] ?? ''));

        $now = time();
        db()->prepare('INSERT INTO support_tickets (session_id, status, subject, customer_email, customer_name, customer_phone, details, department, channel, priority, sla_deadline, assigned_admin_id, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$sessionId, 'open', $subject, $email ?: null, $name ?: null, $phone ?: null, $details ?: null, $dept, 'chat', $priority,
                       \Tickets\Sla::deadline($priority, $now), $adminId, $now, $now]);
        $ticketId = (int)db()->lastInsertId();
        db()->prepare('UPDATE chat_sessions SET customer_name = COALESCE(?, customer_name), customer_email = COALESCE(?, customer_email), customer_phone = COALESCE(?, customer_phone) WHERE id = ?')
            ->execute([$name ?: null, $email ?: null, $phone ?: null, $sessionId]);

        $mail = \Tickets\Mailer::sendConfirmations($ticketId);
        self::audit($adminId, 'ticket_created_from_chat', (string)$ticketId, $subject);
        try {
            \Telegram\Notifier::sendTicketAlert($ticketId, $subject, $email ?: ($phone ?: null), $session['page_url'] ?? null, $dept);
        } catch (\Throwable $e) {}

        $code = \Tickets\Mailer::code($ticketId);
        if ($adminId !== null) {
            self::say($sessionId, 'system', self::staffName($adminId) . " has raised support ticket {$code} for you."
                . ($email !== '' ? " A confirmation will be emailed to {$email}." : '')
                . " Please quote {$code} if you contact us about this.");
        }
        return ['ok' => true, 'ticket_id' => $ticketId, 'code' => $code, 'emailed_customer' => $mail['customer']];
    }

    public static function setCustomer(string $sessionId, array $f): array
    {
        if (!self::session($sessionId)) return ['ok' => false, 'error' => 'Invalid session'];
        $email = trim((string)($f['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid email address'];
        db()->prepare('UPDATE chat_sessions SET customer_name = ?, customer_email = ?, customer_phone = ? WHERE id = ?')
            ->execute([trim((string)($f['name'] ?? '')) ?: null, $email ?: null, trim((string)($f['phone'] ?? '')) ?: null, $sessionId]);
        return ['ok' => true];
    }

    public static function isAdmin(int $adminId): bool
    {
        $s = db()->prepare('SELECT role FROM admin_users WHERE id = ?');
        $s->execute([$adminId]);
        return $s->fetchColumn() === 'admin';
    }

    // Departments a staff member belongs to (admins with none see everything).
    public static function departmentsOf(int $adminId): array
    {
        $s = db()->prepare('SELECT department FROM admin_user_departments WHERE admin_id = ?');
        $s->execute([$adminId]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    // Chats for the operator console. 'alert' marks the waiting chats this
    // staff member should be popped up about: sent to them personally, to
    // one of their departments, or to a department with nobody online.
    public static function queue(int $adminId, int $recentMinutes = 60): array
    {
        $now  = time();
        $mine = self::departmentsOf($adminId);
        $rows = db()->prepare("
            SELECT s.id, s.mode, s.department, s.handoff_at, s.claimed_by, s.target_admin_id, s.page_url, s.created_at, s.updated_at,
                   s.customer_name, s.customer_email, s.customer_phone,
                   c.username AS claimed_username, t.username AS target_username,
                   (SELECT content FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user' ORDER BY m.id LIMIT 1) AS first_message,
                   (SELECT content FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user' ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT MAX(id) FROM chat_messages m WHERE m.session_id = s.id) AS last_message_id,
                   (SELECT MAX(created_at) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') AS last_customer_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') AS customer_messages,
                   (SELECT COUNT(*) FROM chat_notes n WHERE n.session_id = s.id) AS note_count,
                   (SELECT MAX(id) FROM support_tickets st WHERE st.session_id = s.id) AS ticket_id
            FROM chat_sessions s
            LEFT JOIN admin_users c ON c.id = s.claimed_by
            LEFT JOIN admin_users t ON t.id = s.target_admin_id
            WHERE s.mode IN ('live_requested','live_active','intake')
               OR (s.updated_at >= ? AND EXISTS (SELECT 1 FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user'))
            ORDER BY CASE s.mode WHEN 'live_requested' THEN 0 WHEN 'intake' THEN 1 WHEN 'live_active' THEN 2 ELSE 3 END, s.updated_at DESC
            LIMIT 100");
        $rows->execute([$now - $recentMinutes * 60]);
        $out = [];
        $onlineByDept = [];
        foreach ($rows->fetchAll() as $r) {
            $alert = false;
            if (in_array($r['mode'], ['live_requested', 'intake'], true)) {
                if ($r['target_admin_id'] !== null) {
                    $alert = (int)$r['target_admin_id'] === $adminId;
                } else {
                    $d = $r['department'] ?? '';
                    $onlineByDept[$d] = $onlineByDept[$d] ?? ($d === '' ? 0 : self::onlineStaffCount($d));
                    $alert = $d === '' || !$mine || in_array($d, $mine, true) || $onlineByDept[$d] === 0;
                }
            }
            $r['alert']         = $alert;
            $r['waiting_secs']  = $r['handoff_at'] ? max(0, $now - (int)$r['handoff_at']) : null;
            $r['department_label'] = self::deptLabel($r['department']);
            $r['ticket_code']   = $r['ticket_id'] ? \Tickets\Mailer::code((int)$r['ticket_id']) : null;
            $out[] = $r;
        }
        return $out;
    }

    private static function audit(?int $adminId, string $action, string $target, string $detail): void
    {
        try {
            db()->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')->execute([$adminId, $action, $target, $detail]);
        } catch (\Throwable $e) {}
    }
}

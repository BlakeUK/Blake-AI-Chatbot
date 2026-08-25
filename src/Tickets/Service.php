<?php
// src/Tickets/Service.php
// Ticket mutations shared between the admin API
// (public/api/admin/tickets.php) and the Telegram inline-button handler
// (scripts/process_telegram_updates.php) - one code path for "move a
// ticket to a different department" regardless of where the action came
// from, so the two can't quietly drift apart from each other.

declare(strict_types=1);

namespace Tickets;

class Service
{
    private const VALID_DEPARTMENTS = ['sales', 'technical', 'accounts'];

    // $adminId is the acting admin_users.id for the audit log (the admin
    // API always has one); pass null for a non-admin-panel actor (Telegram)
    // and use $actorNote to say who/what did it instead - appended to the
    // audit log detail rather than replacing it, so the admin-panel case
    // (passing null) keeps its existing "sales"/"(unassigned)" phrasing
    // exactly as before.
    //
    // Returns ['ok', 'error', 'changed', 'department', 'subject']. $changed
    // is false for a no-op resave (same department as before) - callers use
    // that to decide whether anything needs telling to the person who
    // triggered this, on top of the department-change Telegram alert this
    // already fires internally.
    public static function reassignDepartment(int $ticketId, string $department, ?int $adminId, ?string $actorNote = null): array
    {
        $department = trim($department);
        if ($department !== '' && !in_array($department, self::VALID_DEPARTMENTS, true)) {
            return ['ok' => false, 'error' => 'Invalid department'];
        }

        $pdo  = db();
        $prev = $pdo->prepare('SELECT department, subject, customer_email FROM support_tickets WHERE id=?');
        $prev->execute([$ticketId]);
        $prevRow = $prev->fetch();
        if (!$prevRow) {
            return ['ok' => false, 'error' => 'Ticket not found'];
        }
        $prevDept = $prevRow['department'];
        $subject  = $prevRow['subject'] ?? '';

        $pdo->prepare('UPDATE support_tickets SET department=?, updated_at=? WHERE id=?')
            ->execute([$department !== '' ? $department : null, time(), $ticketId]);

        $detail = $department !== '' ? $department : '(unassigned)';
        if ($actorNote) {
            $detail .= " (via {$actorNote})";
        }
        $pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
            ->execute([$adminId, 'ticket_department_change', $ticketId, $detail]);

        // Same rule as before this was extracted: only the receiving
        // department needs telling, and only when this actually moved
        // somewhere - not a no-op resave, not clearing to unassigned.
        $changed = ($department !== '' && $department !== $prevDept);
        if ($changed) {
            \Telegram\Notifier::sendDepartmentChangeAlert($ticketId, $subject, $prevDept, $department, $prevRow['customer_email'] ?? null);
        }

        return [
            'ok'         => true,
            'error'      => null,
            'changed'    => $changed,
            'department' => $department !== '' ? $department : null,
            'subject'    => $subject,
        ];
    }
}

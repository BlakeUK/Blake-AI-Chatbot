-- Ticket routing: department handoff for the operator console.
-- department is the v1 mechanism ("pass onto sales/support/accounts").
-- assigned_admin_id is added now for schema completeness but not yet
-- wired into the UI - person-level assignment reuses users.php, which is
-- admin-only, and deserves its own pass rather than bolting it on here.
ALTER TABLE support_tickets ADD COLUMN department TEXT;
ALTER TABLE support_tickets ADD COLUMN assigned_admin_id INTEGER REFERENCES admin_users(id);

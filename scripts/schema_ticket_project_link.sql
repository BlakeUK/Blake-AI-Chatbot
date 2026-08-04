-- Ticket-project binding ("Project Ticket Binding" in the spec) - lets a
-- ticket optionally belong to a project, same nullable-FK pattern already
-- used for assigned_admin_id.
ALTER TABLE support_tickets ADD COLUMN project_id INTEGER REFERENCES projects(id);

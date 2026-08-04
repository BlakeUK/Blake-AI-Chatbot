-- Reminders, v1 scope: attached to a ticket. Broader "attach to anything"
-- (chats, projects, standalone) is real spec scope but has nowhere to live
-- yet - workspaces/projects don't exist, and a standalone reminder with no
-- entity is a different, simpler feature that can be added without
-- reworking this table when it's actually needed.
CREATE TABLE IF NOT EXISTS reminders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id    INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
    admin_id     INTEGER NOT NULL REFERENCES admin_users(id),  -- who it's for
    created_by   INTEGER NOT NULL REFERENCES admin_users(id),
    remind_at    INTEGER NOT NULL,  -- unix timestamp, computed client-side from a relative pick
    note         TEXT,
    acknowledged INTEGER NOT NULL DEFAULT 0,
    created_at   INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_reminders_due ON reminders(admin_id, acknowledged, remind_at);

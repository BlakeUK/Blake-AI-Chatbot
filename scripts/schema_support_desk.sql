-- scripts/schema_support_desk.sql - chat support desk: department routing of
-- live chats, transfers, staff notes, AI ticket intake, and an email outbox.
-- Applied once (deploy guard checks chat_sessions.department).

-- Which department a handed-off chat is routed to, when it was handed off
-- (drives the 3-minute "nobody answered" timeout), and an optional specific
-- colleague it was passed to.
ALTER TABLE chat_sessions ADD COLUMN department TEXT;
ALTER TABLE chat_sessions ADD COLUMN handoff_at INTEGER;
ALTER TABLE chat_sessions ADD COLUMN target_admin_id INTEGER REFERENCES admin_users(id);
-- AI ticket intake (mode='intake'): JSON state of the details collected so far.
ALTER TABLE chat_sessions ADD COLUMN intake TEXT;
-- Customer details as collected (intake or entered by staff).
ALTER TABLE chat_sessions ADD COLUMN customer_name TEXT;
ALTER TABLE chat_sessions ADD COLUMN customer_email TEXT;
ALTER TABLE chat_sessions ADD COLUMN customer_phone TEXT;

ALTER TABLE support_tickets ADD COLUMN customer_name TEXT;
ALTER TABLE support_tickets ADD COLUMN customer_phone TEXT;
ALTER TABLE support_tickets ADD COLUMN details TEXT;

-- Internal staff notes on a chat, visible to every staff member, never to
-- the customer.
CREATE TABLE IF NOT EXISTS chat_notes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id  TEXT NOT NULL REFERENCES chat_sessions(id),
    admin_id    INTEGER REFERENCES admin_users(id),
    note        TEXT NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX IF NOT EXISTS idx_chat_notes_session ON chat_notes(session_id);

-- Outgoing email (ticket confirmations). Queued so a mail-server hiccup
-- never blocks a customer's chat, and retried by the support cron.
CREATE TABLE IF NOT EXISTS email_outbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    to_addr     TEXT NOT NULL,
    subject     TEXT NOT NULL,
    body_text   TEXT NOT NULL,
    ticket_id   INTEGER,
    status      TEXT NOT NULL DEFAULT 'pending',   -- pending | sent | failed
    attempts    INTEGER NOT NULL DEFAULT 0,
    last_error  TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    sent_at     INTEGER
);
CREATE INDEX IF NOT EXISTS idx_email_outbox_status ON email_outbox(status);
CREATE INDEX IF NOT EXISTS idx_chat_sessions_handoff ON chat_sessions(mode, handoff_at);

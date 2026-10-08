-- Ticket conversations: staff and customer replies, the customer's secure ("magic") link, and BCC recipients for outgoing email.
-- All idempotent (no ALTERs), safe to run on every deploy.
CREATE TABLE IF NOT EXISTS ticket_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id   INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
    author      TEXT NOT NULL CHECK (author IN ('customer', 'staff')),
    admin_id    INTEGER,
    author_name TEXT NOT NULL DEFAULT '',
    body        TEXT NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id, id);

-- The per-ticket secret mixed into the customer's link. Rotating it revokes every link already sent.
CREATE TABLE IF NOT EXISTS ticket_links (
    ticket_id   INTEGER PRIMARY KEY REFERENCES support_tickets(id) ON DELETE CASCADE,
    salt        TEXT NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- Blind-copy recipients of one queued email. They are never written into the message headers.
CREATE TABLE IF NOT EXISTS email_outbox_bcc (
    outbox_id   INTEGER NOT NULL REFERENCES email_outbox(id) ON DELETE CASCADE,
    addr        TEXT NOT NULL,
    PRIMARY KEY (outbox_id, addr)
);

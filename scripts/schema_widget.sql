-- schema_widget.sql — append after schema.sql
-- External widget API client management

CREATE TABLE IF NOT EXISTS widget_clients (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    api_key         TEXT NOT NULL UNIQUE,
    allowed_ips     TEXT NOT NULL DEFAULT '[]',   -- JSON array, empty = allow all
    allowed_origins TEXT NOT NULL DEFAULT '[]',   -- JSON array, empty = allow all
    active          INTEGER NOT NULL DEFAULT 1,
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS widget_tokens (
    token       TEXT PRIMARY KEY,
    client_id   INTEGER NOT NULL REFERENCES widget_clients(id),
    ip          TEXT,
    used        INTEGER NOT NULL DEFAULT 0,
    expires_at  INTEGER NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS widget_access_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id   INTEGER REFERENCES widget_clients(id),
    ip          TEXT,
    origin      TEXT,
    action      TEXT NOT NULL, -- token_issued|invalid_key|ip_blocked|origin_blocked
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_widget_tokens_expires ON widget_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_widget_log_client ON widget_access_log(client_id, created_at);
CREATE INDEX IF NOT EXISTS idx_widget_clients_key ON widget_clients(api_key);

-- qrtrack initial schema. All timestamps are UTC, RFC3339 (YYYY-MM-DDTHH:MM:SSZ),
-- which sorts lexicographically, so plain string comparison is time comparison.

CREATE TABLE users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    username             TEXT    NOT NULL UNIQUE,
    password_hash        TEXT    NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 1,
    created_at           TEXT    NOT NULL,
    updated_at           TEXT    NOT NULL
);

CREATE TABLE sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash   TEXT    NOT NULL UNIQUE,  -- SHA-256 of the cookie token; the token itself is never stored
    csrf_token   TEXT    NOT NULL,         -- synchroniser token for this session
    created_at   TEXT    NOT NULL,
    last_seen_at TEXT    NOT NULL,
    expires_at   TEXT    NOT NULL          -- absolute expiry
);
CREATE INDEX idx_sessions_expires ON sessions(expires_at);

CREATE TABLE links (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT    NOT NULL UNIQUE,
    label           TEXT    NOT NULL,
    destination_url TEXT    NOT NULL,
    track_start     TEXT    NOT NULL,
    track_end       TEXT    NOT NULL,
    expiry_mode     TEXT    NOT NULL DEFAULT 'redirect_untracked'
        CHECK (expiry_mode IN ('redirect_untracked', 'show_expired_page', 'redirect_fallback_url')),
    fallback_url    TEXT    NOT NULL DEFAULT '',
    qr_ecc          TEXT    NOT NULL DEFAULT 'M' CHECK (qr_ecc IN ('L', 'M', 'Q', 'H')),
    enabled         INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE scans (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id      INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    scanned_at   TEXT    NOT NULL,
    ip_hash      TEXT    NOT NULL,          -- HMAC(daily salt, IP). The raw IP is never stored.
    country      TEXT    NOT NULL DEFAULT '',
    device_class TEXT    NOT NULL,
    os           TEXT    NOT NULL DEFAULT '',
    browser      TEXT    NOT NULL DEFAULT '',
    referer_host TEXT    NOT NULL DEFAULT '',
    user_agent   TEXT    NOT NULL DEFAULT '',
    is_bot       INTEGER NOT NULL DEFAULT 0,
    is_unique    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_scans_link_time ON scans(link_id, scanned_at);
CREATE INDEX idx_scans_link_ip   ON scans(link_id, ip_hash, scanned_at);
CREATE INDEX idx_scans_time      ON scans(scanned_at);  -- retention purge

-- ip is a keyed hash of the client address, not the address itself.
CREATE TABLE login_attempts (
    ip           TEXT    NOT NULL,
    attempted_at TEXT    NOT NULL,
    success      INTEGER NOT NULL
);
CREATE INDEX idx_login_attempts ON login_attempts(ip, attempted_at);

-- Server-side secrets generated on first run (key for hashing login IPs).
CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value BLOB NOT NULL
);

-- One random salt per UTC day. Salts older than a couple of days are deleted,
-- after which the stored ip_hash values can no longer be linked back to an IP.
CREATE TABLE daily_salts (
    day  TEXT PRIMARY KEY,
    salt BLOB NOT NULL
);

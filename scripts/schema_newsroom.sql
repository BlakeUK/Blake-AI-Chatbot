-- Newsroom: a short-lived cache of fetched feeds, each person's saved bookmarks and custom feeds, and one-time sign-in codes for the QR tool.
CREATE TABLE IF NOT EXISTS news_cache (
    cache_key  TEXT PRIMARY KEY,
    fetched_at INTEGER NOT NULL,
    body       TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS news_prefs (
    admin_id   INTEGER NOT NULL,
    k          TEXT NOT NULL,
    v          TEXT NOT NULL,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (admin_id, k)
);
CREATE TABLE IF NOT EXISTS qr_sso_tokens (
    token_hash TEXT PRIMARY KEY,           -- SHA-256 of the code; the code itself is never stored
    admin_id   INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    used_at    INTEGER
);
CREATE INDEX IF NOT EXISTS idx_qr_sso_expires ON qr_sso_tokens (expires_at);

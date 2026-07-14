-- Blake UK Chatbot — SQLite schema
-- Run: sqlite3 data/chatbot.db < scripts/schema.sql

PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

-- ─── Admin ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS admin_users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL UNIQUE,
    password    TEXT NOT NULL,  -- password_hash()
    role        TEXT NOT NULL DEFAULT 'admin',
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    last_login  INTEGER
);

-- ─── API Keys (encrypted) ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    service     TEXT NOT NULL UNIQUE, -- 'gemini', 'royalmail', 'dpd', 'dx'
    key_enc     TEXT NOT NULL,        -- AES-256-GCM encrypted, hex
    iv          TEXT NOT NULL,        -- GCM IV, hex
    tag         TEXT NOT NULL,        -- GCM auth tag, hex
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- ─── Knowledge ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS knowledge_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    body        TEXT NOT NULL,
    category    TEXT,
    product_codes TEXT,  -- JSON array
    url         TEXT,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS knowledge_files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    filename    TEXT NOT NULL,
    mime_type   TEXT NOT NULL,
    stored_path TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending', -- pending|indexed|error
    error       TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS knowledge_chunks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type TEXT NOT NULL, -- 'manual'|'file'|'product'|'page'
    source_id   INTEGER NOT NULL,
    chunk_text  TEXT NOT NULL,
    url         TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_fts USING fts5(
    chunk_text,
    url,
    content='knowledge_chunks',
    content_rowid='id'
);

-- ─── Products ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS products (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_code    TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    title           TEXT,
    url             TEXT,
    category_path   TEXT,  -- JSON array
    summary_bullets TEXT,  -- JSON array
    description     TEXT,
    tech_specs      TEXT,  -- JSON object
    price_inc_vat   REAL,
    price_exc_vat   REAL,
    stock_status    TEXT,
    image_url       TEXT,
    image_alt       TEXT,
    search_terms    TEXT,  -- JSON array
    active          INTEGER NOT NULL DEFAULT 1,
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS product_variants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_code     TEXT NOT NULL REFERENCES products(product_code),
    variant_code    TEXT NOT NULL UNIQUE,
    attributes      TEXT,  -- JSON object
    url             TEXT,
    price_inc_vat   REAL,
    price_exc_vat   REAL
);

CREATE TABLE IF NOT EXISTS product_documents (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_code    TEXT NOT NULL REFERENCES products(product_code),
    doc_type        TEXT,  -- 'manual'|'datasheet'|'doc'
    title           TEXT,
    url             TEXT
);

CREATE VIRTUAL TABLE IF NOT EXISTS products_fts USING fts5(
    product_code,
    name,
    title,
    description,
    search_terms,
    content='products',
    content_rowid='id'
);

-- ─── Chat ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS chat_sessions (
    id          TEXT PRIMARY KEY,  -- UUID
    page_url    TEXT,
    product_code TEXT,
    category    TEXT,
    ip_hash     TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id  TEXT NOT NULL REFERENCES chat_sessions(id),
    role        TEXT NOT NULL,  -- 'user'|'assistant'
    content     TEXT NOT NULL,
    confidence  REAL,
    escalated   INTEGER NOT NULL DEFAULT 0,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS answer_sources (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id  INTEGER NOT NULL REFERENCES chat_messages(id),
    source_type TEXT NOT NULL,
    source_id   INTEGER,
    url         TEXT,
    snippet     TEXT
);

CREATE TABLE IF NOT EXISTS answer_corrections (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id  INTEGER NOT NULL REFERENCES chat_messages(id),
    original    TEXT NOT NULL,
    corrected   TEXT NOT NULL,
    promoted    INTEGER NOT NULL DEFAULT 0,
    admin_id    INTEGER,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- ─── Support Tickets ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS support_tickets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id  TEXT REFERENCES chat_sessions(id),
    status      TEXT NOT NULL DEFAULT 'open',  -- open|pending|closed
    subject     TEXT,
    customer_email TEXT,
    notes       TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- ─── Tracking ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS tracking_requests (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id  TEXT REFERENCES chat_sessions(id),
    carrier     TEXT,
    tracking_no TEXT,
    result      TEXT,  -- JSON
    status      TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- ─── Rate Limiting ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash     TEXT NOT NULL,
    endpoint    TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    count       INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (ip_hash, endpoint, window_start)
);

-- ─── Audit Log ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id    INTEGER,
    action      TEXT NOT NULL,
    target      TEXT,
    detail      TEXT,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

-- ─── Indexes ─────────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS idx_chat_messages_session ON chat_messages(session_id);
CREATE INDEX IF NOT EXISTS idx_answer_sources_message ON answer_sources(message_id);
CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_source ON knowledge_chunks(source_type, source_id);
CREATE INDEX IF NOT EXISTS idx_product_variants_parent ON product_variants(parent_code);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(ip_hash, endpoint, window_start);

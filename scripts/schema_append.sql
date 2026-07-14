
-- ─── Settings ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('gemini_chat_model',    'gemini-1.5-flash'),
    ('gemini_extract_model', 'gemini-1.5-pro');

-- ─── Indexes ─────────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS idx_chat_messages_session ON chat_messages(session_id);
CREATE INDEX IF NOT EXISTS idx_answer_sources_message ON answer_sources(message_id);
CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_source ON knowledge_chunks(source_type, source_id);
CREATE INDEX IF NOT EXISTS idx_product_variants_parent ON product_variants(parent_code);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(ip_hash, endpoint, window_start);

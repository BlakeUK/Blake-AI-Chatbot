-- scripts/schema_faq.sql — auto-generated FAQ entries.
--
-- Populated from real chat exchanges by src/Faq/Builder.php, hooked in from
-- public/api/chat/send.php whenever an answer was grounded (not escalated -
-- see Chat\Responder::confidence()/shouldEscalate()). Admin can edit or
-- delete any entry from the admin FAQ tab (public/api/admin/faq.php); the
-- public/api/chat/faq.php endpoint feeds the widget's quick-question chips.
--
-- Unlike knowledge_fts/products_fts (schema_fts_triggers.sql), this table's
-- FTS index and sync triggers are bundled in the same file as the table
-- itself - that file only runs on fresh installs, but this migration also
-- has to apply cleanly to Blake's existing production DB via the guarded
-- migration block in deploy_remote.sh, so it can't depend on a
-- fresh-install-only step.
CREATE TABLE IF NOT EXISTS faq_entries (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    question          TEXT NOT NULL,             -- shown to customers; admin-editable
    question_norm     TEXT NOT NULL,             -- lowercased/punctuation-stripped, for duplicate matching (see Faq\Builder::normalise())
    answer            TEXT NOT NULL,             -- shown to customers; admin-editable
    hit_count         INTEGER NOT NULL DEFAULT 1,
    first_message_id  INTEGER REFERENCES chat_messages(id),
    last_message_id   INTEGER REFERENCES chat_messages(id),
    created_at        INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at        INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE VIRTUAL TABLE IF NOT EXISTS faq_fts USING fts5(
    question,
    content='faq_entries',
    content_rowid='id'
);

DROP TRIGGER IF EXISTS faq_entries_ai;
DROP TRIGGER IF EXISTS faq_entries_ad;
DROP TRIGGER IF EXISTS faq_entries_au;

CREATE TRIGGER faq_entries_ai AFTER INSERT ON faq_entries BEGIN
    INSERT INTO faq_fts(rowid, question) VALUES (new.id, new.question);
END;

CREATE TRIGGER faq_entries_ad AFTER DELETE ON faq_entries BEGIN
    INSERT INTO faq_fts(faq_fts, rowid, question) VALUES ('delete', old.id, old.question);
END;

CREATE TRIGGER faq_entries_au AFTER UPDATE ON faq_entries BEGIN
    INSERT INTO faq_fts(faq_fts, rowid, question) VALUES ('delete', old.id, old.question);
    INSERT INTO faq_fts(rowid, question) VALUES (new.id, new.question);
END;

-- question_norm lookups are on the hot path of every non-escalated chat
-- reply (Faq\Builder::capture()); hit_count DESC serves the widget's
-- "top questions" query (public/api/chat/faq.php).
CREATE INDEX IF NOT EXISTS idx_faq_entries_question_norm ON faq_entries(question_norm);
CREATE INDEX IF NOT EXISTS idx_faq_entries_hit_count ON faq_entries(hit_count DESC);

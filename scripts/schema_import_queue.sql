-- ─── Bulk URL import queue ────────────────────────────────────────────────────
-- Run: sqlite3 data/chatbot.db < scripts/schema_import_queue.sql

ALTER TABLE knowledge_files ADD COLUMN source_url TEXT;  -- set when imported via import_urls.php

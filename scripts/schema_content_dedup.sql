-- schema_content_dedup.sql — duplicate content detection.
--
-- Two tiers, deliberately treated differently (see src/Knowledge/Dedup.php):
-- exact duplicates (byte-identical file, or identical normalised entry
-- text) are unambiguous and blocked automatically at upload/import time.
-- Near-duplicates (similar but not identical) are only ever flagged for
-- admin review, never auto-deleted - a wrong fuzzy match would silently
-- remove real, distinct information.

ALTER TABLE knowledge_files ADD COLUMN content_hash TEXT;   -- sha256 of the raw uploaded file bytes
ALTER TABLE knowledge_entries ADD COLUMN content_hash TEXT; -- sha256 of normalised title+body text

CREATE INDEX IF NOT EXISTS idx_knowledge_files_hash ON knowledge_files(content_hash);
CREATE INDEX IF NOT EXISTS idx_knowledge_entries_hash ON knowledge_entries(content_hash);

CREATE TABLE IF NOT EXISTS knowledge_duplicate_flags (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type         TEXT NOT NULL,                    -- 'file' | 'manual' (matches knowledge_chunks.source_type)
    source_id           INTEGER NOT NULL,
    similar_source_type TEXT NOT NULL,
    similar_source_id   INTEGER NOT NULL,
    similarity          REAL NOT NULL,                     -- 0.0-1.0 word-overlap (Jaccard) score
    status              TEXT NOT NULL DEFAULT 'pending',    -- pending|dismissed
    created_at          INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_dup_flags_status ON knowledge_duplicate_flags(status);
CREATE INDEX IF NOT EXISTS idx_dup_flags_source ON knowledge_duplicate_flags(source_type, source_id);

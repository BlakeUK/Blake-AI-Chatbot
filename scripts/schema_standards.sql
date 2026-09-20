-- scripts/schema_standards.sql - DVB/ETSI technical standards tier
-- (Knowledge\Standards). Their clause chunks live in knowledge_chunks with
-- source_type = 'standard' and are excluded from normal retrieval.
CREATE TABLE IF NOT EXISTS standards_documents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,     -- e.g. ETSI EN 302 755
    version     TEXT,
    short       TEXT NOT NULL,            -- e.g. DVB-T2
    title       TEXT,
    topic       TEXT,
    url         TEXT,
    chunk_count INTEGER NOT NULL DEFAULT 0,
    imported_at INTEGER NOT NULL DEFAULT (unixepoch())
);

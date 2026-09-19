-- scripts/schema_embeddings.sql - vector embeddings for semantic retrieval
-- (hybrid with FTS5/BM25, see Knowledge\Embeddings). One row per knowledge
-- chunk ('chunk', knowledge_chunks.id) or product ('product', product_code).
CREATE TABLE IF NOT EXISTS embeddings (
    source_type TEXT NOT NULL,
    source_id   TEXT NOT NULL,
    model       TEXT NOT NULL,
    text_hash   TEXT NOT NULL,       -- re-embed when the text changes
    vec         BLOB NOT NULL,       -- float32 little-endian, unit length
    updated_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    PRIMARY KEY (source_type, source_id)
);

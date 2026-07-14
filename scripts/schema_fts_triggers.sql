-- schema_fts_triggers.sql — keep FTS5 external-content indexes in sync automatically.
-- Run once after schema.sql. Idempotent (DROP then CREATE).
-- With these triggers in place, application code must NOT write to *_fts directly.

-- ── knowledge_chunks ↔ knowledge_fts ─────────────────────────────────────────
DROP TRIGGER IF EXISTS knowledge_chunks_ai;
DROP TRIGGER IF EXISTS knowledge_chunks_ad;
DROP TRIGGER IF EXISTS knowledge_chunks_au;

CREATE TRIGGER knowledge_chunks_ai AFTER INSERT ON knowledge_chunks BEGIN
    INSERT INTO knowledge_fts(rowid, chunk_text, url)
    VALUES (new.id, new.chunk_text, new.url);
END;

CREATE TRIGGER knowledge_chunks_ad AFTER DELETE ON knowledge_chunks BEGIN
    INSERT INTO knowledge_fts(knowledge_fts, rowid, chunk_text, url)
    VALUES ('delete', old.id, old.chunk_text, old.url);
END;

CREATE TRIGGER knowledge_chunks_au AFTER UPDATE ON knowledge_chunks BEGIN
    INSERT INTO knowledge_fts(knowledge_fts, rowid, chunk_text, url)
    VALUES ('delete', old.id, old.chunk_text, old.url);
    INSERT INTO knowledge_fts(rowid, chunk_text, url)
    VALUES (new.id, new.chunk_text, new.url);
END;

-- ── products ↔ products_fts ──────────────────────────────────────────────────
DROP TRIGGER IF EXISTS products_ai;
DROP TRIGGER IF EXISTS products_ad;
DROP TRIGGER IF EXISTS products_au;

CREATE TRIGGER products_ai AFTER INSERT ON products BEGIN
    INSERT INTO products_fts(rowid, product_code, name, title, description, search_terms)
    VALUES (new.id, new.product_code, new.name, new.title, new.description, new.search_terms);
END;

CREATE TRIGGER products_ad AFTER DELETE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, product_code, name, title, description, search_terms)
    VALUES ('delete', old.id, old.product_code, old.name, old.title, old.description, old.search_terms);
END;

CREATE TRIGGER products_au AFTER UPDATE ON products BEGIN
    INSERT INTO products_fts(products_fts, rowid, product_code, name, title, description, search_terms)
    VALUES ('delete', old.id, old.product_code, old.name, old.title, old.description, old.search_terms);
    INSERT INTO products_fts(rowid, product_code, name, title, description, search_terms)
    VALUES (new.id, new.product_code, new.name, new.title, new.description, new.search_terms);
END;

-- scripts/schema_terms.sql - trade terminology: what customers call things.
-- Used to widen a search ("roman nose" -> brick cover / cable entry cover)
-- and to tell Max apart terms that look similar but are not (an amplifier is
-- active; an SPL204 splitter is passive).
CREATE TABLE IF NOT EXISTS term_aliases (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    term          TEXT NOT NULL,            -- what we call it
    aliases       TEXT NOT NULL,            -- comma separated: what customers call it
    search_terms  TEXT,                     -- extra words to search with (defaults to term)
    note          TEXT,                     -- explanation / distinction for Max
    product_codes TEXT,                     -- example codes, comma separated
    department    TEXT,                     -- who to pass to if we still can't help
    active        INTEGER NOT NULL DEFAULT 1,
    updated_at    INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX IF NOT EXISTS idx_term_aliases_active ON term_aliases(active);

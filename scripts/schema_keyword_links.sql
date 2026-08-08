-- schema_keyword_links.sql — deterministic keyword/phrase -> page mapping.
--
-- RAG retrieval is inherently probabilistic: whether a page's URL reaches
-- the customer depends on FTS5 finding the right chunk AND the model
-- choosing to cite it. This table lets an admin pin specific words/phrases
-- to a specific page so it's guaranteed to be offered to the model as
-- context whenever the customer's message mentions it - see
-- src/Knowledge/KeywordLinks.php and Chat\Responder::buildContext().
CREATE TABLE IF NOT EXISTS keyword_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    keywords   TEXT NOT NULL,              -- JSON array of trigger words/phrases (case-insensitive, word-boundary match)
    title      TEXT NOT NULL,              -- short label describing the page, shown to the model as context
    url        TEXT NOT NULL,
    active     INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_keyword_links_active ON keyword_links(active);

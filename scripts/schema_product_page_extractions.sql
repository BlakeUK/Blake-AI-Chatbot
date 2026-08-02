-- Tracks bulk product-page extraction jobs, processed in the background by
-- scripts/process_product_pages.php - same deferred pattern as
-- knowledge_files uses for PDFs, for the same reason: a batch of pages
-- shouldn't be gated by per-page Gemini latency in one blocking request.
CREATE TABLE IF NOT EXISTS product_page_extractions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    url             TEXT NOT NULL UNIQUE,
    status          TEXT NOT NULL DEFAULT 'pending', -- pending | product | not_a_product | error
    product_code    TEXT,    -- set once status='product', for quick lookup/audit
    extracted_json  TEXT,
    error           TEXT,
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    processed_at    INTEGER
);

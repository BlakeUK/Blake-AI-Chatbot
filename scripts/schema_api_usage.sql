-- External API call log for the admin "API Usage" tab: every Gemini call
-- (with token counts and estimated cost), carrier tracking, postcode
-- lookups, Telegram sends and website fetches.
CREATE TABLE IF NOT EXISTS api_usage_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    service         TEXT NOT NULL,          -- gemini | tracking | postcodes | telegram | web_fetch
    operation       TEXT,                   -- e.g. chat, file_extract, classify
    model           TEXT,
    http_code       INTEGER,
    ok              INTEGER NOT NULL DEFAULT 1,
    error           TEXT,
    input_tokens    INTEGER NOT NULL DEFAULT 0,
    output_tokens   INTEGER NOT NULL DEFAULT 0,
    thinking_tokens INTEGER NOT NULL DEFAULT 0,
    latency_ms      INTEGER,
    cost_usd        REAL NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_api_usage_created ON api_usage_log(created_at);
CREATE INDEX IF NOT EXISTS idx_api_usage_service ON api_usage_log(service, created_at);

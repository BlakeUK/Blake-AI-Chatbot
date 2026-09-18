-- TV reception predictor: cache of postcode -> location lookups (postcodes.io).
-- lat/lon NULL = postcode was looked up and is not valid (negative cache).
CREATE TABLE IF NOT EXISTS reception_postcodes (
    postcode   TEXT PRIMARY KEY,
    lat        REAL,
    lon        REAL,
    country    TEXT,
    fetched_at INTEGER NOT NULL
);

-- Smart routing gains two rule types: 'time' (day and hours, UK time) and 'split' (a share of visitors, for A/B tests).
-- SQLite cannot change a CHECK constraint in place, so the table is rebuilt with its rows kept as they are.
CREATE TABLE link_rules_new (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id  INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    position INTEGER NOT NULL,
    match    TEXT    NOT NULL CHECK (match IN ('os', 'device', 'language', 'country', 'time', 'split')),
    value    TEXT    NOT NULL,
    url      TEXT    NOT NULL
);
INSERT INTO link_rules_new (id, link_id, position, match, value, url) SELECT id, link_id, position, match, value, url FROM link_rules;
DROP TABLE link_rules;
ALTER TABLE link_rules_new RENAME TO link_rules;
CREATE INDEX idx_link_rules_link ON link_rules(link_id, position);

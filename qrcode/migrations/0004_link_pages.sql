-- Link pages: a hosted "all my links" page (like Linktree) per company, in a theme.
CREATE TABLE link_pages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    slug         TEXT    NOT NULL UNIQUE COLLATE NOCASE,   -- the public address: /l/<slug>
    name         TEXT    NOT NULL,                         -- internal name
    brand        TEXT    NOT NULL,                         -- blake-uk | visionplus | solwise (validated in code, so brands can be added without a migration)
    theme        TEXT    NOT NULL,                         -- midnight | daylight | bold
    accent       TEXT    NOT NULL DEFAULT '',              -- '' means the brand's own colour
    title        TEXT    NOT NULL DEFAULT '',
    subtitle     TEXT    NOT NULL DEFAULT '',
    show_urls    INTEGER NOT NULL DEFAULT 1,               -- show the address under each button's title
    show_socials INTEGER NOT NULL DEFAULT 1,               -- the row of social icons at the bottom
    enabled      INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE link_page_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id     INTEGER NOT NULL REFERENCES link_pages(id) ON DELETE CASCADE,
    position    INTEGER NOT NULL,
    title       TEXT    NOT NULL,
    url         TEXT    NOT NULL,                          -- http(s), mailto: or tel:
    description TEXT    NOT NULL DEFAULT '',
    icon        TEXT    NOT NULL DEFAULT 'auto'
);
CREATE INDEX idx_page_items ON link_page_items(page_id, position);

-- Views of a page and clicks on its buttons. Same privacy rules as QR scans:
-- a daily-salted hash of the address, never the address itself.
CREATE TABLE page_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id      INTEGER NOT NULL REFERENCES link_pages(id) ON DELETE CASCADE,
    item_id      INTEGER NOT NULL DEFAULT 0,               -- 0 for a page view
    at           TEXT    NOT NULL,
    kind         TEXT    NOT NULL CHECK (kind IN ('view', 'click')),
    source       TEXT    NOT NULL DEFAULT 'direct',        -- 'qr' when it came from a QR code, else 'direct'
    ip_hash      TEXT    NOT NULL,
    country      TEXT    NOT NULL DEFAULT '',
    country_name TEXT    NOT NULL DEFAULT '',
    device_class TEXT    NOT NULL DEFAULT '',
    os           TEXT    NOT NULL DEFAULT '',
    browser      TEXT    NOT NULL DEFAULT '',
    language     TEXT    NOT NULL DEFAULT '',
    referer_host TEXT    NOT NULL DEFAULT '',
    is_bot       INTEGER NOT NULL DEFAULT 0,
    is_unique    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_page_events_page ON page_events(page_id, at);
CREATE INDEX idx_page_events_item ON page_events(page_id, item_id);
CREATE INDEX idx_page_events_ip   ON page_events(page_id, kind, ip_hash, at);
CREATE INDEX idx_page_events_at   ON page_events(at);

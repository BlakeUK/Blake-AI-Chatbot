-- Multiple users with roles, and an audit trail of who did what.
ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'member'));
-- Names differ only by case are the same person ("Admin" = "admin").
CREATE UNIQUE INDEX idx_users_username_nocase ON users(username COLLATE NOCASE);

-- No IP addresses here: only who (a user name), what, and when.
CREATE TABLE audit_log (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    at     TEXT NOT NULL,
    actor  TEXT NOT NULL,
    action TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    detail TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_audit_at ON audit_log(at);

-- Static vs dynamic, and what kind of QR code it is.
--   dynamic: the QR holds a short tracking URL; scans are counted; the target can be edited.
--   static : the QR holds the content itself; nothing is tracked or editable (it works offline, forever).
ALTER TABLE links ADD COLUMN kind    TEXT NOT NULL DEFAULT 'dynamic' CHECK (kind IN ('dynamic', 'static'));
ALTER TABLE links ADD COLUMN qr_type TEXT NOT NULL DEFAULT 'url';
ALTER TABLE links ADD COLUMN data    TEXT NOT NULL DEFAULT '{}';  -- the type's form fields as JSON, so it can be edited
ALTER TABLE links ADD COLUMN content TEXT NOT NULL DEFAULT '';    -- static: the exact text in the QR. dynamic vCard/event: the document served.
ALTER TABLE links ADD COLUMN design  TEXT NOT NULL DEFAULT '';    -- QR styling as JSON
ALTER TABLE links ADD COLUMN logo    BLOB;                        -- centre logo, re-encoded to PNG on upload

-- Limits and protection (dynamic codes only).
ALTER TABLE links ADD COLUMN max_scans     INTEGER NOT NULL DEFAULT 0;   -- 0 = unlimited
ALTER TABLE links ADD COLUMN scan_count    INTEGER NOT NULL DEFAULT 0;   -- human scans, kept by the scan writer
ALTER TABLE links ADD COLUMN password_hash TEXT    NOT NULL DEFAULT '';  -- bcrypt; empty = not protected
ALTER TABLE links ADD COLUMN has_rules     INTEGER NOT NULL DEFAULT 0;

UPDATE links SET scan_count = (SELECT COUNT(*) FROM scans WHERE scans.link_id = links.id AND scans.is_bot = 0);

-- Smart URL / app-store routing: the first matching rule decides the target.
CREATE TABLE link_rules (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id  INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    position INTEGER NOT NULL,
    match    TEXT    NOT NULL CHECK (match IN ('os', 'device', 'language', 'country')),
    value    TEXT    NOT NULL,
    url      TEXT    NOT NULL
);
CREATE INDEX idx_link_rules_link ON link_rules(link_id, position);

-- Saved QR designs, reusable on new codes.
CREATE TABLE qr_templates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE COLLATE NOCASE,
    design     TEXT NOT NULL,
    logo       BLOB,
    created_at TEXT NOT NULL
);

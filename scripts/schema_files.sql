-- File sharing: each staff member's own files and folders, and the shares made from them.
CREATE TABLE IF NOT EXISTS fs_nodes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    parent_id  INTEGER,                                   -- NULL = the top of the owner's files
    kind       TEXT NOT NULL CHECK (kind IN ('folder','file')),
    name       TEXT NOT NULL,
    size       INTEGER NOT NULL DEFAULT 0,
    mime       TEXT,
    blob       TEXT,                                      -- storage name of a file (random); NULL for a folder
    sha256     TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_fs_nodes_parent ON fs_nodes (owner_id, parent_id);

CREATE TABLE IF NOT EXISTS fs_shares (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id       INTEGER NOT NULL,
    kind           TEXT NOT NULL CHECK (kind IN ('link','staff')),   -- link = anyone with the address; staff = chosen colleagues
    title          TEXT NOT NULL,
    message        TEXT NOT NULL DEFAULT '',
    salt           TEXT NOT NULL,                         -- the link address is derived from the id and this; changing it kills old links
    password_hash  TEXT,
    available_from INTEGER,
    available_to   INTEGER,
    all_staff      INTEGER NOT NULL DEFAULT 0,
    created_at     INTEGER NOT NULL,
    revoked_at     INTEGER,
    downloads      INTEGER NOT NULL DEFAULT 0,
    last_access    INTEGER
);
CREATE INDEX IF NOT EXISTS idx_fs_shares_owner ON fs_shares (owner_id);

CREATE TABLE IF NOT EXISTS fs_share_items (
    share_id INTEGER NOT NULL,
    node_id  INTEGER NOT NULL,
    PRIMARY KEY (share_id, node_id)
);
CREATE INDEX IF NOT EXISTS idx_fs_share_items_node ON fs_share_items (node_id);

CREATE TABLE IF NOT EXISTS fs_share_users (
    share_id INTEGER NOT NULL,
    admin_id INTEGER NOT NULL,
    PRIMARY KEY (share_id, admin_id)
);
CREATE INDEX IF NOT EXISTS idx_fs_share_users_admin ON fs_share_users (admin_id);

-- uploads in progress (sent in pieces)
CREATE TABLE IF NOT EXISTS fs_uploads (
    id         TEXT PRIMARY KEY,
    owner_id   INTEGER NOT NULL,
    parent_id  INTEGER,
    name       TEXT NOT NULL,
    size       INTEGER NOT NULL,
    received   INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fs_access (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    share_id INTEGER NOT NULL,
    at       INTEGER NOT NULL,
    event    TEXT NOT NULL,            -- view, download, zip, password_fail, unlock
    detail   TEXT,
    ip_hash  TEXT
);
CREATE INDEX IF NOT EXISTS idx_fs_access_share ON fs_access (share_id, at);

-- Team chat, v1 scope: every channel is membership-gated for both viewing
-- and posting - no "public channel anyone can browse and join" concept yet.
-- is_private is kept for future display use but nothing in v1 branches on
-- it; simpler to have the column unused than to add it in a later migration
-- once something actually needs the distinction.
CREATE TABLE IF NOT EXISTS channels (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    is_private INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER REFERENCES admin_users(id),
    created_at INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS channel_members (
    channel_id   INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    admin_id     INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    joined_at    INTEGER NOT NULL DEFAULT (unixepoch()),
    last_read_at INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (channel_id, admin_id)
);

CREATE TABLE IF NOT EXISTS channel_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id  INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    admin_id    INTEGER NOT NULL REFERENCES admin_users(id),
    content     TEXT NOT NULL,
    reply_to_id INTEGER REFERENCES channel_messages(id),
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_channel_messages_channel ON channel_messages(channel_id, created_at);
CREATE INDEX IF NOT EXISTS idx_channel_members_admin ON channel_members(admin_id);

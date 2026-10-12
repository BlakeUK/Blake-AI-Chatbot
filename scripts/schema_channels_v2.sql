-- Team chat, second stage: group descriptions, notices inside a chat ("Pat added Sam"), muting, read markers that count messages instead of
-- seconds, and a small queue of things each person should be told about (added, removed, group deleted).
ALTER TABLE channels ADD COLUMN description TEXT NOT NULL DEFAULT '';
ALTER TABLE channel_messages ADD COLUMN kind TEXT NOT NULL DEFAULT 'message';
ALTER TABLE channel_members ADD COLUMN muted INTEGER NOT NULL DEFAULT 0;
ALTER TABLE channel_members ADD COLUMN last_read_id INTEGER NOT NULL DEFAULT 0;
UPDATE channel_members SET last_read_id = COALESCE((SELECT MAX(m.id) FROM channel_messages m WHERE m.channel_id = channel_members.channel_id AND m.created_at <= channel_members.last_read_at), 0);
CREATE TABLE IF NOT EXISTS channel_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_id INTEGER NOT NULL,
    channel_id   INTEGER,
    kind         TEXT NOT NULL,                -- added | removed | deleted
    actor_name   TEXT NOT NULL DEFAULT '',
    channel_name TEXT NOT NULL DEFAULT '',
    detail       TEXT NOT NULL DEFAULT '',
    created_at   INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX IF NOT EXISTS idx_channel_events_recipient ON channel_events (recipient_id, id);

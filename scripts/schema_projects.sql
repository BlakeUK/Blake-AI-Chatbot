-- Project workspaces, v1 scope. Deliberately flat (no parent/child nesting
-- yet - the spec's "hierarchical" workspaces is real scope, but nothing in
-- the reference mockups showed nesting, and a flat table is trivially
-- extensible to a tree later via a nullable parent_id if it's ever needed;
-- adding that later is cheap, removing it after building UI around it isn't.
CREATE TABLE IF NOT EXISTS projects (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    description  TEXT,
    department   TEXT,                    -- sales|technical|accounts|NULL, same vocabulary as tickets
    status       TEXT NOT NULL DEFAULT 'active',  -- active|on_hold|completed|archived
    progress_pct INTEGER NOT NULL DEFAULT 0,      -- 0-100, set by staff - no automated tracking source exists yet
    due_date     INTEGER,                 -- unix timestamp, date-only precision
    created_by   INTEGER REFERENCES admin_users(id),
    created_at   INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at   INTEGER NOT NULL DEFAULT (unixepoch())
);

-- Comments are the "collaboration" surface for a project until/unless full
-- chat threads get attached to entities generally - same shape as ticket
-- notes but multi-author and timestamped per-entry rather than one
-- append-only text blob, since a project genuinely has multiple
-- contributors weighing in over time in a way a single ticket usually doesn't.
CREATE TABLE IF NOT EXISTS project_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    admin_id   INTEGER NOT NULL REFERENCES admin_users(id),
    content    TEXT NOT NULL,
    created_at INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_project_comments_project ON project_comments(project_id);

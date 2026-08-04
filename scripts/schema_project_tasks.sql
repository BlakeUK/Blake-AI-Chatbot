-- Project tasks: lightweight checklist items within a project - simpler
-- than a sub-project (no status/progress/comments of its own), just a
-- title, optional due date, and a completed flag. Cascades with its
-- project since a task has no meaning outside the checklist it belongs to
-- (unlike a ticket, which is a real customer interaction that outlives
-- any project it's linked to).
CREATE TABLE IF NOT EXISTS project_tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title        TEXT NOT NULL,
    description  TEXT,
    due_date     INTEGER,
    completed    INTEGER NOT NULL DEFAULT 0,
    completed_at INTEGER,
    created_by   INTEGER REFERENCES admin_users(id),
    created_at   INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at   INTEGER NOT NULL DEFAULT (unixepoch())
);

-- Many-to-many from the start, not a single assignee column - "multiple or
-- single people" was explicit, same reasoning as admin_user_departments.
CREATE TABLE IF NOT EXISTS task_assignees (
    task_id  INTEGER NOT NULL REFERENCES project_tasks(id) ON DELETE CASCADE,
    admin_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    PRIMARY KEY (task_id, admin_id)
);

CREATE INDEX IF NOT EXISTS idx_tasks_project ON project_tasks(project_id);
CREATE INDEX IF NOT EXISTS idx_task_assignees_admin ON task_assignees(admin_id);

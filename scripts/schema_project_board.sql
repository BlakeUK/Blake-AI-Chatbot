-- scripts/schema_project_board.sql
-- Upgrades project_tasks from a plain checklist item (title/due_date/
-- completed) into a Plaky-style board item: which group it sits in on the
-- table view, a richer status than a done/not-done flag, a start date
-- (paired with the existing due_date to form a timeline range), a
-- reviewer distinct from its assignees, an optional tag, and optional
-- billable hours. Fixed columns rather than a per-board custom-field
-- system - every client that renders this (web admin, operator console,
-- the mobile app) can render one known shape instead of each needing to
-- understand a dynamic schema.
--
-- group_label/tag are deliberately free text, not lookup tables: a group
-- is just whatever text a task's group_label shares with other tasks in
-- the same project - no separate CRUD surface needed to create one.
--
-- completed/completed_at (from schema_project_tasks.sql) are left as-is,
-- now unused going forward - status='done' is the single source of truth
-- for a finished task; the old columns are harmless dead weight rather
-- than something worth an awkward SQLite column-drop/table-rebuild.
ALTER TABLE project_tasks ADD COLUMN group_label TEXT;
ALTER TABLE project_tasks ADD COLUMN status TEXT NOT NULL DEFAULT 'to_do';
ALTER TABLE project_tasks ADD COLUMN start_date INTEGER;
ALTER TABLE project_tasks ADD COLUMN reviewer_id INTEGER REFERENCES admin_users(id);
ALTER TABLE project_tasks ADD COLUMN tag TEXT;
ALTER TABLE project_tasks ADD COLUMN billable_hours REAL;

CREATE INDEX IF NOT EXISTS idx_tasks_status ON project_tasks(status);
CREATE INDEX IF NOT EXISTS idx_tasks_reviewer ON project_tasks(reviewer_id);

-- Comments move from project-only to project-or-task: a NULL task_id is
-- a project-level comment (existing behaviour, unchanged), a set task_id
-- is a comment on that specific item - the Comments tab shown per task in
-- the new board view. project_id stays required either way so "all
-- comments in this project" and cascade-delete-with-the-project both keep
-- working without a join through project_tasks.
ALTER TABLE project_comments ADD COLUMN task_id INTEGER REFERENCES project_tasks(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_project_comments_task ON project_comments(task_id);

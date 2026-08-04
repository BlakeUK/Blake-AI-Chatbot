-- Project assignment - separate from created_by (who made it isn't the
-- same as who it's assigned to). Many-to-many, same pattern as
-- task_assignees and admin_user_departments.
CREATE TABLE IF NOT EXISTS project_assignees (
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    admin_id   INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, admin_id)
);

CREATE INDEX IF NOT EXISTS idx_project_assignees_admin ON project_assignees(admin_id);

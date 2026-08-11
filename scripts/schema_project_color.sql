-- scripts/schema_project_color.sql
-- A user-selectable header colour per project (hex string, e.g. "#7c6ef2"),
-- shown as an accent on its card in the Operator Console's Projects grid.
-- Nullable: existing projects and any created without picking one fall
-- back to a colour hashed from the project name (see tcColor() in
-- operator-console/dist/index.html) rather than needing a backfill.
ALTER TABLE projects ADD COLUMN color TEXT;

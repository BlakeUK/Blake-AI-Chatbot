-- Sub-projects: a project can optionally belong to a parent project.
-- Self-referencing, so nesting depth isn't hardcoded - "children of a
-- project" is just "projects WHERE parent_id = X", which works the same
-- whether X is top-level or itself someone's child.
ALTER TABLE projects ADD COLUMN parent_id INTEGER REFERENCES projects(id);

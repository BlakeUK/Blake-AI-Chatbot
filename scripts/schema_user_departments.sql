-- Staff department membership. Many-to-many (composite PK), not a single
-- column on admin_users - "sometimes multiple dept" was explicit, and a
-- join table is the natural way to let one person sit in Sales AND
-- Technical without a comma-separated string to parse everywhere.
CREATE TABLE IF NOT EXISTS admin_user_departments (
    admin_id   INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    department TEXT NOT NULL,
    PRIMARY KEY (admin_id, department)
);

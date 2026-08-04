-- Explicit DM flag. Without this, "a channel with exactly 2 members" would
-- be ambiguous between an auto-created 1:1 DM and a genuine 2-person named
-- group someone deliberately created - those need different display
-- treatment (a DM shows the other person's name; a group shows its own).
ALTER TABLE channels ADD COLUMN is_dm INTEGER NOT NULL DEFAULT 0;

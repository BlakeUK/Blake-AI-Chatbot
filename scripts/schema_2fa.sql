-- ─── Two-Factor Authentication (TOTP) ─────────────────────────────────────────
-- Run: sqlite3 data/chatbot.db < scripts/schema_2fa.sql

ALTER TABLE admin_users ADD COLUMN totp_secret_enc TEXT;      -- AES-256-GCM encrypted, hex
ALTER TABLE admin_users ADD COLUMN totp_secret_iv  TEXT;      -- GCM IV, hex
ALTER TABLE admin_users ADD COLUMN totp_secret_tag TEXT;      -- GCM auth tag, hex
ALTER TABLE admin_users ADD COLUMN totp_enabled    INTEGER NOT NULL DEFAULT 0;
ALTER TABLE admin_users ADD COLUMN backup_codes    TEXT;      -- JSON array of bcrypt-hashed codes

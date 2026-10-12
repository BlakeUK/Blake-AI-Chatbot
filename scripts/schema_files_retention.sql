-- File sharing: automatic deletion. A link share can ask for its files to be deleted a number of days (setting fs_retention_days, default 7)
-- after the link ends or after it is first downloaded, whichever comes first.
ALTER TABLE fs_shares ADD COLUMN auto_delete INTEGER NOT NULL DEFAULT 0;
ALTER TABLE fs_shares ADD COLUMN first_download_at INTEGER;
ALTER TABLE fs_shares ADD COLUMN purged_at INTEGER;

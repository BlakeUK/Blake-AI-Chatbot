-- scripts/schema_downloads.sql - knowledge files that Max may offer to
-- customers as downloads (e.g. the trade account application form).
ALTER TABLE knowledge_files ADD COLUMN public_download INTEGER NOT NULL DEFAULT 0;
ALTER TABLE knowledge_files ADD COLUMN download_title TEXT;
ALTER TABLE knowledge_files ADD COLUMN download_keywords TEXT;   -- comma-separated triggers
ALTER TABLE knowledge_files ADD COLUMN download_token TEXT;      -- unguessable link id

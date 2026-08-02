-- Distinguishes knowledge entries created by the page-link importer from
-- ones an admin typed by hand - needed for bulk cleanup (e.g. removing a
-- large batch of thin auto-imported entries) without risking hand-typed
-- ones caught in the same net, and for filtering the Knowledge table by
-- where an entry actually came from.
--
-- No such marker existed before this migration, so existing rows are
-- backfilled with a best-effort heuristic rather than known fact: a row
-- with a URL but no category and no product codes matches exactly what
-- import_page_links.php has always written, and - since that importer is
-- new and there's been little opportunity for anyone to have hand-typed
-- many entries matching that same empty-category/empty-codes shape yet -
-- is a reasonable guess for which existing rows came from it. Not a
-- guarantee for every historical row; the admin UI's source filter is
-- there to let this be reviewed before anything gets bulk-deleted based on
-- it, not to be trusted blindly.
ALTER TABLE knowledge_entries ADD COLUMN source TEXT NOT NULL DEFAULT 'manual';

UPDATE knowledge_entries
SET source = 'page_import'
WHERE url IS NOT NULL AND category IS NULL AND product_codes IS NULL;

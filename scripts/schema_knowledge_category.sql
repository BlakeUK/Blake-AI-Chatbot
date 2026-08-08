-- schema_knowledge_category.sql — category tagging for RAG content, used to
-- boost retrieval toward the customer's current product range (see
-- src/Knowledge/Search.php's category-aware ranking and
-- Chat\Responder::buildContext()). knowledge_files carries the category an
-- admin picks at upload time; knowledge_chunks carries its own copy so
-- Search::query() (which reads knowledge_chunks/knowledge_fts directly)
-- never needs a join back to knowledge_files or knowledge_entries to filter
-- or rank by it.
ALTER TABLE knowledge_files ADD COLUMN category TEXT;
ALTER TABLE knowledge_chunks ADD COLUMN category TEXT;

CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_category ON knowledge_chunks(category);

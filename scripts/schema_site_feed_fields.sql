-- Adds storage for fields present in the real website product feed that
-- weren't in the original spec doc's example format: brand, slug, currency,
-- and two more product-relationship categories (alternative/comparison)
-- alongside the existing related_product_codes.
ALTER TABLE products ADD COLUMN slug TEXT;
ALTER TABLE products ADD COLUMN brand TEXT;              -- JSON: {"name","slug","url"}
ALTER TABLE products ADD COLUMN currency TEXT;           -- e.g. "GBP"
ALTER TABLE products ADD COLUMN alternative_product_codes TEXT; -- JSON array of product_code strings
ALTER TABLE products ADD COLUMN comparison_product_codes TEXT;  -- JSON array of product_code strings
ALTER TABLE product_documents ADD COLUMN file_size TEXT;        -- e.g. "2.4 MB", as given by the feed

-- Adds related/cross-sell product storage. The feed spec (docx section 9)
-- already documents related_product_codes as a field, but nothing in the
-- schema or importer actually captured it until now.
ALTER TABLE products ADD COLUMN related_product_codes TEXT; -- JSON array of product_code strings

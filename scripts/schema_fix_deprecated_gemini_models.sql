-- scripts/schema_fix_deprecated_gemini_models.sql
--
-- gemini-1.5-flash and gemini-1.5-pro (the values schema_append.sql
-- originally seeded into `settings`) were fully shut down by Google -
-- every call now returns 404. Moving both to gemini-3.6-flash: a
-- currently-stable, non-preview model with a long runway (unlike
-- gemini-2.5-flash, which already has its own October 2026 shutdown
-- announced), and one that's fully multimodal so it covers both chat
-- and document/PDF extraction without needing two different tiers.
--
-- Only touches rows still sitting at the exact original broken default -
-- if this has already been changed via the admin Model Settings tab
-- (to anything else at all), that choice is left alone.
UPDATE settings SET value = 'gemini-3.6-flash', updated_at = unixepoch()
    WHERE key = 'gemini_chat_model' AND value = 'gemini-1.5-flash';

UPDATE settings SET value = 'gemini-3.6-flash', updated_at = unixepoch()
    WHERE key = 'gemini_extract_model' AND value = 'gemini-1.5-pro';

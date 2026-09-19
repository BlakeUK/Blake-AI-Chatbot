-- FAQ entries are captured automatically from customer chats. Until now
-- every one was shown to every visitor as a quick-question chip, so a
-- visitor could publish arbitrary text site-wide. New and existing
-- entries now need staff approval (admin FAQ tab) before the widget shows
-- them. Editing an entry in the admin panel also approves it.
ALTER TABLE faq_entries ADD COLUMN approved INTEGER NOT NULL DEFAULT 0;

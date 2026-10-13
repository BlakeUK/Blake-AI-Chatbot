-- Team chat, third stage: replies are grouped into threads (every reply knows the first message of its thread), and a reply written by the AI
-- (asked for with @ai) is marked so it is shown as the AI's, not as the person who asked.
ALTER TABLE channel_messages ADD COLUMN is_ai INTEGER NOT NULL DEFAULT 0;
ALTER TABLE channel_messages ADD COLUMN thread_root_id INTEGER;
UPDATE channel_messages SET thread_root_id = reply_to_id WHERE reply_to_id IS NOT NULL;
-- a reply to a reply belongs to the same thread as the message it answers: walk up the chain (replies always have a higher id than what they answer)
UPDATE channel_messages SET thread_root_id = (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) WHERE reply_to_id IS NOT NULL AND (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) IS NOT NULL;
UPDATE channel_messages SET thread_root_id = (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) WHERE reply_to_id IS NOT NULL AND (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) IS NOT NULL;
UPDATE channel_messages SET thread_root_id = (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) WHERE reply_to_id IS NOT NULL AND (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) IS NOT NULL;
UPDATE channel_messages SET thread_root_id = (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) WHERE reply_to_id IS NOT NULL AND (SELECT p.thread_root_id FROM channel_messages p WHERE p.id = channel_messages.reply_to_id) IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_channel_messages_thread ON channel_messages (thread_root_id);

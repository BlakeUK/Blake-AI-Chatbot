-- scripts/schema_live_chat.sql — staff presence + live (human) chat handoff.
--
-- presence_status is a deliberate, staff-set status (Online/Busy/Offline -
-- see the toggle in the operator console and admin panel), distinct from
-- the existing passive last_active-derived "online" flag in agents.php:
-- that one just means "used the system recently", this one means "I'm
-- available to be pulled into a live chat right now". Defaults to
-- 'offline' for every existing and new account - nobody should be
-- silently offered to customers as available until they've actually said
-- so.
ALTER TABLE admin_users ADD COLUMN presence_status TEXT NOT NULL DEFAULT 'offline';

-- A chat_session is 'ai' (the normal case - Gemini answers) until a
-- customer asks for a human (see Chat\LiveChat). 'live_requested' means
-- waiting for an admin to claim it; 'live_active' means claimed and being
-- typed to directly; 'live_ended' means the human handoff is over (the
-- session itself isn't deleted or reset to 'ai' - a customer who keeps
-- typing after that gets a clear "this chat has ended" rather than
-- silently talking to the AI again under a human's name).
ALTER TABLE chat_sessions ADD COLUMN mode TEXT NOT NULL DEFAULT 'ai';
ALTER TABLE chat_sessions ADD COLUMN claimed_by INTEGER REFERENCES admin_users(id);

-- Distinguishes a live-chat request from a normal escalated ticket inside
-- the existing support_tickets table/admin UI/Telegram alerts, rather
-- than building a second, parallel queue system - same department
-- routing, same ticket list, same notifications, just tagged differently
-- so staff can tell "customer waiting right now" apart from "reply
-- whenever".
ALTER TABLE support_tickets ADD COLUMN channel TEXT NOT NULL DEFAULT 'ticket';

CREATE INDEX IF NOT EXISTS idx_chat_sessions_mode ON chat_sessions(mode);

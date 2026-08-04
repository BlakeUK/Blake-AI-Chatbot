-- Agent presence: last_active timestamp on admin_users, bumped (throttled,
-- see Auth\Admin::check()) on every authenticated admin request. Powers
-- "who's actually online right now" in the operator console's ticket
-- handoff feature - the point of transferring a ticket to a specific
-- person is picking someone at their desk, not just any admin account
-- that exists.
ALTER TABLE admin_users ADD COLUMN last_active INTEGER;

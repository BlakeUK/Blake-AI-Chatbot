-- Account-level brute-force lockout for admin login. Existing rate_limit()
-- calls throttle by IP per rolling 60-second window (all attempts, not just
-- failures, and it resets every minute) - this is a genuinely different
-- mechanism: it counts consecutive failures per account and persists a lock
-- once the threshold is hit, closer to what's normally meant by "N failed
-- attempts locks the account".
ALTER TABLE admin_users ADD COLUMN failed_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE admin_users ADD COLUMN locked_until INTEGER; -- unix timestamp, NULL = not locked

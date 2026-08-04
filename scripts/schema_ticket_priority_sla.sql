-- Ticket priority + SLA deadline. Existing tickets get 'medium' as a
-- reasonable, non-alarming default rather than NULL - a priority filter
-- or sort in the console shouldn't have to special-case tickets that
-- predate this column.
ALTER TABLE support_tickets ADD COLUMN priority TEXT NOT NULL DEFAULT 'medium';
ALTER TABLE support_tickets ADD COLUMN sla_deadline INTEGER; -- unix timestamp

UPDATE support_tickets SET sla_deadline = created_at + 86400 WHERE sla_deadline IS NULL;

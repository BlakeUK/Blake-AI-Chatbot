-- Campaign label on each link (leaflet, exhibition stand, product box ...).
ALTER TABLE links ADD COLUMN campaign TEXT NOT NULL DEFAULT '';
CREATE INDEX idx_links_campaign ON links(campaign);

-- Richer per-scan detail. All derived from the request; none identifies a person
-- on its own. Town/region/country are approximate (IP geolocation).
ALTER TABLE scans ADD COLUMN country_name    TEXT NOT NULL DEFAULT '';
ALTER TABLE scans ADD COLUMN region          TEXT NOT NULL DEFAULT '';
ALTER TABLE scans ADD COLUMN city            TEXT NOT NULL DEFAULT '';
ALTER TABLE scans ADD COLUMN language        TEXT NOT NULL DEFAULT '';
-- Where this particular scan was sent. A snapshot, because a link's
-- destination can be edited later.
ALTER TABLE scans ADD COLUMN destination_url TEXT NOT NULL DEFAULT '';
-- Full client address. EMPTY unless the operator turns on STORE_FULL_IP.
ALTER TABLE scans ADD COLUMN ip              TEXT NOT NULL DEFAULT '';

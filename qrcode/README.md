# qrtrack: QR-code generator and scan tracker

Create QR codes, style them, and see who scanned them. Choose per code:

* **Dynamic (tracked):** the QR holds a short address, `https://<domain>/r/<code>`. A scan is counted (privacy-first)
  and the visitor is redirected, so you can change the destination after printing, set dates, a scan limit, a
  password, or route people by device, system, language or country.
* **Static:** the QR holds the content itself (a web address, Wi-Fi details, a contact card ...). Nothing is tracked
  or editable, and it works offline for ever, even if this service is switched off.

One static Go binary, SQLite, no JavaScript dependencies.

It lives in `/var/www/qrcode` on the VPS as its own user, its own systemd unit and its own port. It does not
share code, data, services or deploys with the support chatbot in `/var/www/chat`.

## Assumptions and decisions (read first)

These are the places where this differs from, or fills a gap in, the written spec.

1. **Separate from the support site.** Own folder, own `qrtrack` system user, own `systemd` unit with memory
   (256 MB), CPU (50%) and task caps, so it can never starve the chatbot. Own deploy workflow
   (`qrcode-deploy.yml`); the support `deploy.yml` is untouched.
2. **Port 8081, not 8080.** Local only (`127.0.0.1`). The installer refuses to start if something else already
   holds the port.
3. **One line was added to the support site's `Caddyfile`:** `import /etc/caddy/conf.d/*.caddy`. This is needed
   because every support deploy overwrites `/etc/caddy/Caddyfile` from the repo and restarts Caddy; a QR site
   block added to that file by hand would be wiped by the next support release. The QR site block lives in
   `/etc/caddy/conf.d/qrcode.caddy` instead. The line is a verified no-op when that folder is empty
   (the effective Caddy configuration is identical with and without it, checked by diffing `caddy adapt` output).
4. **Caddy is only touched when a domain is supplied**, and then safely: the new config is validated with
   `caddy validate` first; if it fails, or Caddy refuses the reload, the previous files are restored and Caddy
   is left running as it was. It is reloaded, never restarted.
5. **No domain is hard-coded.** QR codes printed on products embed the domain forever, so choose it
   deliberately (suggested: `qr.blakegroup.uk`). Create its DNS `A` record to the VPS first; the installer
   will not wire Caddy to a name that does not resolve (that causes endless certificate retries). Use a
   DNS-only (grey cloud) record. Behind a proxy the visitor address Caddy sees is the proxy's, which would
   break unique-visitor counting.
6. **The initial admin password is not in the repo.** It comes from the `QRTRACK_ADMIN_PASSWORD` GitHub secret,
   is used once to seed the account, and the installer then deletes it from the env file. The first login
   forces a change (at least 12 characters).
7. **Raw IPs are not stored by default, anywhere**, including the login-attempts table (the spec's schema listed
   a raw `ip` there, which contradicts its own "no raw IPs in the DB" criterion; it holds a keyed HMAC instead).
   Caddy's own access log has the visitor fields stripped as well. Storing the full address is an explicit,
   off-by-default switch, `STORE_FULL_IP` (see "Visitor IP addresses" below).
8. **`ip_hash` is `HMAC-SHA256(daily random salt, IP)`**, not a bare `SHA-256(IP + salt)`. The salt is random
   per UTC day, kept in the database, and deleted after three days, after which old hashes cannot be linked
   to an address by anyone. Consequence: one person scanning either side of midnight UTC counts as unique
   twice.
9. **Before the window opens** a link already redirects, it just is not counted. **Disabled** links return
   `410`; **expired page** mode also returns `410`; unknown or malformed codes return `404`.
10. **`HEAD` requests are served but never counted** (prefetchers and uptime checks).
11. **Bots are recorded but excluded from headline numbers** (with a toggle to include them): crawlers, scripted
    clients, and the link-preview fetchers chat apps use when a link is pasted.
12. **Charts are server-rendered SVG** and the Content-Security-Policy forbids inline styles and scripts, so
    markup uses CSS classes only. `app.js` is optional (copy button and delete confirmation).
13. A destination that points back at the tracker itself is rejected (redirect loop).
14. QR images come from `github.com/skip2/go-qrcode`; SVG is generated from its module bitmap. PNG sizes
    256, 512 and 1024; error correction L/M/Q/H per link (default M), overridable per download with `?ecc=`.
15. **Place names use a free offline database, DB-IP "City Lite"** (CC BY 4.0, no account). The installer downloads
    it, a monthly systemd timer refreshes it, and the service picks up a new file within the hour with no
    restart. A download is only swapped in if it unpacks, is large enough, and `qrtrack geocheck` can open it
    and resolve a test address; otherwise the previous file stays. If there is no database the tracker still
    works and the place columns are simply blank. Nothing is ever sent to a third party. The licence requires
    credit, so admin pages show "IP Geolocation by DB-IP" whenever it is loaded.
16. **Locations are approximate.** Country is usually right; town is often only the nearest large town; mobile
    networks and VPNs can be placed in the wrong region. The UI says so.
17. **Campaign** is a free-text label on each QR link (leaflet, exhibition stand, product box ...) with a
    suggestion list. "leaflet" and "Leaflet" are treated as one campaign. The Campaigns page totals scans and
    unique visitors per campaign; `/admin/?campaign=...` filters the link list.
18. **Destination is recorded per scan** (a snapshot, because a link's destination can be edited later) and shown
    as a friendly name: "Facebook", "Instagram", "YouTube", "Blake UK website" and so on, otherwise the host name.
19. **Exact time** is stored to the second in UTC and shown, and exported, in Europe/London time.
20. **Language** is the first language in the browser's `Accept-Language` header, shown as "English (en-GB)".

## Feature comparison with QR Tiger

Checked against the feature list on qrcode-tiger.com. "Done" means built and covered by tests.

| QR Tiger feature | Here |
|---|---|
| Static and dynamic codes, chosen per code | **Done** |
| URL, Google Form, Google Review, Facebook, Instagram, YouTube, TikTok, X, Pinterest, LinkedIn | **Done** (static or dynamic; the address is checked against the right site) |
| vCard / contact card | **Done** (static, or dynamic: the phone is offered the contact) |
| Wi-Fi, plain text, email, SMS, phone | **Done** (static only: they must work offline) |
| WhatsApp, location, calendar event | **Done** (static or dynamic) |
| App stores (right store for iPhone / Android) | **Done** |
| Smart URL / multi-URL by device, system, language, country | **Done** (first matching rule wins; up to 5 rules). By operating system, device, language, country, **time of day** (UK time, handles clock changes) and a **percentage split** (a visitor stays on the same side all day). By scan number: not built. |
| Link page (link-in-bio, like Linktree) | **Done**: see Link pages below. Three themes, three company brands, live preview, view and click statistics, one-press QR code. |
| File QR (PDF, images), MP3, video, menu, landing page builder, GS1 Digital Link | **Not built.** These need hosted files and a general page builder. Use the Website link type to point at a PDF or video that already lives on blake-uk.com or YouTube. |
| Colours, dot style, corner style, centre logo, frame with text | **Done**, with a live preview. Designs that would not scan (low contrast, inverted, logo too large) are refused. Every style is decoded by a real QR reader in the tests. |
| Saved design templates | **Done** (Designs page; reuse in the form and in bulk) |
| Download PNG (256 / 512 / 1024), SVG, **PDF** and **EPS**, each with an optional **transparent background** | **Done** (see Print files below) |
| Edit the destination and the design after printing | **Done** (dynamic codes; a static code's content can never change) |
| Scan analytics: count, unique, time, place, device, OS, browser, language, referrer | **Done**, with CSV export |
| Folders | **Campaigns** (one flat level) with their own totals page. Nested folders: not built. |
| Alerts, watchlist, top-10 view | **Not built** (alerts need the outgoing email account that is not set up yet) |
| Expiry by date and by scan limit | **Done**. Expiry by IP address: not built. |
| Password-protected codes | **Done** (wrong guesses are throttled) |
| Bulk generation from CSV | **Done**: up to 1000 rows as SVG or 250 as PNG per file (QR Tiger: 3000) |
| Teams, user roles | **Done**: admin and member roles, add / remove / reset passwords, activity log |
| Two-factor sign-in | **Not built** |
| White-labelled links | The whole service runs on your own domain. Different domains per code: not built. |
| Google Tag Manager / Facebook pixel / GA4 retargeting | **Deliberately not built.** It needs an intermediate page that runs third-party scripts before redirecting: slower scans, conflicts with the strict Content-Security-Policy, and in the UK needs a cookie-consent banner. |
| API, Zapier, HubSpot, Canva, MCP server | **Not built yet.** A transparent SVG or PDF can be dragged straight into a Canva design |
| AI insights, mobile apps, 27 interface languages | Not built |
| GDPR / anonymised data | **Done**: no IP stored by default (see below) |

## Link pages

A hosted "all our links" page per company, at `https://<domain>/l/<address>`, managed on the **Link pages** tab.

* **Companies:** Blake UK, VisionPlus, Solwise. Each has logos for light and dark backgrounds (`web/static/img/brands/`) and its own colour. A company is a small entry in `internal/pages/brands.go`.
* **Themes:** Midnight (dark navy, glowing curves, glass buttons), Daylight (clean light), Bold (solid brand-coloured buttons). Styles live in `web/static/pages.css`; colours arrive per page from `/l/<address>/theme.css`, because the Content Security Policy forbids inline styles.
* **Buttons:** web addresses (clicks counted through `/l/<address>/go/<id>`, destination read only from the database), `mailto:` and `tel:` (direct). Icons are chosen from the address. Editing keeps a button's id, so its click history survives.
* **Live preview** in the editor (a same-origin frame of `/admin/pages/preview`, which draws unsaved values and counts nothing).
* **QR code for a page:** one press makes a normal tracked dynamic code pointing at `/l/<address>?s=qr`, so views via QR are counted separately.
* **Statistics:** views, unique visitors, views via QR, clicks per button, views per day, and breakdowns. Same privacy rules as scans (daily-salted hash, no address stored, bots excluded, retention purge).
* Pages are `noindex`. Tables: `link_pages`, `link_page_items`, `page_events` (migration 0004).

## Print files and transparent versions

Every code's page has a **Print files and transparent versions** panel (route `GET /admin/links/{id}/download?format=pdf|svg|eps|png&size=256|512|1024&bg=transparent`, for dynamic and static codes alike).

- **PDF** is a one-page vector file, 100 mm square, that a printer can scale to any size. The text of a framed code is drawn as outlines (taken from the bundled font), so no font is needed. **EPS** is the same drawing for older print software. **SVG** and **PNG** are as before.
- All four formats are drawn from one description of the code (`internal/qr/vector.go`), so they cannot drift apart.
- **Transparent background** leaves the background out, and the gaps inside the three corner squares become see-through too, so the code can sit on a plain light surface. A framed code keeps its own light panel. It is for plain light surfaces only; the Help says never to put it on a photo or a dark colour.
- Tested by decoding every style: PDF and EPS are rasterised with Ghostscript and decoded with ZBar at screen and print resolution; transparent renders are checked for real see-through corners and eye gaps, and decoded after being placed on white and on grey (216 renders). **The tests need `ghostscript`, `poppler-utils`, `zbar-tools` and `librsvg2-bin`** (the CI workflow installs them). The transparent test takes about 100 seconds.

## Routing rules (Smart URL)

A Smart URL code has a default address and up to five rules, checked top to bottom, **the first match wins**. A rule looks at operating system, device, language, country, **time of day** or a **share of visitors**.

- **Time of day:** days and hours in UK time, such as `Mon-Fri 08:00-16:30`, up to four parts separated by semicolons (`Mon-Thu 08:00-16:30; Fri 08:00-16:00`). Hours end exactly at the time given; clock changes are handled; bank holidays are not special.
- **Split:** `30%` sends about 30% of visitors to that rule's address. A visitor is placed by a SHA-256 hash of their daily visitor token, the code and the rule position, so the same person sees the same side all day.
- Each scan records where *that* visitor was actually sent, and the code's page shows **Where visitors were sent**.
- Once a code has ended, *keep redirecting, no longer counted* mode still asks for the password and applies the rules (an earlier version skipped both).
- Migration `0005_routing_rules.sql` widens the stored rule kinds.

## Help, the PDF manual and "what is tracked"

* **Help** (`/admin/help`, from `web/templates/help.html`) documents every function. Each page also has a "What is this / How to use it / What is tracked" box (`internal/web/help.go`).
* **What is tracked** is written once, in `internal/web/tracking.go`: every feature, who can see it, how long it is kept, and every stored field. A test compares it with the real database columns, so adding a column or table without describing it fails the build.
* **The PDF manual** (`web/manual/qrtrack-manual.pdf`, downloadable from Help at `/admin/manual.pdf`) is built from the running Help page plus fictional demo screenshots, with a cover, contents, task finder, appendices and an A to Z index. Rebuild it whenever Help changes (a test fails until you do):

  ```
  go build -o /tmp/qrtrack ./cmd/qrtrack
  QRTRACK_BIN=/tmp/qrtrack GEOIP_DB=/path/to/dbip-city-lite.mmdb python3 tools/manual/build_manual.py
  ```

  Needs Python with `playwright` (and its Chromium), `beautifulsoup4`, `pillow`, `pdfplumber`, `pypdf`.
* Help's quoted limits are checked against the code's constants by tests, so they cannot drift.
* The password-recovery command is `qrtrack resetpassword NAME` (workflow: *Reset a QR tracker password*).

## Users and roles

* **Admin:** everything, including the Users page.
* **Member:** create and manage QR codes, campaigns, designs and see statistics. Cannot manage users.
* New users get a temporary password (typed or generated) and must choose their own at first sign-in. A generated
  password is shown once and never stored in readable form.
* Resetting a password, changing a role or removing a user signs that person out everywhere at once.
* The last admin can never be removed or demoted, and you cannot remove yourself.
* The Users page keeps a log of who did what (never passwords or addresses), purged after the retention period.

## Layout

```
cmd/qrtrack/main.go        entry point: config, seeding, hourly purge, graceful shutdown
internal/auth/             bcrypt, sessions (SHA-256 of token stored), lockout, password policy
internal/db/               SQLite (pure Go driver), WAL, embedded versioned migrations
internal/links/            validation, 8-char base62 codes, window logic, CRUD
internal/scans/            daily-salt hashing, async batched writer, stats, CSV, retention purge
internal/qr/               PNG and SVG rendering: colours, dot and corner styles, logo, frame; contrast checks
internal/pages/             link pages: brands, themes, validation, storage, view/click statistics
internal/qrtypes/          the catalogue of QR types: form fields and how each becomes a payload
internal/ua/               coarse device / OS / browser / bot classification (no versions)
internal/geo/              offline town / region / country lookup, hot-reloads a refreshed database
internal/web/              handlers, templates glue, CSRF, headers, rate limiting, client-IP trust
migrations/*.sql           schema (embedded, applied at start-up)
web/templates, web/static  UI (embedded)
deploy/                    install.sh, systemd units (service + monthly GeoIP timer), update-geoip.sh,
                           Caddy snippet template, installer test
```

## Configuration (environment)

| Variable | Default | Meaning |
|---|---|---|
| `BASE_URL` | required | Public origin the QR codes point at, e.g. `https://qr.blakegroup.uk` |
| `LISTEN_ADDR` | `127.0.0.1:8080` | Listen address (the VPS install uses `127.0.0.1:8081`) |
| `DB_PATH` | `qrtrack.db` | SQLite file |
| `ADMIN_USER` / `ADMIN_PASSWORD` | unset | Seed the first admin, only if no user exists. Forces a password change. |
| `TRUSTED_PROXIES` | `127.0.0.1/32,::1/128` | `X-Forwarded-For` is believed only from these peers |
| `GEOIP_DB` | unset | Path to a City `.mmdb` (DB-IP City Lite or GeoLite2 City). Set automatically by the installer. A bad file is logged and ignored, never fatal. |
| `STORE_FULL_IP` | `false` | `true` keeps each visitor's full IP address with their scan. See below. |
| `RETENTION_DAYS` | `365` | Scans older than this are purged (hourly) |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` |

## Deploy

1. (Once) add the repository secret **`QRTRACK_ADMIN_PASSWORD`**. The `SSH_SERVER`, `SSH_USERNAME` and
   `SSH_PASSWORD` secrets already used by the support deploy are reused.
2. Local-only first deploy (touches nothing shared): run **Actions > Deploy QR tracker to VPS** with an empty
   `domain`.
3. To make it public: create the DNS `A` record, then run the workflow again with `domain` set.
4. `store_full_ip` is `unchanged` by default; see below before ever setting it to `true`.

The workflow tests, builds static `amd64` and `arm64` binaries, copies them to `/var/www/qrcode/release`, and runs
`install.sh`. The installer is idempotent, keeps the previous binary as `qrtrack.prev`, waits for `/healthz`, and
rolls back automatically if the new binary does not come up.

## First login

Open `https://<domain>/admin/`, sign in as `admin` with the initial password, and you are taken straight to a
mandatory password change (12 to 72 characters). After that the initial password no longer works anywhere.

## Backup and restore

Nightly backup (consistent even while the app is running):

```
17 3 * * * root mkdir -p /var/backups/qrcode && sqlite3 /var/www/qrcode/data/qrtrack.db ".backup '/var/backups/qrcode/qrtrack-$(date +\%F).db'" && find /var/backups/qrcode -name 'qrtrack-*.db' -mtime +14 -delete
```

Restore:

```
systemctl stop qrtrack
cp /var/backups/qrcode/qrtrack-YYYY-MM-DD.db /var/www/qrcode/data/qrtrack.db
rm -f /var/www/qrcode/data/qrtrack.db-wal /var/www/qrcode/data/qrtrack.db-shm
chown qrtrack:qrtrack /var/www/qrcode/data/qrtrack.db
systemctl start qrtrack
```

## What is recorded for each scan

| Item | Notes |
|---|---|
| Date and exact time | stored in UTC to the second; shown and exported in London time |
| QR campaign | from the link (leaflet, exhibition stand, product box ...) |
| Country, region, town | approximate, from the visitor's IP via the offline database; the IP itself is not kept (see below) |
| Device type, OS, browser | coarse family only (mobile / tablet / desktop; Android, iOS, Windows ...; Chrome, Safari, Edge ...); no versions or models |
| Language | the browser's preferred language |
| Destination | where that scan was sent, with a friendly name such as "Facebook" |
| Referrer | host only (for example `l.facebook.com`), never the path or query string |
| Visitor hash | `HMAC-SHA256(daily salt, IP)`: lets unique visitors be counted without storing the address |
| Bot / new-visitor flags | bots and link-preview fetchers are recorded but left out of the headline numbers |

## Visitor IP addresses (off by default)

By default no IP address is stored: the server uses it in memory to work out the approximate place and the daily
hash, then discards it. `STORE_FULL_IP=true` (set it with the deploy workflow's `store_full_ip` input) additionally
keeps the full address in the `ip` column, shows it in the scans table, and includes it in the CSV.

An IP address is personal data under UK GDPR. Before turning it on you need a lawful basis (usually legitimate
interests), a line in your privacy notice saying you record it and for how long, and you should be comfortable with
the retention period (`RETENTION_DAYS`, default 365). While it is on, every admin page carries a notice saying so.
Switching it off later stops new addresses being kept; it does not erase ones already stored.

## Privacy (UK GDPR)

Scans are purged after `RETENTION_DAYS`; daily salts after three days, after which old visitor hashes cannot be
linked to an address by anyone. Never stored: referrer paths or query strings, cookies, browser or OS versions,
and (unless `STORE_FULL_IP` is on) the IP address.

## Development

```
go vet ./... && staticcheck ./...
go test -race ./...          # needs ghostscript, poppler-utils, zbar-tools, librsvg2-bin; the qr package takes a few minutes
QRTRACK_BENCH=1 go test -run TestRedirectLatency -v ./internal/web    # p99 < 5 ms at 500 req/s
bash deploy/test_install.sh <path-to-linux-amd64-binary> <path-to-support-Caddyfile>
# optional extras that need a real DB-IP / GeoLite2 City file:
QRTRACK_GEOIP_TEST_DB=/path/city.mmdb go test ./internal/geo
QR_TEST_GEOIP_MMDB=/path/city.mmdb bash deploy/test_install.sh ...
CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" ./cmd/qrtrack
```

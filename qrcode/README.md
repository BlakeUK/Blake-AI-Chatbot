# qrtrack: QR-code link tracker

Create a short tracking link, get a QR code for it, and see who scanned it. A phone scan hits
`https://<domain>/r/<code>`, is counted (privacy-first), and is redirected with a `302` to the
destination you chose. One static Go binary, SQLite, no JavaScript dependencies.

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
7. **Raw IPs are not stored anywhere, including the login-attempts table** (the spec's schema listed a raw
   `ip` there, which contradicts its own "no raw IPs in the DB" criterion). That column holds a keyed HMAC
   instead. Caddy's own access log has the visitor fields stripped as well.
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
15. Optional country lookup uses an offline GeoLite2 Country file (`GEOIP_DB`). Without it the country column is
    blank. Nothing is ever sent to a third party.

## Layout

```
cmd/qrtrack/main.go        entry point: config, seeding, hourly purge, graceful shutdown
internal/auth/             bcrypt, sessions (SHA-256 of token stored), lockout, password policy
internal/db/               SQLite (pure Go driver), WAL, embedded versioned migrations
internal/links/            validation, 8-char base62 codes, window logic, CRUD
internal/scans/            daily-salt hashing, async batched writer, stats, CSV, retention purge
internal/qr/               PNG and SVG rendering
internal/ua/               coarse device / OS / browser / bot classification (no versions)
internal/geo/              optional offline country lookup
internal/web/              handlers, templates glue, CSRF, headers, rate limiting, client-IP trust
migrations/*.sql           schema (embedded, applied at start-up)
web/templates, web/static  UI (embedded)
deploy/                    install.sh, systemd unit, Caddy snippet template, installer test
```

## Configuration (environment)

| Variable | Default | Meaning |
|---|---|---|
| `BASE_URL` | required | Public origin the QR codes point at, e.g. `https://qr.blakegroup.uk` |
| `LISTEN_ADDR` | `127.0.0.1:8080` | Listen address (the VPS install uses `127.0.0.1:8081`) |
| `DB_PATH` | `qrtrack.db` | SQLite file |
| `ADMIN_USER` / `ADMIN_PASSWORD` | unset | Seed the first admin, only if no user exists. Forces a password change. |
| `TRUSTED_PROXIES` | `127.0.0.1/32,::1/128` | `X-Forwarded-For` is believed only from these peers |
| `GEOIP_DB` | unset | Path to a GeoLite2/GeoIP2 Country `.mmdb` |
| `RETENTION_DAYS` | `365` | Scans older than this are purged (hourly) |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` |

## Deploy

1. (Once) add the repository secret **`QRTRACK_ADMIN_PASSWORD`**. The `SSH_SERVER`, `SSH_USERNAME` and
   `SSH_PASSWORD` secrets already used by the support deploy are reused.
2. Local-only first deploy (touches nothing shared): run **Actions > Deploy QR tracker to VPS** with an empty
   `domain`.
3. To make it public: create the DNS `A` record, then run the workflow again with `domain` set.

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

## Privacy (UK GDPR)

Stored per scan: UTC time, a daily-salted keyed hash of the address, optional country, user agent (truncated to
512 chars), coarse device/OS/browser family, referrer **host only**, bot flag, unique flag. Never stored: the IP,
referrer paths or query strings, cookies. Scans are purged after `RETENTION_DAYS`; salts after three days.

## Development

```
go vet ./... && staticcheck ./...
go test -race ./...
QRTRACK_BENCH=1 go test -run TestRedirectLatency -v ./internal/web    # p99 < 5 ms at 500 req/s
bash deploy/test_install.sh <path-to-linux-amd64-binary> <path-to-support-Caddyfile>
CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" ./cmd/qrtrack
```

#!/usr/bin/env bash
# Exercises install.sh against a throwaway filesystem root. systemctl, useradd,
# chown, ss, getent and journalctl are stubbed; the qrtrack binary, curl, sed
# and `caddy validate` are the real ones, so what is tested is the actual logic
# that will touch the production Caddyfile.
#
#   usage: deploy/test_install.sh /path/to/qrtrack-linux-amd64 /path/to/repo/Caddyfile
set -uo pipefail
BIN="${1:?path to a linux/amd64 qrtrack binary}"
LIVE_CADDYFILE="${2:?path to the current support-site Caddyfile}"
HERE="$(cd "$(dirname "$0")" && pwd)"
command -v caddy >/dev/null || { echo "SKIP: caddy not installed"; exit 0; }

PORT=18082
# A leftover process from an interrupted earlier run would answer the health
# check for a broken binary and make the rollback tests meaningless. Refuse to
# start rather than report a misleading result.
if curl -fsS --max-time 1 "http://127.0.0.1:$PORT/healthz" >/dev/null 2>&1; then
    echo "ABORT: something is already serving 127.0.0.1:$PORT (a stale test process?). Stop it and re-run." >&2
    exit 2
fi
ROOT="$(mktemp -d)"
# On exit, stop every service the stub started (they are children of this script), then clean up.
trap 'pkill -f "$ROOT/""var/www/qrcode/qrtrack" 2>/dev/null; pkill -P $$ 2>/dev/null; kill "$(cat "$ROOT/pid" 2>/dev/null)" 2>/dev/null; rm -rf "$ROOT"' EXIT
export QR_APP_DIR="$ROOT/var/www/qrcode" QR_CADDYFILE="$ROOT/etc/caddy/Caddyfile" QR_CADDY_CONF_DIR="$ROOT/etc/caddy/conf.d" \
       QR_UNIT_DIR="$ROOT/etc/systemd/system" QR_PORT=$PORT QR_SKIP_ROOT_CHECK=1 ROOT
mkdir -p "$ROOT/release" "$ROOT/etc/caddy" "$ROOT/etc/systemd/system" "$ROOT/bin" "$QR_APP_DIR"
cp "$HERE/install.sh" "$HERE/qrtrack.service" "$HERE/qrcode.caddy.tmpl" "$HERE/update-geoip.sh" "$HERE/qrtrack-geoip.service" "$HERE/qrtrack-geoip.timer" "$ROOT/release/"
cp "$BIN" "$ROOT/release/qrtrack-linux-amd64"
cp "$LIVE_CADDYFILE" "$QR_CADDYFILE"
LIVE_SUM="$(md5sum < "$QR_CADDYFILE")"

# ---- stubs ----
cat > "$ROOT/bin/id" <<'S'
#!/usr/bin/env bash
[[ "$1" == "-u" && -f "$ROOT/user-created" ]] && { echo 999; exit 0; }; exit 1
S
cat > "$ROOT/bin/useradd" <<'S'
#!/usr/bin/env bash
touch "$ROOT/user-created"; echo "useradd $*" >> "$ROOT/calls"
S
for c in chown journalctl; do printf '#!/usr/bin/env bash\necho "%s $*" >> "$ROOT/calls"\n' "$c" > "$ROOT/bin/$c"; done
cat > "$ROOT/bin/ss" <<'S'
#!/usr/bin/env bash
[[ "${STUB_PORT_BUSY:-0}" == 1 ]] && echo "LISTEN 0 4096 127.0.0.1:$QR_PORT"; exit 0
S
cat > "$ROOT/bin/getent" <<'S'
#!/usr/bin/env bash
for d in ${STUB_RESOLVES:-}; do [[ "$d" == "$2" ]] && { echo "203.0.113.1 STREAM $d"; exit 0; }; done; exit 2
S
cat > "$ROOT/bin/systemctl" <<'S'
#!/usr/bin/env bash
echo "systemctl $*" >> "$ROOT/calls"
case "$1 $2 $3" in
  "is-active --quiet qrtrack") [[ -f "$ROOT/pid" ]] && kill -0 "$(cat "$ROOT/pid")" 2>/dev/null; exit $? ;;
  "restart qrtrack "*|"restart qrtrack")
     [[ -f "$ROOT/pid" ]] && kill -TERM "$(cat "$ROOT/pid")" 2>/dev/null && sleep 0.4
     ( set -a; . "$QR_APP_DIR/qrtrack.env"; set +a; exec "$QR_APP_DIR/qrtrack" >> "$ROOT/app.log" 2>&1 ) &
     echo $! > "$ROOT/pid"; exit 0 ;;
  "reload caddy"*) [[ "${STUB_CADDY_RELOAD_FAIL:-0}" == 1 ]] && exit 1; exit 0 ;;
esac
exit 0
S
chmod +x "$ROOT"/bin/*
export QR_GEOIP_BASE="file://$ROOT/dl-none"   # default: no database available, as on a host with no route out
export PATH="$ROOT/bin:$PATH"

pass=0; fail=0
ok()  { echo "  ok   $*"; pass=$((pass+1)); }
bad() {
  echo "  FAIL $*"; fail=$((fail+1))
  if [[ "${QR_TEST_VERBOSE:-0}" == 1 ]]; then echo "  --- installer output (last 15 lines):"; tail -n 15 "$ROOT/out" | sed 's/^/  | /'; fi
}
check() { local d="$1"; shift; if "$@"; then ok "$d"; else bad "$d"; fi; }
check_not() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d"; else ok "$d"; fi; }
run() { (cd "$ROOT/release" && bash ./install.sh) >"$ROOT/out" 2>&1; echo $? > "$ROOT/rc"; }
rc() { cat "$ROOT/rc"; }
reloads() { grep -c "systemctl reload caddy" "$ROOT/calls" 2>/dev/null || true; }
no_removed_lines() { ! diff "$LIVE_CADDYFILE" "$QR_CADDYFILE" | grep -q '^<'; }
can_login() {
  local jar page tok code
  jar="$(mktemp)"
  page="$(curl -s -c "$jar" "http://127.0.0.1:$PORT/admin/login")"
  tok="$(printf '%s' "$page" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
  code="$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" --data-urlencode "csrf=$tok" -d username=admin --data-urlencode "password=$1" "http://127.0.0.1:$PORT/admin/login")"
  rm -f "$jar"
  [ "$code" = 303 ]
}
: > "$ROOT/calls"

echo "A. first install, no domain"
ADMIN_PASSWORD='Seed-Pw-123' run
check "exit 0 even though no GeoIP database could be fetched" [ "$(rc)" = 0 ]
check "...and says so, rather than failing silently" grep -q "Could not install the IP-geolocation database" "$ROOT/out"
check_not "GEOIP_DB not set when there is no database" grep -q "^GEOIP_DB=" "$QR_APP_DIR/qrtrack.env"
check "monthly GeoIP timer installed" [ -f "$QR_UNIT_DIR/qrtrack-geoip.timer" ]
check "monthly GeoIP timer enabled" grep -q "enable --now qrtrack-geoip.timer" "$ROOT/calls"
check_not "STORE_FULL_IP not set by default" grep -q "^STORE_FULL_IP" "$QR_APP_DIR/qrtrack.env"
check "unit installed" [ -f "$QR_UNIT_DIR/qrtrack.service" ]
check "env file written 0600" [ "$(stat -c %a "$QR_APP_DIR/qrtrack.env")" = 600 ]
check_not "initial password removed from env after start" grep -q ADMIN_PASSWORD "$QR_APP_DIR/qrtrack.env"
check "BASE_URL is local-only" grep -qx "BASE_URL=http://127.0.0.1:$PORT" "$QR_APP_DIR/qrtrack.env"
check "service answers /healthz" curl -fsS "http://127.0.0.1:$PORT/healthz"
check "Caddyfile byte-identical (untouched)" [ "$(md5sum < "$QR_CADDYFILE")" = "$LIVE_SUM" ]
check "no conf.d created" [ ! -d "$QR_CADDY_CONF_DIR" ]
check "caddy was never reloaded" [ "$(reloads)" = 0 ]
check "seeded admin can sign in with the initial password" can_login Seed-Pw-123

echo "B. update with a domain that resolves"
STUB_RESOLVES="qr.test.example" DOMAIN=qr.test.example run
check "exit 0" [ "$(rc)" = 0 ]
check "import line added exactly once" [ "$(grep -cxF "import $QR_CADDY_CONF_DIR/*.caddy" "$QR_CADDYFILE")" = 1 ]
check "snippet has the domain" grep -q "^qr.test.example {" "$QR_CADDY_CONF_DIR/qrcode.caddy"
check "snippet has the port" grep -q "reverse_proxy 127.0.0.1:$PORT" "$QR_CADDY_CONF_DIR/qrcode.caddy"
check_not "no placeholder left in snippet" grep -q "__" "$QR_CADDY_CONF_DIR/qrcode.caddy"
check "nothing in the support config was removed or changed" no_removed_lines
check "combined config validates" caddy validate --config "$QR_CADDYFILE" --adapter caddyfile
check "caddy reloaded once (never restarted)" [ "$(reloads)" = 1 ]
check_not "caddy never restarted" grep -q "restart caddy" "$ROOT/calls"
check "BASE_URL follows the domain" grep -qx "BASE_URL=https://qr.test.example" "$QR_APP_DIR/qrtrack.env"
check "previous binary kept for rollback" [ -f "$QR_APP_DIR/qrtrack.prev" ]
check "existing settings kept (retention)" grep -qx "RETENTION_DAYS=365" "$QR_APP_DIR/qrtrack.env"
check "still healthy" curl -fsS "http://127.0.0.1:$PORT/healthz"

echo "C. idempotent re-run"
SUM_MAIN="$(md5sum < "$QR_CADDYFILE")"; SUM_SNIP="$(md5sum < "$QR_CADDY_CONF_DIR/qrcode.caddy")"
STUB_RESOLVES="qr.test.example" DOMAIN=qr.test.example run
check "exit 0" [ "$(rc)" = 0 ]
check "main Caddyfile unchanged" [ "$(md5sum < "$QR_CADDYFILE")" = "$SUM_MAIN" ]
check "snippet unchanged" [ "$(md5sum < "$QR_CADDY_CONF_DIR/qrcode.caddy")" = "$SUM_SNIP" ]
check "still exactly one qrcode import line" [ "$(grep -cxF "import $QR_CADDY_CONF_DIR/*.caddy" "$QR_CADDYFILE")" = 1 ]
check_not "no password re-needed" grep -q "ADMIN_PASSWORD" "$QR_APP_DIR/qrtrack.env"

echo "D. domain with no DNS record yet"
rm -rf "$QR_CADDY_CONF_DIR"; cp "$LIVE_CADDYFILE" "$QR_CADDYFILE"; LIVE_SUM="$(md5sum < "$QR_CADDYFILE")"; : > "$ROOT/calls"
STUB_RESOLVES="" DOMAIN=nodns.example run
check "exit 0 (tracker still deployed)" [ "$(rc)" = 0 ]
check "told the user to create the DNS record" grep -q "does not resolve" "$ROOT/out"
check "Caddyfile untouched, no ACME retry storm" [ "$(md5sum < "$QR_CADDYFILE")" = "$LIVE_SUM" ]
check "no reload" [ "$(reloads)" = 0 ]

echo "E. config that does not validate is rolled back, caddy not reloaded"
cp "$HERE/qrcode.caddy.tmpl" "$ROOT/tmpl.good"
printf '__DOMAIN__ {\n\tthis_is_not_a_directive __PORT__\n}\n' > "$ROOT/release/qrcode.caddy.tmpl"
: > "$ROOT/calls"
STUB_RESOLVES="qr.test.example" DOMAIN=qr.test.example run
check "exit non-zero" [ "$(rc)" != 0 ]
check "main Caddyfile restored byte-for-byte" [ "$(md5sum < "$QR_CADDYFILE")" = "$LIVE_SUM" ]
check "bad snippet removed" [ ! -f "$QR_CADDY_CONF_DIR/qrcode.caddy" ]
check "caddy NOT reloaded" [ "$(reloads)" = 0 ]
check "tracker itself still healthy" curl -fsS "http://127.0.0.1:$PORT/healthz"
cp "$ROOT/tmpl.good" "$ROOT/release/qrcode.caddy.tmpl"

echo "F. caddy refuses the reload"
: > "$ROOT/calls"
STUB_CADDY_RELOAD_FAIL=1 STUB_RESOLVES="qr.test.example" DOMAIN=qr.test.example run
check "exit non-zero" [ "$(rc)" != 0 ]
check "main Caddyfile restored" [ "$(md5sum < "$QR_CADDYFILE")" = "$LIVE_SUM" ]
check "snippet removed" [ ! -f "$QR_CADDY_CONF_DIR/qrcode.caddy" ]

echo "G. unhealthy new binary rolls back to the previous one"
printf '#!/bin/sh\nexit 1\n' > "$ROOT/release/qrtrack-linux-amd64"
run
check "exit non-zero" [ "$(rc)" != 0 ]
check "rolled back and healthy again" curl -fsS "http://127.0.0.1:$PORT/healthz"
cp "$BIN" "$ROOT/release/qrtrack-linux-amd64"

echo "I. GeoIP database: install, stay current, refuse a bad file, keep the old one"
if [[ -n "${QR_TEST_GEOIP_MMDB:-}" && -f "$QR_TEST_GEOIP_MMDB" ]]; then
  mkdir -p "$ROOT/dl"; M="$(date -u +%Y-%m)"
  gzip -c "$QR_TEST_GEOIP_MMDB" > "$ROOT/dl/dbip-city-lite-$M.mmdb.gz"
  : > "$ROOT/app.log"
  QR_GEOIP_BASE="file://$ROOT/dl" run
  check "exit 0" [ "$(rc)" = 0 ]
  check "database installed" [ -s "$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb" ]
  check "month recorded" [ "$(cat "$QR_APP_DIR/data/geoip/dbip-city-lite.month")" = "$M" ]
  check "GEOIP_DB written to the env file" grep -qx "GEOIP_DB=$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb" "$QR_APP_DIR/qrtrack.env"
  check "no leftover download folders" [ -z "$(find "$QR_APP_DIR/data/geoip" -maxdepth 1 -name '.download.*')" ]
  sleep 1
  check "service loaded the database" grep -q "GeoIP database loaded" "$ROOT/app.log"
  check "still healthy" curl -fsS "http://127.0.0.1:$PORT/healthz"
  SUM_DB="$(md5sum < "$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb")"

  QR_GEOIP_BASE="file://$ROOT/nowhere" run
  check "re-run needs no download when already current" [ "$(rc)" = 0 ]
  check "...database untouched" [ "$(md5sum < "$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb")" = "$SUM_DB" ]

  # A "new month" whose file is valid gzip but not a database: refused, old one kept.
  rm -f "$QR_APP_DIR/data/geoip/dbip-city-lite.month"
  printf 'not a database' | gzip -c > "$ROOT/dl/dbip-city-lite-$M.mmdb.gz"
  QR_GEOIP_MIN_BYTES=1 QR_GEOIP_BASE="file://$ROOT/dl" run
  check "bad download does not fail the deploy" [ "$(rc)" = 0 ]
  check "bad download refused" grep -q "not a usable database" "$ROOT/out"
  check "previous database kept byte-for-byte" [ "$(md5sum < "$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb")" = "$SUM_DB" ]
  check "tracker still healthy" curl -fsS "http://127.0.0.1:$PORT/healthz"

  # A truncated download is refused on size alone.
  head -c 1000 "$QR_TEST_GEOIP_MMDB" | gzip -c > "$ROOT/dl/dbip-city-lite-$M.mmdb.gz"
  QR_GEOIP_BASE="file://$ROOT/dl" run
  check "tiny file refused" grep -q "refusing it" "$ROOT/out"
  check "previous database still kept" [ "$(md5sum < "$QR_APP_DIR/data/geoip/dbip-city-lite.mmdb")" = "$SUM_DB" ]
else
  echo "  skip GeoIP download tests (set QR_TEST_GEOIP_MMDB to a DB-IP/GeoLite2 City .mmdb to run them)"
fi

echo "J. STORE_FULL_IP setting"
: > "$ROOT/app.log"
STORE_FULL_IP=true run
check "exit 0" [ "$(rc)" = 0 ]
check "written to env" grep -qx "STORE_FULL_IP=true" "$QR_APP_DIR/qrtrack.env"
sleep 1
check "service logs the warning that it is on" grep -q "STORE_FULL_IP is on" "$ROOT/app.log"
STORE_FULL_IP=false run
check "switched back off" grep -qx "STORE_FULL_IP=false" "$QR_APP_DIR/qrtrack.env"
BEFORE="$(md5sum < "$QR_APP_DIR/qrtrack.env")"
STORE_FULL_IP=maybe run
check "nonsense value refused" [ "$(rc)" != 0 ]
check "env untouched by the refused run" [ "$(md5sum < "$QR_APP_DIR/qrtrack.env")" = "$BEFORE" ]
run
check "unset leaves the current setting alone" grep -qx "STORE_FULL_IP=false" "$QR_APP_DIR/qrtrack.env"

echo "H. refusals before anything is changed"
kill "$(cat "$ROOT/pid" 2>/dev/null)" 2>/dev/null; sleep 0.5   # stop the running service BEFORE forgetting its pid
rm -rf "$QR_APP_DIR" "$ROOT/user-created" "$ROOT/pid"; mkdir -p "$QR_APP_DIR"
run
check "first install without a password is refused" [ "$(rc)" != 0 ]
check "...with a clear message" grep -q "ADMIN_PASSWORD" "$ROOT/out"
STUB_PORT_BUSY=1 ADMIN_PASSWORD=x run
check "port already used by something else is refused" [ "$(rc)" != 0 ]
check "...naming the port" grep -q "port $PORT is already in use" "$ROOT/out"
DOMAIN='bad domain;rm -rf /' ADMIN_PASSWORD=x run
check "hostile DOMAIN value is refused" [ "$(rc)" != 0 ]

echo; echo "passed=$pass failed=$fail"; [ "$fail" = 0 ]

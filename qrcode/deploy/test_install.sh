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

ROOT="$(mktemp -d)"; trap 'kill "$(cat "$ROOT/pid" 2>/dev/null)" 2>/dev/null; rm -rf "$ROOT"' EXIT
PORT=18082
export QR_APP_DIR="$ROOT/var/www/qrcode" QR_CADDYFILE="$ROOT/etc/caddy/Caddyfile" QR_CADDY_CONF_DIR="$ROOT/etc/caddy/conf.d" \
       QR_UNIT_DIR="$ROOT/etc/systemd/system" QR_PORT=$PORT QR_SKIP_ROOT_CHECK=1 ROOT
mkdir -p "$ROOT/release" "$ROOT/etc/caddy" "$ROOT/etc/systemd/system" "$ROOT/bin" "$QR_APP_DIR"
cp "$HERE/install.sh" "$HERE/qrtrack.service" "$HERE/qrcode.caddy.tmpl" "$ROOT/release/"
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
export PATH="$ROOT/bin:$PATH"

pass=0; fail=0
ok()  { echo "  ok   $*"; pass=$((pass+1)); }
bad() { echo "  FAIL $*"; fail=$((fail+1)); }
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
check "exit 0" [ "$(rc)" = 0 ]
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

echo "H. refusals before anything is changed"
rm -rf "$QR_APP_DIR" "$ROOT/user-created" "$ROOT/pid"; kill "$(cat "$ROOT/pid" 2>/dev/null)" 2>/dev/null; mkdir -p "$QR_APP_DIR"
run
check "first install without a password is refused" [ "$(rc)" != 0 ]
check "...with a clear message" grep -q "ADMIN_PASSWORD" "$ROOT/out"
STUB_PORT_BUSY=1 ADMIN_PASSWORD=x run
check "port already used by something else is refused" [ "$(rc)" != 0 ]
check "...naming the port" grep -q "port $PORT is already in use" "$ROOT/out"
DOMAIN='bad domain;rm -rf /' ADMIN_PASSWORD=x run
check "hostile DOMAIN value is refused" [ "$(rc)" != 0 ]

echo; echo "passed=$pass failed=$fail"; [ "$fail" = 0 ]

#!/usr/bin/env bash
# Install or update qrtrack on the VPS. Run as root from the directory the
# release was copied to. Idempotent: safe to run again for every release.
#
# Isolation guarantees (this host also runs the support chatbot):
#   * everything lives in /var/www/qrcode, as its own user and systemd unit
#   * it only listens on 127.0.0.1:$PORT
#   * the only change outside that folder is the Caddy site snippet, and only
#     when a DOMAIN is given. Caddy is reloaded (never restarted) and only
#     after `caddy validate` passes; on any failure the previous Caddy
#     config is restored and Caddy is left untouched.
#
# Inputs (environment):
#   DOMAIN          public host name, e.g. qr.blakegroup.uk. Empty = local-only.
#   ADMIN_PASSWORD  initial admin password, needed only on the very first install.
#   ADMIN_USER      initial admin user name (default: admin)
#   QR_PORT         local port (default 8081)
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${QR_APP_DIR:-/var/www/qrcode}"
PORT="${QR_PORT:-8081}"
DOMAIN="${DOMAIN:-}"
ADMIN_USER="${ADMIN_USER:-admin}"
CADDY_MAIN="${QR_CADDYFILE:-/etc/caddy/Caddyfile}"
CADDY_CONF_DIR="${QR_CADDY_CONF_DIR:-/etc/caddy/conf.d}"
UNIT_DIR="${QR_UNIT_DIR:-/etc/systemd/system}"
SVC_USER=qrtrack
ENV_FILE="$APP_DIR/qrtrack.env"
DB_FILE="$APP_DIR/data/qrtrack.db"
IMPORT_LINE="import $CADDY_CONF_DIR/*.caddy"

info() { printf '\033[1;32m[qrcode]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[qrcode]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[qrcode]\033[0m %s\n' "$*" >&2; exit 1; }

[[ "${QR_SKIP_ROOT_CHECK:-0}" == 1 || $EUID -eq 0 ]] || die "run as root"

# An ADMIN_PASSWORD may arrive base64-encoded (the workflow does this so shell
# quoting can never mangle it).
if [[ -n "${ADMIN_PASSWORD_B64:-}" && -z "${ADMIN_PASSWORD:-}" ]]; then
    ADMIN_PASSWORD="$(printf '%s' "$ADMIN_PASSWORD_B64" | base64 -d)"
fi
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

case "$(uname -m)" in
    x86_64)  ARCH=amd64 ;;
    aarch64) ARCH=arm64 ;;
    *) die "unsupported CPU architecture $(uname -m)" ;;
esac
BIN_SRC="$SRC/qrtrack-linux-$ARCH"
[[ -f "$BIN_SRC" ]] || die "$BIN_SRC not found"
[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ || -z "$DOMAIN" ]] || die "DOMAIN '$DOMAIN' is not a valid host name"

# ── user and directories ────────────────────────────────────────────────────
if ! id -u "$SVC_USER" >/dev/null 2>&1; then
    info "Creating system user $SVC_USER"
    useradd --system --home-dir "$APP_DIR/data" --no-create-home --shell /usr/sbin/nologin "$SVC_USER"
fi
mkdir -p "$APP_DIR/data"

# Refuse to start on top of a different process already using the port.
if ! systemctl is-active --quiet qrtrack 2>/dev/null; then
    if ss -ltn "sport = :$PORT" 2>/dev/null | grep -q LISTEN; then
        die "port $PORT is already in use by another process; set QR_PORT to a free port"
    fi
fi

# ── binary (keep the previous one for rollback) ─────────────────────────────
if [[ -f "$APP_DIR/qrtrack" ]]; then cp -f "$APP_DIR/qrtrack" "$APP_DIR/qrtrack.prev"; fi
install -m 0755 "$BIN_SRC" "$APP_DIR/qrtrack.new"
mv -f "$APP_DIR/qrtrack.new" "$APP_DIR/qrtrack"
info "Installed binary ($ARCH)"

# ── configuration ────────────────────────────────────────────────────────────
if [[ -n "$DOMAIN" ]]; then BASE_URL="https://$DOMAIN"; else BASE_URL="http://127.0.0.1:$PORT"; fi
if [[ ! -f "$ENV_FILE" ]]; then
    if [[ ! -s "$DB_FILE" && -z "$ADMIN_PASSWORD" ]]; then
        die "first install needs ADMIN_PASSWORD (set the QRTRACK_ADMIN_PASSWORD repository secret)"
    fi
    info "Writing $ENV_FILE"
    umask 077
    {
        echo "LISTEN_ADDR=127.0.0.1:$PORT"
        echo "DB_PATH=$DB_FILE"
        echo "BASE_URL=$BASE_URL"
        echo "TRUSTED_PROXIES=127.0.0.1/32,::1/128"
        echo "RETENTION_DAYS=365"
        echo "LOG_LEVEL=info"
        echo "ADMIN_USER=$ADMIN_USER"
        if [[ -n "$ADMIN_PASSWORD" ]]; then echo "ADMIN_PASSWORD=$ADMIN_PASSWORD"; fi
    } > "$ENV_FILE"
    umask 022
else
    # Existing install: keep every setting, only follow a changed domain.
    if [[ -n "$DOMAIN" ]] && ! grep -qx "BASE_URL=$BASE_URL" "$ENV_FILE"; then
        info "Updating BASE_URL to $BASE_URL (QR codes already printed keep their old address)"
        sed -i "s|^BASE_URL=.*|BASE_URL=$BASE_URL|" "$ENV_FILE"
    fi
fi
chown root:root "$ENV_FILE"; chmod 600 "$ENV_FILE"
chown root:root "$APP_DIR" "$APP_DIR/qrtrack"; chmod 755 "$APP_DIR"
chown -R "$SVC_USER:$SVC_USER" "$APP_DIR/data"; chmod 700 "$APP_DIR/data"

# ── systemd ──────────────────────────────────────────────────────────────────
install -m 0644 "$SRC/qrtrack.service" "$UNIT_DIR/qrtrack.service"
systemctl daemon-reload
systemctl enable qrtrack >/dev/null 2>&1 || true
systemctl restart qrtrack

healthy() { curl -fsS --max-time 3 "http://127.0.0.1:$PORT/healthz" >/dev/null 2>&1; }
for _ in $(seq 1 30); do healthy && break; sleep 1; done
if ! healthy; then
    warn "qrtrack did not become healthy. Recent log:"
    journalctl -u qrtrack -n 30 --no-pager >&2 || true
    if [[ -f "$APP_DIR/qrtrack.prev" ]]; then
        warn "Rolling back to the previous binary"
        cp -f "$APP_DIR/qrtrack.prev" "$APP_DIR/qrtrack"
        systemctl restart qrtrack || true
    fi
    die "deploy failed; the support site was not touched"
fi
info "qrtrack is healthy on 127.0.0.1:$PORT"

# The initial password is only needed for the first start. Do not leave it on disk.
if grep -q '^ADMIN_PASSWORD=' "$ENV_FILE"; then
    sed -i '/^ADMIN_PASSWORD=/d' "$ENV_FILE"
    info "Removed the initial password from $ENV_FILE"
fi

# ── Caddy (only with a domain) ───────────────────────────────────────────────
configure_caddy() {
    local snippet="$CADDY_CONF_DIR/qrcode.caddy" stamp backup snippet_backup=""
    mkdir -p "$CADDY_CONF_DIR"
    stamp="$(date +%Y%m%d-%H%M%S)"
    backup="$CADDY_MAIN.bak-qrcode-$stamp"
    cp -p "$CADDY_MAIN" "$backup"
    if [[ -f "$snippet" ]]; then snippet_backup="$snippet.bak-$stamp"; cp -p "$snippet" "$snippet_backup"; fi

    restore() {
        warn "Restoring the previous Caddy configuration"
        cp -p "$backup" "$CADDY_MAIN"
        if [[ -n "$snippet_backup" ]]; then cp -p "$snippet_backup" "$snippet"; else rm -f "$snippet"; fi
    }

    if ! grep -qxF "$IMPORT_LINE" "$CADDY_MAIN"; then
        printf '\n# Independently managed extra sites (the QR tracker, see /var/www/qrcode).\n%s\n' "$IMPORT_LINE" >> "$CADDY_MAIN"
        info "Added the import line to $CADDY_MAIN"
    fi
    sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__PORT__|$PORT|g" "$SRC/qrcode.caddy.tmpl" > "$snippet"

    if ! caddy validate --config "$CADDY_MAIN" --adapter caddyfile >/tmp/qrtrack-caddy-validate.log 2>&1; then
        tail -n 15 /tmp/qrtrack-caddy-validate.log >&2
        restore
        warn "The new Caddy config did not validate, so Caddy was NOT reloaded. The tracker still runs on 127.0.0.1:$PORT."
        return 1
    fi
    if ! systemctl reload caddy; then
        restore
        systemctl reload caddy || true
        warn "Caddy refused the reload; previous configuration restored."
        return 1
    fi
    info "Caddy now serves https://$DOMAIN -> 127.0.0.1:$PORT"
}

if [[ -n "$DOMAIN" ]]; then
    # A name with no DNS record makes Caddy retry certificate issuance forever
    # (this already happened once for another hostname on this server), so
    # only wire Caddy up once the name resolves.
    if ! getent ahosts "$DOMAIN" >/dev/null 2>&1; then
        warn "$DOMAIN does not resolve yet. Create an A record for it pointing at this server, then run this deploy again."
        warn "The tracker is running on 127.0.0.1:$PORT but is not public."
    else
        # Called plainly (not in an || list) so `set -e` stays active inside it.
        configure_caddy
    fi
else
    info "No DOMAIN given: the tracker is local-only (127.0.0.1:$PORT) and Caddy was not touched."
fi

info "Done."

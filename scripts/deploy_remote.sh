#!/bin/bash
# scripts/deploy_remote.sh — runs ON THE VPS after code has been copied to $WEBROOT.
# Used by .github/workflows/deploy.yml — do not run this against a checkout that
# hasn't already been placed at $WEBROOT (it does not clone or pull anything).
#
# Usage: DOMAIN=chat.blake-uk.com bash scripts/deploy_remote.sh

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
die()     { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && die "Run as root"

DOMAIN="${DOMAIN:-chat.blake-uk.com}"
WEBROOT="/var/www/chat"

info "Blake UK AI Chatbot — Remote Deploy"
info "Domain : $DOMAIN"
info "Webroot: $WEBROOT"
echo ""

[ -d "$WEBROOT/src" ] || die "$WEBROOT/src not found — code must be copied to $WEBROOT before running this script"

# ── PHP 8.2 ──────────────────────────────────────────────────────────────────
if ! command -v php >/dev/null 2>&1; then
    info "Installing PHP 8.2..."
    apt-get update -qq
    apt-get install -y -qq \
        php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-mbstring \
        php8.2-xml php8.2-intl php8.2-fileinfo
else
    info "PHP already installed — skipping."
fi

# ── SQLite ────────────────────────────────────────────────────────────────────
command -v sqlite3 >/dev/null 2>&1 || apt-get install -y -qq sqlite3

# ── Caddy ────────────────────────────────────────────────────────────────────
if ! command -v caddy >/dev/null 2>&1; then
    info "Installing Caddy..."
    apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gnupg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        | tee /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -qq
    apt-get install -y -qq caddy
else
    info "Caddy already installed — skipping."
fi

# ── Directory structure ───────────────────────────────────────────────────────
info "Setting up directories..."
mkdir -p "$WEBROOT"/{data,uploads,logs,config}
chown -R www-data:www-data "$WEBROOT"
chmod 750 "$WEBROOT/config"
chmod 770 "$WEBROOT"/{data,uploads,logs}

# ── Database ──────────────────────────────────────────────────────────────────
info "Initialising SQLite database..."
if [ ! -f "$WEBROOT/data/chatbot.db" ]; then
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema.sql"
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_widget.sql"
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_append.sql"
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_fts_triggers.sql"
    chown www-data:www-data "$WEBROOT/data/chatbot.db"
    info "Database created."
else
    warn "Database already exists — skipping creation."
fi

# ── Config ────────────────────────────────────────────────────────────────────
if [ ! -f "$WEBROOT/config/config.php" ]; then
    info "Generating config..."
    ENC_KEY=$(php -r "echo bin2hex(random_bytes(32));")
    sed "s/CHANGE_ME_32_BYTE_HEX_KEY/$ENC_KEY/" "$WEBROOT/config/config.example.php" \
        > "$WEBROOT/config/config.php"
    chown www-data:www-data "$WEBROOT/config/config.php"
    chmod 640 "$WEBROOT/config/config.php"
    info "Config written. Encryption key auto-generated."
else
    warn "Config already exists — skipping."
fi

# ── Caddyfile ─────────────────────────────────────────────────────────────────
info "Configuring Caddy..."
sed "s/chat\.blake-uk\.com/$DOMAIN/g" "$WEBROOT/Caddyfile" > /etc/caddy/Caddyfile
systemctl enable caddy --quiet
systemctl restart caddy
info "Caddy started."

# ── PHP-FPM ──────────────────────────────────────────────────────────────────
systemctl enable php8.2-fpm --quiet
systemctl restart php8.2-fpm
info "PHP-FPM started."

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN} Deploy complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Next steps:"
echo "  1. Create admin user (if not already done):"
echo "     php $WEBROOT/scripts/create_admin.php admin 'YourStrongPassword'"
echo ""
echo "  2. Visit https://$DOMAIN/admin/"
echo "     -> API Keys -> paste your Gemini API key"
echo "     -> Model Settings -> refresh -> select models"
echo ""
echo "  3. Log path: $WEBROOT/logs/"
echo "  4. DB path:  $WEBROOT/data/chatbot.db"
echo ""

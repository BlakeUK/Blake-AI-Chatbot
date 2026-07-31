#!/bin/bash
# install.sh — Blake UK Chatbot one-line VPS installer
# Usage: bash <(curl -fsSL https://raw.githubusercontent.com/BlakeUK/Blake-AI-Chatbot/main/install.sh)
# Requires: Debian 12, root access, chat.blake-uk.com DNS pointing to this server.

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
die()     { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && die "Run as root"

DOMAIN="${DOMAIN:-chat.blake-uk.com}"
WEBROOT="/var/www/chat"
REPO="https://github.com/BlakeUK/Blake-AI-Chatbot.git"

info "Blake UK AI Chatbot — VPS Installer"
info "Domain : $DOMAIN"
info "Webroot: $WEBROOT"
echo ""

# ── System update ─────────────────────────────────────────────────────────────
info "Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

# ── PHP ───────────────────────────────────────────────────────────────────────
# Package names are versioned and differ by distro release, so install the
# generic/default-version meta-packages and detect the actual version after.
info "Installing PHP..."
apt-get install -y -qq \
    php-fpm php-sqlite3 php-curl php-mbstring php-xml php-intl

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
info "Using PHP $PHP_VERSION"

# ── SQLite ────────────────────────────────────────────────────────────────────
apt-get install -y -qq sqlite3

# ── Caddy ────────────────────────────────────────────────────────────────────
info "Installing Caddy..."
apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gnupg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    | tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update -qq
apt-get install -y -qq caddy

# ── Git ───────────────────────────────────────────────────────────────────────
apt-get install -y -qq git

# ── Clone repo ────────────────────────────────────────────────────────────────
info "Cloning repository to $WEBROOT..."
if [ -d "$WEBROOT/.git" ]; then
    warn "Repo already exists — pulling latest..."
    git -C "$WEBROOT" pull --quiet
else
    git clone "$REPO" "$WEBROOT" --quiet
fi

# ── Directory structure ───────────────────────────────────────────────────────
info "Creating directories..."
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

# ── Migrations (idempotent, run against new and existing DBs alike) ──────────
if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(admin_users);" | grep -q "totp_enabled"; then
    info "Applying 2FA schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_2fa.sql"
else
    warn "2FA schema already applied — skipping."
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
sed -e "s/chat\.blake-uk\.com/$DOMAIN/g" -e "s/php8\.2-fpm/php${PHP_VERSION}-fpm/g" \
    "$WEBROOT/Caddyfile" > /etc/caddy/Caddyfile
systemctl enable caddy --quiet
systemctl restart caddy
info "Caddy started."

# ── PHP-FPM ──────────────────────────────────────────────────────────────────
systemctl enable "php${PHP_VERSION}-fpm" --quiet
systemctl restart "php${PHP_VERSION}-fpm"
info "PHP-FPM started."

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN} Installation complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Next steps:"
echo "  1. Create admin user:"
echo "     php $WEBROOT/scripts/create_admin.php admin 'YourStrongPassword'"
echo ""
echo "  2. Visit https://$DOMAIN/admin/"
echo "     → API Keys → paste your Gemini API key"
echo "     → Model Settings → refresh → select models"
echo ""
echo "  3. Embed widget on blake-uk.com:"
echo "     <script src=\"https://$DOMAIN/widget/chat.js\" defer></script>"
echo ""
echo "  4. Log path: $WEBROOT/logs/"
echo "  5. DB path:  $WEBROOT/data/chatbot.db"
echo ""

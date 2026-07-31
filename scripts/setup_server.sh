#!/bin/bash
# scripts/setup_server.sh
# Run once on a fresh Debian 12 VPS as root.
# Usage: bash scripts/setup_server.sh

set -e

echo "=== Blake UK Chatbot — Server Setup ==="

# ── PHP + extensions ──────────────────────────────────────────────────────────
# Package names are versioned and differ by distro release, so install the
# generic/default-version meta-packages and detect the actual version after.
apt-get update -qq
apt-get install -y php-fpm php-sqlite3 php-curl php-mbstring \
                   php-xml php-intl sqlite3 curl unzip git

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "Using PHP $PHP_VERSION"

# ── Caddy ─────────────────────────────────────────────────────────────────────
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update -qq
apt-get install -y caddy

# ── Web root ──────────────────────────────────────────────────────────────────
mkdir -p /var/www/chat/{public,config,data,uploads,logs,scripts,src}
chown -R www-data:www-data /var/www/chat

# ── SQLite DB ─────────────────────────────────────────────────────────────────
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_widget.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_append.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_fts_triggers.sql
if ! sqlite3 /var/www/chat/data/chatbot.db "PRAGMA table_info(admin_users);" | grep -q "totp_enabled"; then
    sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_2fa.sql
fi
chown www-data:www-data /var/www/chat/data/chatbot.db

# ── Generate encryption key ───────────────────────────────────────────────────
ENC_KEY=$(php -r "echo bin2hex(random_bytes(32));")
echo "ENCRYPTION KEY (add to config/config.php): $ENC_KEY"

# ── Caddyfile ─────────────────────────────────────────────────────────────────
sed "s/php8\.2-fpm/php${PHP_VERSION}-fpm/g" /var/www/chat/Caddyfile > /etc/caddy/Caddyfile
systemctl enable "php${PHP_VERSION}-fpm" --quiet
systemctl restart "php${PHP_VERSION}-fpm"
systemctl reload caddy

echo "=== Done. Copy config/config.example.php to config/config.php and fill in values. ==="

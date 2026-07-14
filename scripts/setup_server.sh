#!/bin/bash
# scripts/setup_server.sh
# Run once on a fresh Debian 12 VPS as root.
# Usage: bash scripts/setup_server.sh

set -e

echo "=== Blake UK Chatbot — Server Setup ==="

# ── PHP 8.2 + extensions ─────────────────────────────────────────────────────
apt-get update -qq
apt-get install -y php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-mbstring \
                   php8.2-xml php8.2-intl sqlite3 curl unzip git

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
chown www-data:www-data /var/www/chat/data/chatbot.db

# ── Generate encryption key ───────────────────────────────────────────────────
ENC_KEY=$(php -r "echo bin2hex(random_bytes(32));")
echo "ENCRYPTION KEY (add to config/config.php): $ENC_KEY"

# ── Caddyfile ─────────────────────────────────────────────────────────────────
cp /var/www/chat/Caddyfile /etc/caddy/Caddyfile
systemctl reload caddy

echo "=== Done. Copy config/config.example.php to config/config.php and fill in values. ==="

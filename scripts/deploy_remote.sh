#!/bin/bash
# scripts/deploy_remote.sh — runs ON THE VPS after code has been copied to $WEBROOT.
# Used by .github/workflows/deploy.yml — do not run this against a checkout that
# hasn't already been placed at $WEBROOT (it does not clone or pull anything).
#
# Usage: DOMAIN=chat.blakegroup.uk bash scripts/deploy_remote.sh

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
die()     { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && die "Run as root"

DOMAIN="${DOMAIN:-chat.blakegroup.uk}"
WEBROOT="/var/www/chat"

info "Blake UK AI Chatbot — Remote Deploy"
info "Domain : $DOMAIN"
info "Webroot: $WEBROOT"
echo ""

[ -d "$WEBROOT/src" ] || die "$WEBROOT/src not found — code must be copied to $WEBROOT before running this script"

# ── PHP ───────────────────────────────────────────────────────────────────────
# Package names are versioned (php8.2-fpm, php8.3-fpm, ...) and differ by distro
# release, so install the generic/default-version meta-packages instead of
# pinning a version, then detect whatever version actually got installed.
if ! command -v php >/dev/null 2>&1; then
    info "Installing PHP..."
    apt-get update -qq
    apt-get install -y -qq \
        php-fpm php-sqlite3 php-curl php-mbstring php-xml php-intl
else
    info "PHP already installed — skipping."
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
info "Using PHP $PHP_VERSION"

# ── SQLite ────────────────────────────────────────────────────────────────────
command -v sqlite3 >/dev/null 2>&1 || apt-get install -y -qq sqlite3

# ── Cron ─────────────────────────────────────────────────────────────────────
if ! command -v crontab >/dev/null 2>&1; then
    info "Installing cron..."
    apt-get install -y -qq cron
    systemctl enable cron --quiet
    systemctl start cron
else
    info "cron already installed — skipping."
fi

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

# ── blakegroup.uk static site ──────────────────────────────────────────────────
# Lives in the same repo (blakegroup-site/public) so it ships via the same
# checkout/copy step as everything else, then gets synced to its own webroot
# here rather than served out of $WEBROOT directly - keeps it a fully
# separate site from the chatbot, matching the Caddy config.
info "Syncing blakegroup.uk site..."
mkdir -p /var/www/blakegroup/public /var/www/blakegroup/robots-admin /var/www/blakegroup/robots-chat
cp -r "$WEBROOT"/blakegroup-site/public/. /var/www/blakegroup/public/
cp "$WEBROOT"/blakegroup-site/robots-admin/robots.txt /var/www/blakegroup/robots-admin/robots.txt
cp "$WEBROOT"/blakegroup-site/robots-chat/robots.txt /var/www/blakegroup/robots-chat/robots.txt
chown -R www-data:www-data /var/www/blakegroup

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

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(knowledge_files);" | grep -q "source_url"; then
    info "Applying bulk URL import schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_import_queue.sql"
else
    warn "Bulk URL import schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(products);" | grep -q "related_product_codes"; then
    info "Applying related-products schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_related_products.sql"
else
    warn "Related-products schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(products);" | grep -q "brand"; then
    info "Applying site-feed fields schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_site_feed_fields.sql"
else
    warn "Site-feed fields schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(admin_users);" | grep -q "locked_until"; then
    info "Applying login-lockout schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_login_lockout.sql"
else
    warn "Login-lockout schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(knowledge_entries);" | grep -q "source"; then
    info "Applying knowledge-source schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_knowledge_source.sql"
else
    warn "Knowledge-source schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "SELECT name FROM sqlite_master WHERE type='table' AND name='product_page_extractions';" | grep -q "product_page_extractions"; then
    info "Applying product-page-extractions schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_product_page_extractions.sql"
else
    warn "Product-page-extractions schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(support_tickets);" | grep -q "department"; then
    info "Applying ticket-routing schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_ticket_routing.sql"
else
    warn "Ticket-routing schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(admin_users);" | grep -q "last_active"; then
    info "Applying agent-presence schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_agent_presence.sql"
else
    warn "Agent-presence schema already applied — skipping."
fi

if ! sqlite3 "$WEBROOT/data/chatbot.db" "PRAGMA table_info(support_tickets);" | grep -q "priority"; then
    info "Applying ticket-priority/SLA schema migration..."
    sqlite3 "$WEBROOT/data/chatbot.db" < "$WEBROOT/scripts/schema_ticket_priority_sla.sql"
else
    warn "Ticket-priority/SLA schema already applied — skipping."
fi

# ── Pending-file processing cron (bulk URL imports extract in the background) ─
CRON_LINE="* * * * * php $WEBROOT/scripts/process_pending_files.php >> $WEBROOT/logs/import_queue.log 2>&1"
if ! (crontab -u www-data -l 2>/dev/null | grep -qF "process_pending_files.php"); then
    info "Installing pending-file processing cron job..."
    ( crontab -u www-data -l 2>/dev/null || true; echo "$CRON_LINE" ) | crontab -u www-data -
else
    warn "Pending-file processing cron job already installed — skipping."
fi

# ── Product-page extraction cron (bulk template application, background) ──────
CRON_LINE_PP="* * * * * php $WEBROOT/scripts/process_product_pages.php >> $WEBROOT/logs/product_extract_queue.log 2>&1"
if ! (crontab -u www-data -l 2>/dev/null | grep -qF "process_product_pages.php"); then
    info "Installing product-page extraction cron job..."
    ( crontab -u www-data -l 2>/dev/null || true; echo "$CRON_LINE_PP" ) | crontab -u www-data -
else
    warn "Product-page extraction cron job already installed — skipping."
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
if [[ "$DOMAIN" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
    warn "DOMAIN ($DOMAIN) is an IP address — Let's Encrypt can't issue a cert for it, serving plain HTTP instead."
    PROTO="http"
    cat > /etc/caddy/Caddyfile <<CADDYEOF
http://$DOMAIN {
    root * /var/www/chat/public
    php_fastcgi unix//run/php/php${PHP_VERSION}-fpm.sock

    @blocked path /config/* /data/* /uploads/* /logs/* /scripts/*
    respond @blocked 403

    file_server

    header {
        X-Content-Type-Options nosniff
        Referrer-Policy strict-origin-when-cross-origin
        Permissions-Policy "geolocation=(), microphone=(), camera=()"
        X-Frame-Options SAMEORIGIN
    }
}
CADDYEOF
else
    PROTO="https"
    # No domain substitution needed anymore - the committed Caddyfile is a
    # fixed multi-domain config (blakegroup.uk + admin./chat. subdomains),
    # not templated around a single placeholder domain the way it was when
    # chat.blake-uk.com was the one real domain. Still substituting the PHP
    # version, which genuinely does vary by server.
    sed -e "s/php8\.2-fpm/php${PHP_VERSION}-fpm/g" \
        "$WEBROOT/Caddyfile" > /etc/caddy/Caddyfile
fi
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
echo -e "${GREEN} Deploy complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Next steps:"
echo "  1. Create admin user (if not already done):"
echo "     php $WEBROOT/scripts/create_admin.php admin 'YourStrongPassword'"
echo ""
echo "  2. Visit $PROTO://$DOMAIN/admin/"
echo "     -> API Keys -> paste your Gemini API key"
echo "     -> Model Settings -> refresh -> select models"
echo ""
echo "  3. Log path: $WEBROOT/logs/"
echo "  4. DB path:  $WEBROOT/data/chatbot.db"
echo ""

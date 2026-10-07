#!/usr/bin/env bash
# Download or refresh the free DB-IP "City Lite" IP-geolocation database used
# for the approximate town / region / country of each scan.
#
#   * Free, no account. Licence CC BY 4.0: the admin pages show the required
#     "IP Geolocation by DB-IP" credit whenever this database is loaded.
#   * Published monthly; this tries the current month, then the previous one.
#   * Nothing is replaced unless the download decompresses cleanly AND
#     `qrtrack geocheck` can open it and resolve a test address. A bad download
#     leaves the previous database in place.
#   * The file is swapped in by rename (never edited in place: it is
#     memory-mapped by the running service). The service notices the new file
#     within the hour, with no restart.
#
# Runs from the monthly systemd timer (as the qrtrack user) and from install.sh.
set -euo pipefail

APP_DIR="${QR_APP_DIR:-/var/www/qrcode}"
BIN="${QR_BIN:-$APP_DIR/qrtrack}"
BASE="${QR_GEOIP_BASE:-https://download.db-ip.com/free}"
MIN_BYTES="${QR_GEOIP_MIN_BYTES:-20000000}"   # the real file is ~120 MB; reject anything tiny
DEST_DIR="$APP_DIR/data/geoip"
DEST="$DEST_DIR/dbip-city-lite.mmdb"
MARKER="$DEST_DIR/dbip-city-lite.month"

say() { printf '[geoip] %s\n' "$*"; }
mkdir -p "$DEST_DIR"

# Room for the download, the unpacked copy and the current file at once.
avail=$(df --output=avail -B1M "$DEST_DIR" | tail -n1 | tr -d ' ')
if [[ "${avail:-0}" -lt 450 ]]; then
    say "only ${avail:-0} MB free; need about 450 MB. Not downloading." >&2
    exit 1
fi

work="$(mktemp -d "$DEST_DIR/.download.XXXXXX")"
trap 'rm -rf "$work"' EXIT

first_of_month="$(date -u +%Y-%m-01)"
for back in 0 1; do
    month="$(date -u -d "$first_of_month -$back month" +%Y-%m)"
    if [[ -s "$DEST" && -f "$MARKER" && "$(cat "$MARKER")" == "$month" ]]; then
        say "already up to date ($month)"
        exit 0
    fi
    url="$BASE/dbip-city-lite-$month.mmdb.gz"
    say "trying $url"
    if ! curl -fsSL --retry 2 --connect-timeout 20 --max-time 600 -o "$work/db.gz" "$url" 2>"$work/curl.err"; then
        say "not available ($(tr -d '\n' < "$work/curl.err" | cut -c1-120))"
        continue
    fi
    gzip -t "$work/db.gz"                            || { say "download is not valid gzip" >&2; continue; }
    gzip -dc "$work/db.gz" > "$work/db.mmdb"
    rm -f "$work/db.gz"
    size=$(stat -c %s "$work/db.mmdb")
    if [[ "$size" -lt "$MIN_BYTES" ]]; then
        say "unpacked file is only $size bytes; refusing it" >&2
        continue
    fi
    if ! "$BIN" geocheck "$work/db.mmdb"; then
        say "file is not a usable database; refusing it" >&2
        continue
    fi
    chmod 0644 "$work/db.mmdb"
    mv -f "$work/db.mmdb" "$DEST"                   # atomic: same filesystem
    echo "$month" > "$MARKER"
    say "installed $month ($size bytes)"
    exit 0
done
say "no usable database found for this or last month; keeping what is installed" >&2
exit 1

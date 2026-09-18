#!/usr/bin/env bash
# Downloads Ofcom's DTT transmitter spreadsheet (Open Government Licence).
# https://www.ofcom.org.uk/tv-radio-and-on-demand/coverage-and-transmitters/transmitter-frequency
set -euo pipefail
OUT="${1:?output path}"
URL="${OFCOM_XLSX_URL:-https://www.ofcom.org.uk/siteassets/resources/documents/spectrum/tv-transmitter-guidance/700-plan-clearance.xlsx}"
mkdir -p "$(dirname "$OUT")"
curl -fsSL --retry 3 \
  -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36" \
  -H "Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,*/*" \
  -o "$OUT" "$URL"
# Cloudflare challenge pages come back as HTML with a 200/403; reject anything that isn't a zip.
if ! head -c 2 "$OUT" | grep -q "PK"; then
  echo "Download is not an xlsx (blocked?)" >&2
  exit 1
fi
ls -la "$OUT"

#!/usr/bin/env bash
# Builds compressed terrain tiles for the TV reception predictor.
#
# Source: Copernicus DEM GLO-90 (free for any use incl. commercial).
# Attribution: "Produced using Copernicus WorldDEM-90 (c) DLR e.V. 2010-2014 and
# (c) Airbus Defence and Space GmbH 2014-2018 provided under COPERNICUS by the
# European Union and ESA; all rights reserved."
#
# Output: <out>/meta.json + <out>/t{row}_{col}.bin.gz (int16 LE heights in m,
# 300x200 cells, ~185 m x ~160 m). All-sea tiles are omitted (read as 0 m).
# Target: well under 25 MB on the VPS. Run on a CI runner, never the VPS.
set -euo pipefail
WORK="${1:?work dir}"; OUT="${2:?output dir}"
HERE="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$WORK/dem" "$OUT"

BASE="https://copernicus-dem-90m.s3.amazonaws.com"
list="$WORK/tiles.txt"; : > "$list"
for lat in $(seq 49 60); do
  for lon in $(seq -9 1); do
    if [ "$lon" -lt 0 ]; then ew=$(printf "W%03d" $(( -lon ))); else ew=$(printf "E%03d" "$lon"); fi
    echo "Copernicus_DSM_COG_30_N${lat}_00_${ew}_00_DEM" >> "$list"
  done
done

# Missing tiles are open sea (Copernicus doesn't publish them) - 404 is expected.
xargs -P 8 -I{} sh -c 'f="'"$WORK"'/dem/{}.tif"; [ -s "$f" ] || curl -fsS -o "$f" "'"$BASE"'/{}/{}.tif" 2>/dev/null || rm -f "$f"' < "$list"
echo "Downloaded $(ls "$WORK/dem" | wc -l) DEM tiles"

gdalbuildvrt -q "$WORK/uk.vrt" "$WORK"/dem/*.tif
rm -f "$WORK/uk.bil" "$WORK/uk.hdr"
gdalwarp -q -overwrite -te -8.5 49.5 2.0 61.0 -ts 4200 6900 -r average \
  -ot Int16 -wo INIT_DEST=0 -of ENVI "$WORK/uk.vrt" "$WORK/uk.bil"

php "$HERE/split_terrain.php" "$WORK/uk.bil" "$OUT"

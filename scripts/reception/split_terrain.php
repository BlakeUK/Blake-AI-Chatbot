<?php
// Splits the ENVI int16 raster from build_terrain.sh into gzip tiles.
// Grid must match the gdalwarp call: extent lon -8.5..2.0, lat 49.5..61.0,
// 4200 x 6900 cells (1/400 deg lon x 1/600 deg lat), row 0 = north edge.
declare(strict_types=1);

[$_, $src, $out] = $argv + [null, null, null];
if (!$src || !$out) { fwrite(STDERR, "usage: split_terrain.php <uk.bil> <outdir>\n"); exit(1); }

$meta = [
    'lat_north' => 61.0, 'lon_west' => -8.5,
    'rows' => 6900, 'cols' => 4200,
    'cells_per_deg_lat' => 600, 'cells_per_deg_lon' => 400,
    'tile_rows' => 300, 'tile_cols' => 200,
    'source' => 'Copernicus DEM GLO-90',
];
$fh = fopen($src, 'rb');
if (!$fh) { fwrite(STDERR, "cannot open $src\n"); exit(1); }
if (filesize($src) !== $meta['rows'] * $meta['cols'] * 2) { fwrite(STDERR, "unexpected raster size\n"); exit(1); }
if (!is_dir($out)) mkdir($out, 0775, true);
foreach (glob("$out/t*.bin.gz") ?: [] as $f) unlink($f);

$tr = $meta['tile_rows']; $tc = $meta['tile_cols'];
$written = 0; $bytes = 0; $max = 0;
for ($tRow = 0; $tRow < $meta['rows'] / $tr; $tRow++) {
    // Read one band of tile rows at once (300 raster rows).
    fseek($fh, $tRow * $tr * $meta['cols'] * 2);
    $band = fread($fh, $tr * $meta['cols'] * 2);
    for ($tCol = 0; $tCol < $meta['cols'] / $tc; $tCol++) {
        $tile = '';
        for ($r = 0; $r < $tr; $r++) {
            $tile .= substr($band, ($r * $meta['cols'] + $tCol * $tc) * 2, $tc * 2);
        }
        $vals = unpack('s*', $tile);
        $hi = max($vals);
        if ($hi <= 0) continue; // all sea
        $max = max($max, $hi);
        $gz = gzencode($tile, 9);
        file_put_contents(sprintf('%s/t%d_%d.bin.gz', $out, $tRow, $tCol), $gz);
        $written++; $bytes += strlen($gz);
    }
}
file_put_contents("$out/meta.json", json_encode($meta, JSON_PRETTY_PRINT) . "\n");
printf("tiles=%d size=%.1fMB max_height=%dm\n", $written, $bytes / 1048576, $max);

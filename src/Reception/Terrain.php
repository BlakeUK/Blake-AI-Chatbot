<?php
// src/Reception/Terrain.php
// Reads the compressed terrain tiles built by scripts/reception/build_terrain.sh
// (Copernicus DEM GLO-90, resampled to ~185 m). Missing tiles are open sea (0 m).

declare(strict_types=1);

namespace Reception;

class Terrain
{
    private array $meta;
    private array $tiles = [];

    public function __construct(private string $dir)
    {
        $meta = @file_get_contents($dir . '/meta.json');
        $this->meta = $meta ? (json_decode($meta, true) ?: []) : [];
        if (!isset($this->meta['rows'])) {
            throw new \RuntimeException('Terrain data not installed');
        }
    }

    public static function available(string $dir): bool
    {
        return is_file($dir . '/meta.json');
    }

    // Terrain height in metres (bilinear), 0 outside the grid or at sea.
    public function height(float $lat, float $lon): float
    {
        $m = $this->meta;
        $r = ($m['lat_north'] - $lat) * $m['cells_per_deg_lat'] - 0.5;
        $c = ($lon - $m['lon_west']) * $m['cells_per_deg_lon'] - 0.5;
        $r0 = (int)floor($r); $c0 = (int)floor($c);
        $fr = $r - $r0;       $fc = $c - $c0;
        $h00 = $this->cell($r0, $c0);     $h01 = $this->cell($r0, $c0 + 1);
        $h10 = $this->cell($r0 + 1, $c0); $h11 = $this->cell($r0 + 1, $c0 + 1);
        return ($h00 * (1 - $fc) + $h01 * $fc) * (1 - $fr) + ($h10 * (1 - $fc) + $h11 * $fc) * $fr;
    }

    private function cell(int $r, int $c): int
    {
        $m = $this->meta;
        if ($r < 0 || $c < 0 || $r >= $m['rows'] || $c >= $m['cols']) return 0;
        $tr = intdiv($r, $m['tile_rows']); $tc = intdiv($c, $m['tile_cols']);
        $key = "$tr,$tc";
        if (!array_key_exists($key, $this->tiles)) {
            $f = sprintf('%s/t%d_%d.bin.gz', $this->dir, $tr, $tc);
            $this->tiles[$key] = is_file($f) ? (gzdecode((string)file_get_contents($f)) ?: null) : null;
        }
        $tile = $this->tiles[$key];
        if ($tile === null) return 0;
        $off = (($r % $m['tile_rows']) * $m['tile_cols'] + ($c % $m['tile_cols'])) * 2;
        $v = ord($tile[$off]) | (ord($tile[$off + 1]) << 8);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
}

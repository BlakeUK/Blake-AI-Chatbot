<?php
// src/Reception/Predictor.php
// Estimates DTT (Freeview) field strength at a location from every nearby
// Ofcom transmitter, using terrain profiles, and turns the best result into
// an aerial recommendation.
//
// Model (deliberately simple, documented so it can be tuned):
//   E = 106.9 + 10log10(ERP kW) - 20log10(d km)       free space, dBuV/m
//       - ground loss (empirical, grows with distance, capped)
//       - terrain diffraction loss (Deygout 3-edge, ITU-R P.526 knife edge,
//         4/3 effective earth radius, receive aerial 10 m above ground)
// Terrain is bare earth at ~185 m resolution: buildings and trees are not
// modelled, so results are an estimate and always come with the Freeview
// checker link for confirmation.

declare(strict_types=1);

namespace Reception;

class Predictor
{
    public const RX_HEIGHT_M       = 10.0;
    public const MAX_RANGE_KM      = 160.0;
    public const MIN_USEFUL_DBUV   = 40.0;
    private const EFFECTIVE_RADIUS = 6371000.0 * 4 / 3;
    private const SAMPLE_SPACING_M = 150.0;

    public function __construct(private array $sites, private ?Terrain $terrain) {}

    // Loads the installed data (data/reception/). Returns null if not installed.
    public static function fromDataDir(string $dir): ?self
    {
        $json = @file_get_contents($dir . '/transmitters.json');
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data['sites'] ?? null)) return null;
        $terrain = Terrain::available($dir . '/terrain') ? new Terrain($dir . '/terrain') : null;
        return new self($data['sites'], $terrain);
    }

    // Ranked predictions for a location, strongest first (max $limit).
    public function predict(float $lat, float $lon, int $limit = 5): array
    {
        $rxGround = $this->terrain ? $this->terrain->height($lat, $lon) : 0.0;
        $results = [];
        foreach ($this->sites as $s) {
            $d = Geo::distanceKm($lat, $lon, $s['lat'], $s['lon']);
            if ($d > self::MAX_RANGE_KM) continue;
            $d = max($d, 0.2);
            $erp = self::psbErp($s);
            if ($erp <= 0) continue;
            $fs = self::freeSpace($erp, $d);
            // Cheap pre-filter: skip anything that can't be useful even unobstructed.
            if ($fs - self::groundLoss($d) < self::MIN_USEFUL_DBUV) continue;

            $freq = self::psbFrequencyMhz($s);
            $diff = $this->terrain
                ? $this->diffractionLoss($s, $lat, $lon, $rxGround, $d * 1000, $freq)
                : 0.0;
            $field = $fs - self::groundLoss($d) - $diff;
            if ($field < self::MIN_USEFUL_DBUV) continue;

            $results[] = [
                'name'            => $s['name'],
                'group'           => $s['group'],
                'distance_km'     => round($d, 1),
                'bearing_deg'     => (int)round(Geo::bearing($lat, $lon, $s['lat'], $s['lon'])),
                'bearing_compass' => Geo::compass(Geo::bearing($lat, $lon, $s['lat'], $s['lon'])),
                'polarisation'    => $s['polarisation'] === 'V' ? 'vertical' : 'horizontal',
                'aerial_group'    => $s['aerial_group'] ?: self::groupFor(array_column($s['muxes'], 'ch')),
                'channels'        => array_column($s['muxes'], 'ch'),
                'erp_kw'          => $erp,
                'muxes'           => count($s['muxes']),
                'full_service'    => count($s['muxes']) >= 6,
                'field_dbuv'      => round($field, 1),
                'terrain_loss_db' => round($diff, 1),
                'itv_region'      => $s['itv_region'] ?? '',
                'bbc_region'      => $s['bbc_region'] ?? '',
            ];
        }
        usort($results, fn($a, $b) => $b['field_dbuv'] <=> $a['field_dbuv']);
        return array_slice($results, 0, $limit);
    }

    // Picks the transmitter to recommend and the aerial class for it.
    // Diffracted (terrain-shadowed) paths vary a lot in practice, so a
    // conservative figure is used for decisions: field minus a share of the
    // terrain loss. A full-service (6 multiplex) transmitter is preferred
    // over a stronger 3-multiplex relay while a standard Yagi still works, because
    // customers expect all Freeview channels.
    public static function recommend(array $predictions): ?array
    {
        if (!$predictions) return null;
        foreach ($predictions as &$p) {
            $p['design_dbuv'] = round($p['field_dbuv'] - min(10.0, 0.35 * $p['terrain_loss_db']), 1);
        }
        unset($p);
        usort($predictions, fn($a, $b) => $b['design_dbuv'] <=> $a['design_dbuv']);
        $best = $predictions[0];
        $pick = $best;
        if (!$best['full_service']) {
            foreach ($predictions as $p) {
                if ($p['full_service'] && $p['design_dbuv'] >= 68) { $pick = $p; break; }
            }
        }
        $alt = null;
        foreach ($predictions as $p) {
            if ($p['name'] !== $pick['name']) { $alt = $p; break; }
        }
        return [
            'transmitter'  => $pick,
            'alternative'  => $alt,
            'aerial'       => self::aerialFor($pick['design_dbuv']),
            'alternative_aerial' => $alt ? self::aerialFor($alt['design_dbuv']) : null,
            'terrain_note' => $pick['terrain_loss_db'] >= 10,
        ];
    }

    // Signal classes -> aerial type. Thresholds are dBuV/m at 10 m AGL and
    // include ~8 dB margin over 50%-location predictions for local variation
    // and clutter (buildings/trees are not in the terrain data).
    public static function aerialFor(float $field): array
    {
        return match (true) {
            $field >= 95 => ['signal' => 'very strong', 'type' => 'log-periodic', 'amplifier' => false,
                             'note' => 'Signal is very strong: a compact or log-periodic aerial is enough, and an attenuator may be needed if an amplifier is used.'],
            $field >= 78 => ['signal' => 'strong', 'type' => 'log-periodic', 'amplifier' => false, 'note' => ''],
            $field >= 68 => ['signal' => 'moderate', 'type' => 'yagi', 'amplifier' => false, 'note' => ''],
            $field >= 58 => ['signal' => 'weak', 'type' => 'high-gain', 'amplifier' => false,
                             'note' => 'Mount the aerial as high as practical with a clear view towards the transmitter.'],
            $field >= 50 => ['signal' => 'very weak', 'type' => 'high-gain', 'amplifier' => true,
                             'note' => 'Use a high-gain aerial mounted high, with a low-noise masthead amplifier.'],
            default      => ['signal' => 'marginal', 'type' => 'high-gain', 'amplifier' => true,
                             'note' => 'Terrestrial reception may be unreliable here; satellite (Freesat) is worth considering.'],
        };
    }

    public static function groupFor(array $channels): string
    {
        if (!$channels) return '';
        $lo = min($channels); $hi = max($channels);
        if ($lo >= 21 && $hi <= 37) return 'A';
        if ($lo >= 35 && $hi <= 53) return 'B';
        if ($lo >= 21 && $hi <= 48) return 'K';
        return 'W';
    }

    public static function freeSpace(float $erpKw, float $dKm): float
    {
        return 106.9 + 10 * log10($erpKw) - 20 * log10($dKm);
    }

    // Empirical extra loss over land vs free space (roughly tracks ITU-R
    // P.1546 50% curves for UHF), capped so long paths lean on the terrain
    // diffraction term rather than double counting.
    public static function groundLoss(float $dKm): float
    {
        if ($dKm <= 8) return 0.0;
        return min(18.0, 22.0 * pow(log10($dKm / 8), 1.3));
    }

    // ITU-R P.526 single knife-edge loss J(v), dB.
    public static function knifeEdge(float $v): float
    {
        if ($v <= -0.78) return 0.0;
        return 6.9 + 20 * log10(sqrt(($v - 0.1) ** 2 + 1) + $v - 0.1);
    }

    private static function psbErp(array $s): float
    {
        $psb = array_filter($s['muxes'], fn($m) => str_starts_with($m['mux'], 'PSB'));
        $use = $psb ?: $s['muxes'];
        return (float)max(array_column($use, 'erp_kw'));
    }

    private static function psbFrequencyMhz(array $s): float
    {
        $chs = array_column($s['muxes'], 'ch');
        return 8 * (array_sum($chs) / count($chs)) + 306;
    }

    // Deygout 3-edge diffraction loss over the terrain profile, dB.
    private function diffractionLoss(array $s, float $lat, float $lon, float $rxGround, float $dM, float $freqMhz): float
    {
        $lambda = 299.792458 / $freqMhz;
        $n = (int)max(20, min(600, ceil($dM / self::SAMPLE_SPACING_M)));
        // Profile from transmitter (x=0) to receiver (x=d): heights incl. earth bulge.
        $xs = []; $hs = [];
        for ($i = 1; $i < $n; $i++) {
            $f = $i / $n;
            $x = $f * $dM;
            $h = $this->terrain->height(
                $s['lat'] + ($lat - $s['lat']) * $f,
                $s['lon'] + ($lon - $s['lon']) * $f
            );
            $xs[] = $x;
            $hs[] = $h + $x * ($dM - $x) / (2 * self::EFFECTIVE_RADIUS);
        }
        $txH = $s['site_height'] + $s['ant_height'];
        $rxH = $rxGround + self::RX_HEIGHT_M;

        [$idx, $v] = self::worstEdge($xs, $hs, 0, count($xs) - 1, 0.0, $txH, $dM, $rxH, $lambda);
        if ($idx === null) return 0.0;
        $loss = self::knifeEdge($v);
        // Secondary edges only matter when the main edge actually blocks the
        // path. Samples next to the main edge are skipped (a broad hilltop is
        // one obstacle, not several) and only obstructing sub-edges count,
        // otherwise a clear path with partial Fresnel clearance, or a flat
        // summit, picks up spurious ~6 dB losses.
        if ($v > 0) {
            $ex = $xs[$idx]; $eh = $hs[$idx];
            $gap = (int)ceil(max(300.0, 0.05 * $dM) / self::SAMPLE_SPACING_M);
            [, $v1] = self::worstEdge($xs, $hs, 0, $idx - $gap, 0.0, $txH, $ex, $eh, $lambda);
            [, $v2] = self::worstEdge($xs, $hs, $idx + $gap, count($xs) - 1, $ex, $eh, $dM, $rxH, $lambda);
            if (($v1 ?? -1) > 0) $loss += self::knifeEdge($v1);
            if (($v2 ?? -1) > 0) $loss += self::knifeEdge($v2);
        }
        return min($loss, 60.0);
    }

    // Edge with the largest Fresnel-Kirchhoff v between two end points.
    private static function worstEdge(array $xs, array $hs, int $from, int $to,
                                      float $x1, float $h1, float $x2, float $h2, float $lambda): array
    {
        $bestI = null; $bestV = -INF;
        for ($i = $from; $i <= $to; $i++) {
            $d1 = $xs[$i] - $x1; $d2 = $x2 - $xs[$i];
            if ($d1 <= 0 || $d2 <= 0) continue;
            $line = $h1 + ($h2 - $h1) * $d1 / ($x2 - $x1);
            $v = ($hs[$i] - $line) * sqrt(2 * ($d1 + $d2) / ($lambda * $d1 * $d2));
            if ($v > $bestV) { $bestV = $v; $bestI = $i; }
        }
        return [$bestI, $bestI === null ? null : $bestV];
    }
}

<?php
// src/Reception/RadioPredictor.php
// FM (VHF Band II, 87.5-108 MHz) and DAB (Band III, ~174-240 MHz) reception
// prediction from Ofcom's radio transmitter parameters (data/reception/fm.json
// and dab.json, built by scripts/reception/build_radio.php). Same physics as
// the TV predictor - free-space field, distance/ground correction and
// terrain diffraction - at the band's own frequency, with radio-specific
// thresholds and aerial advice.
//
// FM matters differently from TV: relays are often vertically polarised, and
// a stereo signal needs far more field strength than mono, so the aerial
// advice follows the predicted field and the polarisation of the best site.

declare(strict_types=1);

namespace Reception;

class RadioPredictor extends Predictor
{
    public const FM_DEFAULT_MHZ  = 98.0;
    public const DAB_DEFAULT_MHZ = 220.0;
    public const MIN_USEFUL_FM   = 34.0;   // dBuV/m: below this not worth listing
    public const MIN_USEFUL_DAB  = 22.0;

    public function __construct(array $sites, ?Terrain $terrain, private string $band)
    {
        parent::__construct($sites, $terrain);
    }

    public static function forBand(string $dir, string $band): ?self
    {
        $band = strtolower($band) === 'dab' ? 'dab' : 'fm';
        $json = @file_get_contents("{$dir}/{$band}.json");
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data['sites'] ?? null) || !$data['sites']) return null;
        $terrain = Terrain::available($dir . '/terrain') ? new Terrain($dir . '/terrain') : null;
        return new self($data['sites'], $terrain, $band);
    }

    public function predict(float $lat, float $lon, int $limit = 5): array
    {
        $minUseful = $this->band === 'dab' ? self::MIN_USEFUL_DAB : self::MIN_USEFUL_FM;
        $rxGround  = $this->terrain ? $this->terrain->height($lat, $lon) : 0.0;
        $results = [];
        foreach ($this->sites as $s) {
            $d = Geo::distanceKm($lat, $lon, $s['lat'], $s['lon']);
            if ($d > self::MAX_RANGE_KM) continue;
            $d = max($d, 0.2);
            $erp = (float)($s['erp_kw'] ?? 0);
            if ($erp <= 0) continue;
            $fs = self::freeSpace($erp, $d) - self::groundLoss($d);
            if ($fs < $minUseful) continue;

            $freq = (float)($s['freq_mhz'] ?? 0) ?: ($this->band === 'dab' ? self::DAB_DEFAULT_MHZ : self::FM_DEFAULT_MHZ);
            $site = $s + ['site_height' => 0.0, 'ant_height' => 30.0];
            $diff = $this->terrain ? $this->diffractionLoss($site, $lat, $lon, $rxGround, $d * 1000, $freq) : 0.0;
            $field = $fs - $diff;
            if ($field < $minUseful) continue;

            $pol = ['V' => 'vertical', 'M' => 'mixed', 'H' => 'horizontal'][$s['polarisation'] ?? 'H'] ?? 'horizontal';
            $results[] = [
                'name'            => $s['name'],
                'area'            => $s['area'] ?? '',
                'distance_km'     => round($d, 1),
                'bearing_deg'     => (int)round(Geo::bearing($lat, $lon, $s['lat'], $s['lon'])),
                'bearing_compass' => Geo::compass(Geo::bearing($lat, $lon, $s['lat'], $s['lon'])),
                'polarisation'    => $pol,
                'erp_kw'          => $erp,
                'freq_mhz'        => $freq,
                'services'        => array_slice($s['services'] ?? [], 0, 8),
                'field_dbuv'      => round($field, 1),
                'terrain_loss_db' => round($diff, 1),
            ];
        }
        usort($results, fn($a, $b) => $b['field_dbuv'] <=> $a['field_dbuv']);
        return array_slice($results, 0, $limit);
    }

    // Aerial advice per band. FM thresholds are for reliable stereo at the
    // receiver; DAB needs less field but the aerial must be vertical.
    public static function aerialForBand(float $field, string $band): array
    {
        if ($band === 'dab') {
            return match (true) {
                $field >= 60 => ['signal' => 'very strong', 'type' => 'dab', 'size' => 'smallest', 'elements' => 'a simple DAB dipole (indoor or loft) is enough',
                                 'note' => 'Signal is very strong: an indoor or loft DAB dipole will normally work.'],
                $field >= 48 => ['signal' => 'strong', 'type' => 'dab', 'size' => 'small', 'elements' => 'a loft or outdoor DAB dipole',
                                 'note' => 'A dipole in the loft is usually enough; move it outdoors if the building is well insulated (foil-backed plasterboard blocks DAB).'],
                $field >= 38 => ['signal' => 'moderate', 'type' => 'dab', 'size' => 'mid', 'elements' => 'an outdoor 3-element DAB Yagi',
                                 'note' => 'Mount outdoors, vertically polarised, pointing at the transmitter.'],
                $field >= 28 => ['signal' => 'weak', 'type' => 'dab', 'size' => 'largest', 'elements' => 'a high-gain outdoor DAB Yagi as high as practical',
                                 'note' => 'Use good quality low-loss coax and keep the run short; a masthead amplifier can help but only if the signal is clean.'],
                default      => ['signal' => 'marginal', 'type' => 'dab', 'size' => 'largest', 'elements' => 'a high-gain outdoor DAB Yagi, though reception may still be unreliable',
                                 'note' => 'DAB may be unreliable here; internet radio or FM may be the better option.'],
            };
        }
        return match (true) {
            $field >= 70 => ['signal' => 'very strong', 'type' => 'fm', 'size' => 'smallest', 'elements' => 'a simple FM dipole (indoor or loft)',
                             'note' => 'Signal is very strong: an indoor or loft dipole gives full stereo.'],
            $field >= 58 => ['signal' => 'strong', 'type' => 'fm', 'size' => 'small', 'elements' => 'a loft or outdoor FM dipole',
                             'note' => 'Plenty for reliable stereo with a simple dipole.'],
            $field >= 48 => ['signal' => 'moderate', 'type' => 'fm', 'size' => 'mid', 'elements' => 'an outdoor 3-element FM Yagi',
                             'note' => 'A 3-element outdoor aerial gives reliable stereo and rejects multipath.'],
            $field >= 40 => ['signal' => 'weak', 'type' => 'fm', 'size' => 'largest', 'elements' => 'a high-gain outdoor FM Yagi (5 element or more) mounted high',
                             'note' => 'Stereo needs a much stronger signal than mono; a directional aerial mounted high is important here.'],
            default      => ['signal' => 'marginal', 'type' => 'fm', 'size' => 'largest', 'elements' => 'a high-gain outdoor FM Yagi, and stereo may still be noisy',
                             'note' => 'Reliable stereo is unlikely here; consider DAB or internet radio for these stations.'],
        };
    }

    // Best site, plus the strongest alternative on a different bearing.
    public static function recommendRadio(array $predictions, string $band): ?array
    {
        if (!$predictions) return null;
        $best = $predictions[0];
        $alt = null;
        foreach ($predictions as $p) {
            if ($p['name'] !== $best['name'] && abs($p['bearing_deg'] - $best['bearing_deg']) > 20) { $alt = $p; break; }
        }
        return [
            'band'         => $band,
            'transmitter'  => $best,
            'alternative'  => $alt,
            'aerial'       => self::aerialForBand((float)$best['field_dbuv'], $band),
            'terrain_note' => $best['terrain_loss_db'] >= 10,
        ];
    }
}

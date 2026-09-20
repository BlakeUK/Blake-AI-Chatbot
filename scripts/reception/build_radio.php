<?php
// scripts/reception/build_radio.php
// Builds data/reception/fm.json and dab.json from Ofcom's radio transmitter
// parameters (Open Government Licence v3.0, "Contains Ofcom data (c) Ofcom"):
//   https://www.ofcom.org.uk/tv-radio-and-on-demand/coverage-and-transmitters/radio-tech-parameters
// Run ON THE VPS (Ofcom blocks CI runners), monthly or on demand:
//   php scripts/reception/build_radio.php [outdir] [--dump-header]
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Reception/Geo.php';

const VHF_CSV = 'https://www.ofcom.org.uk/siteassets/resources/documents/spectrum/tv-transmitter-guidance/tech-parameters/txparamsvhf.csv';
const DAB_CSV = 'https://www.ofcom.org.uk/siteassets/resources/documents/spectrum/tv-transmitter-guidance/tech-parameters/txparamsdab.csv';

$outDir = $argv[1] ?? dirname(__DIR__, 2) . '/data/reception';
$dumpHeader = in_array('--dump-header', $argv, true);

function fetch_csv(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/csv,*/*', 'Accept-Language: en-GB,en;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || str_starts_with(ltrim($body), '<')) {
        fwrite(STDERR, "download failed (HTTP {$code}): {$url}\n");
        exit(1);
    }
    return $body;
}

// CSV -> [header, rows]; Ofcom files are Windows-1252 with a couple of header rows.
function parse_csv(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    $rows = array_map(fn($l) => str_getcsv($l), $lines);
    // The header is the first row that mentions a grid reference / site name.
    $headerIdx = 0;
    foreach ($rows as $i => $r) {
        $join = mb_strtolower(implode('|', $r));
        if (str_contains($join, 'ngr') || (str_contains($join, 'site') && str_contains($join, 'freq'))) { $headerIdx = $i; break; }
    }
    $header = array_map(fn($h) => trim((string)$h), $rows[$headerIdx]);
    return [$header, array_slice($rows, $headerIdx + 1)];
}

// Finds a column whose name contains all the given words (case-insensitive).
function col(array $header, array $words, ?int $default = null): ?int
{
    foreach ($header as $i => $h) {
        $l = mb_strtolower($h);
        $ok = true;
        foreach ($words as $w) if (!str_contains($l, mb_strtolower($w))) { $ok = false; break; }
        if ($ok) return $i;
    }
    return $default;
}

function ngr_to_en(string $ngr): ?array
{
    $ngr = strtoupper(preg_replace('/\s+/', '', $ngr));
    if (!preg_match('/^([A-Z]{2})(\d+)$/', $ngr, $m)) return null;
    $letters = $m[1]; $digits = $m[2];
    if (strlen($digits) % 2 !== 0) return null;
    $l1 = ord($letters[0]) - 65; if ($l1 > 7) $l1--;   // no I
    $l2 = ord($letters[1]) - 65; if ($l2 > 7) $l2--;
    $e = ((($l1 - 2) % 5) * 5) + ($l2 % 5);
    $n = (19 - intdiv($l1, 5) * 5) - intdiv($l2, 5);
    $half = intdiv(strlen($digits), 2);
    $scale = 10 ** (5 - $half);
    return [($e * 100000) + (int)substr($digits, 0, $half) * $scale,
            ($n * 100000) + (int)substr($digits, $half) * $scale];
}

function num(?string $v): float
{
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/-?\d/', $v)) return 0.0;
    return (float)preg_replace('/[^0-9.\-]/', '', $v);
}

function build(string $raw, string $band, bool $dumpHeader): array
{
    [$header, $rows] = parse_csv($raw);
    if ($dumpHeader) fwrite(STDERR, "[{$band}] columns: " . implode(' | ', $header) . "\n");

    $cSite  = col($header, ['site']) ?? col($header, ['station']);
    $cNgr   = col($header, ['ngr']);
    $cFreq  = col($header, ['freq']);
    // Column names differ between the two files ("Site Ht" vs "Site Height",
    // "In-Use Aerial Ht" vs "In-Use AeHt", per-polarisation ERP vs total).
    $cSiteH = col($header, ['site', 'height']) ?? col($header, ['site', 'ht']);
    $cAntH  = col($header, ['aerial', 'ht']) ?? col($header, ['aerial', 'height']) ?? col($header, ['ae', 'ht']) ?? col($header, ['antenna', 'height']);
    $cErpH  = col($header, ['in-use', 'erp', 'hp']) ?? col($header, ['erp', 'hp']);
    $cErpV  = col($header, ['in-use', 'erp', 'vp']) ?? col($header, ['erp', 'vp']);
    $cErpT  = col($header, ['erp', 'total']) ?? col($header, ['erp', 'kw']);
    $cLat   = col($header, ['lat']);
    $cLon   = col($header, ['long']);
    $cName  = col($header, ['station']) ?? col($header, ['ensemble']) ?? col($header, ['service']);
    $cArea  = col($header, ['area']);
    $cEns   = col($header, ['ensemble']) ?? col($header, ['multiplex']) ?? col($header, ['emb']);
    $cBlock = col($header, ['block']) ?? $cFreq;
    if ($cSite === null || $cNgr === null) {
        fwrite(STDERR, "[{$band}] could not find site/NGR columns: " . implode(' | ', $header) . "\n");
        exit(1);
    }

    $sites = [];
    foreach ($rows as $r) {
        $site = trim((string)($r[$cSite] ?? ''));
        if ($site === '') continue;
        $en = ngr_to_en((string)($r[$cNgr] ?? ''));
        $lat = $lon = null;
        if ($en) {
            [$lat, $lon] = \Reception\Geo::gridToWgs84((float)$en[0], (float)$en[1]);
        } elseif ($cLat !== null && $cLon !== null) {
            $lat = num($r[$cLat] ?? ''); $lon = num($r[$cLon] ?? '');
            if ($lat < 49 || $lat > 61) continue;
        } else {
            continue;
        }
        $erpH = $cErpH !== null ? num($r[$cErpH] ?? '') : 0.0;
        $erpV = $cErpV !== null ? num($r[$cErpV] ?? '') : 0.0;
        if ($erpH <= 0 && $erpV <= 0 && $cErpT !== null) {
            // DAB publishes a single total ERP; DAB is vertical only.
            $erpV = num($r[$cErpT] ?? '');
        }
        if ($band === 'dab' && $erpV <= 0 && $erpH > 0) { $erpV = $erpH; $erpH = 0.0; }
        $erp = max($erpH, $erpV);
        if ($erp <= 0) continue;
        $freq = $cFreq !== null ? num($r[$cFreq] ?? '') : 0.0;
        if ($band === 'fm' && ($freq < 87 || $freq > 109)) continue;    // skip MF/other rows

        $key = $site . '|' . round($lat, 4) . '|' . round($lon, 4);
        $service = trim((string)($r[$cName ?? $cSite] ?? ''));
        $ensemble = $cEns !== null ? trim((string)($r[$cEns] ?? '')) : '';
        if (!isset($sites[$key])) {
            $sites[$key] = [
                'name'         => $site,
                'area'         => $cArea !== null ? trim((string)($r[$cArea] ?? '')) : '',
                'lat'          => round($lat, 5),
                'lon'          => round($lon, 5),
                'site_height'  => $cSiteH !== null ? num($r[$cSiteH] ?? '') : 0.0,
                'ant_height'   => $cAntH !== null ? num($r[$cAntH] ?? '') : 30.0,
                'polarisation' => $band === 'dab' ? 'V' : ($erpV > $erpH ? 'V' : ($erpH > 0 && $erpV > 0 ? 'M' : 'H')),
                'erp_kw'       => 0.0,
                'services'     => [],
            ];
        }
        $s = &$sites[$key];
        $s['erp_kw'] = max($s['erp_kw'], round($erp, 4));
        if ($band === 'fm' && $erpH > 0 && $erpV > 0) $s['polarisation'] = 'M';   // mixed
        elseif ($band === 'fm' && $erpV > $erpH) $s['polarisation'] = 'V';
        $label = $band === 'dab'
            ? trim($ensemble . ($freq ? ' (' . rtrim(rtrim(number_format($freq, 3, '.', ''), '0'), '.') . ' MHz)' : ''))
            : trim($service . ($freq ? ' ' . number_format($freq, 1) . ' FM' : ''));
        if ($label !== '' && !in_array($label, $s['services'], true) && count($s['services']) < 25) {
            $s['services'][] = $label;
        }
        if ($freq > 0) $s['freq_mhz'] = $band === 'fm' ? round($freq, 2) : round($freq, 3);
        unset($s);
    }
    $out = array_values(array_filter($sites, fn($s) => $s['erp_kw'] > 0));
    usort($out, fn($a, $b) => $b['erp_kw'] <=> $a['erp_kw']);
    return $out;
}

@mkdir($outDir, 0775, true);
foreach ([['fm', VHF_CSV], ['dab', DAB_CSV]] as [$band, $url]) {
    $sites = build(fetch_csv($url), $band, $dumpHeader);
    $file = "{$outDir}/{$band}.json";
    file_put_contents($file, json_encode([
        'band'       => $band,
        'source'     => $url,
        'licence'    => 'Contains Ofcom data (c) Ofcom, Open Government Licence v3.0',
        'built_at'   => gmdate('c'),
        'sites'      => $sites,
    ], JSON_UNESCAPED_SLASHES));
    printf("%s: %d sites -> %s (%d bytes)\n", strtoupper($band), count($sites), $file, filesize($file));
}

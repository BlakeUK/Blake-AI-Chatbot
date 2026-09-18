<?php
// Converts Ofcom's DTT transmitter spreadsheet (Open Government Licence v3.0,
// "Contains Ofcom data (c) Ofcom") into data/reception/transmitters.json for
// \Reception\Predictor. Uses the PSB_COM_Muxes sheet only.
//
// usage: php build_transmitters.php <ofcom.xlsx> <out.json>
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Reception/Geo.php';

[$_, $src, $out] = $argv + [null, null, null];
if (!$src || !$out) { fwrite(STDERR, "usage: build_transmitters.php <xlsx> <out.json>\n"); exit(1); }

$sheet = xlsx_sheet($src, 'PSB_COM_Muxes');
$header = array_map('trim', $sheet[1] ?? []);
$col = fn(string $name) => array_search($name, $header, true);
$need = ['Transmitter Group', 'Site Number', 'Site Name', 'GBNGR', 'GBNGRX', 'GBNGRY',
         'Site Height (m)', 'Polarisation', 'Aerial Group', 'ITV Region', 'BBC Region'];
foreach ($need as $n) {
    if ($col($n) === false) { fwrite(STDERR, "missing column: $n\n"); exit(1); }
}
$antCol = null;
foreach ($header as $i => $h) if (stripos($h, 'Antenna Height') !== false) $antCol = $i;
if ($antCol === null) { fwrite(STDERR, "missing antenna height column\n"); exit(1); }

$sites = []; $skipped = 0;
foreach ($sheet as $rowNo => $r) {
    if ($rowNo === 1) continue;
    $get = fn(string $n) => trim((string)($r[$col($n)] ?? ''));
    $name = $get('Site Name');
    if ($name === '') continue;

    $en = parse_gbngr($get('GBNGR'));
    if (!$en && (float)$get('GBNGRX') > 0 && (float)$get('GBNGRY') > 0) {
        $en = [(float)$get('GBNGRX'), (float)$get('GBNGRY')];
    }
    if (!$en) { $skipped++; continue; }
    [$lat, $lon] = \Reception\Geo::gridToWgs84($en[0], $en[1]);

    $muxes = [];
    foreach (['PSB1', 'PSB2', 'PSB3', 'COM4', 'COM5', 'COM6'] as $m) {
        $ch  = $col("$m Channel");  $erp = $col("$m ERP (kW)");
        if ($ch === false || $erp === false) continue;
        $chV = trim((string)($r[$ch] ?? '')); $erpV = trim((string)($r[$erp] ?? ''));
        if ($chV === '' || !is_numeric($chV) || !is_numeric($erpV)) continue;
        $muxes[] = ['mux' => $m, 'ch' => (int)$chV, 'erp_kw' => round((float)$erpV, 5)];
    }
    if (!$muxes) { $skipped++; continue; }

    $group = $get('Transmitter Group');
    $sites[] = [
        'id'           => $get('Site Number'),
        'name'         => $name,
        'group'        => $group,
        'primary'      => strcasecmp($group, $name) === 0,
        'itv_region'   => $get('ITV Region'),
        'bbc_region'   => $get('BBC Region'),
        'lat'          => round($lat, 5),
        'lon'          => round($lon, 5),
        'site_height'  => (float)$get('Site Height (m)'),
        'ant_height'   => (float)trim((string)($r[$antCol] ?? '0')),
        'polarisation' => strtoupper($get('Polarisation')),
        'aerial_group' => strtoupper($get('Aerial Group')),
        'muxes'        => $muxes,
    ];
}

if (count($sites) < 500) { fwrite(STDERR, "only " . count($sites) . " sites parsed - format changed?\n"); exit(1); }
if (!is_dir(dirname($out))) mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode([
    'source'    => 'Ofcom DTT transmitter frequency data (Open Government Licence v3.0)',
    'generated' => gmdate('c'),
    'sites'     => $sites,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("sites=%d primary=%d skipped=%d\n", count($sites), count(array_filter($sites, fn($s) => $s['primary'])), $skipped);

// Alphanumeric OS grid ref (e.g. "TL20474944") -> [easting, northing].
function parse_gbngr(string $ref): ?array
{
    $ref = strtoupper(preg_replace('/\s+/', '', $ref));
    if (!preg_match('/^([A-HJ-Z])([A-HJ-Z])(\d+)$/', $ref, $m) || strlen($m[3]) % 2) return null;
    $l1 = ord($m[1]) - 65; $l2 = ord($m[2]) - 65;
    if ($l1 > 7) $l1--;
    if ($l2 > 7) $l2--;
    $e100 = ((($l1 - 2) % 5 + 5) % 5) * 5 + ($l2 % 5);
    $n100 = (19 - intdiv($l1, 5) * 5) - intdiv($l2, 5);
    $half = strlen($m[3]) / 2;
    $e = (int)str_pad(substr($m[3], 0, $half), 5, '0');
    $n = (int)str_pad(substr($m[3], $half), 5, '0');
    return [$e100 * 100000 + $e, $n100 * 100000 + $n];
}

function xlsx_sheet(string $path, string $want): array
{
    $z = new ZipArchive;
    if ($z->open($path) !== true) throw new RuntimeException('not an xlsx');
    $ss = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        foreach (simplexml_load_string($x)->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            if (!isset($si->t)) foreach ($si->r as $run) $t .= (string)$run->t;
            $ss[] = $t;
        }
    }
    $wb = simplexml_load_string($z->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels'));
    $map = [];
    foreach ($rels->Relationship as $rel) $map[(string)$rel['Id']] = (string)$rel['Target'];
    foreach ($wb->sheets->sheet as $s) {
        if ((string)$s['name'] !== $want) continue;
        $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $t = ltrim($map[$rid], '/');
        if (!str_starts_with($t, 'xl/')) $t = 'xl/' . $t;
        $rows = [];
        foreach (simplexml_load_string($z->getFromName($t))->sheetData->row as $row) {
            $vals = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
                $ci = 0;
                foreach (str_split($m[1]) as $ch) $ci = $ci * 26 + ord($ch) - 64;
                $v = isset($c->v) ? (string)$c->v : (isset($c->is->t) ? (string)$c->is->t : '');
                if ((string)$c['t'] === 's') $v = $ss[(int)$v] ?? '';
                $vals[$ci - 1] = $v;
            }
            $rows[(int)$row['r']] = $vals;
        }
        return $rows;
    }
    throw new RuntimeException("sheet $want not found");
}

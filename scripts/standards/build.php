<?php
// scripts/standards/build.php - builds scripts/standards/chunks.json.gz from
// the DVB/ETSI documents in manifest.json (download -> pdftotext -layout ->
// clause chunks). Run on a machine that can reach etsi.org (ETSI blocks some
// server ranges, so the output is committed and the VPS only imports it).
// Usage: php scripts/standards/build.php [cache_dir]
require dirname(__DIR__, 2) . '/src/Knowledge/StandardsChunker.php';

$dir   = __DIR__;
$cache = $argv[1] ?? sys_get_temp_dir() . '/dvb-standards';
@mkdir($cache, 0775, true);
$manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
$out = ['built_at' => gmdate('c'), 'documents' => []];
foreach ($manifest['documents'] as $d) {
    $pdf = $cache . '/' . basename($d['url']);
    if (!is_file($pdf) || filesize($pdf) < 10000) {
        $ch = curl_init($d['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 180]);
        $bin = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200 || !str_starts_with((string)$bin, '%PDF')) { fwrite(STDERR, "download failed ({$code}): {$d['url']}\n"); exit(1); }
        file_put_contents($pdf, $bin);
    }
    $txt = shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdf) . ' - 2>/dev/null');
    $chunks = \Knowledge\StandardsChunker::chunk((string)$txt, ['code' => $d['code'], 'short' => $d['short']]);
    $d['chunks'] = array_map(fn($c) => ['clause' => $c['clause'], 'title' => $c['title'], 'text' => $c['text']], $chunks);
    $out['documents'][] = $d;
    fwrite(STDERR, sprintf("%-20s %-18s %4d chunks\n", $d['code'], $d['short'], count($chunks)));
}
file_put_contents("$dir/chunks.json.gz", gzencode(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 9));
fwrite(STDERR, 'wrote ' . filesize("$dir/chunks.json.gz") . " bytes\n");

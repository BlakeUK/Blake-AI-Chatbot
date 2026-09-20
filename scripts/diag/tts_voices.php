<?php
// scripts/diag/tts_voices.php - synthesises the same line with several
// prebuilt Gemini voices so the audio can be compared. Prints one line per
// voice: VOICE <name> <bytes> <sha1> then the base64 WAV (for analysis).
// Usage: php scripts/diag/tts_voices.php [Voice,Voice,...] [--with-style]
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$voices = explode(',', $argv[1] ?? 'Charon,Puck,Fenrir,Iapetus,Algieba');
$withStyle = in_array('--with-style', $argv, true);
$cfg = \Speech\Speaker::settings();
$key = \Gemini\Client::getStoredApiKey();
$text = 'Blake UK support, this is Max speaking. How can I help you today?';

// Rough voice fingerprint: duration, loudness, zero-crossing rate and
// average pitch (autocorrelation per 40 ms frame, voiced frames only).
// Two different voices differ clearly in pitch; the same voice twice does not.
function pcm_stats(string $pcm, int $rate): array
{
    $n = intdiv(strlen($pcm), 2);
    $x = unpack('s*', substr($pcm, 0, $n * 2));
    $frame = (int)($rate * 0.04);
    $f0s = []; $sumSq = 0; $zc = 0;
    for ($i = 1; $i < $n; $i++) {
        $sumSq += $x[$i] * $x[$i];
        if (($x[$i] >= 0) !== ($x[$i + 1] >= 0)) $zc++;
    }
    for ($start = 0; $start + $frame < $n; $start += $frame) {
        $e = 0;
        for ($i = 0; $i < $frame; $i++) $e += $x[$start + $i + 1] ** 2;
        if (sqrt($e / $frame) < 800) continue;                    // silence/unvoiced
        $best = 0; $bestLag = 0;
        $minLag = (int)($rate / 320); $maxLag = (int)($rate / 70);  // 70-320 Hz
        for ($lag = $minLag; $lag <= $maxLag; $lag++) {
            $c = 0;
            for ($i = 0; $i + $lag < $frame; $i += 2) $c += $x[$start + $i + 1] * $x[$start + $i + $lag + 1];
            if ($c > $best) { $best = $c; $bestLag = $lag; }
        }
        if ($bestLag) $f0s[] = $rate / $bestLag;
    }
    sort($f0s);
    $mid = array_slice($f0s, (int)(count($f0s) * 0.1), max(1, (int)(count($f0s) * 0.8)));
    $mean = $mid ? array_sum($mid) / count($mid) : 0;
    $sd = 0;
    foreach ($mid as $f) $sd += ($f - $mean) ** 2;
    return ['dur' => $n / $rate, 'rms' => (int)sqrt($sumSq / max(1, $n)), 'zcr' => $zc / max(1, $n),
            'f0' => $mean, 'f0sd' => $mid ? sqrt($sd / count($mid)) : 0];
}
foreach ($voices as $v) {
    $v = trim($v);
    try {
        $prompt = $withStyle ? $cfg['style'] . ":\n\n" . $text : $text;
        $a = (new \Gemini\Client($key))->speak($cfg['model'], $prompt, $v);
        $wav = \Speech\Speaker::wav($a['pcm'], $a['rate']);
        $st = pcm_stats($a['pcm'], $a['rate']);
        printf("VOICE %-14s bytes=%-8d sha1=%s dur=%.2fs f0=%.1fHz f0sd=%.1f rms=%d zcr=%.3f\n",
            $v, strlen($wav), substr(sha1($wav), 0, 12), $st['dur'], $st['f0'], $st['f0sd'], $st['rms'], $st['zcr']);
    } catch (\Throwable $e) {
        echo "VOICE {$v} ERROR " . $e->getMessage() . "\n";
    }
}

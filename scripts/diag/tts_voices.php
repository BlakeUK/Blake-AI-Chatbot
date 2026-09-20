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
foreach ($voices as $v) {
    $v = trim($v);
    try {
        $prompt = $withStyle ? $cfg['style'] . ":\n\n" . $text : $text;
        $a = (new \Gemini\Client($key))->speak($cfg['model'], $prompt, $v);
        $wav = \Speech\Speaker::wav($a['pcm'], $a['rate']);
        echo "VOICE {$v} " . strlen($wav) . ' ' . sha1($wav) . "\n";
        echo "B64 {$v} " . base64_encode($wav) . "\n";
    } catch (\Throwable $e) {
        echo "VOICE {$v} ERROR " . $e->getMessage() . "\n";
    }
}

<?php
namespace Speech;

// Max's voice: turns a chat reply into a short spoken summary (links are
// never read out, just mentioned) and synthesises it with Gemini TTS in a
// male Yorkshire voice. Generated audio is cached on disk by content hash,
// so the same reply (or the fixed welcome line) is only paid for once.
class Speaker
{
    public const DEFAULT_MODEL = 'gemini-3.1-flash-tts-preview';
    public const DEFAULT_VOICE = 'Charon';   // male
    public const DEFAULT_STYLE = 'Read this aloud as Max, a professional customer support advisor for Blake UK. Speak clearly and confidently in a polished, courteous business tone with a light, natural Yorkshire accent: warm and approachable but never casual, exaggerated or comedic. Measured pace, crisp diction, natural pauses at full stops';
    // Earlier defaults: a stored copy of one of these is treated as "not
    // customised" and upgraded to the current default.
    public const OLD_STYLES    = ['Read this aloud as Max, a warm, friendly, down-to-earth man from Yorkshire in the north of England, with a natural broad Yorkshire accent, speaking at a relaxed conversational pace'];
    public const WELCOME       = "Hello, I'm Max, Blake UK's support assistant. How can I help you today?";
    public const LINKS_LINE    = "I've included the links below.";
    public const MAX_WORDS     = 60;
    public const CACHE_DAYS    = 30;

    // Male prebuilt Gemini TTS voices offered in the admin UI.
    public const MALE_VOICES = ['Charon', 'Puck', 'Fenrir', 'Orus', 'Enceladus', 'Iapetus', 'Algieba', 'Algenib', 'Rasalgethi', 'Alnilam', 'Schedar', 'Achird', 'Zubenelgenubi', 'Sadachbia', 'Sadaltager'];

    public static function settings(): array
    {
        $get = function (string $k, $d) {
            try {
                $s = db()->prepare('SELECT value FROM settings WHERE key = ?');
                $s->execute([$k]);
                $v = $s->fetchColumn();
                return ($v === false || $v === '') ? $d : $v;
            } catch (\Throwable $e) { return $d; }
        };
        return [
            'enabled' => $get('voice_enabled', '1') === '1',
            'model'   => $get('tts_model', self::DEFAULT_MODEL),
            'voice'   => $get('tts_voice', self::DEFAULT_VOICE),
            'style'   => in_array($style = $get('tts_style', self::DEFAULT_STYLE), self::OLD_STYLES, true) ? self::DEFAULT_STYLE : $style,
        ];
    }

    public static function hasLinks(string $text): bool
    {
        return (bool)preg_match('#https?://|www\.|\]\(#i', $text);
    }

    // Reply text with links, markdown and code noise removed - what a
    // person would actually read out. Used as the summariser's input and
    // as the fallback when the summariser fails.
    public static function speakable(string $text): string
    {
        $t = preg_replace('/\[([^\]]+)\]\((?:[^)]+)\)/', '$1', $text);          // [label](url) -> label
        $t = preg_replace('#https?://\S+|www\.\S+#i', '', $t);                    // bare URLs
        $t = preg_replace('/[*_`#>|]+/', '', $t);                                 // markdown
        $t = preg_replace('/^\s*[-•]\s*/m', '', $t);                              // bullets
        $t = preg_replace('/\(\s*\)/', '', $t);
        $t = preg_replace('/\s+([,.!?;:])/', '$1', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        return trim(preg_replace("/\n{2,}/", "\n", $t));
    }

    // Deterministic fallback: first couple of sentences, word-capped, plus
    // the links line when the reply had links.
    public static function fallbackSummary(string $reply): string
    {
        $t = str_replace("\n", ' ', self::speakable($reply));
        $sentences = preg_split('/(?<=[.!?])\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
        $out = trim(implode(' ', array_slice($sentences, 0, 2)));
        $words = preg_split('/\s+/', $out, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > self::MAX_WORDS) {
            $out = implode(' ', array_slice($words, 0, self::MAX_WORDS)) . '.';
        }
        if (self::hasLinks($reply)) {
            $out = rtrim($out) . ' ' . self::LINKS_LINE;
        }
        return trim($out);
    }

    // Short spoken version of a reply, written by the chat model.
    public static function spokenSummary(string $reply): string
    {
        $clean = self::speakable($reply);
        if ($clean === '') {
            return self::hasLinks($reply) ? self::LINKS_LINE : '';
        }
        $links = self::hasLinks($reply);
        try {
            $key = \Gemini\Client::getStoredApiKey();
            if (!$key) return self::fallbackSummary($reply);
            $prompt = "You are Max, Blake UK's friendly support assistant. Rewrite the chat reply below as what you would say out loud in one to three short sentences (no more than " . self::MAX_WORDS . " words). "
                . "Give the key point or answer only. Never read out web addresses, URLs, long product codes or lists; do not use markdown, emojis or symbols. Use clear, polished, professional British English suitable for a customer support advisor: courteous and helpful, no slang or dialect words."
                . ($links ? " The reply contains links, so end with exactly: \"" . self::LINKS_LINE . "\"" : '')
                . " Output only the words to speak.\n\nREPLY:\n" . $clean;
            $out = (new \Gemini\Client($key))->chat(
                \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash'),
                [['role' => 'user', 'content' => $prompt]]
            );
            $out = trim(self::speakable($out), " \t\n\"'");
            if ($out === '' || str_word_count($out) > self::MAX_WORDS + 15) {
                return self::fallbackSummary($reply);
            }
            if ($links && stripos($out, 'link') === false) {
                $out .= ' ' . self::LINKS_LINE;
            }
            return $out;
        } catch (\Throwable $e) {
            return self::fallbackSummary($reply);
        }
    }

    public static function cacheDir(): string
    {
        $dir = ROOT . '/data/tts';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        return $dir;
    }

    // WAV bytes for $text in the configured voice, from cache when possible.
    public static function synthesise(string $text): string
    {
        $cfg  = self::settings();
        $hash = sha1($cfg['model'] . '|' . $cfg['voice'] . '|' . $cfg['style'] . '|' . $text);
        $file = self::cacheDir() . "/{$hash}.wav";
        if (is_file($file)) {
            @touch($file);
            return (string)file_get_contents($file);
        }
        $key = \Gemini\Client::getStoredApiKey();
        if (!$key) throw new \RuntimeException('Gemini API key not configured');
        $audio = (new \Gemini\Client($key))->speak($cfg['model'], $cfg['style'] . ":\n\n" . $text, $cfg['voice']);
        $wav   = self::wav($audio['pcm'], $audio['rate']);
        @file_put_contents($file, $wav, LOCK_EX);
        if (mt_rand(1, 50) === 1) self::pruneCache();
        return $wav;
    }

    public static function wav(string $pcm, int $rate, int $channels = 1, int $bits = 16): string
    {
        $byteRate = $rate * $channels * $bits / 8;
        $block    = $channels * $bits / 8;
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $block, $bits)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    // One line per speech request/outcome in logs/speech.log (rotated at 1 MB).
    public static function log(string $sessionId, string $event, string $detail = ''): void
    {
        try {
            $dir = ROOT . '/logs';
            if (!is_dir($dir)) @mkdir($dir, 0770, true);
            $f = "$dir/speech.log";
            if (is_file($f) && filesize($f) > 1_000_000) @rename($f, "$f.1");
            $ua = substr(preg_replace('/\s+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 140);
            $line = gmdate('Y-m-d H:i:s') . ' ' . substr($sessionId, 0, 8) . " {$event} " . str_replace(["\n", "\r"], ' ', mb_substr($detail, 0, 200)) . " | {$ua}\n";
            @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    public static function pruneCache(): void
    {
        foreach (glob(self::cacheDir() . '/*.wav') ?: [] as $f) {
            if (filemtime($f) < time() - self::CACHE_DAYS * 86400) @unlink($f);
        }
    }
}

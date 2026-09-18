<?php
// src/Reception/Postcode.php
// Finds a UK postcode in a chat message and resolves it to a location via
// postcodes.io (free, Open Government Licence data). Results are cached in
// reception_postcodes so repeat lookups never leave the server.

declare(strict_types=1);

namespace Reception;

class Postcode
{
    private const FULL = '/\b([A-Z]{1,2}[0-9][A-Z0-9]?)\s*([0-9][A-Z]{2})\b/i';
    private const OUTCODE = '/\b([A-Z]{1,2}[0-9][A-Z0-9]?)\b/i';
    private const CACHE_TTL = 90 * 86400;
    private const NEGATIVE_TTL = 86400;

    // Test seam: replaces the HTTP call (fn(string $url): ?array decoded JSON).
    public static $fetcher = null;

    // Returns a normalised postcode ("S3 9PT") or outcode ("S3"), or null.
    // An outcode on its own is only accepted when the message is short or
    // says "postcode"/"area", since things like "RG6" and "M4" are also
    // cable and road names.
    public static function extract(string $text): ?string
    {
        if (preg_match(self::FULL, $text, $m)) {
            return strtoupper($m[1]) . ' ' . strtoupper($m[2]);
        }
        $short = mb_strlen(trim($text)) <= 20;
        if (($short || preg_match('/\b(post\s?code|area)\b/i', $text)) && preg_match(self::OUTCODE, $text, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    // ['postcode','lat','lon','country'] or null if unknown/unavailable.
    public static function lookup(string $postcode): ?array
    {
        $key = strtoupper(trim($postcode));
        $pdo = db();
        $row = $pdo->prepare('SELECT * FROM reception_postcodes WHERE postcode = ?');
        $row->execute([$key]);
        $c = $row->fetch();
        if ($c) {
            $ttl = $c['lat'] === null ? self::NEGATIVE_TTL : self::CACHE_TTL;
            if (time() - (int)$c['fetched_at'] < $ttl) {
                return $c['lat'] === null ? null
                    : ['postcode' => $key, 'lat' => (float)$c['lat'], 'lon' => (float)$c['lon'], 'country' => $c['country']];
            }
        }

        $isOutcode = !str_contains($key, ' ');
        $url = 'https://api.postcodes.io/' . ($isOutcode ? 'outcodes/' : 'postcodes/') . rawurlencode(str_replace(' ', '', $key));
        $json = self::fetch($url);
        if ($json === false) return null; // network problem: don't cache
        $r = $json['result'] ?? null;
        $lat = isset($r['latitude']) ? (float)$r['latitude'] : null;
        $lon = isset($r['longitude']) ? (float)$r['longitude'] : null;
        $country = is_array($r['country'] ?? null) ? implode('/', $r['country']) : ($r['country'] ?? null);
        $pdo->prepare('INSERT OR REPLACE INTO reception_postcodes (postcode, lat, lon, country, fetched_at) VALUES (?,?,?,?,?)')
            ->execute([$key, $lat, $lon, $country, time()]);
        return $lat === null ? null : ['postcode' => $key, 'lat' => $lat, 'lon' => $lon, 'country' => $country];
    }

    // Decoded JSON (404 -> ['result' => null]), or false on transport failure.
    private static function fetch(string $url): array|false
    {
        if (self::$fetcher) {
            $r = (self::$fetcher)($url);
            return $r === false ? false : ($r ?? ['result' => null]);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'BlakeUKChatbot/1.0',
        ]);
        $t0   = microtime(true);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        \ApiUsage\Logger::log('postcodes', [
            'operation'  => 'lookup',
            'http_code'  => $code,
            'ok'         => $body !== false && ($code === 200 || $code === 404),
            'error'      => ($body === false || ($code !== 200 && $code !== 404)) ? ($cerr ?: "HTTP $code") : null,
            'latency_ms' => (int)((microtime(true) - $t0) * 1000),
        ]);
        if ($body === false || $code >= 500 || $code === 0) return false;
        if ($code === 404) return ['result' => null];
        $d = json_decode($body, true);
        return is_array($d) ? $d : false;
    }
}

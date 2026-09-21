<?php
// src/Google/ServiceAccount.php
// OAuth access tokens for a Google Cloud service account (JWT bearer flow,
// RS256 signed with the key from the service account JSON). No SDK needed.
// The JSON is stored encrypted in api_keys under service 'gcp_sa'.

declare(strict_types=1);

namespace Google;

class ServiceAccount
{
    public const SCOPE_BIGQUERY = 'https://www.googleapis.com/auth/bigquery.readonly';

    // Test hook: fn(string $url, array $postFields): array decoded JSON
    public static $http = null;
    private static array $tokenCache = [];

    public static function json(): ?array
    {
        try {
            $s = db()->prepare("SELECT key_enc, iv, tag FROM api_keys WHERE service = 'gcp_sa'");
            $s->execute();
            $r = $s->fetch();
            if (!$r) return null;
            $dec = openssl_decrypt(hex2bin($r['key_enc']), 'aes-256-gcm', hex2bin(CFG['encrypt_key']), OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag']));
            $j = $dec ? json_decode($dec, true) : null;
            return is_array($j) ? $j : null;
        } catch (\Throwable $e) { return null; }
    }

    public static function save(string $json): array
    {
        $j = json_decode($json, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['private_key']) || empty($j['client_email'])) {
            return ['ok' => false, 'error' => 'That is not a Google service account key (JSON with type "service_account", client_email and private_key).'];
        }
        if (!openssl_pkey_get_private($j['private_key'])) return ['ok' => false, 'error' => 'The private key in the JSON could not be read.'];
        $iv = random_bytes(12); $tag = '';
        $enc = openssl_encrypt(json_encode($j), 'aes-256-gcm', hex2bin(CFG['encrypt_key']), OPENSSL_RAW_DATA, $iv, $tag);
        db()->prepare('INSERT INTO api_keys (service, key_enc, iv, tag, updated_at) VALUES (?,?,?,?,?)
                       ON CONFLICT(service) DO UPDATE SET key_enc=excluded.key_enc, iv=excluded.iv, tag=excluded.tag, updated_at=excluded.updated_at')
            ->execute(['gcp_sa', bin2hex($enc), bin2hex($iv), bin2hex($tag), time()]);
        self::$tokenCache = [];
        return ['ok' => true, 'client_email' => $j['client_email'], 'project_id' => $j['project_id'] ?? null];
    }

    public static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function jwt(array $sa, string $scope, ?int $now = null): string
    {
        $now = $now ?? time();
        $head = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(json_encode([
            'iss' => $sa['client_email'], 'scope' => $scope,
            'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now, 'exp' => $now + 3600,
        ]));
        $sig = '';
        if (!openssl_sign("{$head}.{$claims}", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the service account token');
        }
        return "{$head}.{$claims}." . self::b64url($sig);
    }

    public static function accessToken(string $scope = self::SCOPE_BIGQUERY): string
    {
        if (isset(self::$tokenCache[$scope]) && self::$tokenCache[$scope]['exp'] > time() + 60) return self::$tokenCache[$scope]['token'];
        $sa = self::json();
        if (!$sa) throw new \RuntimeException('No Google service account key saved');
        $fields = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => self::jwt($sa, $scope)];
        $r = self::post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', $fields, null, true);
        if (empty($r['access_token'])) throw new \RuntimeException('Google refused the service account: ' . ($r['error_description'] ?? $r['error'] ?? 'no token'));
        self::$tokenCache[$scope] = ['token' => $r['access_token'], 'exp' => time() + (int)($r['expires_in'] ?? 3600)];
        return $r['access_token'];
    }

    // POST form fields (token) or JSON (APIs); returns decoded JSON.
    public static function post(string $url, array $data, ?string $bearer = null, bool $form = false): array
    {
        if (self::$http) return (self::$http)($url, $data);
        $ch = curl_init($url);
        $headers = [$form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json'];
        if ($bearer) $headers[] = "Authorization: Bearer {$bearer}";
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $form ? http_build_query($data) : json_encode($data)]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string)$body, true) ?: [];
        if ($code >= 400 && !isset($j['error_description'])) {
            $msg = $j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : "HTTP {$code}");
            throw new \RuntimeException("Google API error: {$msg}");
        }
        return $j;
    }
}

<?php
// src/Auth/Totp.php — RFC 6238 TOTP (HMAC-SHA1, 6-digit, 30s period), pure PHP.
// No external libraries — this project intentionally has no Composer/npm/pip.

declare(strict_types=1);

namespace Auth;

class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    // Generate a new random secret, base32-encoded (for storage + QR/manual entry).
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    // Verify a 6-digit code against a base32 secret, allowing +/- $window steps
    // (90s total drift tolerance by default) for clock skew between server and phone.
    public static function verifyCode(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!$code || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $secretBytes = self::base32Decode($base32Secret);
        $counter     = intdiv(time(), self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secretBytes, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    // otpauth:// URI for manual entry / authenticator apps that accept a link.
    public static function provisioningUri(string $base32Secret, string $accountLabel, string $issuer = 'Blake UK'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
            . '?secret=' . $base32Secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    // ── HOTP core (RFC 4226) — TOTP is just HOTP with counter = floor(time / period) ──
    public static function hotp(string $secretBytes, int $counter): string
    {
        $counterBytes = pack('J', $counter); // 8-byte big-endian unsigned
        $hash   = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ── Base32 (RFC 4648, no padding required for decode) ─────────────────────
    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $data): string
    {
        if ($data === '') return '';
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int)(ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32_ALPHABET[bindec($chunk)];
        }
        $pad = strlen($out) % 8;
        if ($pad !== 0) {
            $out .= str_repeat('=', 8 - $pad);
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim(preg_replace('/\s+/', '', $b32), '='));
        if ($b32 === '') return '';
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::B32_ALPHABET, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byteBits) {
            if (strlen($byteBits) < 8) break; // discard incomplete trailing bits
            $bytes .= chr(bindec($byteBits));
        }
        return $bytes;
    }
}

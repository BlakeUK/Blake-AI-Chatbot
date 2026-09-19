<?php
// src/Mail/Smtp.php
// Minimal SMTP client (no Composer/PHPMailer): implicit TLS (465),
// STARTTLS (587) or plain (25), AUTH LOGIN, UTF-8 plain-text messages.
// Settings live in the settings table (smtp_*) with the password stored
// encrypted in api_keys under service 'smtp' - edited in Admin > Email.

declare(strict_types=1);

namespace Mail;

class Smtp
{
    public const DEFAULT_NOTIFY = 'sales@blake-uk.com';

    public static function settings(): array
    {
        $get = function (string $k, string $d = ''): string {
            try {
                $s = db()->prepare('SELECT value FROM settings WHERE key = ?');
                $s->execute([$k]);
                $v = $s->fetchColumn();
                return ($v === false || $v === null) ? $d : (string)$v;
            } catch (\Throwable $e) { return $d; }
        };
        return [
            'host'       => $get('smtp_host'),
            'port'       => (int)($get('smtp_port', '587') ?: 587),
            'security'   => $get('smtp_security', 'tls'),       // tls | ssl | none
            'username'   => $get('smtp_username'),
            'from_email' => $get('smtp_from_email'),
            'from_name'  => $get('smtp_from_name', 'Blake UK Support'),
            'notify'     => $get('support_notify_email', self::DEFAULT_NOTIFY) ?: self::DEFAULT_NOTIFY,
            'password'   => self::password(),
        ];
    }

    public static function password(): ?string
    {
        try {
            $row = db()->prepare("SELECT key_enc, iv, tag FROM api_keys WHERE service = 'smtp'");
            $row->execute();
            $r = $row->fetch();
            if (!$r) return null;
            $dec = openssl_decrypt(hex2bin($r['key_enc']), 'aes-256-gcm', hex2bin(CFG['encrypt_key']),
                OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag']));
            return $dec === false ? null : $dec;
        } catch (\Throwable $e) { return null; }
    }

    public static function isConfigured(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::settings();
        return $cfg['host'] !== '' && $cfg['from_email'] !== '';
    }

    // Sends one plain-text email. Throws \RuntimeException with the server's
    // reply on failure.
    public static function send(string $to, string $subject, string $body, ?array $cfg = null): void
    {
        $cfg = $cfg ?? self::settings();
        if (!self::isConfigured($cfg)) {
            throw new \RuntimeException('Email is not configured (Admin > Email)');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Invalid recipient: {$to}");
        }

        $host   = $cfg['host'];
        $port   = $cfg['port'];
        $remote = ($cfg['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx    = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $errno  = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($fp, 20);

        try {
            self::expect($fp, 220);
            $ehloHost = gethostname() ?: 'blakegroup.uk';
            self::cmd($fp, "EHLO {$ehloHost}", 250);
            if ($cfg['security'] === 'tls') {
                self::cmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                self::cmd($fp, "EHLO {$ehloHost}", 250);
            }
            if ($cfg['username'] !== '') {
                self::cmd($fp, 'AUTH LOGIN', 334);
                self::cmd($fp, base64_encode($cfg['username']), 334);
                self::cmd($fp, base64_encode((string)$cfg['password']), 235);
            }
            self::cmd($fp, 'MAIL FROM:<' . $cfg['from_email'] . '>', 250);
            self::cmd($fp, "RCPT TO:<{$to}>", [250, 251]);
            self::cmd($fp, 'DATA', 354);
            fwrite($fp, self::message($cfg, $to, $subject, $body) . "\r\n.\r\n");
            self::expect($fp, 250);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    public static function message(array $cfg, string $to, string $subject, string $body): string
    {
        $fromName = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . '?=';
        $domain   = substr(strrchr($cfg['from_email'], '@') ?: '@blakegroup.uk', 1);
        $headers  = [
            'Date: ' . date(DATE_RFC2822),
            "From: {$fromName} <{$cfg['from_email']}>",
            "To: <{$to}>",
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@{$domain}>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function cmd($fp, string $line, $expect): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $expect);
    }

    private static function expect($fp, $codes): string
    {
        $codes = (array)$codes;
        $resp  = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($resp ?: 'no response'));
        }
        return $resp;
    }
}

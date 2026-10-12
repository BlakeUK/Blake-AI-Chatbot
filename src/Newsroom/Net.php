<?php
// src/Newsroom/Net.php: fetching a web address on someone's behalf, safely. The Newsroom lets staff add any news feed, and it fetches pages and
// pictures for them, so this is the one place that decides whether the server may go to an address. It refuses anything that is not plain
// http or https on the usual ports, anything with a password in it, and anything that points (directly, through a name, or through a
// redirect) at this server or a private or reserved network. The address it checked is the address it connects to, so a name that
// changes its answer between the check and the visit cannot trick it.

declare(strict_types=1);

namespace Newsroom;

final class Net
{
    public const MAX_URL = 2000;
    public const MAX_REDIRECTS = 3;
    private const BLOCKED_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
    private const BLOCKED_V6 = ['::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr); $a = @inet_pton($ip); $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
        $bits = (int)$bits; $full = intdiv($bits, 8); $rest = $bits % 8;
        if ($full && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
        if ($rest) { $mask = (0xFF << (8 - $rest)) & 0xFF; if ((ord($a[$full]) & $mask) !== (ord($b[$full]) & $mask)) return false; }
        return true;
    }

    /** True only for an ordinary public internet address. */
    public static function isPublicIp(string $ip): bool
    {
        $ip = strtolower(trim($ip, '[]'));
        if (preg_match('/^(?:::ffff:|::)(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) $ip = $m[1];          // an IPv4 address dressed as IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $v6 = str_contains($ip, ':');
        foreach ($v6 ? self::BLOCKED_V6 : self::BLOCKED_V4 as $c) if (self::inCidr($ip, $c)) return false;
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function resolve(string $host): array
    {
        $ips = [];
        foreach ((array)@gethostbynamel($host) as $ip) $ips[] = $ip;
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /** Checks an address and says where it points. Throws \InvalidArgumentException with a message fit for the person. @return array{url:string,host:string,port:int,scheme:string,ips:list<string>} */
    public static function check(string $url, ?callable $resolver = null): array
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $url)) throw new \InvalidArgumentException('That is not a valid web address.');
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) throw new \InvalidArgumentException('That is not a valid web address.');
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) throw new \InvalidArgumentException('Only http and https addresses are allowed.');
        if (isset($p['user']) || isset($p['pass'])) throw new \InvalidArgumentException('Addresses with a user name or password in them are not allowed.');
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) throw new \InvalidArgumentException('Only the normal web ports are allowed.');
        $host = strtolower(rtrim(trim($p['host'], '[]'), '.'));
        if ($host === '') throw new \InvalidArgumentException('That is not a valid web address.');
        if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
        elseif (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}$/i', $host)) throw new \InvalidArgumentException('That is not a valid web address.');   // 2130706433, 0x7f.1, 127.1
        else $ips = $resolver ? (array)$resolver($host) : self::resolve($host);
        if (!$ips) throw new \InvalidArgumentException('That website could not be found.');
        foreach ($ips as $ip) if (!self::isPublicIp((string)$ip)) throw new \InvalidArgumentException('That address points inside a private network, so it is not allowed.');
        return ['url' => $url, 'host' => $host, 'port' => $port, 'scheme' => $scheme, 'ips' => array_values($ips)];
    }

    /** The real transport: connects to the address that was checked, never re-resolves, never follows redirects itself, and stops reading at the size limit. */
    public static function curl(string $url, string $host, int $port, string $ip, int $maxBytes, string $accept, int $timeout): array
    {
        $body = ''; $truncated = false; $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["{$host}:{$port}:" . (str_contains($ip, ':') ? "[{$ip}]" : $ip)],
            CURLOPT_USERAGENT => 'BlakeUK-Newsroom/1.0 (+https://blakegroup.uk)', CURLOPT_HTTPHEADER => ['Accept: ' . $accept], CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); } return strlen($line); },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$truncated, $maxBytes) { if (strlen($body) + strlen($chunk) > $maxBytes) { $truncated = true; return 0; } $body .= $chunk; return strlen($chunk); },
        ]);
        curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => $truncated];
    }

    /** Fetches an address, following up to three redirects and checking every hop. @param array{maxBytes?:int,accept?:string,timeout?:int,transport?:callable,resolver?:callable} $o @return array{status:int,type:string,body:string,url:string} */
    public static function fetch(string $url, array $o = []): array
    {
        $max = $o['maxBytes'] ?? 2_000_000; $accept = $o['accept'] ?? '*/*'; $timeout = $o['timeout'] ?? 8; $tr = $o['transport'] ?? [self::class, 'curl'];
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $c = self::check($url, $o['resolver'] ?? null);
            $r = $tr($c['url'], $c['host'], $c['port'], $c['ips'][0], $max, $accept, $timeout);
            if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) { $url = self::absolute($c['url'], $r['headers']['location']); continue; }
            if (!empty($r['truncated'])) throw new \InvalidArgumentException('That page is too large.');
            return ['status' => $r['status'], 'type' => strtolower(trim(explode(';', $r['headers']['content-type'] ?? '')[0])), 'body' => $r['body'], 'url' => $c['url']];
        }
        throw new \InvalidArgumentException('That address redirects too many times.');
    }

    private static function absolute(string $base, string $loc): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $loc)) return $loc;
        $b = parse_url($base); $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($loc, '//')) return $b['scheme'] . ':' . $loc;
        if (str_starts_with($loc, '/')) return $origin . $loc;
        return $origin . rtrim(dirname($b['path'] ?? '/'), '/') . '/' . $loc;
    }
}

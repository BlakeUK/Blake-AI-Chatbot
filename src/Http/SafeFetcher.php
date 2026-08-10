<?php
// src/Http/SafeFetcher.php
// Shared server-side-fetch guard for every admin feature that downloads a
// URL an admin/editor supplies (sitemap scans, page imports, PDF imports):
// discover_urls.php, import_urls.php, import_page_links.php,
// download_cleaned_sitemap.php, refresh_site_pages.php,
// Products\PageExtractor::fetch(). Without this, a compromised or
// malicious editor account (or a copy-pasted internal URL) can make the
// server fetch internal-network resources - cloud metadata endpoints
// (169.254.169.254), localhost services, RFC1918 addresses - since
// nothing about "fetch this sitemap/page" otherwise distinguishes a
// public URL from an internal one.
//
// Best-effort, not exhaustive: resolves the host and rejects
// private/loopback/link-local/reserved IPs before connecting, and
// re-validates on every redirect hop rather than trusting curl's
// CURLOPT_FOLLOWLOCATION to only ever redirect somewhere equally safe.
// Does not defend against DNS rebinding between the check and the
// connect() a moment later - closing that fully needs pinning the
// resolved IP into the request (e.g. CURLOPT_RESOLVE), which is a
// larger change than this pass covers.

declare(strict_types=1);

namespace Http;

class SafeFetcher
{
    private const MAX_REDIRECTS = 5;

    // Fetches $url, following redirects manually so every hop is
    // re-validated (a public URL can still redirect to an internal one).
    // Returns ['ok' => bool, 'body' => string|false, 'code' => int, 'error' => string].
    public static function get(string $url, int $timeout = 30, int $connectTimeout = 10, string $userAgent = 'BlakeUKChatbotImporter/1.0'): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                self::assertPublicUrl($current);
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'body' => false, 'code' => 0, 'error' => $e->getMessage()];
            }

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);

            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
                curl_close($ch);
                if (!$location) {
                    return ['ok' => false, 'body' => false, 'code' => $code, 'error' => 'Redirect with no Location header'];
                }
                $current = $location;
                continue;
            }

            curl_close($ch);
            return ['ok' => $body !== false && $code === 200, 'body' => $body, 'code' => $code, 'error' => $err];
        }

        return ['ok' => false, 'body' => false, 'code' => 0, 'error' => 'Too many redirects'];
    }

    // Throws unless $url is a plain http(s) URL whose host resolves only to
    // public, routable addresses.
    public static function assertPublicUrl(string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Not a valid http(s) URL');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new \RuntimeException('URL has no host');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            foreach ($records ?: [] as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }

        if (!$ips) {
            throw new \RuntimeException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException("Refusing to fetch a URL that resolves to a non-public address ({$ip})");
            }
        }
    }
}

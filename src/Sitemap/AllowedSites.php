<?php
// src/Sitemap/AllowedSites.php
// The fixed set of sites public/check/{sitemap,probe,robots}.php are
// permitted to fetch - single source of truth for the host-allowlist
// every one of those endpoints already enforces (never fetch anything
// this list doesn't cover, regardless of what a caller's ?url= says),
// and for the site picker public/check/index.html shows so a caller
// picks a supported site rather than typing an arbitrary domain that
// would just get rejected.
//
// Adding a new site to scan means adding it here, nowhere else.

declare(strict_types=1);

namespace Sitemap;

class AllowedSites
{
    public const SITES = [
        'blake-uk.com' => [
            'label'           => 'Blake UK',
            'hosts'           => ['blake-uk.com', 'www.blake-uk.com'],
            'default_sitemap' => 'https://www.blake-uk.com/sitemap.xml',
        ],
        'visionplus.co.uk' => [
            'label'           => 'Vision Plus',
            'hosts'           => ['visionplus.co.uk', 'www.visionplus.co.uk'],
            'default_sitemap' => 'https://visionplus.co.uk/wp-sitemap.xml',
        ],
        'solwise.co.uk' => [
            // No XML sitemap - Sitemap\HtmlLinkExtractor handles this one
            // as a fallback (see sitemap.php), pulling same-site <a href>
            // links off the HTML page instead of parsing a <urlset>.
            'label'           => 'Solwise',
            'hosts'           => ['solwise.co.uk', 'www.solwise.co.uk'],
            'default_sitemap' => 'https://www.solwise.co.uk/sitemap.htm',
        ],
    ];

    public static function allowedHosts(): array
    {
        $all = [];
        foreach (self::SITES as $site) {
            $all = array_merge($all, $site['hosts']);
        }
        return $all;
    }

    public static function isHostAllowed(?string $host): bool
    {
        if (!$host) return false;
        return in_array(strtolower($host), self::allowedHosts(), true);
    }

    public static function isUrlAllowed(string $url): bool
    {
        return self::isHostAllowed(parse_url($url, PHP_URL_HOST) ?: null);
    }
}

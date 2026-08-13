<?php
// src/Sitemap/HtmlLinkExtractor.php
// Fallback for a site with no XML sitemap: pulls every same-host <a
// href> off a plain HTML page - an "HTML sitemap" page, a directory-
// listing index, or any other navigation page - and returns it in the
// same shape Sitemap\Parser's urlset entries use, so sitemap.php can
// treat both sources identically downstream (the rest of the pipeline
// - probing, duplicate detection, etc. - doesn't need to know which one
// produced the URL list).
//
// Deliberately restricted to links on the SAME host as the page being
// read, not every link on the page - an HTML sitemap or directory index
// often carries a handful of external links (social, partner sites) that
// aren't "pages on this site" and shouldn't be pulled into a scan of it.

declare(strict_types=1);

namespace Sitemap;

class HtmlLinkExtractor
{
    public static function extractLinks(string $html, string $baseUrl): array
    {
        if (trim($html) === '') return [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        if (!$ok) return [];

        $baseHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        if ($baseHost === '') return [];

        $xpath = new \DOMXPath($dom);
        $seen = [];
        $entries = [];

        foreach ($xpath->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) continue;
            if (preg_match('#^(mailto|tel|javascript):#i', $href)) continue;

            $absolute = UrlHelper::resolve($baseUrl, $href);
            if (!preg_match('#^https?://#i', $absolute)) continue;

            $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
            if ($host !== $baseHost) continue;

            $key = UrlHelper::normalize($absolute);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $entries[] = ['loc' => $absolute, 'lastmod' => null];
        }

        return $entries;
    }
}

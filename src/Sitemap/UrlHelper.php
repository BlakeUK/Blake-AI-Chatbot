<?php
// src/Sitemap/UrlHelper.php
// Small pure URL helpers shared by public/check's sitemap/probe endpoints:
// resolving a redirect Location header against the request URL that
// produced it (a browser's fetch()/XHR does this automatically; a manual
// curl redirect loop - needed so probe.php can see each hop's individual
// status code - has to do it itself, since Location is allowed to be a
// relative reference and most real servers send one that way), and a
// loose "same page" normalization used for canonical-tag matching and
// duplicate-URL detection so a trailing slash or http-vs-https difference
// doesn't register as a false mismatch.

declare(strict_types=1);

namespace Sitemap;

class UrlHelper
{
    public static function resolve(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') return $base;
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $location)) {
            return $location; // already absolute
        }

        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $location;
        }
        $scheme = $baseParts['scheme'];
        $host   = $baseParts['host'];
        $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $authority = "{$scheme}://{$host}{$port}";

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location; // protocol-relative
        }
        if ($location[0] === '/') {
            return $authority . self::collapseDots($location);
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = substr($basePath, 0, (strrpos($basePath, '/') ?: 0) + 1) ?: '/';
        return $authority . self::collapseDots($dir . $location);
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        $url = rtrim($url, '/');
        return strtolower($url);
    }

    private static function collapseDots(string $path): string
    {
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '') continue;
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        return '/' . implode('/', $out) . (str_ends_with($path, '/') && $path !== '/' ? '/' : '');
    }
}

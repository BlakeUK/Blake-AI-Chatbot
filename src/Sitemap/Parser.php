<?php
// src/Sitemap/Parser.php
// Pure XML parsing for public/check/sitemap.php - deliberately split out
// from the fetch step (Http\SafeFetcher does that) so this is unit
// testable with plain XML strings, no network involved.
//
// XXE note: this does NOT pass LIBXML_NOENT, which is what actually
// matters here - libxml has disabled external entity substitution by
// default since 2.9 (the PHP versions this app targets all bundle that or
// newer), so a <!ENTITY xxe SYSTEM "file:///etc/passwd"> in an attacker
// -supplied sitemap is left unexpanded rather than being resolved and
// inlined into the parsed output. LIBXML_NONET is kept too as a second
// layer (blocks fetching an external DTD over the network during parsing)
// but isn't the primary defence - don't rely on it alone if this is ever
// changed to pass LIBXML_NOENT for some other reason.

declare(strict_types=1);

namespace Sitemap;

class Parser
{
    // Returns one of:
    //   ['type' => 'urlset',      'entries' => [['loc'=>.., 'lastmod'=>.., 'changefreq'=>.., 'priority'=>..], ...]]
    //   ['type' => 'sitemapindex','entries' => [['loc'=>.., 'lastmod'=>..], ...]]
    //   ['type' => 'error',       'error' => string]
    public static function parse(string $xml): array
    {
        if (trim($xml) === '') {
            return ['type' => 'error', 'error' => 'Empty response'];
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($doc === false) {
            $firstError = $xmlErrors[0]->message ?? 'Malformed XML';
            return ['type' => 'error', 'error' => trim($firstError)];
        }

        $root = $doc->getName();

        if ($root === 'sitemapindex') {
            $entries = [];
            foreach ($doc->sitemap as $sm) {
                $loc = trim((string)$sm->loc);
                if ($loc === '') continue;
                $entries[] = [
                    'loc'     => $loc,
                    'lastmod' => self::nullableString((string)$sm->lastmod),
                ];
            }
            return ['type' => 'sitemapindex', 'entries' => $entries];
        }

        if ($root === 'urlset') {
            $entries = [];
            foreach ($doc->url as $u) {
                $loc = trim((string)$u->loc);
                if ($loc === '') continue;
                $entries[] = [
                    'loc'        => $loc,
                    'lastmod'    => self::nullableString((string)$u->lastmod),
                    'changefreq' => self::nullableString((string)$u->changefreq),
                    'priority'   => self::nullableString((string)$u->priority),
                ];
            }
            return ['type' => 'urlset', 'entries' => $entries];
        }

        return ['type' => 'error', 'error' => "Unrecognized sitemap root element <{$root}> - expected <urlset> or <sitemapindex>"];
    }

    private static function nullableString(string $v): ?string
    {
        $v = trim($v);
        return $v !== '' ? $v : null;
    }
}

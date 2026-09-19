<?php

declare(strict_types=1);

namespace Products;

// Builds Importer-ready product records straight from blake-uk.com product
// pages. Every product page carries a schema.org Product JSON-LD block
// (sku, name, category, image, gtin, brand, offers.price exc VAT,
// offers.availability); the page HTML adds the inc-VAT price, the feature
// bullets, the full description, the Technical Specification rows, the
// Downloads (PDF manuals/datasheets) and the related-product codes.
// Deterministic parsing only - no Gemini calls, no per-page cost.
class SiteScraper
{
    public const BASE_URL = 'https://www.blake-uk.com';

    // Returns null when the page is not a product page (no Product JSON-LD
    // with a sku), e.g. category and information pages from the sitemap.
    public static function parse(string $html, string $pageUrl): ?array
    {
        $ld = self::productJsonLd($html);
        if (!$ld || empty($ld['sku'])) {
            return null;
        }

        $dec  = fn($s) => trim(html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $code = $dec($ld['sku']);
        $name = $dec($ld['name'] ?? '');
        if ($name === '' && preg_match('#<h1[^>]*>(.*?)</h1>#si', $html, $m)) {
            $name = $dec(strip_tags($m[1]));
        }

        $url = $pageUrl;
        if (preg_match('#<link[^>]+rel="canonical"[^>]+href="([^"]+)"#i', $html, $m)) {
            $url = $dec($m[1]);
        }

        $offers = $ld['offers'] ?? [];
        if (is_array($offers) && array_is_list($offers)) $offers = $offers[0] ?? [];
        $exc = is_numeric($offers['price'] ?? null) ? (float)$offers['price'] : null;
        $inc = self::htmlPrice($html, 'incvat');
        if ($exc === null) $exc = self::htmlPrice($html, 'excvat');

        $record = [
            'product_code'    => $code,
            'name'            => $name,
            'url'             => $url,
            'category_path'   => array_values(array_filter([$dec($ld['category'] ?? '')])),
            'summary_bullets' => self::features($html),
            'description'     => self::description($html) ?: $dec($ld['description'] ?? ''),
            'tech_specs'      => self::techSpecs($html),
            'price_exc_vat'   => $exc,
            'price_inc_vat'   => $inc,
            'stock_status'    => self::availability($offers['availability'] ?? null),
            'image_url'       => is_string($ld['image'] ?? null) ? $ld['image'] : (is_array($ld['image'] ?? null) ? ($ld['image'][0] ?? null) : null),
            'image_alt'       => $name,
            'documents'       => self::downloads($html),
            'related_product_codes' => array_values(array_diff(self::relatedCodes($html), [$code])),
            'search_terms'    => array_values(array_filter([$code, $dec($ld['gtin'] ?? ($ld['gtin13'] ?? '')), $dec($ld['mpn'] ?? '')])),
            'currency'        => $offers['priceCurrency'] ?? 'GBP',
            'active'          => true,
        ];
        if (!empty($ld['brand']['name'])) {
            $record['brand'] = ['name' => $dec($ld['brand']['name'])];
        }
        // Only the exc-VAT figure in the structured data: derive inc VAT at
        // the UK standard rate when the page's own inc-VAT figure is missing.
        if ($record['price_inc_vat'] === null && $exc !== null) {
            $record['vat_rate'] = 20;
        }
        return $record;
    }

    private static function productJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $raw) {
            $j = json_decode(trim($raw), true);
            if (!is_array($j)) {
                // Some pages have unescaped control characters in descriptions.
                $j = json_decode(preg_replace('/[\x00-\x1F]+/', ' ', trim($raw)), true);
            }
            if (!is_array($j)) continue;
            foreach (array_is_list($j) ? $j : (isset($j['@graph']) ? $j['@graph'] : [$j]) as $node) {
                if (is_array($node) && strcasecmp((string)($node['@type'] ?? ''), 'Product') === 0) {
                    return $node;
                }
            }
        }
        return null;
    }

    private static function htmlPrice(string $html, string $class): ?float
    {
        if (preg_match('#class="price ' . $class . '[^"]*"[^>]*>\s*(?:&\#x00a3;|&pound;|£)\s*([\d,]+\.\d{2})#i', $html, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }
        return null;
    }

    private static function availability($v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        $s = preg_replace('#^https?://schema\.org/#i', '', $v);
        return match (strtolower($s)) {
            'instock'             => 'In stock',
            'outofstock'          => 'Out of stock',
            'backorder'           => 'On back order',
            'preorder'            => 'Pre-order',
            'discontinued'        => 'Discontinued',
            'limitedavailability' => 'Limited stock',
            default               => trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $s)),
        };
    }

    private static function text(string $fragment): string
    {
        $fragment = preg_replace('#<li[^>]*>#i', "\n- ", $fragment);
        $fragment = preg_replace('#<(br|/p|/h\d|/div|/tr)[^>]*>#i', "\n", $fragment);
        $t = html_entity_decode(strip_tags($fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = preg_replace("/ *\n[ \n]*/", "\n", $t);
        return trim($t);
    }

    private static function features(string $html): array
    {
        if (!preg_match('#<ul class="product-page__features[^"]*"[^>]*>(.*?)</ul>#si', $html, $m)) return [];
        preg_match_all('#<li[^>]*>(.*?)</li>#si', $m[1], $li);
        return array_values(array_filter(array_map(fn($x) => self::text($x), $li[1])));
    }

    private static function description(string $html): string
    {
        if (!preg_match('#<span id="productdescription">(.*?)</span>\s*(?:</div>|<h4)#si', $html, $m)
            && !preg_match('#<span id="productdescription">(.*?)</span>#si', $html, $m)) {
            return '';
        }
        $t = self::text($m[1]);
        return mb_strlen($t) > 4000 ? mb_substr($t, 0, 4000) . '…' : $t;
    }

    // Section of the page between an anchor id and the next section heading.
    private static function section(string $html, string $anchorId): string
    {
        $p = strpos($html, 'id="' . $anchorId . '"');
        if ($p === false) return '';
        $end = strpos($html, 'js-anchor-point', $p + 20);
        return substr($html, $p, ($end === false ? 20000 : $end - $p));
    }

    private static function techSpecs(string $html): array
    {
        $sec = self::section($html, 'prod-tech');
        if ($sec === '') return [];
        preg_match_all('#<div class="product-page__list">\s*<div class="product-page__list--column">(.*?)</div>\s*<div class="product-page__list--column">(.*?)</div>#si', $sec, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $k = self::text($row[1]);
            $v = self::text($row[2]);
            if ($k !== '' && $v !== '' && mb_strlen($k) <= 80) $out[$k] = mb_substr($v, 0, 300);
        }
        return $out;
    }

    private static function downloads(string $html): array
    {
        $sec = self::section($html, 'prod-down');
        if ($sec === '') return [];
        preg_match_all('#<a href="(https?://[^"]+)"[^>]*>(.*?)</a>#si', $sec, $m, PREG_SET_ORDER);
        $out = [];
        $seen = [];
        foreach ($m as $a) {
            $url = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            $label = self::text($a[2]);
            // The site reports some sizes as a meaningless "(1B)" - keep KB/MB/GB only.
            $size  = preg_match('/\(([\d.]+\s*[KMG]B)\)\s*$/i', $label, $sm) ? $sm[1] : null;
            $label = trim(preg_replace('/\s*\([\d.]+\s*[KMG]?B\)\s*$/i', '', $label));
            $out[] = ['type' => preg_match('/\.pdf$/i', parse_url($url, PHP_URL_PATH) ?? '') ? 'pdf' : 'doc',
                      'title' => $label ?: basename((string)parse_url($url, PHP_URL_PATH)), 'url' => $url, 'size' => $size];
        }
        return $out;
    }

    private static function relatedCodes(string $html): array
    {
        $p = strpos($html, 'js-related-products--related');
        if ($p === false) return [];
        $end = strpos($html, '</section>', $p);
        $sec = substr($html, $p, $end === false ? 60000 : $end - $p);
        preg_match_all('#stockcode="([^"]+)"#i', $sec, $m);
        return array_values(array_unique(array_map('trim', $m[1])));
    }

    // Product page URLs from the sitemap: everything except category,
    // brand and known information pages. Non-product pages that slip
    // through are rejected by parse() (no Product JSON-LD).
    public static function candidateUrls(array $sitemapUrls): array
    {
        return array_values(array_filter($sitemapUrls, function ($u) {
            $path = (string)parse_url($u, PHP_URL_PATH);
            return str_ends_with($path, '.html')
                && !preg_match('#^/(category|brand|blog|news|guides?)/#i', $path);
        }));
    }
}

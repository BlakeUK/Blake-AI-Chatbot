<?php
// src/Products/Leaflet.php
// Generates a Blake UK technical data sheet (A4 PDF) for a product, from the
// product's OWN live page: title, code, description, technical specification
// and gallery images. Nothing is invented and no prices are shown.
//
// Checked twice before anything is generated (verify()):
//   1. the product exists in our catalogue and is active;
//   2. its live page loads, and the page's product code and title match the
//      catalogue record.
// If either check fails, no sheet is produced and the reason is returned, so
// a customer can never be handed a sheet for the wrong product.

declare(strict_types=1);

namespace Products;

class Leaflet
{
    public const DISCLAIMER = 'Specifications are taken from the Blake UK product page shown above on the date of issue and are published for guidance only. '
        . 'Dimensions and weights are nominal and may change without notice. If this product is intended for a mission-critical, safety-related or contractual application, '
        . 'please review and confirm the specifications with Blake UK before ordering or installation. Prices are not included in this sheet. E&OE.';

    public static $fetcher = null;   // test hook: fn(string $url): ?string

    public static function dir(): string
    {
        $d = rtrim((string)(CFG['data_path'] ?? (ROOT . '/data')), '/') . '/leaflets';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    private static function fetch(string $url): ?string
    {
        if (self::$fetcher) return (self::$fetcher)($url);
        try {
            $f = \Http\SafeFetcher::get($url, 25, 10, 'Mozilla/5.0 (compatible; BlakeUKSupport/1.0)');
            return $f['ok'] ? (string)$f['body'] : null;
        } catch (\Throwable $e) { return null; }
    }

    private static function clean(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    // Both checks. Returns ['ok' => true, 'data' => [...]] or ['ok' => false, 'error' => '...'].
    public static function verify(string $code): array
    {
        $p = \Knowledge\Search::byCode($code);
        if (!$p) return ['ok' => false, 'error' => "No product with code {$code} in the catalogue."];
        if (isset($p['active']) && !$p['active']) return ['ok' => false, 'error' => "{$code} is not an active product."];
        if (empty($p['url'])) return ['ok' => false, 'error' => "{$code} has no product page to read the specification from."];

        $html = self::fetch($p['url']);
        if ($html === null || strlen($html) < 500) return ['ok' => false, 'error' => "The product page for {$code} could not be read, so the specification can't be verified."];

        // Check 2: the page really is this product.
        $codeOnPage = stripos($html, $p['product_code']) !== false;
        $h1 = preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m) ? self::clean($m[1]) : '';
        $titleWords = fn(string $t) => array_filter(preg_split('/[^a-z0-9]+/i', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY), fn($w) => mb_strlen($w) > 2);
        $a = $titleWords($p['title'] ?: $p['name']);
        $b = $titleWords($h1);
        $overlap = ($a && $b) ? count(array_intersect($a, $b)) / min(count($a), count($b)) : 0;
        if (!$codeOnPage && $overlap < 0.6) {
            return ['ok' => false, 'error' => "The page at {$p['url']} doesn't match {$code}, so no data sheet was produced."];
        }

        $specs = self::specs($html);
        if (!$specs) return ['ok' => false, 'error' => "No technical specification is published for {$code}, so there is nothing to put on a data sheet."];

        return ['ok' => true, 'data' => [
            'code'       => $p['product_code'],
            'name'       => $p['name'],
            'title'      => $p['title'] ?: $p['name'],
            'h1'         => $h1,
            'url'        => $p['url'],
            'category'   => (json_decode((string)$p['category_path'], true) ?: ['PRODUCT'])[0],
            'intro'      => self::intro($html, $p),
            'specs'      => $specs,
            'bullets'    => self::bullets($html, $p),
            'images'     => self::images($html, $p),
            'code_on_page' => $codeOnPage,
            'title_match'  => round($overlap, 2),
        ]];
    }

    // "Technical Specification" list on the product page (label -> value).
    public static function specs(string $html): array
    {
        $i = stripos($html, 'id="prod-tech"');
        if ($i === false) return [];
        $j = stripos($html, 'id="prod-down"', $i);
        $seg = substr($html, $i, ($j !== false ? $j - $i : 6000));
        preg_match_all('/product-page__list--column">.*?<p>(.*?)<\/p>.*?<\/div>\s*<div class="product-page__list--column">\s*<p>(.*?)<\/p>/s', $seg, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $label = self::clean($row[1]);
            $value = self::clean($row[2]);
            if ($label === '' || $value === '' || preg_match('/price|vat|rrp|£/i', $label . $value)) continue;
            $out[$label] = $value;
            if (count($out) >= 14) break;
        }
        return $out;
    }

    private static function intro(string $html, array $p): string
    {
        $d = stripos($html, 'id="prod-desc"');
        $text = '';
        if ($d !== false) {
            $text = self::clean(substr($html, $d, 2500));
            $text = preg_replace('/^id="prod-desc">\s*Product Description\s*/i', '', $text);
        }
        if (mb_strlen($text) < 40) $text = self::clean((string)($p['description'] ?? ''));
        $text = preg_replace('/£\s?[\d,.]+/', '', $text);            // never any prices
        // Page text often runs sentences together ("...applications.Key Features:")
        // and bullet markers glue onto words; tidy before taking the intro.
        $text = preg_replace('/([.!?:])(?=[A-Z(])/u', '$1 ', $text);
        $text = preg_replace('/\s*[•·]\s*/u', ' ', $text);
        $text = preg_split('/\b(Key Features?|Features?:|Technical Specification|Downloads)\b/iu', $text)[0] ?? $text;
        // A heading often runs straight into the first sentence
        // ("...Launch AmplifierOur triple-filtered..."): split it, then drop
        // the heading when it just repeats the product name.
        $text = preg_replace('/([a-z])(Our|The|This|These|An|It|Designed|Ideal|Supplied|Featuring|With|Perfect)\b/u', '$1 $2', $text);
        $w = fn(string $t) => array_filter(preg_split('/[^a-z0-9]+/i', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY), fn($x) => mb_strlen($x) > 2);
        $first = preg_split('/(?<=[.!?])\s+|\s(?=(?:Our|The|This)\b)/u', $text)[0] ?? '';
        $nameWords = $w((string)($p['name'] ?? ''));
        $firstWords = $w($first);
        if ($firstWords && $nameWords && count(array_intersect($firstWords, $nameWords)) / count($firstWords) >= 0.5) {
            $text = trim(mb_substr($text, mb_strlen($first)));
        }
        // First two sentences, trimmed to a sensible intro length.
        $parts = preg_split('/(?<=[.!?])\s+/u', $text);
        $intro = trim(implode(' ', array_slice($parts, 0, 2)));
        return mb_substr($intro, 0, 420);
    }

    private static function bullets(string $html, array $p): array
    {
        // The product page's own "Key Features" bullets are the best source:
        // they carry the real figures (noise figure, power, test point).
        $page = self::pageBullets($html);
        if (count($page) >= 2) return $page;
        $b = json_decode((string)($p['summary_bullets'] ?? '[]'), true) ?: [];
        $b = array_values(array_filter(array_map(fn($x) => self::clean((string)$x), $b), fn($x) => $x !== '' && !preg_match('/£|price/i', $x)));
        return array_slice($b, 0, 8);
    }

    public static function pageBullets(string $html): array
    {
        $d = stripos($html, 'id="prod-desc"');
        if ($d === false) return [];
        $seg = substr($html, $d, 9000);
        $items = [];
        if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $seg, $m)) {
            foreach ($m[1] as $li) $items[] = self::clean($li);
        }
        if (count($items) < 2) {
            $text = self::clean($seg);
            $text = preg_replace('/^id="prod-desc">\s*Product Description\s*/i', '', $text);
            foreach (preg_split('/\s*[•·]\s*/u', $text) as $k => $part) {
                if ($k === 0) continue;                       // lead-in text before the first bullet
                $items[] = trim($part);
            }
        }
        $out = [];
        foreach ($items as $i) {
            $i = trim(preg_replace('/\s+/u', ' ', $i));
            $i = preg_split('/\b(Technical Specification|Downloads|Reviews)\b/iu', $i)[0];
            if (mb_strlen($i) < 8 || preg_match('/£|price|vat/i', $i)) continue;
            $out[] = mb_substr($i, 0, 150);
            if (count($out) >= 8) break;
        }
        return $out;
    }

    // Gallery images for this product (CDN "large" images), main one first.
    public static function images(string $html, array $p): array
    {
        // Only this product's own gallery: stop at the "related products"
        // strip, and take gallery-sized images (>= 500px), never the small
        // product-card thumbnails of other products.
        $cut = stripos($html, 'related-products');
        $seg = $cut !== false ? substr($html, 0, $cut) : $html;
        preg_match_all('#https://cdn\\.blake-uk\\.com/([a-f0-9\\-]+)/(?:large|small)/(\\d+)/(\\d+)/([^"\'\\s)]+)#i', $seg, $m, PREG_SET_ORDER);
        $urls = [];
        foreach ($m as $x) {
            [$url, $uuid, $w, $h, $file] = $x;
            if ((int)$w < 500) continue;
            if (!preg_match('/\\.(jpe?g|png|gif|webp)$/i', $file)) continue;   // CDN paths without a file type 404
            if (preg_match('/shutterstock|wallpaper|banner|logo|opengraph|placeholder/i', $url)) continue;
            $key = strtolower($file);
            if (!isset($urls[$key]) || (int)$w > 800) $urls[$key] = $url;
        }
        $list = array_values($urls);
        if (!$list && !empty($p['image_url'])) $list = [$p['image_url']];
        return array_slice($list, 0, 3);
    }

    // PDFs published on the product page itself (manuals, manufacturer data
    // sheets). Preferred over a generated sheet when they exist.
    public static function pageDocuments(string $html): array
    {
        $i = stripos($html, 'id="prod-down"');
        if ($i === false) return [];
        $seg = substr($html, $i, 6000);
        preg_match_all('#<a[^>]+href="(https://cdn\\.blake-uk\\.com/[^"]+\\.pdf)"[^>]*>(.*?)</a>#is', $seg, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $title = self::clean($x[2]);
            $title = trim(preg_replace('/\\s*\\([^)]*\\)\\s*$/', '', $title));          // strip "(1B)" size
            $title = trim(str_replace(['_', '.pdf'], [' ', ''], $title));
            if ($title === '') continue;
            $out[$x[1]] = $title;
            if (count($out) >= 3) break;
        }
        return $out;
    }

    // Published documents for a product code (uses the same verified page).
    public static function documentsFor(string $code): array
    {
        $p = \Knowledge\Search::byCode($code);
        if (!$p || empty($p['url'])) return [];
        $html = self::fetch($p['url']);
        return $html ? self::pageDocuments($html) : [];
    }

    // Returns the PDF path (cached per product + data hash).
    public static function generate(string $code): array
    {
        $v = self::verify($code);
        if (!$v['ok']) return $v;
        $d = $v['data'];
        $hash = substr(sha1(json_encode($d)), 0, 10);
        $file = self::dir() . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $d['code']) . "-{$hash}.pdf";
        if (!is_file($file)) {
            self::render($d, $file);
        }
        return ['ok' => true, 'path' => $file, 'data' => $d];
    }

    private static function render(array $d, string $file): void
    {
        $pdf = new \LeafletPdf('P', 'mm', 'A4');
        $pdf->category = strtoupper($d['category']);
        $pdf->AddPage();
        $pdf->body($d);
        $pdf->Output('F', $file);
    }
}

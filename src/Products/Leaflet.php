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
    public const LAYOUT_VERSION = '2026-09-23.5';

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

        // Technical PDFs indexed for this product add the full specification.
        $fromDocs = ['specs' => [], 'files' => []];
        try { $fromDocs = self::specsFromKnowledge($p['product_code']); } catch (\Throwable $e) {}
        $specs = $specs + $fromDocs['specs'];          // page values win on a clash
        $specs = array_slice($specs, 0, 14, true);

        return ['ok' => true, 'data' => [
            'code'       => $p['product_code'],
            'name'       => $p['name'],
            'title'      => $p['title'] ?: $p['name'],
            'h1'         => $h1,
            'url'        => $p['url'],
            'category'   => (json_decode((string)$p['category_path'], true) ?: ['PRODUCT'])[0],
            'intro'      => self::intro($html, $p),
            'specs'      => $specs,
            'doc_files'  => $fromDocs['files'],
            'charts'     => self::docCharts($p['product_code']),
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
    // PDFs published on the product page itself (manuals, manufacturer data
    // sheets). The page's own <a href> links are truncated and return
    // "Access Denied" from the CDN, so the working URL is taken from the
    // adjacent download <form action>, and every link is checked before it
    // is offered to a customer.
    public static function pageDocuments(string $html, bool $check = true): array
    {
        $i = stripos($html, 'id="prod-down"');
        if ($i === false) return [];
        $seg = substr($html, $i, 12000);
        $out = [];
        // Each download block: a label link, then a form with the real URL.
        preg_match_all('#<div class="product-page__download-cont".*?</div>\s*</div>\s*</div>#is', $seg, $blocks);
        $cands = $blocks[0] ?: [$seg];
        foreach ($cands as $block) {
            $url = preg_match('#<form[^>]+action="([^"]+\.pdf)"#i', $block, $f) ? html_entity_decode($f[1]) : null;
            if (!$url && preg_match('#<a[^>]+href="(https://[^"]+\.pdf)"#i', $block, $a)) $url = html_entity_decode($a[1]);
            if (!$url) continue;
            $label = preg_match('#<a[^>]*>(.*?)</a>#is', $block, $l) ? self::clean($l[1]) : basename($url);
            $label = trim(preg_replace('/\s*\(\d+(\.\d+)?\s*[KMG]?B\)\s*$/i', '', $label));      // drop "(712KB)"
            $label = trim(str_replace(['_', '-'], ' ', preg_replace('/\.pdf$/i', '', $label)));
            if ($label === '') $label = 'Product document';
            $out[$url] = $label;
            if (count($out) >= 4) break;
        }
        if (!$check) return $out;
        // Only offer links that actually work.
        $ok = [];
        foreach ($out as $url => $label) {
            if (self::linkWorks($url)) $ok[$url] = $label;
        }
        return $ok;
    }

    public static $linkChecker = null;   // test hook: fn(string $url): bool

    public static function linkWorks(string $url): bool
    {
        if (self::$linkChecker) return (self::$linkChecker)($url);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BlakeUKSupport/1.0)', CURLOPT_RETURNTRANSFER => true]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            return $code === 200 && stripos($type, 'pdf') !== false;
        } catch (\Throwable $e) { return false; }
    }

    // Published documents for a product code (uses the same verified page).
    public static function documentsFor(string $code): array
    {
        $p = \Knowledge\Search::byCode($code);
        if (!$p || empty($p['url'])) return [];
        $html = self::fetch($p['url']);
        return $html ? self::pageDocuments($html) : [];
    }

    // Specification from the indexed technical PDFs for this product
    // (Admin > Files / RAG). The model extracts label/value pairs from the
    // document text, then EVERY pair is checked to appear verbatim in that
    // text before it is used, so nothing can be invented. Results are cached
    // against the source text.
    public static $extractor = null;   // test hook: fn(string $text, string $code): array

    public static function knowledgeSource(string $code): ?array
    {
        $like = '%' . strtoupper($code) . '%';
        try {
            $q = db()->prepare("SELECT kf.id, kf.filename, kc.chunk_text
                                FROM knowledge_chunks kc JOIN knowledge_files kf ON kf.id = kc.source_id
                                WHERE kc.source_type = 'file' AND kf.status = 'indexed'
                                  AND (upper(kf.filename) LIKE ? OR upper(kc.chunk_text) LIKE ?)
                                ORDER BY (upper(kf.filename) LIKE ?) DESC,
                                         (lower(kf.filename) LIKE '%technical%' OR lower(kf.filename) LIKE '%data%sheet%' OR lower(kf.filename) LIKE '%characteristic%' OR lower(kf.filename) LIKE '%spec%') DESC,
                                         length(kc.chunk_text) DESC
                                LIMIT 3");
            $q->execute([$like, $like, $like]);
            $rows = $q->fetchAll();
        } catch (\Throwable $e) { return null; }
        if (!$rows) return null;
        $text = '';
        $names = [];
        foreach ($rows as $r) {
            if (stripos($r['chunk_text'], $code) === false && stripos($r['filename'], $code) === false) continue;
            $text .= "\n" . $r['chunk_text'];
            $names[$r['filename']] = true;
            if (mb_strlen($text) > 7000) break;
        }
        $text = trim($text);
        return $text === '' ? null : ['text' => mb_substr($text, 0, 7000), 'files' => array_keys($names)];
    }

    public static function specsFromKnowledge(string $code): array
    {
        $src = self::knowledgeSource($code);
        if (!$src) return ['specs' => [], 'files' => []];
        $key = 'leaflet_specs_' . strtoupper($code);
        $hash = sha1($src['text']);
        try {
            $c = db()->prepare('SELECT value FROM settings WHERE key = ?');
            $c->execute([$key]);
            $cached = json_decode((string)$c->fetchColumn(), true);
            if (is_array($cached) && ($cached['hash'] ?? '') === $hash) return ['specs' => $cached['specs'], 'files' => $src['files']];
        } catch (\Throwable $e) {}

        $pairs = [];
        try {
            if (self::$extractor) {
                $pairs = (self::$extractor)($src['text'], $code);
            } else {
                $keyApi = \Gemini\Client::getStoredApiKey();
                if (!$keyApi) return ['specs' => [], 'files' => []];
                $out = (new \Gemini\Client($keyApi))->chat(
                    \Gemini\Client::getModel('gemini_extract_model', 'gemini_flash_lite'),
                    [['role' => 'user', 'content' =>
                        "From the technical document text below, list the technical specification of product {$code} ONLY.\n"
                        . "Copy labels and values EXACTLY as written - do not convert, round, summarise or invent anything. "
                        . "Ignore prices, part lists, marketing text and any other product's figures.\n"
                        . "Answer with JSON only: {\"specs\":[{\"label\":\"...\",\"value\":\"...\"}]} (max 14, most important first).\n\n"
                        . $src['text']]]
                );
                $json = preg_replace('/^```(?:json)?|```$/m', '', trim((string)$out));
                $pairs = json_decode(trim($json), true)['specs'] ?? [];
            }
        } catch (\Throwable $e) {
            return ['specs' => [], 'files' => []];
        }

        // Check every pair against the document text (second check).
        $flat = mb_strtolower(preg_replace('/\s+/u', ' ', $src['text']));
        $specs = [];
        foreach (is_array($pairs) ? $pairs : [] as $p) {
            $label = self::clean((string)($p['label'] ?? ''));
            $value = self::clean((string)($p['value'] ?? ''));
            if ($label === '' || $value === '' || mb_strlen($label) > 44 || mb_strlen($value) > 44) continue;
            if (preg_match('/£|price|vat/i', $label . ' ' . $value)) continue;
            $norm = fn(string $x) => mb_strtolower(preg_replace('/\s+/u', ' ', $x));
            if (!str_contains($flat, $norm($label)) || !str_contains($flat, $norm($value))) continue;   // not verbatim: drop
            $specs[$label] = $value;
            if (count($specs) >= 14) break;
        }
        try {
            db()->prepare('INSERT INTO settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at')
                ->execute([$key, json_encode(['hash' => $hash, 'specs' => $specs]), time()]);
        } catch (\Throwable $e) {}
        return ['specs' => $specs, 'files' => $src['files']];
    }

    // Charts and measured-performance pages from this product's own indexed
    // PDFs (test reports, technical characteristics). The pages are rendered
    // as images and trimmed, so graphs drawn as vectors are captured too.
    // Only files whose NAME contains the product code are used, so another
    // product's measurements can never appear.
    // Axis labels: a page that plots something, not just a page that talks
    // about gain (which would pull in an intro page or a product photo).
    public const CHART_WORDS = '/(frequency,?\s*MHz|gain,?\s*dB|noise figure,?\s*dB|MER,?\s*dB|relative gain|output power,?\s*dB|the figure above|dB[µu]V\b.*\bMHz\b)/i';

    public static function docCharts(string $code, int $max = 4): array
    {
        if (!self::hasTool('pdftoppm') || !self::hasTool('pdftotext')) return [];
        try {
            $q = db()->prepare("SELECT id, filename, stored_path FROM knowledge_files
                                WHERE status = 'indexed' AND upper(filename) LIKE ? AND lower(mime_type) LIKE '%pdf%'
                                ORDER BY (lower(filename) LIKE '%test report%' OR lower(filename) LIKE '%graph%') DESC, id DESC LIMIT 3");
            $q->execute(['%' . strtoupper($code) . '%']);
            $files = $q->fetchAll();
        } catch (\Throwable $e) { return []; }

        $out = [];
        foreach ($files as $f) {
            $path = (string)$f['stored_path'];
            if (!is_file($path)) continue;
            $pages = (int)trim((string)shell_exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null | awk \'/^Pages:/{print $2}\''));
            $pages = max(1, min($pages ?: 1, 12));
            for ($p = 1; $p <= $pages && count($out) < $max; $p++) {
                $text = (string)shell_exec('pdftotext -f ' . $p . ' -l ' . $p . ' ' . escapeshellarg($path) . ' - 2>/dev/null');
                if (!preg_match(self::CHART_WORDS, $text)) continue;
                if (stripos($text, $code) === false && stripos((string)$f['filename'], $code) === false) continue;
                // Prefer the chart image itself; fall back to the whole page
                // when the graph is drawn rather than embedded.
                $imgs = self::pageImages($path, $p);
                foreach ($imgs as $img) {
                    $out[] = ['file' => $img, 'caption' => self::clean((string)$f['filename']) . ' - page ' . $p];
                    if (count($out) >= $max) break;
                }
                if (!$imgs) {
                    $img = self::renderPage($path, $p);
                    if ($img) $out[] = ['file' => $img, 'caption' => self::clean((string)$f['filename']) . ' - page ' . $p];
                }
            }
            if (count($out) >= $max) break;
        }
        return $out;
    }

    private static function hasTool(string $bin): bool
    {
        return trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
    }

    // Large embedded images on one page (the charts themselves), as JPEGs.
    public static function pageImages(string $pdf, int $page, int $max = 2): array
    {
        if (!self::hasTool('pdfimages')) return [];
        $key = sha1($pdf . '|' . $page . '|' . (string)@filemtime($pdf));
        $dir = self::dir() . '/img-' . $key;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            shell_exec('pdfimages -png -f ' . $page . ' -l ' . $page . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($dir . '/i') . ' 2>/dev/null');
        }
        $out = [];
        foreach (glob($dir . '/*.png') ?: [] as $png) {
            $size = @getimagesize($png);
            if (!$size) continue;
            [$w, $h] = $size;
            $ratio = $h > 0 ? $w / $h : 0;
            if ($w < 380 || $h < 200 || $ratio < 0.5 || $ratio > 3.2) continue;      // skip logos, rules, tiny marks
            $jpg = preg_replace('/\.png$/', '.jpg', $png);
            if (!is_file($jpg) && function_exists('imagecreatefrompng')) {
                $im = @imagecreatefrompng($png);
                if ($im) {
                    $flat = imagecreatetruecolor($w, $h);
                    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
                    imagejpeg($flat, $jpg, 86);
                    imagedestroy($im); imagedestroy($flat);
                }
            }
            if (is_file($jpg)) $out[] = ['file' => $jpg, 'px' => $w * $h];
        }
        usort($out, fn($a, $b) => $b['px'] <=> $a['px']);
        return array_column(array_slice($out, 0, $max), 'file');
    }

    // One page of a PDF as a trimmed JPEG (cached on the file's timestamp).
    public static function renderPage(string $pdf, int $page): ?string
    {
        $cache = self::dir() . '/doc-' . sha1($pdf . '|' . $page . '|' . (string)@filemtime($pdf)) . '.jpg';
        if (is_file($cache)) return $cache;
        $tmp = self::dir() . '/tmp-' . bin2hex(random_bytes(6));
        shell_exec('pdftoppm -jpeg -r 110 -f ' . $page . ' -l ' . $page . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
        $made = glob($tmp . '*.jpg') ?: [];
        if (!$made) return null;
        $src = $made[0];
        if (function_exists('imagecreatefromjpeg')) {
            $im = @imagecreatefromjpeg($src);
            if ($im) {
                $trim = self::trimWhite($im);
                imagejpeg($trim, $cache, 82);
                imagedestroy($trim);
                if ($trim !== $im) imagedestroy($im);
                @unlink($src);
                return is_file($cache) ? $cache : null;
            }
        }
        rename($src, $cache);
        return $cache;
    }

    // Crops the white margin off a rendered page.
    private static function trimWhite(\GdImage $im): \GdImage
    {
        $w = imagesx($im); $h = imagesy($im);
        $isInk = function (int $x, int $y) use ($im): bool {
            $c = imagecolorat($im, $x, $y);
            return ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3 < 235;
        };
        $step = 2;
        $top = 0; $bottom = $h - 1; $left = 0; $right = $w - 1;
        $rowInk = function (int $y) use ($w, $isInk, $step): bool {
            for ($x = 0; $x < $w; $x += $step) if ($isInk($x, $y)) return true;
            return false;
        };
        $colInk = function (int $x) use ($h, $isInk, $step): bool {
            for ($y = 0; $y < $h; $y += $step) if ($isInk($x, $y)) return true;
            return false;
        };
        while ($top < $h - 1 && !$rowInk($top)) $top += $step;
        while ($bottom > $top + 10 && !$rowInk($bottom)) $bottom -= $step;
        while ($left < $w - 1 && !$colInk($left)) $left += $step;
        while ($right > $left + 10 && !$colInk($right)) $right -= $step;
        $pad = 8;
        $x = max(0, $left - $pad); $y = max(0, $top - $pad);
        $cw = min($w - $x, $right - $left + 2 * $pad);
        $ch = min($h - $y, $bottom - $top + 2 * $pad);
        if ($cw < 50 || $ch < 50) return $im;
        $crop = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]);
        return $crop ?: $im;
    }

    // Returns the PDF path (cached per product + data hash).
    public static function generate(string $code): array
    {
        $v = self::verify($code);
        if (!$v['ok']) return $v;
        $d = $v['data'];
        // LAYOUT_VERSION is part of the key so design changes replace cached sheets.
        $hash = substr(sha1(self::LAYOUT_VERSION . json_encode($d)), 0, 10);
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

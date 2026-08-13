<?php
// src/Sitemap/PageAnalyzer.php
// Pulls SEO/QA-relevant signals out of a fetched page's HTML for
// public/check/probe.php - title, meta description, canonical tag,
// robots directives, heading structure, mixed content, and a best-effort
// "soft 404" heuristic (a page that answers HTTP 200 but is visibly an
// error page - a plain status-code check can't see this at all). Pure
// function of (html, finalUrl) -> array, no network or DB, so it's unit
// testable against fixture HTML strings.

declare(strict_types=1);

namespace Sitemap;

class PageAnalyzer
{
    // Deliberately conservative (favours missing a soft-404 over
    // flagging a normal page) - this is a heuristic for a human to
    // spot-check, not an automatic judgement, and the UI labels it that
    // way rather than presenting it as a definite error.
    private const SOFT_404_PATTERNS = [
        '/page\s*(?:cannot|can[\'’]?t|could not)\s*be\s*found/i',
        '/page\s*not\s*found/i',
        '/\b404[\s\-:]*(?:error|not\s*found)\b/i',
        '/we\s*can[\'’]?t\s*find\s*(?:that|this|the)\s*page/i',
        '/the\s*page\s*you[\'’]?re\s*looking\s*for.{0,25}(?:doesn[\'’]?t|does\s*not)\s*exist/i',
        '/this\s*(?:content|product|item)\s*is\s*no\s*longer\s*available/i',
        '/sorry,?\s*(?:we|this)\b.{0,20}\bpage\b/i',
    ];

    public static function empty(): array
    {
        return [
            'title' => null, 'title_length' => 0,
            'meta_description' => null, 'meta_description_length' => 0,
            'canonical' => null, 'canonical_mismatch' => false,
            'meta_robots' => null, 'noindex_meta' => false,
            'h1_count' => 0, 'lang' => null,
            'mixed_content' => false,
            'soft_404' => false, 'soft_404_evidence' => null,
            'structured_data_types' => [], 'has_structured_data' => false,
            'has_open_graph' => false, 'has_twitter_card' => false,
            'hreflang_count' => 0, 'hreflang_langs' => [],
            'images_total' => 0, 'images_missing_alt' => 0,
            'word_count' => 0,
            'heading_skips' => false,
            'js_dependent' => false,
        ];
    }

    public static function analyze(string $html, string $finalUrl): array
    {
        $result = self::empty();
        if (trim($html) === '') return $result;

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Force UTF-8 interpretation regardless of what (if any) charset
        // meta tag the page declares - loadHTML falls back to Latin-1
        // otherwise, which mangles anything non-ASCII in the title/description.
        $ok = @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        if (!$ok) return $result;

        $xpath = new \DOMXPath($dom);

        $titleNode = $xpath->query('//title')->item(0);
        if ($titleNode) {
            $title = trim(preg_replace('/\s+/', ' ', $titleNode->textContent) ?? '');
            if ($title !== '') {
                $result['title'] = $title;
                $result['title_length'] = mb_strlen($title);
            }
        }

        $descNode = $xpath->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content')->item(0);
        if ($descNode) {
            $desc = trim($descNode->nodeValue ?? '');
            if ($desc !== '') {
                $result['meta_description'] = $desc;
                $result['meta_description_length'] = mb_strlen($desc);
            }
        }

        $canonNode = $xpath->query('//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href')->item(0);
        if ($canonNode) {
            $canon = trim($canonNode->nodeValue ?? '');
            if ($canon !== '') {
                $result['canonical'] = $canon;
                $absoluteCanon = UrlHelper::resolve($finalUrl, $canon);
                $result['canonical_mismatch'] = UrlHelper::normalize($absoluteCanon) !== UrlHelper::normalize($finalUrl);
            }
        }

        $robotsNode = $xpath->query('//meta[translate(@name,"ROBOTS","robots")="robots"]/@content')->item(0);
        if ($robotsNode) {
            $robots = trim($robotsNode->nodeValue ?? '');
            if ($robots !== '') {
                $result['meta_robots'] = $robots;
                $result['noindex_meta'] = stripos($robots, 'noindex') !== false;
            }
        }

        $result['h1_count'] = $xpath->query('//h1')->length;

        $langAttr = $xpath->query('//html/@lang')->item(0);
        if ($langAttr) {
            $lang = trim($langAttr->nodeValue ?? '');
            $result['lang'] = $lang !== '' ? $lang : null;
        }

        if (stripos($finalUrl, 'https://') === 0) {
            $resourceNodes = $xpath->query(
                '//script/@src | //img/@src | //iframe/@src | //source/@src |' .
                ' //video/@src | //audio/@src | //embed/@src |' .
                ' //link[translate(@rel,"STYLESHEET","stylesheet")="stylesheet"]/@href'
            );
            foreach ($resourceNodes as $attr) {
                if (stripos(trim($attr->nodeValue ?? ''), 'http://') === 0) {
                    $result['mixed_content'] = true;
                    break;
                }
            }
        }

        $bodyText = $dom->textContent ?? '';
        $haystack = ($result['title'] ?? '') . ' ' . self::firstChars($bodyText, 2000);
        foreach (self::SOFT_404_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack, $m)) {
                $result['soft_404'] = true;
                $result['soft_404_evidence'] = trim($m[0]);
                break;
            }
        }

        // Structured data (JSON-LD) - what lets search engines and AI
        // answer engines understand this page as a specific kind of thing
        // (Product, Article, FAQPage, Organization...) rather than just
        // parsing prose. Handles both a bare {"@type": "..."} object and
        // the {"@graph": [...]} shape many CMS/SEO plugins emit.
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent ?? ''), true);
            if (is_array($decoded)) {
                self::collectJsonLdTypes($decoded, $result['structured_data_types']);
            }
        }
        $result['structured_data_types'] = array_values(array_unique($result['structured_data_types']));
        $result['has_structured_data'] = count($result['structured_data_types']) > 0;

        // Open Graph / Twitter Card - how the page previews when shared,
        // and signal several AI crawlers also read for a quick summary
        // instead of (or alongside) parsing the full body.
        foreach ($xpath->query('//meta[@property]') as $meta) {
            if (stripos(trim($meta->getAttribute('property')), 'og:') === 0) {
                $result['has_open_graph'] = true;
                break;
            }
        }
        foreach ($xpath->query('//meta[@name]') as $meta) {
            if (stripos(trim($meta->getAttribute('name')), 'twitter:') === 0) {
                $result['has_twitter_card'] = true;
                break;
            }
        }

        $hreflangLangs = [];
        foreach ($xpath->query('//link[@hreflang]') as $link) {
            $lang = trim($link->getAttribute('hreflang'));
            if ($lang !== '') $hreflangLangs[] = $lang;
        }
        $result['hreflang_langs'] = array_values(array_unique($hreflangLangs));
        $result['hreflang_count'] = count($hreflangLangs);

        $images = $xpath->query('//img');
        $result['images_total'] = $images->length;
        foreach ($images as $img) {
            if (!$img->hasAttribute('alt')) $result['images_missing_alt']++;
        }

        $words = preg_split('/\s+/', trim(preg_replace('/\s+/', ' ', $bodyText) ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $result['word_count'] = count($words);

        $result['heading_skips'] = self::hasHeadingSkips($xpath);

        // Coarse heuristic: a page with almost no visible text but a
        // substantial amount of markup is very likely a JS-rendered SPA
        // shell - real content pages essentially never look like this.
        // Several major AI crawlers (GPTBot, ClaudeBot, CCBot among them)
        // do not execute JavaScript, so this is exactly the content they
        // would miss entirely.
        $result['js_dependent'] = $result['word_count'] < 50 && strlen($html) > 2000;

        return $result;
    }

    // True if the heading levels used on the page skip forward by more
    // than one (e.g. an h2 followed directly by an h4 with no h3 between
    // them) - a common accessibility/SEO structure issue. Union XPath
    // queries return nodes in document order, so this walks the combined
    // h1..h6 node list as they actually appear on the page.
    private static function hasHeadingSkips(\DOMXPath $xpath): bool
    {
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $prevLevel = null;
        foreach ($headings as $h) {
            $level = (int)substr($h->nodeName, 1);
            if ($prevLevel !== null && $level > $prevLevel + 1) return true;
            $prevLevel = $level;
        }
        return false;
    }

    // Recursively pulls @type values out of a decoded JSON-LD structure,
    // including nested @graph arrays and @type values given as an array
    // rather than a single string (both are valid per the spec).
    private static function collectJsonLdTypes(array $node, array &$types, int $depth = 0): void
    {
        if ($depth > 5) return; // guards against pathological nesting

        if (isset($node['@type'])) {
            foreach ((array)$node['@type'] as $t) {
                if (is_string($t) && trim($t) !== '') $types[] = trim($t);
            }
        }
        foreach (['@graph', 'itemListElement'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                foreach ($node[$key] as $child) {
                    if (is_array($child)) self::collectJsonLdTypes($child, $types, $depth + 1);
                }
            }
        }
        // A top-level JSON-LD array of objects (rather than one object).
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) self::collectJsonLdTypes($child, $types, $depth + 1);
            }
        }
    }

    private static function firstChars(string $text, int $n): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return mb_substr(trim($text), 0, $n);
    }
}

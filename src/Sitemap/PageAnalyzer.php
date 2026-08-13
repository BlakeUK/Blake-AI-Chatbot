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

        $haystack = ($result['title'] ?? '') . ' ' . self::firstChars($dom->textContent ?? '', 2000);
        foreach (self::SOFT_404_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack, $m)) {
                $result['soft_404'] = true;
                $result['soft_404_evidence'] = trim($m[0]);
                break;
            }
        }

        return $result;
    }

    private static function firstChars(string $text, int $n): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return mb_substr(trim($text), 0, $n);
    }
}

<?php
// src/Html/TextCleaner.php
// Strips a raw HTML page down to readable text before it goes to Gemini for
// extraction - script/style content, comments, and tags themselves are all
// noise for this purpose and just cost tokens without adding signal.

declare(strict_types=1);

namespace Html;

class TextCleaner
{
    public static function toReadableText(string $html, int $maxChars = 15000): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('/<(br|p|div|li|tr|h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n+/', "\n", $text) ?? $text;
        $text = trim($text);

        // Product pages can run long with reviews/related-product blocks;
        // the fields this feature cares about (code, price, title, specs)
        // are almost always near the top of the page content, so a cap
        // keeps the request small without needing to be clever about it.
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . "\n[...truncated...]";
        }

        return $text;
    }

    // Page text WITHOUT the site chrome - header, menus, basket, footer,
    // cookie/newsletter panels, breadcrumbs and "related products" strips.
    // Those blocks are identical on every page of the site, so leaving them
    // in (a) buries the real content in search and (b) makes every pair of
    // pages look ~99% similar to the duplicate checker. Falls back to the
    // whole-page text when the page has no recognisable structure.
    public static function mainContentText(string $html, int $maxChars = 15000): string
    {
        if (trim($html) === '') return '';
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) return self::toReadableText($html, $maxChars);

        $xp = new \DOMXPath($doc);
        $chrome = '//header | //footer | //nav | //aside | //form | //script | //style | //noscript | //svg | //iframe'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " header ") or contains(concat(" ", normalize-space(@class), " "), " footer ")]'
            . ' | //*[@role="navigation" or @role="banner" or @role="contentinfo" or @aria-hidden="true"]';
        $noise = '/(^|[\s_-])(basket|minicart|mini-cart|cart|breadcrumb|related|upsell|cross-?sell|recently-viewed|cookie|newsletter|modal|popup|menu|sub-nav|subnav|megamenu|quick-?add|trustpilot|feefo|social|share)([\s_-]|$)/i';
        $remove = [];
        foreach ($xp->query($chrome) ?: [] as $n) $remove[] = $n;
        foreach ($xp->query('//*[@class or @id]') ?: [] as $n) {
            /** @var \DOMElement $n */
            if (preg_match($noise, $n->getAttribute('class') . ' ' . $n->getAttribute('id'))) $remove[] = $n;
        }
        foreach ($remove as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
        // Prefer an explicit main region when the page has one.
        $main = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")] | //main | //*[@id="maincontent" or @id="main" or @role="main"]');
        $root = ($main && $main->length) ? $main->item(0) : ($doc->getElementsByTagName('body')->item(0) ?? $doc);
        $text = self::toReadableText($doc->saveHTML($root), $maxChars);
        if (mb_strlen(trim($text)) < 80) return self::toReadableText($html, $maxChars);
        return $text;
    }

    public static function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        }
        return null;
    }

    public static function extractMetaDescription(string $html): ?string
    {
        if (preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }
        return null;
    }
}

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

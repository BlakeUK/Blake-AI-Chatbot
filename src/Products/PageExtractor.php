<?php
// src/Products/PageExtractor.php
// Shared by product_template_preview.php (one page, for review) and
// scripts/process_product_pages.php (many pages, in the background) -
// same fetch/clean/prompt/parse logic either way, just called once vs. in
// a loop.

declare(strict_types=1);

namespace Products;

class PageExtractor
{
    // Returns one of:
    //   ['ok' => true,  'is_product_page' => true,  'extracted' => array]
    //   ['ok' => true,  'is_product_page' => false]
    //   ['ok' => false, 'raw' => string]                                    (Gemini didn't return valid JSON)
    // Throws \RuntimeException on fetch or Gemini request failure - the
    // caller decides how to record that (json_err for the interactive
    // preview, a row-level 'error' status for the background processor).
    public static function extract(string $url, array $shape, ?array $referenceExample = null): array
    {
        $html = self::fetch($url);
        $text = \Html\TextCleaner::toReadableText($html);
        if (trim($text) === '') {
            throw new \RuntimeException('No readable text found on that page');
        }

        $apiKey = \Gemini\Client::getStoredApiKey();
        if (!$apiKey) {
            throw new \RuntimeException('Gemini API key not configured');
        }

        $model = \Gemini\Client::getModel('gemini_extract_model', 'gemini_flash');

        $prompt = self::buildPrompt($shape, $referenceExample);

        $client = new \Gemini\Client($apiKey);
        $raw    = $client->extractStructured($model, $text, $prompt);

        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```\s*$/', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            return ['ok' => false, 'raw' => $raw];
        }
        if (!empty($parsed['_not_a_product_page'])) {
            return ['ok' => true, 'is_product_page' => false];
        }
        return ['ok' => true, 'is_product_page' => true, 'extracted' => $parsed];
    }

    public static function fetch(string $url): string
    {
        $fetch = \Http\SafeFetcher::get($url, 20);
        if (!$fetch['ok']) {
            throw new \RuntimeException('Could not fetch page: ' . ($fetch['error'] ?: "HTTP {$fetch['code']}"));
        }
        return $fetch['body'];
    }

    private static function buildPrompt(array $shape, ?array $referenceExample): string
    {
        $shapeJson = json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $referenceBlock = '';
        if ($referenceExample) {
            $exampleJson = json_encode($referenceExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $referenceBlock = <<<REF

For reference, here is a correctly-extracted example from a similar page
on the same site, confirmed accurate by a human reviewer. Match this same
style of interpretation (e.g. how prices, stock status, and category paths
are represented) for consistency across pages, but extract THIS page's own
actual values, not the example's:

{$exampleJson}
REF;
        }

        return <<<PROMPT
You extract structured product data from e-commerce page content.

Below is the target JSON shape - the field names and their expected types,
shown with example/placeholder values. Extract those SAME fields from the
page content that follows, using values actually found on that page.

- If a field genuinely isn't present on the page, use null for it - do not
  guess or invent a value.
- If this page is not a product page at all (e.g. an FAQ, guide, or other
  informational page with no product to describe), respond with exactly:
  {"_not_a_product_page": true}
- Respond with ONLY the JSON object, matching the target shape's fields.
  No markdown code fences, no commentary, no explanation.

TARGET SHAPE:
{$shapeJson}
{$referenceBlock}
PROMPT;
    }
}

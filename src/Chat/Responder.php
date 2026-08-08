<?php
// src/Chat/Responder.php
// The RAG context/prompt/confidence logic behind the main chat endpoint,
// factored out of public/api/chat/send.php so it can be exercised directly
// by tests (tests/cases/*.php, tests/eval/*.php) without duplicating it -
// a copy in a test fixture would silently drift from what production
// actually runs.

declare(strict_types=1);

namespace Chat;

class Responder
{
    // Retrieves knowledge + product context for a message, given the
    // customer's current page context (product-aware chat).
    public static function buildContext(string $message, ?string $productCode): array
    {
        $knowledgeHits   = \Knowledge\Search::query($message, 5);
        $productHits     = \Knowledge\Search::products($message, 3);
        $currentProduct  = $productCode ? \Knowledge\Search::byCode($productCode) : null;

        // Admin-curated word/phrase -> page pins (see KeywordLinks). Unlike
        // the FTS hits above, a match here is a deliberate editorial
        // decision, not a probabilistic one - so it counts toward
        // confidence just as strongly as an organic hit (see confidence()).
        $keywordLinks = \Knowledge\KeywordLinks::match($message);

        // The product the customer is currently on gets included in
        // context/cards even if their message text doesn't happen to match
        // it via FTS - but it does NOT by itself count toward confidence
        // (see confidence() below), since being on a product page doesn't
        // mean an unrelated question ("what are your hours?") is actually
        // answerable from that product's data.
        $contextProducts = \Knowledge\Search::withCurrentFirst($productHits, $currentProduct);

        // Cross-sell: if the current product lists related/alternative
        // codes, pull them in too (also not a confidence signal - same
        // reasoning as above).
        $relatedCodes     = [];
        $alternativeCodes = [];
        if ($currentProduct) {
            $relatedCodes     = json_decode($currentProduct['related_product_codes'] ?? '[]', true) ?: [];
            $related          = \Knowledge\Search::byCodes($relatedCodes, 3);
            $contextProducts  = \Knowledge\Search::addRelated($contextProducts, $related);

            $alternativeCodes = json_decode($currentProduct['alternative_product_codes'] ?? '[]', true) ?: [];
            $alternatives     = \Knowledge\Search::byCodes($alternativeCodes, 3);
            $contextProducts  = \Knowledge\Search::addRelated($contextProducts, $alternatives);
        }

        return [
            'knowledge_hits'    => $knowledgeHits,
            'product_hits'      => $productHits,
            'current_product'   => $currentProduct,
            'context_products'  => $contextProducts,
            'related_codes'     => $relatedCodes,
            'alternative_codes' => $alternativeCodes,
            'keyword_links'     => $keywordLinks,
        ];
    }

    // Builds the full Gemini system prompt from a buildContext() result.
    public static function buildPrompt(array $ctx, ?string $currentCode, ?string $pageUrl): string
    {
        $contextParts = [];

        if ($ctx['knowledge_hits']) {
            $contextParts[] = "KNOWLEDGE BASE:\n" . implode("\n---\n", array_map(
                fn($h) => $h['chunk_text'] . ($h['url'] ? "\nSource: " . $h['url'] : ''),
                $ctx['knowledge_hits']
            ));
        }

        if ($ctx['keyword_links']) {
            $contextParts[] = "RELEVANT PAGES:\n" . implode("\n", array_map(
                fn($k) => "- {$k['title']}: {$k['url']}",
                $ctx['keyword_links']
            ));
        }

        if ($ctx['context_products']) {
            $contextParts[] = "PRODUCTS:\n" . implode("\n---\n", array_map(
                fn($p) => \Knowledge\Search::formatForPrompt($p, $currentCode, $ctx['related_codes'], $ctx['alternative_codes']),
                $ctx['context_products']
            ));
        }

        $pageCtx = $pageUrl ? "Customer is viewing: {$pageUrl}\n" : '';

        $system = <<<PROMPT
You are the Blake UK customer support assistant. Blake UK sells aerials, IRS, CCTV, networking, fibre, satellite and installation products.

RULES:
- Answer ONLY using the context provided below. Do not invent products, prices or specifications.
- Keep answers concise and helpful.
- Always include direct Blake UK URLs when recommending products or support pages.
- If a page is listed under RELEVANT PAGES and matches what the customer is asking about, include its exact URL in your answer.
- Products tagged [Related product] are cross-sell/accessory suggestions for what the customer is viewing — mention one only if it's naturally relevant to their question, don't force it into every reply.
- Products tagged [Alternative product] are substitutes for what the customer is viewing (e.g. if it's out of stock or they want a different spec) — mention one if the customer asks about alternatives, other options, or if the current product is out of stock.
- If you cannot answer from the context, say: "I don't have enough information to answer that. Please contact Blake UK support at https://www.blake-uk.com/support.html"
- Never make up product codes, prices or specifications.

{$pageCtx}
PROMPT;

        $contextBlock = implode("\n\n", $contextParts);
        return $contextBlock ? $system . "\n\n" . $contextBlock : $system;
    }

    // Simple: if context was found, confidence is higher. Deliberately based
    // on $productHits (organic matches for this message), not
    // $contextProducts (which always includes the current product
    // regardless of relevance) - see buildContext()'s comment. $keywordLinkHits
    // defaults to [] so existing callers/tests written before keyword links
    // existed don't need updating.
    public static function confidence(array $knowledgeHits, array $productHits, array $keywordLinkHits = []): float
    {
        return (count($knowledgeHits) + count($productHits) + count($keywordLinkHits)) > 0 ? 0.75 : 0.3;
    }

    public static function shouldEscalate(float $confidence): bool
    {
        return $confidence < CFG['escalate_threshold'];
    }
}

<?php
// src/Sitemap/RobotsTxt.php
// Parses robots.txt and answers "is bot X allowed to fetch path Y" per the
// de-facto standard Google documents (RFC 9309): groups keyed by
// User-agent, longest-matching Disallow/Allow prefix wins, ties go to
// Allow, '*' wildcards and a trailing '$' end-anchor are supported. Pure
// function of (robots.txt text) -> structure, no network - public/check
// /robots.php does the fetching via Http\SafeFetcher.
//
// Also carries the known-bot reference lists public/check/robots.php and
// index.html use to answer "can AI crawlers read this site" - the
// specific thing this tool's bot/AI-handling section exists for.

declare(strict_types=1);

namespace Sitemap;

class RobotsTxt
{
    // Mainstream search engine crawlers - the traditional "is this site
    // even indexable" check every SEO audit tool has always done.
    public const SEARCH_BOTS = [
        'Googlebot'   => 'Google Search indexing',
        'Bingbot'     => 'Bing indexing',
        'DuckDuckBot' => 'DuckDuckGo indexing',
        'Slurp'       => 'Yahoo indexing',
        'YandexBot'   => 'Yandex indexing',
        'Baiduspider' => 'Baidu indexing',
    ];

    // AI crawlers - what actually reads this site to train models, answer
    // chat queries, or generate AI Overviews / cited answers. Distinct
    // from the search bots above: a site can be fully open to Googlebot
    // for search while blocking Google-Extended (Google's separate
    // AI-training token) or GPTBot, and that distinction is invisible
    // unless it's checked explicitly - which is the point of this list.
    public const AI_BOTS = [
        'GPTBot'             => 'OpenAI - trains ChatGPT/GPT models on this content',
        'ChatGPT-User'       => 'OpenAI - fetches pages a ChatGPT user links to or asks about',
        'OAI-SearchBot'      => 'OpenAI - powers ChatGPT search result citations',
        'ClaudeBot'          => 'Anthropic - crawls for Claude model training',
        'Claude-Web'         => 'Anthropic - fetches pages a Claude user references',
        'anthropic-ai'       => 'Anthropic - general AI crawler',
        'Google-Extended'    => "Google - controls this site's use in Gemini / AI Overviews training, separate from normal Googlebot search indexing",
        'CCBot'              => 'Common Crawl - open web dataset many LLMs (including early GPT models) are trained on',
        'PerplexityBot'      => 'Perplexity AI - powers Perplexity search answers',
        'Bytespider'         => 'ByteDance/TikTok - AI training crawler',
        'Amazonbot'          => 'Amazon - includes Alexa/AI crawling',
        'Applebot-Extended'  => "Apple - controls this site's use in Apple Intelligence training",
        'meta-externalagent' => 'Meta - crawls for Meta AI training',
        'Diffbot'            => 'Diffbot - structured-data/AI extraction crawler',
    ];

    // Returns ['groups' => [['agents' => [...], 'rules' => [['type'=>'disallow'|'allow'|'crawl-delay', 'path'=>string]]], ...], 'sitemaps' => [string, ...]]
    public static function parse(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $groups = [];
        $sitemaps = [];
        $currentAgents = [];
        $currentRules = [];

        $flush = function () use (&$groups, &$currentAgents, &$currentRules) {
            if ($currentAgents) {
                $groups[] = ['agents' => $currentAgents, 'rules' => $currentRules];
            }
            $currentAgents = [];
            $currentRules = [];
        };

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? $line);
            if ($line === '' || !str_contains($line, ':')) continue;

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($value !== '') $sitemaps[] = $value;
                continue;
            }
            if ($field === 'user-agent') {
                // A new User-agent line after rules have already been
                // collected for the current group starts a fresh group;
                // consecutive User-agent lines with no rules between them
                // share one group, per the spec.
                if ($currentRules) $flush();
                $currentAgents[] = $value;
                continue;
            }
            if (in_array($field, ['disallow', 'allow', 'crawl-delay'], true) && $currentAgents) {
                $currentRules[] = ['type' => $field, 'path' => $value];
            }
        }
        $flush();

        return ['groups' => $groups, 'sitemaps' => $sitemaps];
    }

    // True if $botName is refused $path by this robots.txt (falls back to
    // the '*' group if the bot isn't named explicitly; true/allowed if
    // there's no applicable rule at all - the correct default).
    public static function isAllowed(array $parsed, string $botName, string $path = '/'): bool
    {
        $group = self::findGroupFor($parsed, $botName);
        if ($group === null) return true;
        return self::isPathAllowed($group, $path);
    }

    private static function findGroupFor(array $parsed, string $botName): ?array
    {
        $exact = null;
        $wildcard = null;
        foreach ($parsed['groups'] as $g) {
            foreach ($g['agents'] as $a) {
                if (strcasecmp($a, $botName) === 0) $exact = $g;
                if ($a === '*') $wildcard = $g;
            }
        }
        return $exact ?? $wildcard;
    }

    private static function isPathAllowed(array $group, string $path): bool
    {
        $bestLen = -1;
        $bestType = 'allow';
        foreach ($group['rules'] as $r) {
            if (!in_array($r['type'], ['allow', 'disallow'], true) || $r['path'] === '') continue;
            if (!self::pathMatches($path, $r['path'])) continue;
            $len = strlen($r['path']);
            // Longest match wins; on a tie, Allow wins (Google's
            // documented tie-break for robots.txt).
            if ($len > $bestLen || ($len === $bestLen && $r['type'] === 'allow')) {
                $bestLen = $len;
                $bestType = $r['type'];
            }
        }
        return $bestLen === -1 || $bestType === 'allow';
    }

    private static function pathMatches(string $path, string $pattern): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $core = $anchored ? substr($pattern, 0, -1) : $pattern;
        $parts = array_map(fn($seg) => preg_quote($seg, '#'), explode('*', $core));
        $regex = '#^' . implode('.*', $parts) . ($anchored ? '$' : '') . '#';
        return (bool)preg_match($regex, $path);
    }
}

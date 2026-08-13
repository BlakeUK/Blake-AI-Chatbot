<?php
// public/check/robots.php
// Bot & AI access report for public/check/index.html: fetches robots.txt
// (and checks for llms.txt, the emerging convention sites use to describe
// their content for LLMs - see llmstxt.org) and reports, per known
// crawler, whether it's allowed to fetch the site at all. This is what
// answers "does this site block AI crawlers" - something a generic 404
// checker has no visibility into, since robots.txt access has nothing to
// do with whether individual pages return 200.
//
// Same SSRF posture as sitemap.php/probe.php: fetch goes through
// Http\SafeFetcher, and the host is pinned to blake-uk.com regardless of
// what's passed in - this never fetches an arbitrary caller-supplied host.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();
\Auth\CheckTool::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

rate_limit('check_robots', 20);

const ROBOTS_DEFAULT_HOST = 'www.blake-uk.com';

$ref = trim((string)($_GET['url'] ?? ''));
$refHost = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : null;
$host = ($refHost && \Sitemap\AllowedSites::isHostAllowed($refHost))
    ? $refHost
    : ROBOTS_DEFAULT_HOST;

$robotsUrl = "https://{$host}/robots.txt";
$llmsUrl = "https://{$host}/llms.txt";

$robotsFetch = \Http\SafeFetcher::get($robotsUrl, 15);
$llmsFetch = \Http\SafeFetcher::get($llmsUrl, 10);

$robotsOut = [
    'url'    => $robotsUrl,
    'exists' => $robotsFetch['ok'],
    'status' => $robotsFetch['code'],
];

if ($robotsFetch['ok']) {
    $parsed = \Sitemap\RobotsTxt::parse((string)$robotsFetch['body']);

    $bots = [];
    foreach (\Sitemap\RobotsTxt::SEARCH_BOTS as $token => $label) {
        $bots[] = ['token' => $token, 'label' => $label, 'category' => 'search', 'allowed' => \Sitemap\RobotsTxt::isAllowed($parsed, $token)];
    }
    foreach (\Sitemap\RobotsTxt::AI_BOTS as $token => $label) {
        $bots[] = ['token' => $token, 'label' => $label, 'category' => 'ai', 'allowed' => \Sitemap\RobotsTxt::isAllowed($parsed, $token)];
    }

    $robotsOut['sitemaps'] = $parsed['sitemaps'];
    $robotsOut['blocks_everything'] = !\Sitemap\RobotsTxt::isAllowed($parsed, '*');
    $robotsOut['bots'] = $bots;
    $robotsOut['raw_excerpt'] = mb_substr((string)$robotsFetch['body'], 0, 3000);
    $robotsOut['truncated'] = mb_strlen((string)$robotsFetch['body']) > 3000;
}

json_out([
    'ok'         => true,
    'robots_txt' => $robotsOut,
    'llms_txt'   => [
        'url'    => $llmsUrl,
        'exists' => $llmsFetch['ok'],
        'status' => $llmsFetch['code'],
        'bytes'  => $llmsFetch['ok'] ? strlen((string)$llmsFetch['body']) : 0,
    ],
]);

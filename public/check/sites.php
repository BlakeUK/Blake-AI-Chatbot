<?php
// public/check/sites.php
// Lists the sites public/check is allowed to scan (Sitemap\AllowedSites),
// so the frontend's site picker reads from the same single source of
// truth sitemap.php/probe.php/robots.php enforce as their allowlist,
// rather than a second hardcoded copy of the list that could drift.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();
\Auth\CheckTool::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

rate_limit('check_sites', 60);

$out = [];
foreach (\Sitemap\AllowedSites::SITES as $key => $site) {
    $out[] = ['key' => $key, 'label' => $site['label'], 'default_sitemap' => $site['default_sitemap']];
}

json_out(['ok' => true, 'sites' => $out]);

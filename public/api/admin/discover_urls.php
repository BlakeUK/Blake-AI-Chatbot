<?php
// public/api/admin/discover_urls.php — GET: find PDF and other links on a
// page or in a sitemap
// Used ahead of import_urls.php (PDFs) or import_page_links.php (everything
// else - category/support pages etc.) so an admin can point at a listing
// page or sitemap.xml instead of manually collecting individual URLs.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pageUrl = trim($_GET['url'] ?? '');
if (!preg_match('#^https?://#i', $pageUrl)) {
    json_err('Not a valid http(s) URL');
}

$fetch = \Http\SafeFetcher::get($pageUrl, 30);
$data  = $fetch['body'];

if (!$fetch['ok']) {
    json_err('Could not fetch page: ' . ($fetch['error'] ?: "HTTP {$fetch['code']}"), 502);
}

$links = [];

if (str_contains($data, '<urlset') || str_contains($data, '<sitemapindex')) {
    // Sitemap XML — pull <loc> entries (covers both a urlset and a sitemap
    // index; index entries are just more URLs the admin can discover from).
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml) {
        foreach ($xml->url ?? [] as $u) {
            $links[] = (string)$u->loc;
        }
        foreach ($xml->sitemap ?? [] as $s) {
            $links[] = (string)$s->loc;
        }
    }
} elseif (preg_match_all('/href=["\']([^"\']+)["\']/i', $data, $m)) {
    $links = $m[1];
}

$pdfLinks   = [];
$otherLinks = [];
foreach ($links as $link) {
    $abs = _resolve_url($pageUrl, $link);
    if (!$abs) continue;
    if (preg_match('/\.pdf(\?|$)/i', $abs)) {
        $pdfLinks[] = $abs;
    } else {
        $otherLinks[] = $abs;
    }
}
$pdfLinks   = array_values(array_unique($pdfLinks));
$otherLinks = array_values(array_unique($otherLinks));

json_out(['links' => $pdfLinks, 'other_links' => $otherLinks, 'total_links_found' => count($links)]);

function _resolve_url(string $base, string $rel): ?string
{
    if (preg_match('#^https?://#i', $rel)) return $rel;
    if (str_starts_with($rel, '#') || str_starts_with($rel, 'mailto:') || str_starts_with($rel, 'javascript:')) {
        return null;
    }
    $baseParts = parse_url($base);
    if (!$baseParts) return null;
    $scheme   = $baseParts['scheme'] ?? 'https';
    $host     = $baseParts['host'] ?? '';
    $hostPort = $host . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

    if (str_starts_with($rel, '//')) {
        return "{$scheme}:{$rel}";
    }
    if (str_starts_with($rel, '/')) {
        return "{$scheme}://{$hostPort}{$rel}";
    }
    $basePath = $baseParts['path'] ?? '/';
    $dir      = substr($basePath, 0, strrpos($basePath, '/') + 1) ?: '/';
    return "{$scheme}://{$hostPort}{$dir}{$rel}";
}

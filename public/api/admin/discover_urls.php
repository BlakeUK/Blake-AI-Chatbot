<?php
// public/api/admin/discover_urls.php — GET: find PDF links on a page or in a sitemap
// Used ahead of import_urls.php so an admin can point at a listing page
// or sitemap.xml instead of manually collecting individual PDF URLs.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$pageUrl = trim($_GET['url'] ?? '');
if (!preg_match('#^https?://#i', $pageUrl)) {
    json_err('Not a valid http(s) URL');
}

$ch = curl_init($pageUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'BlakeUKChatbotImporter/1.0',
]);
$data = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($data === false || $code !== 200) {
    json_err('Could not fetch page: ' . ($err ?: "HTTP {$code}"), 502);
}

$links = [];

if (str_contains($data, '<urlset') || str_contains($data, '<sitemapindex')) {
    // Sitemap XML — pull <loc> entries (covers both a urlset and a sitemap
    // index; index entries are just more URLs the admin can discover from).
    // LIBXML_NONET blocks any network fetch a crafted DOCTYPE/entity in the
    // fetched page might try to trigger while parsing.
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

$pdfLinks = [];
foreach ($links as $link) {
    $abs = _resolve_url($pageUrl, $link);
    if ($abs && preg_match('/\.pdf(\?|$)/i', $abs)) {
        $pdfLinks[] = $abs;
    }
}
$pdfLinks = array_values(array_unique($pdfLinks));

json_out(['links' => $pdfLinks, 'total_links_found' => count($links)]);

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

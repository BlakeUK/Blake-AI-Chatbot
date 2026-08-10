<?php
// public/api/admin/download_cleaned_sitemap.php — POST: re-fetch a sitemap
// (or sitemap index) the admin originally scanned, and return it with only
// the specific <url>/<sitemap> entries whose <loc> is in exclude_urls
// removed — everything else (lastmod, priority, namespaces, comments,
// formatting, entries never even checked) survives untouched.
//
// Deliberately re-fetches rather than reusing anything discover_urls.php
// saw earlier: that endpoint only ever kept the flattened <loc> text, not
// the original document, and re-fetching avoids adding storage just to
// bridge scan-time to download-time. DOM removal (not string rebuilding)
// is what makes this a faithful replica rather than the synthetic minimal
// sitemap the frontend falls back to when the original source wasn't XML
// at all (e.g. an HTML page was scanned instead of a sitemap).

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$sitemapUrl = trim($body['sitemap_url'] ?? '');
$excludeRaw = $body['exclude_urls'] ?? [];
$excludeRaw = is_array($excludeRaw) ? $excludeRaw : [];
$exclude    = array_flip(array_map('trim', $excludeRaw));

if (!preg_match('#^https?://#i', $sitemapUrl)) {
    json_err('Not a valid http(s) URL');
}

$ch = curl_init($sitemapUrl);
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
    json_err('Could not re-fetch the sitemap: ' . ($err ?: "HTTP {$code}"), 502);
}

if (!str_contains($data, '<urlset') && !str_contains($data, '<sitemapindex')) {
    // Not XML - the admin scanned an HTML page rather than a sitemap, so
    // there's no original document to replicate. Frontend falls back to
    // building a plain sitemap from the URLs it already knows about.
    json_out(['is_xml' => false]);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
if (!$dom->loadXML($data, LIBXML_NONET)) {
    json_out(['is_xml' => false, 'error' => 'Fetched content looked like a sitemap but did not parse as valid XML']);
}

// local-name() matching rather than a registered namespace prefix: real
// sitemaps almost always declare the sitemap protocol as a *default*
// namespace, and some feeds are inconsistent about declaring it at all -
// matching by local name is robust to both rather than silently finding
// zero entries against a technically-valid document.
$xpath    = new DOMXPath($dom);
$entryTag = str_contains($data, '<sitemapindex') ? 'sitemap' : 'url';
$entries  = $xpath->query("//*[local-name()='{$entryTag}']");

$removed = 0;
foreach ($entries as $entry) {
    $locNodes = $xpath->query(".//*[local-name()='loc']", $entry);
    if ($locNodes->length === 0) continue;
    $loc = trim($locNodes->item(0)->textContent);
    if (isset($exclude[$loc])) {
        $prev = $entry->previousSibling;
        $entry->parentNode->removeChild($entry);
        // Tidy the blank line a removed element leaves behind, if any -
        // cosmetic only, doesn't touch any surviving content.
        if ($prev && $prev->nodeType === XML_TEXT_NODE && trim($prev->textContent) === '') {
            $prev->parentNode->removeChild($prev);
        }
        $removed++;
    }
}

json_out([
    'is_xml'  => true,
    'xml'     => $dom->saveXML(),
    'removed' => $removed,
]);

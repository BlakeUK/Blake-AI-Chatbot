<?php
// public/api/admin/import_page_links.php — POST: import/re-index selected
// non-PDF links (from discover_urls.php's other_links, or pasted directly)
// as knowledge entries.
//
// Deliberately not routed through import_urls.php/knowledge_files: these
// are live HTML pages, not downloadable documents, and text/html isn't in
// that endpoint's allowed-mimetype list for good reason (a PDF's content
// is the document; a page's raw HTML is mostly nav/header/footer markup
// around the content, so it needs cleaning rather than the same
// pending-file-then-Gemini-extraction pipeline as a PDF manual).
// \Knowledge\PageIndexer does that cleaning (Html\TextCleaner strips
// markup, no Gemini call needed) and chunks the real body text, so a
// category page, FAQ, or service description becomes genuinely
// searchable - not just a one-line title+description stub.
//
// Upserts by URL: running this again on an already-indexed page (because
// its content changed) updates the existing entry rather than creating a
// duplicate.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
\Auth\Admin::requireRole('admin', 'editor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

$raw  = $body['urls'] ?? '';
$urls = is_array($raw) ? $raw : preg_split('/[\s,]+/', trim((string)$raw));
$urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

if (!$urls) {
    json_err('At least one URL required');
}

// Hand-picked via checkboxes, not a bulk sitemap dump - a much smaller cap
// than import_urls.php's is appropriate, and each entry here does a real
// fetch synchronously (no pending/cron deferral), so this also bounds the
// request's own runtime.
const MAX_BATCH = 50;
if (count($urls) > MAX_BATCH) {
    json_err('Too many URLs in one batch (max ' . MAX_BATCH . ') — submit in smaller batches');
}

set_time_limit(120);

$pdo     = db();
$results = [];

foreach ($urls as $url) {
    $result = ['url' => $url];

    if (!preg_match('#^https?://#i', $url)) {
        $results[] = $result + ['status' => 'error', 'error' => 'Not a valid http(s) URL', 'http_code' => null];
        continue;
    }

    $fetch = \Http\SafeFetcher::get($url, 20);
    $data  = $fetch['body'];
    $code  = $fetch['code'];

    if (!$fetch['ok']) {
        // http_code is 0 when no HTTP response was ever received at all -
        // timeout, DNS failure, connection reset (or this request was
        // blocked before connecting at all, e.g. a non-public address).
        // That, vs. an actual 404 response, is exactly the line the
        // frontend needs: 404 means the page is confirmed gone (safe to
        // drop from a cleaned sitemap), anything else just means the check
        // didn't complete (retry-worthy, not delete-worthy - a timeout is
        // not evidence a page is dead).
        $results[] = $result + ['status' => 'error', 'error' => $fetch['error'] ?: "HTTP {$code}", 'http_code' => $code];
        continue;
    }

    // Indexes the page's actual body content (chunked, the same way file
    // uploads are), not just its title+meta description - upserts by URL,
    // so re-scanning an already-indexed page refreshes it in place.
    $indexed = \Knowledge\PageIndexer::indexPage($url, $data);

    $results[] = $result + ['status' => $indexed['status'], 'title' => $indexed['title'], 'http_code' => $code];
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'page_links_imported', null, count(array_filter($results, fn($r) => in_array($r['status'], ['imported', 'updated'], true))) . ' of ' . count($urls)]);

json_out(['results' => $results]);

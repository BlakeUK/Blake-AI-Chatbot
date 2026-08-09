<?php
// public/api/admin/import_urls.php — POST: bulk-import files by URL
// Fetches each URL server-side, saving the round trip of downloading a
// leaflet locally just to re-upload it. Only downloads and stores the
// file here — Gemini extraction is NOT run inline (that used to cap
// batches at 15 URLs to stay within request timeouts). Instead each
// file is left in 'pending' status and picked up by
// scripts/process_pending_files.php (run on a schedule), the same way
// a crashed-mid-request regular upload would be retried.

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

// No Gemini call happens in this request anymore, so the cap is just a
// sanity/DoS guard rather than a timeout constraint - each URL is a
// download of at most 20 MB.
const MAX_BATCH = 200;
if (count($urls) > MAX_BATCH) {
    json_err('Too many URLs in one batch (max ' . MAX_BATCH . ') — submit in smaller batches');
}

set_time_limit(300);

$allowed = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain', 'text/csv', 'application/json', 'application/xml', 'text/xml',
    'image/jpeg', 'image/png', 'image/webp',
];
$maxBytes = 20 * 1024 * 1024;

$pdo     = db();
$results = [];

foreach ($urls as $url) {
    $result = ['url' => $url];

    if (!preg_match('#^https?://#i', $url)) {
        $results[] = $result + ['status' => 'error', 'error' => 'Not a valid http(s) URL'];
        continue;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'BlakeUKChatbotImporter/1.0',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $code !== 200) {
        $results[] = $result + ['status' => 'error', 'error' => $err ?: "HTTP {$code}"];
        continue;
    }
    if ($data === '') {
        $results[] = $result + ['status' => 'error', 'error' => 'Empty response'];
        continue;
    }
    if (strlen($data) > $maxBytes) {
        $results[] = $result + ['status' => 'error', 'error' => 'File exceeds 20 MB limit'];
        continue;
    }

    // Exact-duplicate check before storing anything: a byte-identical file
    // already indexed (from a previous bulk import or a direct upload)
    // means this download would just queue a redundant copy for
    // extraction later.
    $contentHash = \Knowledge\Dedup::hashBytes($data);
    $dupFile     = \Knowledge\Dedup::findExactFileDuplicate($contentHash);
    if ($dupFile) {
        $results[] = $result + ['status' => 'duplicate', 'duplicate_of' => $dupFile['id'], 'duplicate_filename' => $dupFile['filename']];
        continue;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'buk_import_');
    file_put_contents($tmp, $data);
    $mime = mime_content_type($tmp) ?: 'application/octet-stream';

    if (!in_array($mime, $allowed, true)) {
        @unlink($tmp);
        $results[] = $result + ['status' => 'error', 'error' => 'File type not permitted: ' . $mime];
        continue;
    }

    $urlPath = parse_url($url, PHP_URL_PATH) ?: '';
    $name    = basename($urlPath) ?: 'imported-file';
    $ext     = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION));
    $stored  = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
    $destPath = rtrim(CFG['upload_path'], '/') . '/' . $stored;

    if (!copy($tmp, $destPath)) {
        @unlink($tmp);
        $results[] = $result + ['status' => 'error', 'error' => 'Failed to store file'];
        continue;
    }
    @unlink($tmp);

    $pdo->prepare('INSERT INTO knowledge_files (filename, mime_type, stored_path, status, source_url, content_hash) VALUES (?,?,?,?,?,?)')
        ->execute([$name, $mime, $destPath, 'pending', $url, $contentHash]);

    $results[] = $result + ['status' => 'queued', 'filename' => $name];
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'bulk_url_import', count($urls), json_encode(array_column($results, 'status'))]);

json_out(['results' => $results]);

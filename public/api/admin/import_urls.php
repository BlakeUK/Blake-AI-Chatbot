<?php
// public/api/admin/import_urls.php — POST: bulk-import files by URL
// Fetches each URL server-side and runs it through the same
// FileExtractor pipeline as a manual upload, saving the round trip of
// downloading a leaflet locally just to re-upload it.

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

// Each URL runs a synchronous Gemini extraction (up to ~60s), so batches
// are capped to keep the request within reasonable server/proxy timeouts.
// Submit larger imports in multiple batches.
const MAX_BATCH = 15;
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

    $pdo->prepare('INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES (?,?,?,?)')
        ->execute([$name, $mime, $destPath, 'pending']);
    $fileId = (int)$pdo->lastInsertId();

    $extractErr = \Knowledge\FileExtractor::extract($fileId, $destPath, $mime);
    if ($extractErr) {
        $pdo->prepare('UPDATE knowledge_files SET status=?, error=? WHERE id=?')
            ->execute(['error', $extractErr, $fileId]);
        $results[] = $result + ['status' => 'error', 'error' => $extractErr, 'filename' => $name];
    } else {
        $results[] = $result + ['status' => 'indexed', 'filename' => $name];
    }
}

$pdo->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (?,?,?,?)')
    ->execute([$_SESSION['admin_id'], 'bulk_url_import', count($urls), json_encode(array_column($results, 'status'))]);

json_out(['results' => $results]);

<?php
// public/api/chat/track.php
// POST — customer provides tracking/order number + verification
// Queries carrier API and returns tracking events

require dirname(__DIR__, 3) . '/src/bootstrap.php';
cors();
rate_limit('track', 10);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body       = json_body();
$session_id = $body['session_id'] ?? '';
$trackingNo = trim($body['tracking_no'] ?? '');
$postcode   = trim($body['postcode']    ?? '');
$carrier    = trim($body['carrier']     ?? ''); // optional: auto-detect if empty

if (!$session_id || !$trackingNo) {
    json_err('session_id and tracking_no required');
}

$pdo = db();

// Verify session
$sess = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ?');
$sess->execute([$session_id]);
if (!$sess->fetch()) json_err('Invalid session', 404);

// If no carrier specified, try to detect from tracking number format
if (!$carrier) {
    $detect = \Tracking\Detector::analyse($trackingNo);
    $carrier = $detect['carrier'] ?? '';
}

if (!$carrier) {
    json_out([
        'status'  => 'unknown_carrier',
        'message' => 'I couldn\'t identify the carrier from that tracking number. Please tell me if it\'s Royal Mail, DPD or DX.',
        'carriers' => ['royalmail', 'dpd', 'dx'],
    ]);
}

// DX: Blake's own white-label tracking page, keyed off the Sales Order
// number + postcode - a direct link, no DX API account involved, so this
// branches off before the carrier-API-key path below (which DX never
// needs to reach).
if ($carrier === 'dx') {
    if (!$postcode) {
        json_out([
            'status'  => 'need_postcode',
            'message' => 'Please also let me know the delivery postcode so I can look that up.',
        ]);
    }

    $link = \Tracking\LinkBuilder::dx($trackingNo, $postcode);

    $pdo->prepare('
        INSERT INTO tracking_requests (session_id, carrier, tracking_no, result, status)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$session_id, 'dx', $trackingNo, json_encode($link), 'link_provided']);

    json_out([
        'status'    => 'found',
        'carrier'   => 'DX',
        'tracking'  => $trackingNo,
        'current'   => $link['message'],
        'events'    => [],
        'link_only' => true,
    ]);
}

// DPD: also a direct link (see Tracking\LinkBuilder::dpd()), no API
// account or postcode needed - branches off before the carrier-API-key
// path below for the same reason DX does.
if ($carrier === 'dpd') {
    $link = \Tracking\LinkBuilder::dpd($trackingNo);

    $pdo->prepare('
        INSERT INTO tracking_requests (session_id, carrier, tracking_no, result, status)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$session_id, 'dpd', $trackingNo, json_encode($link), 'link_provided']);

    json_out([
        'status'    => 'found',
        'carrier'   => 'DPD',
        'tracking'  => $trackingNo,
        'current'   => $link['message'],
        'events'    => [],
        'link_only' => true,
    ]);
}

// Get carrier API key
$keyStmt = $pdo->prepare('SELECT key_enc, iv, tag FROM api_keys WHERE service = ?');
$keyStmt->execute([$carrier]);
$keyRow = $keyStmt->fetch();

if (!$keyRow) {
    json_out([
        'status'  => 'carrier_not_configured',
        'message' => 'Tracking for that carrier is not yet set up. Please contact support at https://www.blake-uk.com/support.html',
    ]);
}

// Decrypt carrier key
$encKey = hex2bin(CFG['encrypt_key']);
$apiKey = openssl_decrypt(
    hex2bin($keyRow['key_enc']), 'aes-256-gcm', $encKey,
    OPENSSL_RAW_DATA, hex2bin($keyRow['iv']), hex2bin($keyRow['tag'])
);

if (!$apiKey) json_err('Failed to retrieve carrier credentials', 500);

// Query carrier
try {
    $result = \Tracking\Dispatcher::query($carrier, $trackingNo, $apiKey);
} catch (\Throwable $e) {
    error_log('Tracking error: ' . $e->getMessage());
    json_out([
        'status'  => 'carrier_error',
        'message' => 'I wasn\'t able to retrieve tracking information right now. Please try again shortly or visit the carrier\'s website directly.',
    ]);
}

// Log the tracking request
$pdo->prepare('
    INSERT INTO tracking_requests (session_id, carrier, tracking_no, result, status)
    VALUES (?, ?, ?, ?, ?)
')->execute([
    $session_id, $carrier, $trackingNo,
    json_encode($result), $result['status'] ?? 'queried',
]);

json_out([
    'status'  => 'found',
    'carrier' => $result['carrier'],
    'tracking'=> $result['tracking'],
    'current' => $result['status'],
    'events'  => $result['events'],
]);

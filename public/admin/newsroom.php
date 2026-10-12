<?php
// public/admin/newsroom.php: the Newsroom page itself, for signed-in staff only. It is meant to be opened inside the Newsroom tab's
// sandboxed frame. It hands the page a limited pass (valid for news only, for twelve hours) and that person's saved bookmarks and feeds,
// then the page does everything else by itself. Opened any other way it shows only a short notice and no pass.

require dirname(__DIR__, 2) . '/src/bootstrap.php';
\Auth\Admin::check();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
if ($dest !== '' && $dest !== 'iframe') {        // opened directly in a tab, outside the sandbox: show nothing of the page and give no pass
    echo '<!doctype html><meta charset="utf-8"><title>Newsroom</title><body style="font:16px sans-serif;padding:2rem"><h2>Open the Newsroom from the admin</h2><p>This page only works inside the <strong>Newsroom</strong> tab.</p>';
    exit;
}

$aid  = (int)$_SESSION['admin_id'];
$boot = json_encode(['pass' => \Newsroom\Pass::mint($aid), 'api' => '/api/newsroom.php', 'prefs' => (object)\Newsroom\Data::prefs($aid)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$html = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Newsroom/hub.html');
echo str_replace('/*__NEWS_BOOT__*/', 'window.NEWS = ' . $boot . ';', $html);

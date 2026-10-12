<?php
// public/api/admin/channel_notify.php: GET ?since=<message id>&events_since=<event id>
// What this person should be told about since the two watermarks the console carries forward: new messages (every one in a chat they have not
// muted; a mention or a reply to them even in a muted one) and being added, removed or a group being deleted. A first call with since=0 only
// returns the starting watermarks and nothing to show. See Chat\Channels::notifications.

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);
header('Cache-Control: no-store');
json_out(\Chat\Channels::notifications((int)$_SESSION['admin_id'], max(0, (int)($_GET['since'] ?? 0)), max(0, (int)($_GET['events_since'] ?? 0))));

<?php
// public/api/admin/channels.php: staff team chat. The rules are in src/Chat/Channels.php; this only reads the request and answers.
//   GET                      my chats, with last message, unread count, muted
//   GET ?id=X[&after=N]      one chat: members and messages (the latest 200, or only those after message N)
//   POST {dm_with}           open (or start) a one-to-one chat
//   POST {channel_id, content, reply_to_id?}   send a message
//   POST {name, member_ids[], description?}    make a group
//   PUT  {id, mark_read | mute:bool | rename | description | add_member_ids[] | add_member_id | remove_member_id | leave}
//   DELETE {id}              delete a group (owner or administrator)

require dirname(__DIR__, 3) . '/src/bootstrap.php';
admin_cors();
\Auth\Admin::check();

use Chat\Channels;

$method = $_SERVER['REQUEST_METHOD'];
$me     = (int)$_SESSION['admin_id'];
$guard  = static function (callable $fn) {
    try { return $fn(); }
    catch (\DomainException $e)          { json_err($e->getMessage(), 403); }
    catch (\InvalidArgumentException $e) { json_err($e->getMessage(), 422); }
};

if ($method === 'GET') {
    header('Cache-Control: no-store');
    if (!empty($_GET['id'])) json_out($guard(fn() => Channels::detail((int)$_GET['id'], $me, max(0, (int)($_GET['after'] ?? 0)))));
    json_out(Channels::listFor($me));
}

$body = json_body();
\Auth\Admin::verifyCsrf($body['csrf'] ?? '');

if ($method === 'POST') {
    if (!empty($body['dm_with']) && empty($body['channel_id'])) {
        $other = (int)$body['dm_with'];
        $id = $guard(fn() => Channels::dm($me, $other));
        json_out(['ok' => true, 'id' => $id, 'display_name' => Channels::username($other)]);
    }
    if (!empty($body['channel_id'])) {
        rate_limit('chat_post', 180);
        $id = $guard(fn() => Channels::post((int)$body['channel_id'], $me, (string)($body['content'] ?? ''), !empty($body['reply_to_id']) ? (int)$body['reply_to_id'] : null));
        json_out(['ok' => true, 'id' => $id]);
    }
    rate_limit('chat_create', 30);
    $id = $guard(fn() => Channels::create($me, (string)($body['name'] ?? ''), is_array($body['member_ids'] ?? null) ? $body['member_ids'] : [], (string)($body['description'] ?? '')));
    json_out(['ok' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    $guard(function () use ($body, $id, $me) {
        if (!empty($body['mark_read']))                { Channels::markRead($id, $me); return; }
        if (array_key_exists('mute', $body))           { Channels::mute($id, $me, (bool)$body['mute']); return; }
        if (isset($body['rename']))                    { Channels::rename($id, $me, (string)$body['rename']); return; }
        if (array_key_exists('description', $body))    { Channels::setDescription($id, $me, (string)$body['description']); return; }
        if (!empty($body['add_member_ids']) && is_array($body['add_member_ids'])) { Channels::addMembers($id, $me, $body['add_member_ids']); return; }
        if (!empty($body['add_member_id']))            { Channels::addMembers($id, $me, [(int)$body['add_member_id']]); return; }
        if (!empty($body['remove_member_id']))         { Channels::removeMember($id, $me, (int)$body['remove_member_id']); return; }
        if (!empty($body['leave']))                    { Channels::leave($id, $me); return; }
        throw new \InvalidArgumentException('Nothing to change.');
    });
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_err('id required');
    $guard(fn() => Channels::delete($id, $me));
    json_out(['ok' => true]);
}

json_err('Method not allowed', 405);

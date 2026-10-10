<?php
// src/Files/SharePage.php: the page a customer (or anyone with the address) gets from https://blakegroup.uk/s.php/<id>/<token>
// No sign-in. No scripts. The address is checked every time; a wrong address and a missing share look identical. Files are only ever
// sent as downloads. handle() returns either a page ['status','headers','body'] or ['stream' => ...] for public/s.php to send.

declare(strict_types=1);

namespace Files;

class SharePage
{
    public const HEADERS = [
        'Cache-Control: no-store', 'X-Robots-Tag: noindex, nofollow', 'Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY',
        "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    ];

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function size(int $b): string
    {
        if ($b < 1024) return $b . ' bytes';
        foreach (['KB', 'MB', 'GB'] as $i => $u) { $v = $b / (1024 ** ($i + 1)); if ($v < 1024 || $u === 'GB') return ($v >= 100 ? (string)round($v) : number_format($v, 1)) . ' ' . $u; }
        return $b . ' bytes';
    }

    private static function page(int $status, string $title, string $body, array $extraHeaders = []): array
    {
        $css = 'body{margin:0;background:#f3f5fb;color:#1b2230;font:16px/1.5 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}header{background:#fff;border-bottom:1px solid #d9deef;padding:14px 20px;font-weight:800;color:#485cc7;font-size:22px;letter-spacing:-.5px}'
            . 'main{max-width:760px;margin:0 auto;padding:22px 16px 60px}.card{background:#fff;border:1px solid #d9deef;border-radius:12px;padding:20px 22px;margin:0 0 16px}h1{font-size:1.35rem;margin:0 0 .4rem;color:#1c2766;overflow-wrap:anywhere}p{margin:0 0 .8rem}.muted{color:#5b6479;font-size:.92rem}'
            . '.msg{background:#eef0fb;border-radius:8px;padding:.7rem .9rem;white-space:pre-wrap;overflow-wrap:anywhere}table{width:100%;border-collapse:collapse}td{padding:.6rem .3rem;border-top:1px solid #e6e9f5;vertical-align:middle}td.s{text-align:right;color:#5b6479;white-space:nowrap;font-size:.9rem;padding-left:.8rem}td.n{overflow-wrap:anywhere}'
            . 'a.btn,button{display:inline-block;background:#485cc7;color:#fff;border:0;border-radius:9px;padding:.6rem 1.1rem;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}a.btn:hover,button:hover{background:#3a4cae}a.alt{display:inline-block;color:#485cc7;text-decoration:none;font-weight:600;border:1px solid #485cc7;border-radius:9px;padding:.35rem .8rem;font-size:.9rem}'
            . 'a.f{color:#1c2766;font-weight:600;text-decoration:none}.crumbs{font-size:.92rem;margin:0 0 .8rem;color:#5b6479}.crumbs a{color:#485cc7}input[type=password]{font:inherit;padding:.55rem .7rem;border:1px solid #aab3d6;border-radius:8px;width:100%;max-width:340px}.err{background:#fdeceb;border:1px solid #f0a9a4;border-radius:8px;padding:.5rem .8rem;margin:0 0 .8rem}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}';
        return ['status' => $status, 'headers' => array_merge(self::HEADERS, ['Content-Type: text/html; charset=utf-8'], $extraHeaders),
            'body' => '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . self::e($title) . '</title><style>' . $css . '</style></head><body><header>blake UK</header><main>' . $body . '</main></body></html>'];
    }

    private static function notice(int $status, string $heading, string $text): array
    {
        return self::page($status, $heading, '<div class="card"><h1>' . self::e($heading) . '</h1><p>' . self::e($text) . '</p><p class="muted">If you think this is a mistake, please contact the person who sent you the link.</p></div>');
    }

    public static function handle(string $path, string $method, array $post, array $query, array $cookies, string $ip): array
    {
        if (!preg_match('~^/(\d{1,9})/([A-Za-z0-9_-]{43})$~', $path, $m)) return self::notice(404, 'This link is not valid', 'The address may be incomplete. Please use the whole link you were sent.');
        $share = Shares::verify((int)$m[1], $m[2]);
        if (!$share) return self::notice(404, 'This link is not valid', 'The address may be incomplete, or the link may have been replaced. Please use the whole link you were sent, or ask for a new one.');
        $self = '/s.php/' . (int)$share['id'] . '/' . $m[2];       // rebuilt from the verified values, never echoed from the request

        $state = Shares::state($share);
        if ($state === 'revoked') return self::notice(410, 'This link has been withdrawn', 'The files are no longer shared.');
        if ($state === 'not_yet') return self::notice(403, 'This link is not available yet', 'It opens on ' . Shares::ukTime((int)$share['available_from']) . ' (UK time).');
        if ($state === 'expired') return self::notice(410, 'This link has expired', 'It was available until ' . Shares::ukTime((int)$share['available_to']) . ' (UK time).');

        if (!empty($share['password_hash']) && !Shares::isUnlocked($share, $cookies['fsu_' . $share['id']] ?? null)) {
            $error = '';
            if ($method === 'POST' && isset($post['password'])) {
                if (Shares::failuresSince((int)$share['id']) >= 10) return self::notice(429, 'Too many attempts', 'Please wait ten minutes before trying again.');
                if (Shares::checkPassword($share, (string)$post['password'])) {
                    Shares::log((int)$share['id'], 'unlock', '', $ip);
                    $exp = time() + Shares::UNLOCK_SECONDS;
                    return ['status' => 303, 'headers' => array_merge(self::HEADERS, ['Location: ' . $self, 'Set-Cookie: fsu_' . $share['id'] . '=' . Shares::unlockValue($share, $exp) . '; Path=/s.php; Max-Age=' . Shares::UNLOCK_SECONDS . '; Secure; HttpOnly; SameSite=Lax']), 'body' => ''];
                }
                Shares::log((int)$share['id'], 'password_fail', '', $ip);
                $error = '<div class="err" role="alert">That password is not right. Please try again.</div>';
            }
            return self::page($error ? 401 : 200, 'Password needed', '<div class="card"><h1>Password needed</h1><p>This link is protected. Please enter the password you were given.</p>' . $error
                . '<form method="post" action="' . self::e($self) . '"><p><input type="password" name="password" autocomplete="off" autofocus aria-label="Password"></p><p><button type="submit">Continue</button></p></form></div>');
        }

        $items = Shares::items((int)$share['id']);
        $roots = array_map(fn($n) => (int)$n['id'], $items);

        // downloads
        if (isset($query['d'])) {
            $n = Store::get((int)$query['d']);
            if (!$n || $n['kind'] !== 'file' || !Shares::covers((int)$share['id'], (int)$n['id'])) return self::notice(404, 'That file is not here', 'It may have been removed from the share.');
            Shares::log((int)$share['id'], 'download', (string)$n['name'], $ip);
            return ['stream' => ['kind' => 'file', 'node' => $n]];
        }
        if (isset($query['z'])) {
            if ($query['z'] === 'all') { $nodes = $items; $name = (string)$share['title']; }
            else {
                $n = Store::get((int)$query['z']);
                if (!$n || $n['kind'] !== 'folder' || !Shares::covers((int)$share['id'], (int)$n['id'])) return self::notice(404, 'That folder is not here', 'It may have been removed from the share.');
                $nodes = [$n]; $name = (string)$n['name'];
            }
            if (!$nodes) return self::notice(404, 'Nothing to download', 'This share is empty.');
            Shares::log((int)$share['id'], 'zip', $name, $ip);
            return ['stream' => ['kind' => 'zip', 'nodes' => $nodes, 'name' => Store::cleanName($name) . '.zip']];
        }

        // the page
        $folder = null; $crumbs = [];
        if (isset($query['f'])) {
            $folder = Store::get((int)$query['f']);
            if (!$folder || $folder['kind'] !== 'folder' || !Shares::covers((int)$share['id'], (int)$folder['id'])) return self::notice(404, 'That folder is not here', 'It may have been removed from the share.');
            $started = false;
            foreach (Store::path((int)$folder['id']) as $p) { if (in_array($p['id'], $roots, true)) $started = true; if ($started) $crumbs[] = $p; }
        }
        $list = $folder ? Store::contents((int)$folder['id']) : $items;
        Shares::log((int)$share['id'], 'view', '', $ip);

        $owner = (string)db()->query('SELECT username FROM admin_users WHERE id = ' . (int)$share['owner_id'])->fetchColumn();
        $to = $share['available_to'] !== null ? '<p class="muted">Available until ' . self::e(Shares::ukTime((int)$share['available_to'])) . ' (UK time).</p>' : '';
        $html = '<div class="card"><h1>' . self::e((string)$share['title']) . '</h1><p class="muted">Shared by ' . self::e($owner ?: 'Blake UK') . '</p>'
            . (trim((string)$share['message']) !== '' ? '<p class="msg">' . self::e((string)$share['message']) . '</p>' : '') . $to . '</div><div class="card">';
        if ($folder) {
            $html .= '<p class="crumbs"><a href="' . self::e($self) . '">All files</a>';
            foreach ($crumbs as $i => $c) $html .= ' / ' . ($i === count($crumbs) - 1 ? self::e($c['name']) : '<a href="' . self::e($self) . '?f=' . $c['id'] . '">' . self::e($c['name']) . '</a>');
            $html .= '</p>';
        }
        if (!$list) $html .= '<p class="muted">There is nothing in here.</p>';
        else {
            $html .= '<table>';
            foreach ($list as $n) {
                $isF = $n['kind'] === 'folder';
                $html .= '<tr><td class="n">' . ($isF ? '<a class="f" href="' . self::e($self) . '?f=' . $n['id'] . '">&#128193; ' . self::e($n['name']) . '</a>' : '&#128196; ' . self::e($n['name'])) . '</td>'
                    . '<td class="s">' . ($isF ? '' : self::e(self::size((int)$n['size']))) . '</td><td class="s">'
                    . ($isF ? '<a class="alt" href="' . self::e($self) . '?z=' . $n['id'] . '">Download as ZIP</a>' : '<a class="btn" href="' . self::e($self) . '?d=' . $n['id'] . '">Download</a>') . '</td></tr>';
            }
            $html .= '</table>';
        }
        if (!$folder && (count($items) > 1 || ($items && $items[0]['kind'] === 'folder'))) $html .= '<p style="margin-top:1rem"><a class="btn" href="' . self::e($self) . '?z=all">Download everything as one ZIP</a></p>';
        return self::page(200, (string)$share['title'], $html . '</div>');
    }

    /** Sends a download. Always as an attachment, never shown in the browser, whatever the file is. */
    public static function send(array $s, string $dispositionName = ''): void
    {
        while (ob_get_level()) ob_end_clean();
        @set_time_limit(0); ignore_user_abort(false);
        foreach (['Cache-Control: no-store', 'X-Content-Type-Options: nosniff', "Content-Security-Policy: sandbox; default-src 'none'"] as $h) header($h);
        $disp = static function (string $name): string {
            $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'download';
            return 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
        };
        if ($s['kind'] === 'file') {
            $path = Store::blobPath((string)$s['node']['blob']);
            if (!is_file($path)) { http_response_code(404); echo 'Not found'; return; }
            header('Content-Type: application/octet-stream'); header('Content-Length: ' . filesize($path)); header($disp($dispositionName !== '' ? $dispositionName : (string)$s['node']['name']));
            $fh = fopen($path, 'rb');
            while (!feof($fh) && !connection_aborted()) { echo fread($fh, 65536); flush(); }
            fclose($fh);
            return;
        }
        $entries = Store::collect($s['nodes']);
        header('Content-Type: application/zip'); header($disp($s['name']));
        Zip::write($entries, static function (string $b): void { echo $b; flush(); });
    }
}

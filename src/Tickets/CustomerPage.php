<?php
// src/Tickets/CustomerPage.php
// The page a customer reaches from the secure link in their email: https://blakegroup.uk/ticket.php/TCK-1001/<token>
// They see their ticket and every reply, and can write back. No account or password.
//
// handle() takes the request and returns [status, headers, body], so it can be tested without a web server.
// The page carries no scripts at all. Its headers stop it being indexed, cached, framed or leaking the
// link (which is the secret) through the Referer header.

declare(strict_types=1);

namespace Tickets;

class CustomerPage
{
    private const STATUS = [
        'open'        => ['Received', 'We have your enquiry and will pick it up shortly.'],
        'in_progress' => ['In progress', 'Our team is working on this.'],
        'waiting'     => ['Waiting for information', 'We may need a little more from you. You can reply below.'],
        'resolved'    => ['Resolved', 'We think this is sorted. If not, reply below and we will pick it straight back up.'],
        'closed'      => ['Closed', 'This enquiry is closed. If you still need help, reply below and we will reopen it.'],
    ];

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function headers(): array
    {
        return [
            'Content-Type: text/html; charset=utf-8',
            'Cache-Control: no-store, max-age=0',
            'Referrer-Policy: no-referrer',
            'X-Robots-Tag: noindex, nofollow, noarchive',
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ];
    }

    private static function ukTime(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(\Support\Hours::TZ))->format('j M Y, H:i');
    }

    // $pathInfo is "/TCK-1001/<token>"; $self is the same path as the browser should post to; $query is $_GET.
    public static function handle(string $pathInfo, string $method, array $post, string $self, array $query = []): array
    {
        $parts = explode('/', trim($pathInfo, '/'));
        $id = count($parts) === 2 ? Link::verify($parts[0], $parts[1]) : null;
        if ($id !== null) $self = '/ticket.php/' . $parts[0] . '/' . $parts[1];     // rebuilt from the verified values, never echoed from the request
        if ($id === null) {
            // the same answer whatever was wrong, so a guess tells an attacker nothing
            return ['status' => 404, 'headers' => self::headers(), 'body' => self::shell('Link not recognised',
                '<h1>We could not find that ticket</h1><p>The link may be incomplete. Please use the full link from your email, or contact us and quote your ticket number.</p>' . self::contact())];
        }

        $error = null; $draft = '';
        if ($method === 'POST') {
            if (trim((string)($post['website'] ?? '')) !== '') {          // a field people cannot see: only a robot fills it in
                return ['status' => 303, 'headers' => ['Location: ' . $self . '?sent=1', 'Cache-Control: no-store'], 'body' => ''];
            }
            $draft = (string)($post['message'] ?? '');
            $r = Messages::customerReply($id, $draft);
            if ($r['ok']) {
                return ['status' => 303, 'headers' => ['Location: ' . $self . '?sent=1', 'Cache-Control: no-store', 'Referrer-Policy: no-referrer'], 'body' => ''];
            }
            $error = (string)$r['error'];
        }
        return ['status' => $error ? 422 : 200, 'headers' => self::headers(), 'body' => self::ticketPage($id, $self, $error, $draft, isset($query['sent']))];
    }

    private static function contact(): string
    {
        $cfg = \Mail\Smtp::settings();
        $phone = trim((string)$cfg['phone']);
        return '<div class="box"><h2>Contact us</h2>'
            . ($phone !== '' ? '<p>Telephone: <strong>' . self::h($phone) . '</strong></p>' : '')
            . '<p>Opening hours: ' . self::h(\Support\Hours::SUMMARY) . '.</p></div>';
    }

    private static function ticketPage(int $id, string $self, ?string $error, string $draft, bool $sent): string
    {
        $q = db()->prepare('SELECT * FROM support_tickets WHERE id = ?'); $q->execute([$id]); $t = $q->fetch();
        $code = Mailer::code($id);
        [$label, $hint] = self::STATUS[$t['status']] ?? [ucfirst(str_replace('_', ' ', (string)$t['status'])), ''];
        $subject = trim((string)$t['subject']) ?: 'Support request';
        $details = trim((string)($t['details'] ?? ''));
        $open = \Support\Hours::isOpen();

        $thread = '';
        foreach (Messages::all($id) as $m) {
            $mine = $m['author'] === 'customer';
            $thread .= '<div class="msg ' . ($mine ? 'me' : 'them') . '"><div class="who">' . ($mine ? 'You' : 'Blake UK Support')
                . ' <span>' . self::h(self::ukTime((int)$m['created_at'])) . '</span></div><div class="body">' . nl2br(self::h((string)$m['body']), false) . '</div></div>';
        }
        if ($thread === '') $thread = '<p class="muted">No replies yet. We will email you as soon as we reply, and you can come back to this page at any time.</p>';

        $flash = $sent ? '<div class="ok" role="status">Thank you. Your message has been sent to our team.</div>' : '';
        $err = $error ? '<div class="bad" role="alert">' . self::h($error) . '</div>' : '';
        $hours = $open ? 'We are open now.' : 'We are closed at the moment. Your message will be seen from ' . self::h(\Support\Hours::nextOpening()) . '.';

        $html = '<h1>Support ticket ' . self::h($code) . '</h1>'
            . '<p class="sub">' . self::h($subject) . '</p>'
            . '<p><span class="pill">' . self::h($label) . '</span> <span class="muted">Raised ' . self::h(self::ukTime((int)$t['created_at'])) . '</span></p>'
            . ($hint !== '' ? '<p class="muted">' . self::h($hint) . '</p>' : '')
            . ($details !== '' && $details !== $subject ? '<div class="box"><h2>Your enquiry</h2><p>' . nl2br(self::h($details), false) . '</p></div>' : '')
            . '<h2>Conversation</h2>' . $flash . '<div class="thread">' . $thread . '</div>'
            . '<h2>Send us a message</h2>' . $err
            . '<form method="post" action="' . self::h($self) . '">'
            . '<label for="message">Your message</label>'
            . '<textarea id="message" name="message" rows="6" maxlength="' . Messages::MAX_LEN . '" required>' . self::h($draft) . '</textarea>'
            . '<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<p class="muted">' . $hours . '</p>'
            . '<button type="submit">Send message</button></form>'
            . self::contact();
        return self::shell('Ticket ' . $code, $html);
    }

    private static function shell(string $title, string $inner): string
    {
        return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::h($title) . ' | Blake UK Support</title><style>'
            . 'body{margin:0;background:#f3f5fb;color:#1b2230;font:16px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}'
            . 'header{background:#fff;border-bottom:1px solid #d9deef;padding:14px 20px}header img{height:34px;display:block}'
            . 'main{max-width:720px;margin:0 auto;padding:22px 16px 48px}h1{font-size:1.5rem;margin:.2em 0;color:#1c2766}h2{font-size:1.1rem;margin:1.6em 0 .5em;color:#2c3a8c}'
            . '.sub{font-size:1.1rem;margin:.1em 0 .8em}.muted{color:#5b6479;font-size:.92rem}.pill{display:inline-block;background:#485cc7;color:#fff;border-radius:99px;padding:.1em .8em;font-size:.88rem;font-weight:600}'
            . '.box{background:#fff;border:1px solid #d9deef;border-radius:10px;padding:.2em 1em;margin:1.2em 0}'
            . '.thread{display:flex;flex-direction:column;gap:10px}.msg{border-radius:10px;padding:.6em .9em;max-width:92%;border:1px solid #d9deef;background:#fff}'
            . '.msg.me{align-self:flex-end;background:#e8ecfc;border-color:#c9d2f6}.who{font-weight:600;font-size:.9rem;color:#1c2766}.who span{font-weight:400;color:#5b6479;margin-left:.5em}.body{margin-top:.2em;overflow-wrap:anywhere}'
            . 'label{display:block;font-weight:600;margin:.2em 0}textarea{width:100%;box-sizing:border-box;font:inherit;padding:.6em;border:1px solid #aab3d6;border-radius:8px;resize:vertical}'
            . 'button{background:#485cc7;color:#fff;border:0;border-radius:8px;padding:.7em 1.6em;font:inherit;font-weight:600;cursor:pointer}button:hover{background:#3a4cae}'
            . '.ok{background:#e6f6ec;border:1px solid #9bd3ae;border-radius:8px;padding:.6em 1em;margin:.6em 0}.bad{background:#fdeceb;border:1px solid #f0a9a4;border-radius:8px;padding:.6em 1em;margin:.6em 0}'
            . '.hp{position:absolute;left:-9999px;height:0;overflow:hidden}'
            . '</style></head><body><header><img src="/assets/blake-uk-logo.png" alt="Blake UK"></header><main>' . $inner
            . '<p class="muted" style="margin-top:2em">This page is private to you. Please do not share its address.</p></main></body></html>';
    }
}

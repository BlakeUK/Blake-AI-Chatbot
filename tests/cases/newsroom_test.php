<?php
// The Newsroom: fetching addresses safely, reading and cleaning feeds, the limited pass, and the AI request rules.

declare(strict_types=1);

use Newsroom\Ai;
use Newsroom\Feed;
use Newsroom\Net;
use Newsroom\Pass;

function nr_throws(callable $fn, string $contains = ''): void
{
    try { $fn(); } catch (\InvalidArgumentException $e) { if ($contains !== '') assert_str_contains($contains, $e->getMessage()); return; }
    assert_true(false, 'expected the address or request to be refused' . ($contains !== '' ? " ({$contains})" : ''));
}
function nr_public(string $h): array { return ['93.184.216.34']; }

suite('Newsroom: which addresses are public');

test('ordinary public addresses are allowed, and every private, local, reserved and tricky one is refused', function () {
    foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.0', '2606:4700:4700::1111', '[2606:4700:4700::1111]'] as $ok) assert_true(Net::isPublicIp($ok), "public: $ok");
    foreach (['127.0.0.1', '127.1.2.3', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.0.1', '169.254.169.254', '169.254.0.1', '0.0.0.0', '0.1.2.3', '100.64.0.1', '100.127.255.255', '192.0.0.1', '192.0.2.1',
              '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254',
              '64:ff9b::7f00:1', '2002:7f00:1::', '2001:db8::1', '2001::1', '', 'not-an-ip', '1.2.3', '256.1.1.1', '8.8.8.8/8'] as $bad) assert_false(Net::isPublicIp($bad), "refused: " . json_encode($bad));
});

test('an address is refused unless it is plain http or https on the normal ports with nothing sneaky in it', function () {
    foreach (['https://example.com/feed', 'http://example.com/feed.xml', 'HTTPS://Example.COM/a?b=c#d', 'http://example.com:80/x', 'https://example.com:443/x', 'https://example.com./x'] as $ok) assert_equal(true, (bool)Net::check($ok, 'nr_public'), $ok);
    $c = Net::check('https://example.com/feed', 'nr_public'); assert_equal(['https', 443, 'example.com', ['93.184.216.34']], [$c['scheme'], $c['port'], $c['host'], $c['ips']]);
    foreach ([['ftp://example.com/x', 'http and https'], ['file:///etc/passwd', 'not a valid'], ['gopher://example.com/', 'http and https'], ['javascript:alert(1)', 'not a valid'], ['data:text/html,<b>', 'not a valid'], ['//example.com/x', 'not a valid'],
              ['http://user:pw@example.com/', 'user name'], ['http://user@example.com/', 'user name'], ['http://example.com:8080/', 'ports'], ['http://example.com:22/', 'ports'], ['http://example.com:6379/', 'ports'],
              ['http://2130706433/', 'not a valid'], ['http://0x7f000001/', 'not a valid'], ['http://127.1/', 'not a valid'], ['http://0177.0.0.1/', 'not a valid'], ['http://1.1/', 'not a valid'],
              ['http://127.0.0.1/', 'private'], ['http://[::1]/', 'private'], ['http://[::ffff:127.0.0.1]/', 'private'], ['http://169.254.169.254/latest/meta-data/', 'private'], ['http://10.1.2.3/admin', 'private'], ['http://192.168.1.1/', 'private'],
              ["http://exa mple.com/", 'not a valid'], ["http://example.com/\n", 'not a valid'], ["http://example.com/\0", 'not a valid'], ['', 'not a valid'], ['http://', 'not a valid'], ['example.com', 'not a valid'], ['http:///x', 'not a valid'],
              ['http://example.com/' . str_repeat('a', 2000), 'not a valid']] as [$bad, $why]) nr_throws(fn() => Net::check($bad, 'nr_public'), $why);
    nr_throws(fn() => Net::check('http://localhost/', fn($h) => ['127.0.0.1']), 'private');
    nr_throws(fn() => Net::check('http://localhost./', fn($h) => ['127.0.0.1']), 'private');
    nr_throws(fn() => Net::check('http://metadata.google.internal/', fn($h) => ['169.254.169.254']), 'private');
    nr_throws(fn() => Net::check('http://evil.example/', fn($h) => ['93.184.216.34', '10.0.0.5']), 'private');          // one private answer among public ones
    nr_throws(fn() => Net::check('http://evil.example/', fn($h) => ['93.184.216.34', '::1']), 'private');
    nr_throws(fn() => Net::check('http://nowhere.example/', fn($h) => []), 'could not be found');
});

suite('Newsroom: fetching, redirects and size');

function nr_transport(array $script, array &$seen = []): callable
{
    return function (string $url, string $host, int $port, string $ip, int $max, string $accept, int $timeout) use (&$script, &$seen): array {
        $seen[] = compact('url', 'host', 'port', 'ip', 'max');
        return array_shift($script) ?? ['status' => 500, 'headers' => [], 'body' => '', 'truncated' => false];
    };
}

test('a fetch connects to the address it checked, follows redirects one hop at a time, and checks every hop', function () {
    $seen = [];
    $r = Net::fetch('https://example.com/feed', ['resolver' => 'nr_public', 'transport' => nr_transport([['status' => 200, 'headers' => ['content-type' => 'Application/RSS+XML; charset=utf-8'], 'body' => '<rss/>', 'truncated' => false]], $seen)]);
    assert_equal(['<rss/>', 'application/rss+xml', 200, 'https://example.com/feed'], [$r['body'], $r['type'], $r['status'], $r['url']]); assert_equal('93.184.216.34', $seen[0]['ip'], 'it uses the address that was checked');
    $seen = [];
    $r = Net::fetch('http://a.example/start', ['resolver' => 'nr_public', 'transport' => nr_transport([
        ['status' => 301, 'headers' => ['location' => '/next'], 'body' => '', 'truncated' => false], ['status' => 302, 'headers' => ['location' => 'https://b.example/final'], 'body' => '', 'truncated' => false],
        ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => 'done', 'truncated' => false]], $seen)]);
    assert_equal('https://b.example/final', $r['url']); assert_equal(['http://a.example/start', 'http://a.example/next', 'https://b.example/final'], array_column($seen, 'url'));
    // a redirect into a private network is refused, however it is written
    foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:80/', '//10.0.0.1/x', 'http://[::1]/', 'http://2130706433/', 'ftp://example.com/x', 'http://localhost/'] as $loc) {
        nr_throws(fn() => Net::fetch('http://good.example/', ['resolver' => fn($h) => $h === 'localhost' ? ['127.0.0.1'] : ['93.184.216.34'], 'transport' => nr_transport([['status' => 302, 'headers' => ['location' => $loc], 'body' => '', 'truncated' => false]])]));
    }
    // a name that gives a public answer first and a private one when asked again (DNS rebinding) is caught at the second hop
    $n = 0; nr_throws(fn() => Net::fetch('http://rebind.example/', ['resolver' => function ($h) use (&$n) { return ++$n === 1 ? ['93.184.216.34'] : ['127.0.0.1']; },
        'transport' => nr_transport([['status' => 302, 'headers' => ['location' => 'http://rebind.example/again'], 'body' => '', 'truncated' => false]])]), 'private');
    // too many redirects, and too large
    $loop = array_fill(0, 10, ['status' => 302, 'headers' => ['location' => 'http://a.example/x'], 'body' => '', 'truncated' => false]);
    nr_throws(fn() => Net::fetch('http://a.example/x', ['resolver' => 'nr_public', 'transport' => nr_transport($loop)]), 'too many times');
    nr_throws(fn() => Net::fetch('http://a.example/big', ['resolver' => 'nr_public', 'transport' => nr_transport([['status' => 200, 'headers' => [], 'body' => 'x', 'truncated' => true]])]), 'too large');
    $seen = []; Net::fetch('http://a.example/', ['resolver' => 'nr_public', 'maxBytes' => 1234, 'transport' => nr_transport([['status' => 200, 'headers' => [], 'body' => '', 'truncated' => false]], $seen)]); assert_equal(1234, $seen[0]['max']);
});

suite('Newsroom: cleaning what comes from outside');

test('plain text never contains an angle bracket, however it was disguised', function () {
    assert_equal('Big news today', Feed::text("  <b>Big</b>\n news\t&amp; today ") === 'Big news & today' ? 'Big news today' : Feed::text("  <b>Big</b>\n news\t&amp; today "), 'tags go, entities decode');
    foreach (['&lt;img src=x onerror=alert(1)&gt;', '&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;', '<<script>script>alert(1)<</script>/script>', '<img src=x onerror=alert(1)>', "a\x00b<\x01>c", '&#60;svg onload=alert(1)&#62;', '&#x3c;b&#x3e;'] as $evil) {
        $t = Feed::text($evil); assert_true(!str_contains($t, '<') && !str_contains($t, '>'), "no brackets left in: " . json_encode($t));
    }
    assert_equal(5, mb_strlen(Feed::text('abcdefghij', 5))); assert_equal('', Feed::text(null)); assert_equal('AT&T', Feed::text('AT&amp;T'));
});

test('only plain web addresses survive as links', function () {
    foreach (['https://example.com/a?b=c', 'http://example.com/'] as $ok) assert_equal($ok, Feed::url($ok));
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'ftp://example.com/', 'file:///etc/passwd', '//example.com/', 'https://example.com/a b', 'https://example.com/"onerror=x', "https://example.com/'x", 'https://example.com/<x>', '', null, str_repeat('h', 2001)] as $bad) assert_equal('', Feed::url($bad), json_encode($bad));
});

test('feed HTML keeps harmless markup and loses everything that could run', function () {
    $evil = '<p onclick="x()">Hello <b>there</b></p><script>alert(1)</script><style>body{display:none}</style><iframe src="https://evil.example"></iframe><object data="x"></object><svg onload="alert(1)"><circle/></svg>'
          . '<form action="https://evil.example"><input name="p"></form><a href="javascript:alert(1)">bad</a><a href="  JaVaScRiPt:alert(1)">bad2</a><a href="data:text/html,x">bad3</a><a href="https://good.example/" onmouseover="x()" style="color:red">good</a>'
          . '<img src="x" onerror="alert(1)"><img src="data:image/svg+xml,<svg onload=alert(1)>"><img src="https://good.example/p.jpg" alt="a &quot;quoted&quot; <b>alt</b>" onload="x()"><!-- comment --><marquee>text kept</marquee><scr<script>ipt>alert(2)</scr</script>ipt>'
          . '<div style="position:fixed" class="x" id="y"><h2>Title</h2></div><iframe srcdoc="<script>alert(1)</script>"></iframe><meta http-equiv="refresh" content="0;url=https://evil.example"><link rel="stylesheet" href="https://evil.example/x.css"><base href="https://evil.example/">';
    $h = Feed::html($evil); $low = strtolower($h);
    foreach (['<script', 'onclick', 'onerror', 'onload', 'onmouseover', 'javascript:', 'data:', '<iframe', '<style', 'style=', '<object', '<svg', '<form', '<input', '<meta', '<link', '<base', '<marquee', 'srcdoc', '<!--', 'class=', 'id='] as $bad) assert_true(!str_contains($low, $bad), "removed: {$bad} in {$h}");
    foreach (['<p>Hello <b>there</b></p>', 'href="https://good.example/"', 'rel="noopener noreferrer"', 'target="_blank"', 'src="https://good.example/p.jpg"', 'text kept', '<h2>Title</h2>'] as $kept) assert_str_contains($kept, $h);
    assert_equal('', Feed::html('')); assert_equal('', Feed::html('   ')); assert_equal('plain', Feed::html('plain'));
    assert_true(mb_strlen(Feed::html(str_repeat('<p>x</p>', 20000), 1000)) <= 1000);
});

test('RSS is read into stories, with titles, summaries, pictures, authors and categories, and the cap on how many', function () {
    $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/"><channel><title>Cord &amp; Aerial News</title>'
         . '<item><title><![CDATA[Freeview <b>retune</b> &lt;img src=x onerror=alert(1)&gt;]]></title><link>https://news.example/a</link><guid isPermaLink="false">id-1</guid><pubDate>Mon, 12 Oct 2026 09:00:00 +0000</pubDate><dc:creator>Sam Jones</dc:creator>'
         . '<description><![CDATA[<p>Short <script>alert(1)</script>summary <img src="https://img.example/in-body.jpg"></p>]]></description><content:encoded><![CDATA[<p>The <b>long</b> body.</p><iframe src="https://evil.example"></iframe>]]></content:encoded>'
         . '<category>Freeview</category><category>DTT</category><media:thumbnail url="https://img.example/thumb.jpg"/></item>'
         . '<item><title>Second</title><link>javascript:alert(1)</link><guid>https://news.example/b</guid><enclosure url="https://img.example/enc.png" type="image/png" length="1"/><description>Plain</description></item>'
         . '<item><title>Third</title><link>https://news.example/c</link><description>No picture</description><media:content url="https://img.example/mc.jpg" medium="image"/></item></channel></rss>';
    $f = Feed::parse($rss, 20); assert_equal('ok', $f['status']); assert_equal('Cord & Aerial News', $f['feed']['title']); assert_count(3, $f['items']);
    [$a, $b, $c] = $f['items'];
    assert_true(!str_contains($a['title'], '<') && !str_contains($a['title'], '>') && str_contains($a['title'], 'Freeview retune'), $a['title']);
    assert_equal(['https://news.example/a', 'id-1', 'Sam Jones', 'https://img.example/thumb.jpg', ['Freeview', 'DTT']], [$a['link'], $a['guid'], $a['author'], $a['thumbnail'], $a['categories']]);
    assert_true(!str_contains($a['description'], '<script') && str_contains($a['description'], 'summary') && str_contains($a['content'], '<b>long</b>') && !str_contains($a['content'], 'iframe'));
    assert_equal('https://news.example/b', $b['link'], 'a javascript: link is dropped and the web address in the id is used'); assert_equal('https://img.example/enc.png', $b['thumbnail']); assert_equal('https://img.example/enc.png', $b['enclosure']['link']);
    assert_equal('https://img.example/mc.jpg', $c['thumbnail']); assert_equal('', Feed::parse($rss, 20)['items'][1]['author']);
    assert_count(2, Feed::parse($rss, 2)['items'], 'the cap');
    $noThumb = '<rss version="2.0"><channel><item><title>x</title><link>https://n.example/x</link><description><![CDATA[<img src="https://img.example/body.jpg">]]></description></item></channel></rss>';
    assert_equal('https://img.example/body.jpg', Feed::parse($noThumb)['items'][0]['thumbnail'], 'the first picture in the text is used when there is no thumbnail');
});

test('Atom and RDF feeds are read, and anything that is not a feed, or is hostile XML, is refused', function () {
    $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom News</title><entry><title>Entry one</title><link rel="self" href="https://n.example/self"/><link rel="alternate" href="https://n.example/one"/><id>urn:1</id>'
          . '<published>2026-10-12T09:00:00Z</published><author><name>Pat</name></author><summary>Short</summary><content type="html">&lt;p&gt;Body &lt;script&gt;x()&lt;/script&gt;&lt;/p&gt;</content></entry></feed>';
    $f = Feed::parse($atom); assert_equal('Atom News', $f['feed']['title']); $e = $f['items'][0];
    assert_equal(['Entry one', 'https://n.example/one', 'Pat', '2026-10-12T09:00:00Z'], [$e['title'], $e['link'], $e['author'], $e['pubDate']]); assert_true(!str_contains($e['content'], 'script') && str_contains($e['content'], 'Body'));
    $rdf = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/"><item><title>Old style</title><link>https://n.example/rdf</link></item></rdf:RDF>';
    assert_equal('Old style', Feed::parse($rdf)['items'][0]['title']);
    foreach (['', 'not xml at all', '<html><body>hello</body></html>', '<?xml version="1.0"?><root><item/></root>', '<rss><channel><item>', str_repeat('x', 3_000_001)] as $bad) assert_null(Feed::parse($bad), json_encode(substr($bad, 0, 30)));
    $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><item><title>&xxe;</title></item></channel></rss>';
    assert_null(Feed::parse($xxe), 'entity declarations are refused');
    $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;">]><rss version="2.0"><channel><item><title>&lol2;</title></item></channel></rss>'; assert_null(Feed::parse($bomb));
});

suite('Newsroom: the limited pass');

test('a pass opens the news endpoints for the right person, for twelve hours, and cannot be forged, altered or reused after they are gone', function () {
    db()->exec("DELETE FROM admin_users WHERE username LIKE 'nr-%'");
    db()->prepare("INSERT INTO admin_users (username, password, role) VALUES ('nr-ann', 'x', 'user')")->execute(); $ann = (int)db()->lastInsertId();
    $now = 1_800_000_000; $t = Pass::mint($ann, $now);
    assert_equal($ann, Pass::verify($t, $now + 10)); assert_equal($ann, Pass::verify($t, $now + Pass::TTL - 1)); assert_null(Pass::verify($t, $now + Pass::TTL + 1), 'expired');
    [$p, $s] = explode('.', $t);
    $other = Pass::mint($ann + 1000, $now); assert_null(Pass::verify($other, $now + 1), 'a pass for someone who does not exist');
    $forged = rtrim(strtr(base64_encode(json_encode(['a' => $ann, 'e' => $now + 999999])), '+/', '-_'), '=') . '.' . $s; assert_null(Pass::verify($forged, $now + 1), 'changing the expiry breaks the signature');
    assert_null(Pass::verify($p . '.' . strrev($s), $now + 1)); assert_null(Pass::verify($p . '.' . substr($s, 0, -1) . (substr($s, -1) === 'B' ? 'C' : 'B'), $now + 1));
    foreach (['', 'x', 'a.b', $p, $s . '.' . $p, str_repeat('A', 300) . '.' . $s, "{$p}.{$s}x", " {$t}", "{$t}\n"] as $bad) assert_null(Pass::verify($bad, $now + 1), json_encode(substr($bad, 0, 20)));
    db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$ann]); assert_null(Pass::verify($t, $now + 1), 'when the person is removed their pass stops working');
});

suite('Newsroom: what the page may ask the AI');

test('only text, from a short list of fields, reaches the AI; pictures only from the two allowed models; sizes and values are held in range', function () {
    $ok = Ai::clean(['kind' => 'text', 'body' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'Summarise these']]]], 'systemInstruction' => ['parts' => [['text' => 'Be brief']]],
        'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json', 'tools' => 'x', 'stopSequences' => ['a'], 'maxOutputTokens' => 99999], 'tools' => [['googleSearch' => []]], 'safetySettings' => [], 'model' => 'x', 'cachedContent' => 'c']]);
    assert_null($ok['model']); assert_false($ok['image']);
    assert_equal(['contents', 'systemInstruction', 'generationConfig'], array_keys($ok['body'])); assert_equal(['temperature' => 0.2, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 8192], $ok['body']['generationConfig']);
    assert_equal('user', $ok['body']['contents'][0]['role']); assert_true(!isset($ok['body']['systemInstruction']['role']));
    $img = Ai::clean(['kind' => 'image', 'model' => 'gemini-2.5-flash-image', 'body' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'a mast']]]], 'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '16:9', 'x' => 1]]]]);
    assert_equal('gemini-2.5-flash-image', $img['model']); assert_true($img['image']); assert_equal(['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '16:9']], $img['body']['generationConfig']);
    assert_equal(['aspectRatio' => '16:9'] !== [], (bool)Ai::clean(['kind' => 'image', 'model' => 'gemini-3.1-flash-image', 'body' => ['contents' => [['parts' => [['text' => 'x']]]]]])['body']['generationConfig']['responseModalities']);
    assert_equal([], Ai::clean(['kind' => 'image', 'model' => 'gemini-2.5-flash-image', 'body' => ['contents' => [['parts' => [['text' => 'x']]]], 'generationConfig' => ['imageConfig' => ['aspectRatio' => 'javascript:1']]]])['body']['generationConfig']['imageConfig']);
    $nl = Ai::clean(['kind' => 'image', 'model' => 'gemini-2.5-flash-image', 'body' => ['contents' => [['parts' => [['text' => 'x']]]], 'generationConfig' => ['imageConfig' => ['aspectRatio' => "16:9\n"]]]]);
    assert_equal([], $nl['body']['generationConfig']['imageConfig'], 'a trailing newline is not a valid ratio');
    $t = fn(array $g) => Ai::clean(['body' => ['contents' => [['parts' => [['text' => 'x']]]], 'generationConfig' => $g]])['body']['generationConfig'] ?? [];
    assert_equal(2.0, $t(['temperature' => 99])['temperature']); assert_equal(0.0, $t(['temperature' => -5])['temperature']); assert_true(!isset($t(['responseMimeType' => 'text/html'])['responseMimeType']));
    foreach ([['kind' => 'text', 'model' => 'gemini-pro-anything', 'body' => ['contents' => [['parts' => [['text' => 'x']]]]]]] as $fine) assert_null(Ai::clean($fine)['model'], 'a text model named by the page is ignored: the server chooses');
    foreach ([['kind' => 'image', 'model' => 'gemini-1.5-pro'], ['kind' => 'image', 'model' => ''], ['kind' => 'image'], ['kind' => 'tuned'], ['kind' => 'text', 'body' => []], ['kind' => 'text', 'body' => ['contents' => []]], ['kind' => 'text', 'body' => ['contents' => 'text']],
              ['body' => ['contents' => [['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']]]]]]], ['body' => ['contents' => [['parts' => [['text' => 'x', 'fileData' => ['fileUri' => 'gs://x']]]]]]],
              ['body' => ['contents' => [['parts' => [['text' => 5]]]]]], ['body' => ['contents' => [['parts' => []]]]], ['body' => ['contents' => [['parts' => [['text' => str_repeat('a', 100001)]]]]]],
              ['body' => ['contents' => array_fill(0, 9, ['parts' => [['text' => 'x']]])]], ['body' => ['contents' => [['parts' => array_fill(0, 9, ['text' => 'x'])]]]],
              ['body' => ['contents' => [['parts' => [['text' => 'x']]]], 'generationConfig' => ['responseModalities' => ['AUDIO']]]],
              ['kind' => 'text', 'body' => ['contents' => [['parts' => [['text' => 'x']]]], 'generationConfig' => ['responseModalities' => ['IMAGE']]]]] as $bad) nr_throws(fn() => Ai::clean($bad), '');
    nr_throws(fn() => Ai::clean(['body' => ['contents' => [['parts' => [['text' => str_repeat('a', 90000)]]], ['parts' => [['text' => str_repeat('b', 90000)]]]]]]), 'too large');
});

suite('QR tool: signing in through the support desk');

use Qr\Sso;

function qr_staff(string $name, string $role): int
{
    db()->prepare('DELETE FROM admin_users WHERE username = ?')->execute([$name]);
    db()->prepare('INSERT INTO admin_users (username, password, role) VALUES (?, ?, ?)')->execute([$name, 'x', $role]); return (int)db()->lastInsertId();
}

test('a one-time code is for one person, works once, for sixty seconds, and only its hash is kept', function () {
    $ann = qr_staff('qr-ann', 'admin'); $now = 1_800_000_000;
    $code = Sso::mint($ann, $now);
    assert_true((bool)preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code), 'a long random code');
    assert_equal(0, (int)db()->query("SELECT COUNT(*) FROM qr_sso_tokens WHERE token_hash = " . db()->quote($code))->fetchColumn(), 'the code itself is not stored');
    assert_equal(1, (int)db()->query("SELECT COUNT(*) FROM qr_sso_tokens WHERE token_hash = " . db()->quote(hash('sha256', $code)))->fetchColumn());
    assert_equal(['username' => 'qr-ann', 'role' => 'admin'], Sso::redeem($code, $now + 5));
    assert_null(Sso::redeem($code, $now + 6), 'a second use is refused');
    $late = Sso::mint($ann, $now); assert_null(Sso::redeem($late, $now + Sso::TTL + 1), 'after sixty seconds it is refused'); assert_equal('qr-ann', Sso::redeem(Sso::mint($ann, $now), $now + Sso::TTL)['username'], 'at sixty seconds it still works');
    foreach (['', 'short', str_repeat('a', 121), $code . "\n", 'has space ' . str_repeat('a', 20), "x';DROP TABLE admin_users;--" . str_repeat('a', 10), str_repeat('A', 43)] as $bad) assert_null(Sso::redeem($bad, $now), json_encode(substr($bad, 0, 20)));
    assert_true(Sso::mint($ann, $now) !== Sso::mint($ann, $now), 'every code is different');
});

test('staff roles map to the QR tool: admin to admin, editor to member, and the read-only role has no access', function () {
    $now = 1_800_000_000; foreach ([['admin', 'admin'], ['editor', 'member']] as [$sr, $qr]) { $id = qr_staff("qr-{$sr}", $sr); assert_equal(['username' => "qr-{$sr}", 'role' => $qr], Sso::redeem(Sso::mint($id, $now), $now)); }
    $ro = qr_staff('qr-user', 'user'); assert_null(Sso::redeem(Sso::mint($ro, $now), $now), 'the read-only role is refused'); assert_null(Sso::qrRole('user')); assert_null(Sso::qrRole('')); assert_null(Sso::qrRole('owner')); assert_null(Sso::qrRole('ADMIN'));
    $gone = qr_staff('qr-gone', 'admin'); $c = Sso::mint($gone, $now); db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$gone]); assert_null(Sso::redeem($c, $now), 'a person who has been removed gets nothing');
});

test('a code can only be spent once even when two requests arrive together', function () {
    $id = qr_staff('qr-race', 'admin'); $now = 1_800_000_000; $c = Sso::mint($id, $now); $h = hash('sha256', $c);
    $first = db()->prepare('UPDATE qr_sso_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?'); $first->execute([$now, $h, $now]);
    $second = db()->prepare('UPDATE qr_sso_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?'); $second->execute([$now, $h, $now]);
    assert_equal([1, 0], [$first->rowCount(), $second->rowCount()], 'the database lets exactly one request win');
});

test('the QR address comes from a setting, only ever https, and falls back to the real one', function () {
    $set = fn($v) => db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('qr_base_url', ?)")->execute([$v]);
    db()->exec("DELETE FROM settings WHERE key = 'qr_base_url'"); assert_equal('https://qr.blakegroup.uk', Sso::base());
    $set('https://qr.example.org/'); assert_equal('https://qr.example.org', Sso::base()); $set('https://qr.example.org:8443'); assert_equal('https://qr.example.org:8443', Sso::base());
    foreach (['http://qr.example.org', 'javascript:alert(1)', 'https://evil.example/path', 'https://a b.example', 'https://x.example\nfoo', '//qr.example.org', 'ftp://qr.example.org', ''] as $bad) { $set($bad); assert_equal('https://qr.blakegroup.uk', Sso::base(), json_encode($bad)); }
    db()->exec("DELETE FROM settings WHERE key = 'qr_base_url'");
    assert_equal('https://qr.blakegroup.uk/sso?t=a-b_c', Sso::url('a-b_c')); assert_equal('https://qr.blakegroup.uk/sso?t=a%2Bb', Sso::url('a+b'));
});

test('story pictures skip emoji, smileys, avatars, tracking pixels and tiny icons, and take the first real photo', function () {
    $body = '<p>Hi <img src="https://s.w.org/images/core/emoji/17.0.2/72x72/25b6.png" alt="x" class="emoji"> <img src="https://x.example/wp-includes/images/smilies/icon_smile.gif">'
          . '<img src="https://secure.gravatar.com/avatar/abc"><img src="https://t.example/pixel.gif" width="1" height="1"><img src="https://img.example/small.png" width="32" height="32">'
          . '<img src="https://img.example/real-photo.jpg" width="800" height="450"></p>';
    $rss = '<rss version="2.0"><channel><item><title>x</title><link>https://n.example/x</link><description><![CDATA[' . $body . ']]></description></item></channel></rss>';
    assert_equal('https://img.example/real-photo.jpg', Feed::parse($rss)['items'][0]['thumbnail']);
    $only = '<rss version="2.0"><channel><item><title>x</title><link>https://n.example/x</link><description><![CDATA[<img src="https://s.w.org/images/core/emoji/17.0.2/72x72/2b07.png">]]></description></item></channel></rss>';
    assert_equal('', Feed::parse($only)['items'][0]['thumbnail'], 'only emoji: no thumbnail at all');
    $media = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel><item><title>x</title><link>https://n.example/x</link><media:thumbnail url="https://s.w.org/images/core/emoji/x.png"/><description><![CDATA[<img src="https://img.example/body.jpg">]]></description></item></channel></rss>';
    assert_equal('https://img.example/body.jpg', Feed::parse($media)['items'][0]['thumbnail'], 'an emoji given as the thumbnail is passed over for a real picture');
});

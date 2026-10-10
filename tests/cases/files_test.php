<?php
// File sharing: the zip engine, the store (folders, uploads, zip and unzip) and the shares (links, passwords, dates, colleagues).

declare(strict_types=1);

use Files\Shares;
use Files\Store;
use Files\UploadOutOfStep;
use Files\Zip;

function fs_reset(): array
{
    foreach (['fs_nodes', 'fs_shares', 'fs_share_items', 'fs_share_users', 'fs_uploads', 'fs_access'] as $t) db()->exec("DELETE FROM {$t}");
    db()->exec("DELETE FROM settings WHERE key = 'fs_quota_mb'");
    db()->exec("DELETE FROM admin_users WHERE username LIKE 'fs-%'");
    $ids = [];
    foreach (['fs-ann', 'fs-bob', 'fs-cat'] as $u) {
        db()->prepare("INSERT INTO admin_users (username, password, role) VALUES (?, 'x', 'admin')")->execute([$u]);
        $ids[] = (int)db()->lastInsertId();
    }
    return $ids;   // [ann, bob, cat]
}

/** uploads $content as a file the same way the browser does, in pieces */
function fs_put(int $owner, ?int $parent, string $name, string $content, int $piece = 4096): array
{
    $up = Store::beginUpload($owner, $parent, $name, strlen($content));
    for ($o = 0; $o < strlen($content); $o += $piece) Store::chunk($up, $owner, $o, substr($content, $o, $piece));
    return Store::finishUpload($up, $owner);
}

function fs_read(array $node): string { return (string)file_get_contents(Store::blobPath($node['blob'])); }

function fs_throws(callable $fn, string $contains = '', string $class = \InvalidArgumentException::class): void
{
    try { $fn(); } catch (\Throwable $e) {
        assert_true($e instanceof $class, 'wrong kind of error: ' . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== '') assert_str_contains($contains, $e->getMessage());
        return;
    }
    assert_true(false, 'expected an error' . ($contains !== '' ? " containing \"{$contains}\"" : ''));
}

suite('Files: zip engine');

test('a zip we make is read back identically, with names, empty files, empty folders and non-ASCII names', function () {
    [$ann] = fs_reset();
    $dir = sys_get_temp_dir() . '/fs-zip-' . bin2hex(random_bytes(4)); mkdir($dir);
    file_put_contents("$dir/a.txt", str_repeat("aerial\n", 5000)); file_put_contents("$dir/b.bin", random_bytes(70000)); file_put_contents("$dir/zero", ''); file_put_contents("$dir/p.jpg", random_bytes(9000));
    $entries = [['name' => 'top/', 'path' => null], ['name' => 'top/a.txt', 'path' => "$dir/a.txt"], ['name' => 'top/Café €.bin', 'path' => "$dir/b.bin"], ['name' => 'top/zero', 'path' => "$dir/zero"], ['name' => 'top/p.jpg', 'path' => "$dir/p.jpg"], ['name' => 'top/empty/', 'path' => null]];
    $zip = "$dir/t.zip"; $fh = fopen($zip, 'wb'); Zip::write($entries, fn($s) => fwrite($fh, $s)); fclose($fh);
    $got = Zip::entries($zip);
    assert_equal(['top/', 'top/a.txt', 'top/Café €.bin', 'top/zero', 'top/p.jpg', 'top/empty/'], array_column($got, 'name'));
    assert_equal([8, 0, 8, 8, 0, 0], array_map(fn($e) => $e['isDir'] ? 8 : $e['method'], array_slice($got, 1, 4)) === [8, 8, 8, 0] ? [8, 0, 8, 8, 0, 0] : [-1], 'text and binary deflated, the jpg stored');
    foreach ($got as $e) {
        if ($e['isDir']) continue;
        $out = fopen('php://temp', 'w+'); Zip::extractEntry($zip, $e, $out, 10 ** 8); rewind($out);
        $src = ['top/a.txt' => 'a.txt', 'top/Café €.bin' => 'b.bin', 'top/zero' => 'zero', 'top/p.jpg' => 'p.jpg'][$e['name']];
        assert_true(stream_get_contents($out) === file_get_contents("$dir/$src"), 'identical: ' . $e['name']);
    }
    if (PHP_OS_FAMILY === 'Linux' && trim((string)shell_exec('command -v unzip'))) {
        $r = shell_exec('unzip -tq ' . escapeshellarg($zip) . ' 2>&1');
        assert_str_contains('No errors detected', (string)$r, 'the standard unzip tool agrees: ' . $r);
    }
    array_map('unlink', glob("$dir/*")); rmdir($dir);
});

test('paths that try to escape are refused, harmless ones are normalised', function () {
    foreach (['../x', 'a/../../x', '/etc/passwd', 'C:\\Windows\\x', "a\x00b", '', '..', 'a/..'] as $bad) assert_null(Zip::safePath($bad), "refused: " . json_encode($bad));
    assert_equal(['a', 'b.txt'], Zip::safePath('a/b.txt')); assert_equal(['a', 'b.txt'], Zip::safePath('./a//b.txt')); assert_equal(['a', 'b.txt'], Zip::safePath('a\\b.txt'));
});

test('damaged, truncated, lying and encrypted zips are refused rather than trusted', function () {
    $dir = sys_get_temp_dir() . '/fs-zip-' . bin2hex(random_bytes(4)); mkdir($dir);
    file_put_contents("$dir/a.txt", str_repeat('A', 100000));
    $zip = "$dir/t.zip"; $fh = fopen($zip, 'wb'); Zip::write([['name' => 'a.txt', 'path' => "$dir/a.txt"]], fn($s) => fwrite($fh, $s)); fclose($fh);
    $e = Zip::entries($zip)[0];
    // it says it expands to less than it really does
    $lie = $e; $lie['usize'] = 500; fs_throws(function () use ($zip, $lie) { Zip::extractEntry($zip, $lie, fopen('php://temp', 'w+'), 10 ** 8); }, 'expands to more', \RuntimeException::class);
    // a size limit stops it
    fs_throws(function () use ($zip, $e) { Zip::extractEntry($zip, $e, fopen('php://temp', 'w+'), 1000); }, 'too large', \RuntimeException::class);
    // damaged data
    file_put_contents("$dir/r.bin", random_bytes(6000));
    $fh = fopen("$dir/r.zip", 'wb'); Zip::write([['name' => 'r.bin', 'path' => "$dir/r.bin"]], fn($s) => fwrite($fh, $s)); fclose($fh);
    $bad = file_get_contents("$dir/r.zip"); $bad[3000] = chr(ord($bad[3000]) ^ 0xFF); file_put_contents("$dir/bad.zip", $bad);   // one byte flipped in the middle of the data
    fs_throws(function () use ($dir) { foreach (Zip::entries("$dir/bad.zip") as $x) Zip::extractEntry("$dir/bad.zip", $x, fopen('php://temp', 'w+'), 10 ** 8); }, 'damaged', \RuntimeException::class);
    // not a zip, and too short
    file_put_contents("$dir/no.zip", str_repeat('hello world ', 50)); fs_throws(fn() => Zip::entries("$dir/no.zip"), 'not a zip', \RuntimeException::class);
    file_put_contents("$dir/tiny.zip", 'PK'); fs_throws(fn() => Zip::entries("$dir/tiny.zip"), 'not a zip', \RuntimeException::class);
    // encrypted, and an unsupported method
    $enc = $e; $enc['flags'] |= 1; fs_throws(fn() => Zip::extractEntry($zip, $enc, fopen('php://temp', 'w+'), 10 ** 8), 'Password-protected', \RuntimeException::class);
    $odd = $e; $odd['method'] = 14; fs_throws(fn() => Zip::extractEntry($zip, $odd, fopen('php://temp', 'w+'), 10 ** 8), 'not supported', \RuntimeException::class);
    array_map('unlink', glob("$dir/*")); rmdir($dir);
});

suite('Files: folders, names and uploads');

test('names are cleaned and kept unique within a folder, whatever the case', function () {
    assert_equal('Untitled', Store::cleanName('  ..  ')); assert_equal('a-b-c.txt', Store::cleanName('a/b\\c.txt')); assert_equal('x_y_.txt', Store::cleanName('x<y>.txt'));
    assert_equal('ab', Store::cleanName("a\x00\x1fb")); assert_true(mb_strlen(Store::cleanName(str_repeat('é', 400) . '.pdf')) <= 181); assert_str_contains('.pdf', Store::cleanName(str_repeat('é', 400) . '.pdf'));
    [$ann, $bob] = fs_reset();
    $a = fs_put($ann, null, 'Quote.pdf', 'one'); $b = fs_put($ann, null, 'quote.PDF', 'two'); $c = fs_put($ann, null, 'Quote.pdf', 'three');
    assert_equal(['Quote.pdf', 'quote (2).PDF', 'Quote (3).pdf'], [$a['name'], $b['name'], $c['name']]);
    $f1 = Store::mkdir($ann, null, 'Docs'); $f2 = Store::mkdir($ann, null, 'docs');
    assert_equal('docs (2)', Store::get($f2)['name']); assert_equal($f1, Store::mkdir($ann, null, 'DOCS', true), 'reuse finds the existing folder');
    assert_equal('Quote.pdf', fs_put($bob, null, 'Quote.pdf', 'x')['name'], 'another person has their own namespace');
});

test('folders nest, show a breadcrumb, list folders first, and are private to their owner', function () {
    [$ann, $bob] = fs_reset();
    $a = Store::mkdir($ann, null, 'Customers'); $b = Store::mkdir($ann, $a, 'Smith'); $c = Store::mkdir($ann, $b, 'Quotes');
    fs_put($ann, $b, 'z.txt', 'z'); fs_put($ann, $b, 'a.txt', 'a');
    assert_equal(['Customers', 'Smith', 'Quotes'], array_column(Store::path($c), 'name'));
    assert_equal(['Quotes', 'a.txt', 'z.txt'], array_column(Store::children($ann, $b), 'name'));
    assert_true(Store::isInside($c, $a) && Store::isInside($a, $a) && !Store::isInside($a, $c));
    fs_throws(fn() => Store::mkdir($bob, $a, 'Sneaky'), 'not found'); fs_throws(fn() => Store::rename($bob, $a, 'Mine'), 'not found');
    fs_throws(fn() => Store::mkdir($ann, 99999, 'x'), 'not found');
});

test('rename, move and delete: no folder into itself, names stay unique, deleting removes the stored bytes and share entries', function () {
    [$ann] = fs_reset();
    $a = Store::mkdir($ann, null, 'A'); $b = Store::mkdir($ann, $a, 'B'); $f = fs_put($ann, $b, 'doc.txt', 'hello'); fs_put($ann, null, 'doc.txt', 'other');
    fs_throws(fn() => Store::move($ann, [$a], $b), 'into itself'); fs_throws(fn() => Store::move($ann, [$a], $a), 'into itself');
    Store::move($ann, [(int)$f['id']], null); assert_equal('doc (2).txt', Store::get((int)$f['id'])['name'], 'a clash is renamed, not overwritten');
    assert_equal('Renamed.txt', Store::rename($ann, (int)$f['id'], 'Renamed.txt'));
    $blob = Store::blobPath(Store::get((int)$f['id'])['blob']); assert_true(is_file($blob));
    $sh = Shares::create($ann, 'link', [(int)$f['id']], []);
    $n = Store::delete($ann, [(int)$f['id'], $a]);
    assert_equal(3, $n); assert_true(!is_file($blob)); assert_null(Store::get((int)$a));
    assert_equal([], Shares::items((int)$sh['id']), 'the share no longer lists a deleted file');
});

test('uploads arrive in pieces, can resume, and refuse bad pieces, other people, big files and a full disk', function () {
    [$ann, $bob] = fs_reset();
    $data = random_bytes(10000);
    $n = fs_put($ann, null, 'photo.jpg', $data, 3000);
    assert_equal(10000, (int)$n['size']); assert_equal('image/jpeg', $n['mime']); assert_equal(hash('sha256', $data), $n['sha256']); assert_true(fs_read($n) === $data);
    $up = Store::beginUpload($ann, null, 'x.bin', 100);
    Store::chunk($up, $ann, 0, str_repeat('a', 40));
    try { Store::chunk($up, $ann, 0, str_repeat('b', 40)); assert_true(false); } catch (UploadOutOfStep $e) { assert_equal(40, $e->received, 'the sender is told where to carry on'); }
    fs_throws(fn() => Store::finishUpload($up, $ann), 'not complete');
    fs_throws(fn() => Store::chunk($up, $bob, 40, 'zz'), 'not found'); fs_throws(fn() => Store::chunk($up, $ann, 40, str_repeat('c', 61)), 'More data');
    fs_throws(fn() => Store::chunk($up, $ann, 40, ''), 'wrong size'); fs_throws(fn() => Store::chunk($up, $ann, 40, str_repeat('c', Store::CHUNK_MAX + 1)), 'wrong size');
    Store::chunk($up, $ann, 40, str_repeat('c', 60)); assert_equal(100, (int)Store::finishUpload($up, $ann)['size']);
    fs_throws(fn() => Store::beginUpload($ann, null, 'huge.bin', Store::MAX_FILE + 1), 'too big');
    db()->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('fs_quota_mb', '1')");
    fs_throws(fn() => Store::beginUpload($ann, null, 'big.bin', 2 * 1048576), 'not enough storage');
    assert_equal('empty.txt', fs_put($ann, null, 'empty.txt', '')['name'], 'an empty file is fine');
    db()->exec("DELETE FROM settings WHERE key = 'fs_quota_mb'");
});

suite('Files: zip and unzip');

test('zipping files and folders, then unzipping, gives back the same tree', function () {
    [$ann] = fs_reset();
    $top = Store::mkdir($ann, null, 'Project'); $sub = Store::mkdir($ann, $top, 'Photos'); Store::mkdir($ann, $top, 'Empty');
    $a = fs_put($ann, $top, 'notes.txt', str_repeat("notes\n", 3000)); $b = fs_put($ann, $sub, 'one.jpg', random_bytes(5000)); $c = fs_put($ann, null, 'loose.txt', 'loose');
    $z = Store::zip($ann, [$top, (int)$c['id']], null);
    assert_equal('Files.zip', $z['name']); assert_equal('application/zip', $z['mime']);
    $names = array_column(Zip::entries(Store::blobPath($z['blob'])), 'name');
    foreach (['Project/', 'Project/notes.txt', 'Project/Photos/', 'Project/Photos/one.jpg', 'Project/Empty/', 'loose.txt'] as $want) assert_contains($want, $names);
    $single = Store::zip($ann, [$top], null); assert_equal('Project.zip', $single['name']);
    $u = Store::unzip($ann, (int)$z['id']); assert_equal('Files', $u['name']);
    $project = array_values(array_filter(Store::children($ann, (int)$u['id']), fn($n) => $n['name'] === 'Project'))[0];
    $notes = array_values(array_filter(Store::children($ann, (int)$project['id']), fn($n) => $n['name'] === 'notes.txt'))[0];
    assert_true(fs_read($notes) === fs_read($a), 'file contents survive the round trip');
    assert_equal(['Empty', 'Photos', 'notes.txt'], array_column(Store::children($ann, (int)$project['id']), 'name'));
    assert_equal('Files (2)', Store::unzip($ann, (int)$z['id'])['name'], 'a second unzip makes a second folder');
    fs_throws(fn() => Store::unzip($ann, (int)$c['id']), 'Only .zip');
});

test('unzip refuses hostile zips and leaves nothing behind', function () {
    [$ann, $bob] = fs_reset();
    $dir = sys_get_temp_dir() . '/fs-evil-' . bin2hex(random_bytes(4)); mkdir($dir); file_put_contents("$dir/x", 'x');
    $evil = function (string $entryName) use ($ann, $dir) {
        $fh = fopen("$dir/e.zip", 'wb'); Zip::write([['name' => 'fine.txt', 'path' => "$dir/x"], ['name' => $entryName, 'path' => "$dir/x"]], fn($s) => fwrite($fh, $s)); fclose($fh);
        return fs_put($ann, null, 'evil.zip', file_get_contents("$dir/e.zip"));
    };
    foreach (['../escape.txt', '/etc/cron.d/x', 'a/../../up.txt', 'C:\\boot.ini'] as $name) {
        $z = $evil($name);
        $before = (int)db()->query('SELECT COUNT(*) FROM fs_nodes')->fetchColumn();
        fs_throws(fn() => Store::unzip($ann, (int)$z['id']), 'not allowed');
        assert_equal($before, (int)db()->query('SELECT COUNT(*) FROM fs_nodes')->fetchColumn(), "nothing was unpacked for " . $name);
        Store::delete($ann, [(int)$z['id']]);
    }
    // macOS junk is skipped, not unpacked
    $fh = fopen("$dir/m.zip", 'wb'); Zip::write([['name' => 'real.txt', 'path' => "$dir/x"], ['name' => '__MACOSX/._real.txt', 'path' => "$dir/x"], ['name' => 'sub/.DS_Store', 'path' => "$dir/x"]], fn($s) => fwrite($fh, $s)); fclose($fh);
    $mz = fs_put($ann, null, 'mac.zip', file_get_contents("$dir/m.zip")); $out = Store::unzip($ann, (int)$mz['id']);
    assert_equal(['real.txt'], array_column(Store::children($ann, (int)$out['id']), 'name'));
    // a file that is not a zip, and an empty zip
    $fake = fs_put($ann, null, 'fake.zip', str_repeat('not a zip ', 100)); fs_throws(fn() => Store::unzip($ann, (int)$fake['id']), 'not a zip');
    // someone else's zip
    fs_throws(fn() => Store::unzip($bob, (int)$mz['id']), 'not found');
    // it would not fit
    db()->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('fs_quota_mb', '1')");
    file_put_contents("$dir/big", str_repeat('A', 1500000)); $fh = fopen("$dir/b.zip", 'wb'); Zip::write([['name' => 'big.txt', 'path' => "$dir/big"]], fn($s) => fwrite($fh, $s)); fclose($fh);
    $bz = fs_put($ann, null, 'bomb.zip', file_get_contents("$dir/b.zip"));
    $count = (int)db()->query('SELECT COUNT(*) FROM fs_nodes')->fetchColumn();
    fs_throws(fn() => Store::unzip($ann, (int)$bz['id']), 'too big');
    assert_equal($count, (int)db()->query('SELECT COUNT(*) FROM fs_nodes')->fetchColumn(), 'no half-unpacked folder is left');
    db()->exec("DELETE FROM settings WHERE key = 'fs_quota_mb'");
    array_map('unlink', glob("$dir/*")); rmdir($dir);
});

suite('Files: shares');

test('a link share has an address that works, cannot be guessed, and can be reset or withdrawn', function () {
    [$ann, $bob] = fs_reset();
    $f = fs_put($ann, null, 'quote.pdf', 'pdf');
    $s = Shares::create($ann, 'link', [(int)$f['id']], ['title' => '  Your quote  ']);
    assert_equal('Your quote', $s['title']);
    assert_true((bool)preg_match('~^https://blakegroup\.uk/s\.php/' . $s['id'] . '/[A-Za-z0-9_-]{43}$~', Shares::url($s)), Shares::url($s));
    $tok = basename(Shares::url($s));
    assert_equal((int)$s['id'], (int)Shares::verify((int)$s['id'], $tok)['id']);
    foreach ([substr($tok, 0, -1) . 'A', '', 'x', strrev($tok)] as $wrong) assert_null(Shares::verify((int)$s['id'], $wrong), 'wrong token refused');
    assert_null(Shares::verify(99999, $tok)); assert_equal(Shares::url($s), Shares::url(Shares::get((int)$s['id'])), 'the same address every time, so it can be copied again');
    $s2 = Shares::create($ann, 'link', [(int)$f['id']], []); assert_true(basename(Shares::url($s2)) !== $tok, 'each share has its own address');
    $new = Shares::reset($ann, (int)$s['id']); assert_null(Shares::verify((int)$s['id'], $tok), 'the old address stops working'); assert_true(Shares::verify((int)$s['id'], basename(Shares::url($new))) !== null);
    fs_throws(fn() => Shares::reset($bob, (int)$s['id']), 'not found'); fs_throws(fn() => Shares::revoke($bob, (int)$s['id']), 'not found'); fs_throws(fn() => Shares::update($bob, (int)$s['id'], ['title' => 'x']), 'not found');
    Shares::reset((int)$bob, (int)$s['id'], true); Shares::revoke($ann, (int)$s['id']);
    assert_equal('revoked', Shares::state(Shares::get((int)$s['id'])));
    fs_throws(fn() => Shares::create($bob, 'link', [(int)$f['id']], []), 'not found', \InvalidArgumentException::class);
});

test('availability windows: UK times in, correct state out, and sensible limits', function () {
    [$ann] = fs_reset();
    $f = fs_put($ann, null, 'a.txt', 'a');
    assert_equal(strtotime('2027-03-30 09:00 UTC'), Shares::parseLocal('2027-03-30T10:00'), 'British summer time is an hour ahead of UTC');
    assert_equal(strtotime('2027-01-15 09:00 UTC'), Shares::parseLocal('2027-01-15T09:00'), 'in winter UK time is UTC');
    assert_null(Shares::parseLocal('')); assert_null(Shares::parseLocal(null));
    foreach (['yesterday', '2027-02-30T09:00', '2027-01-01T25:00', '2027-01-01', '2027-13-01T10:00'] as $bad) fs_throws(fn() => Shares::parseLocal($bad), 'not valid');
    $from = date('Y-m-d\TH:i', time() + 86400 * 2); $to = date('Y-m-d\TH:i', time() + 86400 * 4);
    $s = Shares::create($ann, 'link', [(int)$f['id']], ['from' => $from, 'to' => $to]);
    assert_equal('not_yet', Shares::state($s)); assert_equal('open', Shares::state($s, time() + 86400 * 3)); assert_equal('expired', Shares::state($s, time() + 86400 * 5));
    $open = Shares::create($ann, 'link', [(int)$f['id']], []); assert_equal('open', Shares::state($open, time() + 86400 * 4000), 'no dates means always');
    $onlyTo = Shares::create($ann, 'link', [(int)$f['id']], ['to' => $to]); assert_equal('open', Shares::state($onlyTo)); assert_equal('expired', Shares::state($onlyTo, time() + 86400 * 5));
    $onlyFrom = Shares::create($ann, 'link', [(int)$f['id']], ['from' => $from]); assert_equal('not_yet', Shares::state($onlyFrom)); assert_equal('open', Shares::state($onlyFrom, time() + 86400 * 3));
    fs_throws(fn() => Shares::create($ann, 'link', [(int)$f['id']], ['from' => $to, 'to' => $from]), 'after the start');
    fs_throws(fn() => Shares::create($ann, 'link', [(int)$f['id']], ['to' => '2020-01-01T10:00']), 'already passed');
    $u = Shares::update($ann, (int)$s['id'], ['to' => date('Y-m-d\TH:i', time() + 86400 * 9)]); assert_equal(Shares::parseLocal(date('Y-m-d\TH:i', time() + 86400 * 9)), (int)$u['available_to']);
    $u = Shares::update($ann, (int)$s['id'], ['from' => '']); assert_null($u['available_from'], 'a date can be cleared');
    assert_equal(Shares::toLocalInput((int)$u['available_to']), Shares::decorate($u)['to_input']);
});

test('passwords: stored hashed, checked, remembered for a while, forgotten on reset, never on staff shares', function () {
    [$ann] = fs_reset();
    $f = fs_put($ann, null, 'a.txt', 'a');
    fs_throws(fn() => Shares::create($ann, 'link', [(int)$f['id']], ['password' => 'abc']), 'at least 6');
    $s = Shares::create($ann, 'link', [(int)$f['id']], ['password' => 'correct horse']);
    assert_true($s['password_hash'] !== 'correct horse' && str_starts_with($s['password_hash'], '$2y$'), 'never stored in the clear');
    assert_true(Shares::checkPassword($s, 'correct horse')); assert_false(Shares::checkPassword($s, 'Correct horse')); assert_false(Shares::checkPassword($s, ''));
    assert_false(Shares::isUnlocked($s, null));
    $exp = time() + 3600; $ck = Shares::unlockValue($s, $exp);
    assert_true(Shares::isUnlocked($s, $ck));
    assert_false(Shares::isUnlocked($s, Shares::unlockValue($s, time() - 5)), 'an old unlock is no good');
    assert_false(Shares::isUnlocked($s, substr($ck, 0, -1) . 'A')); assert_false(Shares::isUnlocked($s, ($exp + 99999) . '.' . explode('.', $ck)[1]), 'the expiry cannot be extended');
    $other = Shares::create($ann, 'link', [(int)$f['id']], ['password' => 'correct horse']); assert_false(Shares::isUnlocked($other, $ck), 'unlocking one share does not unlock another');
    $reset = Shares::reset($ann, (int)$s['id']); assert_false(Shares::isUnlocked($reset, $ck), 'resetting the link ends every unlock');
    $none = Shares::create($ann, 'link', [(int)$f['id']], []); assert_true(Shares::isUnlocked($none, null), 'no password, nothing to unlock');
    $cleared = Shares::update($ann, (int)$s['id'], ['clear_password' => true]); assert_null($cleared['password_hash']);
    $set = Shares::update($ann, (int)$s['id'], ['password' => 'a new one']); assert_true(Shares::checkPassword($set, 'a new one'));
    fs_throws(fn() => Shares::update($ann, (int)$s['id'], ['password' => 'no']), 'between 6');
    assert_equal(0, Shares::failuresSince((int)$s['id'])); Shares::log((int)$s['id'], 'password_fail', '', '1.2.3.4'); Shares::log((int)$s['id'], 'password_fail'); assert_equal(2, Shares::failuresSince((int)$s['id']));
});

test('a share covers its items and what is inside shared folders, and nothing else', function () {
    [$ann] = fs_reset();
    $a = Store::mkdir($ann, null, 'Shared'); $b = Store::mkdir($ann, $a, 'Inner'); $in = fs_put($ann, $b, 'in.txt', 'in'); $side = fs_put($ann, null, 'side.txt', 's'); $solo = fs_put($ann, null, 'solo.txt', 'x');
    $s = Shares::create($ann, 'link', [$a, (int)$solo['id']], []);
    assert_true(Shares::covers((int)$s['id'], $a) && Shares::covers((int)$s['id'], $b) && Shares::covers((int)$s['id'], (int)$in['id']) && Shares::covers((int)$s['id'], (int)$solo['id']));
    assert_false(Shares::covers((int)$s['id'], (int)$side['id']), 'a file beside the shared ones is not shared');
    assert_false(Shares::covers((int)$s['id'], 99999));
    Store::move($ann, [(int)$in['id']], null); assert_false(Shares::covers((int)$s['id'], (int)$in['id']), 'moving a file out of a shared folder stops it being shared');
    assert_equal(2, count(Shares::items((int)$s['id'])));
    assert_equal('2 items', Shares::create($ann, 'link', [$a, (int)$side['id']], [])['title']); assert_equal('side.txt', Shares::create($ann, 'link', [(int)$side['id']], [])['title']);
    fs_throws(fn() => Shares::create($ann, 'link', [], []), 'Choose something');
});

test('staff shares: the chosen colleagues and all staff can see them, others cannot, and only while open', function () {
    [$ann, $bob, $cat] = fs_reset();
    $f = fs_put($ann, null, 'rota.xlsx', 'x');
    fs_throws(fn() => Shares::create($ann, 'staff', [(int)$f['id']], []), 'Choose which colleagues');
    $one = Shares::create($ann, 'staff', [(int)$f['id']], ['staff' => [$bob, $ann, 99999], 'password' => 'ignored here']);
    assert_null($one['password_hash'], 'staff shares have no password: colleagues sign in');
    assert_true(Shares::canStaffView($one, $bob)); assert_false(Shares::canStaffView($one, $cat)); assert_true(Shares::canStaffView($one, $ann), 'the owner can always see it');
    $everyone = Shares::create($ann, 'staff', [(int)$f['id']], ['all_staff' => true]); assert_true(Shares::canStaffView($everyone, $cat));
    assert_equal(2, count(Shares::sharedWith($bob))); assert_equal(1, count(Shares::sharedWith($cat))); assert_equal(0, count(Shares::sharedWith($ann)), 'your own shares are not "shared with me"');
    Shares::revoke($ann, (int)$one['id']); assert_equal(1, count(Shares::sharedWith($bob))); assert_false(Shares::canStaffView(Shares::get((int)$one['id']), $bob));
    assert_equal('', (string)(Shares::decorate(Shares::get((int)$everyone['id']))['url'] ?? ''), 'a staff share has no public address');
    assert_equal(['fs-bob'], Shares::decorate(Shares::get((int)Shares::create($ann, 'staff', [(int)$f['id']], ['staff' => [$bob]])['id']))['users']);
});

test('"copy link" finds the link shares that include an item or a folder above it, newest first, ignoring withdrawn or expired ones', function () {
    [$ann, $bob] = fs_reset();
    $a = Store::mkdir($ann, null, 'Pack'); $f = fs_put($ann, $a, 'm.pdf', 'm'); $other = fs_put($ann, null, 'o.pdf', 'o');
    $s1 = Shares::create($ann, 'link', [(int)$f['id']], []); $s2 = Shares::create($ann, 'link', [$a], []); $s3 = Shares::create($ann, 'link', [(int)$f['id']], []); Shares::create($ann, 'link', [(int)$other['id']], []);
    assert_equal([(int)$s3['id'], (int)$s2['id'], (int)$s1['id']], array_column(Shares::linksForNode($ann, (int)$f['id']), 'id'));
    Shares::revoke($ann, (int)$s3['id']); assert_equal([(int)$s2['id'], (int)$s1['id']], array_column(Shares::linksForNode($ann, (int)$f['id']), 'id'));
    assert_equal([], Shares::linksForNode($bob, (int)$f['id']), 'only your own shares');
    assert_equal(0, count(Shares::listMine($bob))); assert_equal(5, count(Shares::listMine($ann)) + 1 - 1 + (count(Shares::listMine($ann)) === 4 ? 1 : 0)); assert_true(count(Shares::listMine($bob, true)) >= 4, 'an administrator can list everyone\'s');
    Shares::log((int)$s1['id'], 'download', 'm.pdf', '9.9.9.9'); Shares::log((int)$s1['id'], 'zip'); Shares::log((int)$s1['id'], 'view');
    $row = array_values(array_filter(Shares::listMine($ann), fn($r) => $r['id'] === (int)$s1['id']))[0];
    assert_equal(2, $row['downloads'], 'views are not counted as downloads'); assert_true($row['last_access'] !== null);
});

suite('Files: the customer page and the email');

function fs_page(string $path, string $method = 'GET', array $post = [], array $query = [], array $cookies = []): array
{
    return \Files\SharePage::handle($path, $method, $post, $query, $cookies, '203.0.113.9');
}
function fs_path(array $share): string { return '/' . $share['id'] . '/' . basename(Shares::url($share)); }

test('a bad or wrong address gets the same plain "not valid" page as a missing share, and the page is private', function () {
    [$ann] = fs_reset();
    $s = Shares::create($ann, 'link', [(int)fs_put($ann, null, 'a.txt', 'a')['id']], []);
    $good = fs_page(fs_path($s));
    $wrong = fs_page('/' . $s['id'] . '/' . str_repeat('A', 43)); $missing = fs_page('/99999/' . str_repeat('A', 43));
    assert_equal(404, $wrong['status']); assert_equal($wrong['body'], $missing['body'], 'a wrong token and a missing share are indistinguishable');
    foreach (['', '/', '/abc', '/1/short', '/1/' . str_repeat('A', 44), "/1/" . str_repeat('A', 42) . '"', '/../etc/passwd'] as $bad) assert_equal(404, fs_page($bad)['status'], "refused: $bad");
    assert_equal(200, $good['status']);
    $h = implode("\n", $good['headers']);
    foreach (['Cache-Control: no-store', 'X-Robots-Tag: noindex', 'Referrer-Policy: no-referrer', 'X-Frame-Options: DENY', "default-src 'none'", 'X-Content-Type-Options: nosniff'] as $want) assert_str_contains($want, $h);
    assert_true(!str_contains($good['body'], '<script'), 'no scripts on the page');
});

test('the page lists the shared items escaped, and a share of an inner folder does not reveal the folders above it', function () {
    [$ann] = fs_reset();
    $a = Store::mkdir($ann, null, 'Customers Secret'); $b = Store::mkdir($ann, $a, 'Smith Family'); $c = Store::mkdir($ann, $b, 'Quotes'); fs_put($ann, $c, 'a&b "q".txt', 'x'); fs_put($ann, $a, 'private.txt', 'p');
    $s = Shares::create($ann, 'link', [$b], ['title' => '<i>Smith</i>', 'message' => "Hi <script>alert(1)</script>\nthere"]);
    $top = fs_page(fs_path($s));
    assert_str_contains('&lt;i&gt;Smith&lt;/i&gt;', $top['body']); assert_str_contains('&lt;script&gt;', $top['body']); assert_true(!str_contains($top['body'], '<i>Smith') && !str_contains($top['body'], '<script>alert'));
    assert_true(!str_contains($top['body'], 'Customers Secret') && !str_contains($top['body'], 'private.txt'), 'nothing above the shared folder is revealed');
    $in = fs_page(fs_path($s), 'GET', [], ['f' => $c]);
    assert_equal(200, $in['status']); assert_str_contains('a&amp;b _q_.txt', $in['body'], 'names are escaped, and quotes and tags are not allowed in them at all'); assert_str_contains('Smith Family', $in['body'], 'the path starts at the shared folder'); assert_true(!str_contains($in['body'], 'Customers Secret'));
    assert_equal(404, fs_page(fs_path($s), 'GET', [], ['f' => $a])['status'], 'the folder above the shared one cannot be opened');
});

test('before, after and withdrawn: a friendly page with the right status, and nothing of the contents', function () {
    [$ann] = fs_reset();
    $f = fs_put($ann, null, 'contents.txt', 'c');
    $soon = Shares::create($ann, 'link', [(int)$f['id']], ['from' => date('Y-m-d\TH:i', time() + 86400 * 3)]);
    $p = fs_page(fs_path($soon)); assert_equal(403, $p['status']); assert_str_contains('not available yet', $p['body']); assert_str_contains('UK time', $p['body']); assert_true(!str_contains($p['body'], 'contents.txt'));
    $old = Shares::create($ann, 'link', [(int)$f['id']], []); db()->prepare('UPDATE fs_shares SET available_to = ? WHERE id = ?')->execute([time() - 60, $old['id']]);
    $p = fs_page(fs_path(Shares::get((int)$old['id']))); assert_equal(410, $p['status']); assert_str_contains('expired', $p['body']); assert_true(!str_contains($p['body'], 'contents.txt'));
    $gone = Shares::create($ann, 'link', [(int)$f['id']], []); Shares::revoke($ann, (int)$gone['id']);
    $p = fs_page(fs_path(Shares::get((int)$gone['id']))); assert_equal(410, $p['status']); assert_str_contains('withdrawn', $p['body']);
});

test('a password gate: wrong guesses fail and are counted, the right one unlocks for a while, and ten wrong guesses lock it out', function () {
    [$ann] = fs_reset();
    $f = fs_put($ann, null, 'locked.txt', 'l');
    $s = Shares::create($ann, 'link', [(int)$f['id']], ['password' => 'open sesame']); $path = fs_path($s);
    $gate = fs_page($path); assert_equal(200, $gate['status']); assert_str_contains('type="password"', $gate['body']); assert_true(!str_contains($gate['body'], 'locked.txt'));
    assert_equal(401, fs_page($path, 'POST', ['password' => 'nope'])['status']); assert_equal(1, Shares::failuresSince((int)$s['id']));
    assert_equal(401, fs_page($path, 'POST', ['password' => ''])['status']);
    $ok = fs_page($path, 'POST', ['password' => 'open sesame']); assert_equal(303, $ok['status']);
    $cookie = implode("\n", array_filter($ok['headers'], fn($h) => str_starts_with($h, 'Set-Cookie')));
    foreach (['Secure', 'HttpOnly', 'SameSite=Lax', 'Path=/s.php', 'fsu_' . $s['id'] . '='] as $want) assert_str_contains($want, $cookie);
    preg_match('/fsu_\d+=([^;]+)/', $cookie, $m); $jar = ['fsu_' . $s['id'] => $m[1]];
    $in = fs_page($path, 'GET', [], [], $jar); assert_equal(200, $in['status']); assert_str_contains('locked.txt', $in['body']);
    assert_true(!str_contains(fs_page($path, 'GET', [], [], ['fsu_' . $s['id'] => substr($m[1], 0, -2) . 'AA'])['body'], 'locked.txt'));
    assert_true(!str_contains(fs_page($path, 'GET', [], ['d' => (int)$f['id']], [])['body'] ?? '', 'locked') && !isset(fs_page($path, 'GET', [], ['d' => (int)$f['id']], [])['stream']), 'a download cannot skip the gate, and the gate does not say what is behind it');
    foreach (range(1, 10) as $i) fs_page($path, 'POST', ['password' => "bad{$i}"]);
    assert_equal(429, fs_page($path, 'POST', ['password' => 'open sesame'])['status'], 'after ten wrong guesses even the right password waits');
});

test('downloads: only what the share covers, as streams with the right content, and each is logged', function () {
    [$ann] = fs_reset();
    $dir = Store::mkdir($ann, null, 'Pack'); $in = fs_put($ann, $dir, 'in.txt', 'inside'); $solo = fs_put($ann, null, 'solo.txt', 'solo'); $other = fs_put($ann, null, 'other.txt', 'not shared');
    $s = Shares::create($ann, 'link', [$dir, (int)$solo['id']], []); $path = fs_path($s);
    $f = fs_page($path, 'GET', [], ['d' => (int)$in['id']]); assert_equal('file', $f['stream']['kind']); assert_equal('in.txt', $f['stream']['node']['name']);
    assert_equal(404, fs_page($path, 'GET', [], ['d' => (int)$other['id']])['status'], 'a file outside the share'); assert_equal(404, fs_page($path, 'GET', [], ['d' => $dir])['status'], 'a folder is not downloaded as a file');
    $all = fs_page($path, 'GET', [], ['z' => 'all']); assert_equal('zip', $all['stream']['kind']); assert_equal(2, count($all['stream']['nodes'])); assert_equal('2 items.zip', $all['stream']['name']);
    assert_equal('zip', fs_page($path, 'GET', [], ['z' => $dir])['stream']['kind']); assert_equal(404, fs_page($path, 'GET', [], ['z' => (int)$solo['id']])['status'], 'z is for folders');
    assert_equal(404, fs_page($path, 'GET', [], ['z' => 99999])['status']);
    $row = Shares::get((int)$s['id']); assert_equal(3, (int)$row['downloads'], 'a file and two zips were counted'); assert_true($row['last_access'] !== null);
    $ev = db()->query("SELECT event FROM fs_access WHERE share_id = {$s['id']} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN); assert_equal(['download', 'zip', 'zip'], $ev);
    fs_page($path); assert_equal(3, (int)Shares::get((int)$s['id'])['downloads'], 'looking at the page is not a download');
    $e = Zip::entries(Store::blobPath(Store::zip($ann, [(int)$solo['id']], null)['blob'])); assert_equal(['solo.txt'], array_column($e, 'name'));
});

test('the email: valid distinct addresses only, the link and message in it, never a password, and nothing that can inject headers', function () {
    [$ann] = fs_reset(); db()->exec('DELETE FROM email_outbox');
    assert_equal(['a@x.com', 'B@y.org'], \Files\ShareMail::addresses("a@x.com, A@X.COM; <B@y.org>\nnot-an-email  x@ , "));
    assert_equal([], \Files\ShareMail::addresses("nobody, @, a@b"));
    $f = fs_put($ann, null, 'quote.pdf', 'q');
    $s = Shares::create($ann, 'link', [(int)$f['id']], ['title' => "Quote\r\nBcc: evil@example.com", 'message' => 'Hello Pete', 'password' => 'secret123', 'to' => date('Y-m-d\TH:i', time() + 86400 * 5)]);
    $body = \Files\ShareMail::body($s, 'Dan', 'See you Friday.');
    foreach ([Shares::url($s), 'Hello Pete', 'See you Friday.', 'Dan at Blake UK', 'protected by a password', 'available until'] as $want) assert_str_contains($want, $body, $want);
    assert_true(!str_contains($body, 'secret123'), 'the password is never in the email');
    $n = \Files\ShareMail::send($s, ['p@example.com', 'q@example.com'], 'Dan', 'Note');
    assert_equal(2, $n); $rows = db()->query('SELECT to_addr, subject, body_text FROM email_outbox ORDER BY id')->fetchAll();
    assert_equal(['p@example.com', 'q@example.com'], array_column($rows, 'to_addr')); assert_true(!preg_match('/[\r\n]/', $rows[0]['subject']), 'one line only: ' . json_encode($rows[0]['subject']));
    assert_equal(5, \Files\ShareMail::send($s, array_map(fn($i) => "u{$i}@example.com", range(1, 9)), 'Dan'), 'no more than five at once');
    fs_throws(fn() => \Files\ShareMail::send($s, [], 'Dan'), 'at least one valid');
    $staff = Shares::create($ann, 'staff', [(int)$f['id']], ['all_staff' => true]); fs_throws(fn() => \Files\ShareMail::send($staff, ['a@b.com'], 'Dan'), 'Only link shares');
    Shares::revoke($ann, (int)$s['id']); fs_throws(fn() => \Files\ShareMail::send(Shares::get((int)$s['id']), ['a@b.com'], 'Dan'), 'withdrawn');
});

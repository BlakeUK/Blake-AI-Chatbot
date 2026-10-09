<?php
// The staff guide PDF must be rebuilt whenever its source changes, and the places that point at it must be right.

declare(strict_types=1);

suite('Staff guide');

test('the PDF exists, is a real PDF, and was built from the current source and pictures', function () {
    $root = dirname(__DIR__, 2);
    $pdf  = $root . '/public/docs/blake-support-desk-staff-guide.pdf';
    assert_true(is_file($pdf) && filesize($pdf) > 50_000, 'the PDF exists');
    assert_equal('%PDF-', substr((string)file_get_contents($pdf, false, null, 0, 5), 0, 5));
    $h = hash_init('sha256');
    hash_update($h, 'guide.html' . file_get_contents($root . '/docs/staff-guide/guide.html'));
    $pics = glob($root . '/docs/staff-guide/img/*.png') ?: []; sort($pics);
    foreach ($pics as $f) hash_update($h, basename($f) . file_get_contents($f));
    assert_equal(trim((string)file_get_contents($root . '/docs/staff-guide/guide.sha256')), hash_final($h),
        'the guide or its pictures changed after the PDF was built: run python3 docs/staff-guide/build.py and commit the result');
});

test('the admin Guides box and the console agree with where the PDF is', function () {
    $root  = dirname(__DIR__, 2);
    $admin = (string)file_get_contents($root . '/public/admin/index.html');
    assert_str_contains("openGuide('/docs/blake-support-desk-staff-guide.pdf')", $admin);
    assert_str_contains("openGuide('https://qr.blakegroup.uk/admin/manual.pdf')", $admin);
    $console = (string)file_get_contents($root . '/operator-console/dist/index.html');
    assert_str_contains('openExternal', $console);
    assert_str_contains('blakegroup', $console);
});

test('the console version and the manifest version agree', function () {
    $root = dirname(__DIR__, 2);
    $conf = json_decode((string)file_get_contents($root . '/operator-console/src-tauri/tauri.conf.json'), true)['package']['version'];
    preg_match('/^version = "([^"]+)"/m', (string)file_get_contents($root . '/operator-console/src-tauri/Cargo.toml'), $m);
    assert_equal($conf, $m[1], 'tauri.conf.json and Cargo.toml must carry the same version');
});

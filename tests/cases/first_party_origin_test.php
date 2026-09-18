<?php
// tests/cases/first_party_origin_test.php

suite('First-party origins');

test('blakegroup.uk site origins are first-party', function () {
    assert_true(is_first_party_origin('https://blakegroup.uk'));
    assert_true(is_first_party_origin('https://www.blakegroup.uk'));
    assert_true(is_first_party_origin('https://chat.blakegroup.uk'));
});

test('empty, http and foreign origins are not first-party', function () {
    assert_false(is_first_party_origin(''));
    assert_false(is_first_party_origin('http://blakegroup.uk'));
    assert_false(is_first_party_origin('https://evil.example'));
    assert_false(is_first_party_origin('https://blakegroup.uk.evil.example'));
});

suite('Admin API origins');

test('admin CORS allows only first-party and Tauri origins', function () {
    assert_true(admin_origin_allowed('https://blakegroup.uk'));
    assert_true(admin_origin_allowed('https://chat.blakegroup.uk'));
    assert_true(admin_origin_allowed('https://tauri.localhost'));
    assert_true(admin_origin_allowed('tauri://localhost'));
    assert_false(admin_origin_allowed(''));
    assert_false(admin_origin_allowed('https://evil.example'));
    assert_false(admin_origin_allowed('https://www.blake-uk.com'));
});

test('admin CORS ignores an allow-all widget client', function () {
    $pdo = db();
    $pdo->prepare('INSERT INTO widget_clients (name, api_key, allowed_ips, allowed_origins, active) VALUES (?,?,?,?,1)')
        ->execute(['allow-all-test', bin2hex(random_bytes(8)), '[]', '[]']);
    assert_true(widget_origin_allowed('https://evil.example'), 'precondition: widget CORS is allow-all');
    assert_false(admin_origin_allowed('https://evil.example'));
    $pdo->prepare('DELETE FROM widget_clients WHERE name = ?')->execute(['allow-all-test']);
});

test('every admin endpoint uses admin_cors(), never cors()', function () {
    foreach (glob(ROOT . '/public/api/admin/*.php') as $f) {
        $src = file_get_contents($f);
        assert_false((bool)preg_match('/^\s*cors\(\);/m', $src), basename($f) . ' calls cors()');
    }
});

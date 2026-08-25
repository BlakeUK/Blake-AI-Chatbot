<?php
// tests/cases/link_builder_test.php
// Regression tests for src/Tracking/LinkBuilder.php - direct customer-
// facing tracking links for carriers with a white-label page keyed off
// Blake's own order data, rather than a carrier API account.

declare(strict_types=1);

suite('Tracking\LinkBuilder — dx()');

test('builds the exact URL shape agreed with Blake', function () {
    $r = \Tracking\LinkBuilder::dx('SO201350-1', 'DN6 7FB');
    assert_equal('https://dx-track.com/track/blake.aspx?consno=SO201350-1&postcode=DN67FB', $r['url']);
});

test('strips spaces from the postcode but leaves the SO number as-is', function () {
    $r = \Tracking\LinkBuilder::dx('SO201350-1', '  dn6 7fb  ');
    assert_str_contains('postcode=DN67FB', $r['url']);
    assert_str_contains('consno=SO201350-1', $r['url']);
});

test('upper-cases a lowercase SO number and postcode', function () {
    $r = \Tracking\LinkBuilder::dx('so201350-1', 'dn67fb');
    assert_str_contains('consno=SO201350-1', $r['url']);
    assert_str_contains('postcode=DN67FB', $r['url']);
});

test('message includes the exact wording agreed with Blake and the link itself', function () {
    $r = \Tracking\LinkBuilder::dx('SO201350-1', 'DN6 7FB');
    assert_str_contains('Please track with this link.', $r['message']);
    assert_str_contains('select a different delivery date if updated before 9:30pm the previous day', $r['message']);
    assert_str_contains($r['url'], $r['message']);
});

suite('Tracking\Detector — DX Sales Order number');

test('a plain SO number is detected as a DX tracking intent', function () {
    $d = \Tracking\Detector::analyse('My order SO201350-1 has not arrived yet');
    assert_true($d['is_tracking']);
    assert_equal('dx', $d['carrier']);
    assert_equal('SO201350-1', $d['tracking_no']);
});

test('an SO number without a line suffix is still detected', function () {
    $d = \Tracking\Detector::analyse('Can you track SO201350 for me please');
    assert_equal('dx', $d['carrier']);
    assert_equal('SO201350', $d['tracking_no']);
});

test('lower-case so is still detected', function () {
    $d = \Tracking\Detector::analyse('tracking so201350-1');
    assert_equal('dx', $d['carrier']);
});

test('a DPD-shaped 14-digit number is still detected as DPD, not DX', function () {
    $d = \Tracking\Detector::analyse('my parcel number is 15976950635219');
    assert_equal('dpd', $d['carrier']);
});

suite('Tracking\LinkBuilder — dpd()');

test('builds the exact URL shape agreed with Blake', function () {
    $r = \Tracking\LinkBuilder::dpd('6950635219');
    assert_equal('https://track.dpd.co.uk/parcels/15976950635219*21421', $r['url']);
});

test('strips whitespace from a consignment number pasted with spaces', function () {
    $r = \Tracking\LinkBuilder::dpd('  6950 635 219  ');
    assert_equal('https://track.dpd.co.uk/parcels/15976950635219*21421', $r['url']);
});

test('message includes the link itself', function () {
    $r = \Tracking\LinkBuilder::dpd('6950635219');
    assert_str_contains($r['url'], $r['message']);
    assert_str_contains('Please track your DPD parcel', $r['message']);
});

suite('Tracking\Detector — DPD consignment number');

test('Blake\'s real 10-digit consignment number is detected as DPD', function () {
    $d = \Tracking\Detector::analyse('my DPD number is 6950635219');
    assert_true($d['is_tracking']);
    assert_equal('dpd', $d['carrier']);
    assert_equal('6950635219', $d['tracking_no']);
});

test('a bare 10-digit number does not get misdetected as a DX Sales Order number', function () {
    $d = \Tracking\Detector::analyse('tracking 6950635219');
    assert_equal('dpd', $d['carrier']);
});

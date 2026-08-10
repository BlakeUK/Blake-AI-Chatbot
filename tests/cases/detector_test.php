<?php
// tests/cases/detector_test.php
// Regression tests for Tracking\Detector's carrier pattern matching -
// specifically the ordering bug where Royal Mail's bare 9-digit fallback
// pattern, if checked alongside the other carriers' more specific
// patterns, would win over a genuine DPD/DX number whenever the message
// also happened to contain any incidental 9-digit number.

declare(strict_types=1);

suite('Tracking\Detector — carrier detection');

test('a genuine DPD 14-digit number is classified as DPD', function () {
    $r = \Tracking\Detector::analyse('Where is my order, tracking number 12345678901234');
    assert_true($r['is_tracking']);
    assert_equal('dpd', $r['carrier']);
    assert_equal('12345678901234', $r['tracking_no']);
});

test('a DPD number is still classified as DPD even with an incidental 9-digit number in the same message', function () {
    // The 9-digit "order 987654321" here is exactly the kind of incidental
    // number (order id, phone extension, etc.) that must not hijack
    // classification away from the real DPD number also present.
    $r = \Tracking\Detector::analyse('My order 987654321 tracking is 12345678901234, where is it?');
    assert_true($r['is_tracking']);
    assert_equal('dpd', $r['carrier']);
    assert_equal('12345678901234', $r['tracking_no']);
});

test('a bare 9-digit number with no other carrier match falls back to Royal Mail', function () {
    $r = \Tracking\Detector::analyse('My tracking number is 123456789');
    assert_true($r['is_tracking']);
    assert_equal('royalmail', $r['carrier']);
    assert_equal('123456789', $r['tracking_no']);
});

test('a Royal Mail GB-suffixed number is classified as Royal Mail', function () {
    $r = \Tracking\Detector::analyse('Tracking AB123456789GB please');
    assert_true($r['is_tracking']);
    assert_equal('royalmail', $r['carrier']);
    assert_equal('AB123456789GB', $r['tracking_no']);
});

test('a plain tracking-intent message with no number is still flagged as tracking, with no carrier', function () {
    $r = \Tracking\Detector::analyse('where is my order');
    assert_true($r['is_tracking']);
    assert_null($r['carrier']);
    assert_null($r['tracking_no']);
});

test('an unrelated message is not flagged as tracking', function () {
    $r = \Tracking\Detector::analyse('what colours does this cable come in?');
    assert_false($r['is_tracking']);
});

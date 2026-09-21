<?php
// tests/cases/tracking_test.php - DPD live tracking lookup (Tracking\LinkBuilder::dpdLive).
declare(strict_types=1);

suite('Tracking — DPD live status');

test('DPD live lookup returns the real parcel code and a clean status', function () {
    $seen = null;
    \Tracking\LinkBuilder::$dpdFetcher = function (string $url) use (&$seen) {
        $seen = $url;
        return json_encode(['data' => [[
            'parcelStatus' => 'Your BLAKE UK order will be delivered today by <SPAN class="REDTEXT">Andrei</SPAN>, your DPD Local driver,<SPAN class="REDTEXT"> between 10:02 and 11:02</SPAN>.',
            'parcelNumber' => '1597 6979 566 142 T',
            'parcelCode'   => '15976979566142*21446',
        ]]]);
    };
    try {
        $r = \Tracking\LinkBuilder::dpdLive('6979566142', 'HP20 1DQ');
        assert_str_contains('referenceNumber=6979566142', $seen);
        assert_str_contains('postcode=HP201DQ', $seen);
        assert_equal('https://track.dpd.co.uk/parcels/15976979566142*21446', $r['url']);
        assert_equal('Your BLAKE UK order will be delivered today by Andrei, your DPD Local driver, between 10:02 and 11:02.', $r['status']);
        \Tracking\LinkBuilder::$dpdFetcher = fn() => json_encode(['data' => []]);
        assert_equal(null, \Tracking\LinkBuilder::dpdLive('1234567890', 'AB1 2CD'));
    } finally {
        \Tracking\LinkBuilder::$dpdFetcher = null;
    }
});

test('DX: a number DX cannot find is reported, with a pointer to the SO number', function () {
    \Tracking\LinkBuilder::$dxFetcher = fn($u) => '<p>DX is unable to find the consignment relating to the details provided.</p>';
    try {
        assert_equal(false, \Tracking\LinkBuilder::dxFound('https://dx-track.com/track/blake.aspx?consno=L6778340&postcode=KT234BT'));
        \Tracking\LinkBuilder::$dxFetcher = fn($u) => '<h2>Out for delivery</h2>';
        assert_equal(true, \Tracking\LinkBuilder::dxFound('https://dx-track.com/track/blake.aspx?consno=SO1&postcode=X'));
        \Tracking\LinkBuilder::$dxFetcher = fn($u) => '';
        assert_equal(null, \Tracking\LinkBuilder::dxFound('x'));
    } finally {
        \Tracking\LinkBuilder::$dxFetcher = null;
    }
});

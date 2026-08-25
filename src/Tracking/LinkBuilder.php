<?php
// src/Tracking/LinkBuilder.php
// Builds customer-facing tracking links directly, for carriers where Blake
// has a white-label tracking page keyed off Blake's own order data rather
// than a carrier API account - no carrier API key involved at all.

declare(strict_types=1);

namespace Tracking;

class LinkBuilder
{
    private const DX_BASE  = 'https://dx-track.com/track/blake.aspx';
    private const DPD_BASE = 'https://track.dpd.co.uk/parcels/';

    // DPD: track.dpd.co.uk/parcels/{DEPOT_PREFIX}{consignment number}*{ACCOUNT_CODE}.
    // Confirmed with Blake against a real link
    // (track.dpd.co.uk/parcels/15976950635219*21421 for consignment
    // 6950635219): 21421 is the same on every link, and 1597 - the 4
    // digits ahead of the consignment number - isn't mentioned as varying
    // either, so both are treated as fixed constants for this account.
    // If a link ever comes back wrong, the depot prefix is the one to
    // re-check first, since only the account code was explicitly confirmed
    // constant, not the prefix.
    private const DPD_DEPOT_PREFIX = '1597';
    private const DPD_ACCOUNT_CODE = '21421';

    // DX: looked up by Blake's own Sales Order number (top right of a
    // sales order, top left of a despatch note) plus the delivery
    // postcode - see the query string shape agreed with Blake:
    // https://dx-track.com/track/blake.aspx?consno=SO201350-1&postcode=DN67FB
    public static function dx(string $soNumber, string $postcode): array
    {
        $so = strtoupper(trim($soNumber));
        // Spaces are how a customer will naturally type a postcode
        // ("DN6 7FB"); the URL itself has none, and http_build_query()
        // would otherwise encode a space as +, producing a link DX's page
        // doesn't recognise.
        $pc = strtoupper(preg_replace('/\s+/', '', trim($postcode)) ?? '');

        $url = self::DX_BASE . '?' . http_build_query(['consno' => $so, 'postcode' => $pc]);

        return [
            'url'     => $url,
            'message' => "Please track with this link. Also you can select a different delivery date if updated before 9:30pm the previous day.\n{$url}",
        ];
    }

    public static function dpd(string $consignmentNumber): array
    {
        $consignment = preg_replace('/\s+/', '', trim($consignmentNumber)) ?? '';
        $url = self::DPD_BASE . self::DPD_DEPOT_PREFIX . $consignment . '*' . self::DPD_ACCOUNT_CODE;

        return [
            'url'     => $url,
            'message' => "Please track your DPD parcel with this link.\n{$url}",
        ];
    }
}

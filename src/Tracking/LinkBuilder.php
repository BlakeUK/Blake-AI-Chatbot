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

    // Live DPD status from DPD's public tracking service (the same one
    // track.dpd.co.uk uses), looked up by consignment number + postcode.
    // Returns the real parcel code - the "*NNNNN" suffix is NOT a fixed
    // account code as first assumed (it changes per parcel/date), which is
    // why links built from a fixed suffix showed DPD's "Oops" page.
    // $fetcher is injectable for tests. Null when DPD has no match.
    public static $dpdFetcher = null;

    public static function dpdLive(string $consignmentNumber, string $postcode = ''): ?array
    {
        $ref = preg_replace('/\D/', '', $consignmentNumber) ?? '';
        if ($ref === '') return null;
        $pc  = strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
        $url = 'https://apis.track.dpd.co.uk/v1/reference?referenceNumber=' . rawurlencode($ref) . ($pc !== '' ? '&postcode=' . rawurlencode($pc) : '');
        try {
            if (self::$dpdFetcher) {
                $body = (self::$dpdFetcher)($url);
            } else {
                $f = \Http\SafeFetcher::get($url, 15, 8, 'Mozilla/5.0 (compatible; BlakeUKSupport/1.0)');
                $body = $f['ok'] ? $f['body'] : null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $data = json_decode((string)$body, true);
        $p = $data['data'][0] ?? null;
        if (!is_array($p) || empty($p['parcelCode'])) return null;
        $status = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)($p['parcelStatus'] ?? '')), ENT_QUOTES)));
        $link = self::DPD_BASE . $p['parcelCode'];
        return [
            'url'     => $link,
            'status'  => $status,
            'parcel'  => (string)($p['parcelNumber'] ?? ''),
            'message' => ($status !== '' ? $status . "\n\n" : '') . "Full tracking and delivery options: {$link}",
        ];
    }

    // Checks a DX link actually finds something: DX redirects unknown
    // numbers to TrackingError.aspx ("unable to find the consignment").
    // true = found, false = DX says not found, null = couldn't check.
    public static $dxFetcher = null;

    public static function dxFound(string $url): ?bool
    {
        try {
            $body = self::$dxFetcher ? (self::$dxFetcher)($url) : (\Http\SafeFetcher::get($url, 20, 8, 'Mozilla/5.0 (compatible; BlakeUKSupport/1.0)')['body'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($body) || $body === '') return null;
        return !preg_match('/unable to find the consignment|TrackingError/i', $body);
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

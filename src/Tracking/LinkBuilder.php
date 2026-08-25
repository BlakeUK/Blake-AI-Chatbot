<?php
// src/Tracking/LinkBuilder.php
// Builds customer-facing tracking links directly, for carriers where Blake
// has a white-label tracking page keyed off Blake's own order data rather
// than a carrier API account - no carrier API key involved at all.

declare(strict_types=1);

namespace Tracking;

class LinkBuilder
{
    private const DX_BASE = 'https://dx-track.com/track/blake.aspx';

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
}

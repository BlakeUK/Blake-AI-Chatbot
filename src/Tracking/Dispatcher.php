<?php
// src/Tracking/Dispatcher.php
// Routes a tracking query to the correct carrier API and returns standardised result.

declare(strict_types=1);

namespace Tracking;

class Dispatcher
{
    /**
     * Query a carrier's tracking API.
     * Returns standardised array or throws RuntimeException.
     */
    public static function query(string $carrier, string $trackingNo, string $apiKey): array
    {
        return match ($carrier) {
            'royalmail' => self::queryRoyalMail($trackingNo, $apiKey),
            'dpd'       => self::queryDpd($trackingNo, $apiKey),
            'dx'        => self::queryDx($trackingNo, $apiKey),
            default     => throw new \RuntimeException("Unknown carrier: $carrier"),
        };
    }

    // ── Royal Mail ────────────────────────────────────────────────────────────
    private static function queryRoyalMail(string $trackingNo, string $apiKey): array
    {
        $url  = 'https://api.royalmail.net/mailpieces/v2/' . urlencode($trackingNo) . '/events';
        $resp = self::get($url, ['X-Accept-RMG-Terms: yes', 'X-IBM-Client-Id: ' . $apiKey]);

        $data   = json_decode($resp, true);
        $events = $data['mailPieces'][0]['events'] ?? [];

        return [
            'carrier'    => 'Royal Mail',
            'tracking'   => $trackingNo,
            'status'     => $data['mailPieces'][0]['summary']['statusDescription'] ?? 'Unknown',
            'events'     => array_map(fn($e) => [
                'date'        => $e['eventDateTime'] ?? '',
                'description' => $e['eventDescription'] ?? '',
                'location'    => $e['locationDescription'] ?? '',
            ], array_slice($events, 0, 5)),
        ];
    }

    // ── DPD ───────────────────────────────────────────────────────────────────
    private static function queryDpd(string $trackingNo, string $apiKey): array
    {
        // DPD tracking API — endpoint and auth vary by account type
        // Placeholder: replace with actual DPD REST API endpoint
        $url  = 'https://api.dpd.co.uk/v1/track/' . urlencode($trackingNo);
        $resp = self::get($url, ['Authorization: Bearer ' . $apiKey]);

        $data = json_decode($resp, true);

        return [
            'carrier'  => 'DPD',
            'tracking' => $trackingNo,
            'status'   => $data['status'] ?? 'Unknown',
            'events'   => $data['events'] ?? [],
        ];
    }

    // ── DX ────────────────────────────────────────────────────────────────────
    private static function queryDx(string $trackingNo, string $apiKey): array
    {
        $url  = 'https://www.dxdelivery.com/api/v1/track?consignment=' . urlencode($trackingNo);
        $resp = self::get($url, ['Authorization: Basic ' . $apiKey]);

        $data = json_decode($resp, true);

        return [
            'carrier'  => 'DX',
            'tracking' => $trackingNo,
            'status'   => $data['status'] ?? 'Unknown',
            'events'   => $data['events'] ?? [],
        ];
    }

    // ── HTTP GET helper ───────────────────────────────────────────────────────
    private static function get(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_USERAGENT      => 'Blake-UK-Chatbot/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new \RuntimeException("Carrier API returned HTTP $code");
        }
        return $resp;
    }
}

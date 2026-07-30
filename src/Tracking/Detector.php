<?php
// src/Tracking/Detector.php
// Classifies whether a customer message is a tracking/order intent
// and extracts tracking/order numbers and verification data.

declare(strict_types=1);

namespace Tracking;

class Detector
{
    // Tracking/order intent keywords
    private const TRACKING_KEYWORDS = [
        'where is my order', 'track my order', 'tracking number',
        'where is my parcel', 'where is my package', 'has it shipped',
        'has it been dispatched', 'when will it arrive', 'delivery update',
        'still not arrived', 'not received', 'not delivered', 'missing order',
        'order status', 'dispatch status', 'delivery status',
    ];

    // Carrier tracking number patterns
    private const CARRIER_PATTERNS = [
        'royalmail' => [
            '/\b([A-Z]{2}\d{9}GB)\b/i',           // RM parcel (e.g. AB123456789GB)
            '/\b([A-Z]{2}\d{8}\d?GB)\b/i',         // Signed for
            '/\b(\d{9})\b/',                         // 9-digit domestic
        ],
        'dpd' => [
            '/\b(\d{14})\b/',                        // 14-digit DPD
            '/\b(1[56]\d{12})\b/',                   // DPD parcel ID
        ],
        'dx' => [
            '/\b([A-Z]{1,2}\d{7,9})\b/i',           // DX consignment
        ],
    ];

    /**
     * Returns ['is_tracking' => bool, 'tracking_no' => string|null, 'carrier' => string|null]
     */
    public static function analyse(string $message): array
    {
        $lower = strtolower(trim($message));

        $isTracking = false;
        foreach (self::TRACKING_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                $isTracking = true;
                break;
            }
        }

        // Also flag if a tracking number pattern is found
        $trackingNo = null;
        $carrier    = null;

        foreach (self::CARRIER_PATTERNS as $c => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message, $m)) {
                    $trackingNo = $m[1];
                    $carrier    = $c;
                    $isTracking = true;
                    break 2;
                }
            }
        }

        return [
            'is_tracking' => $isTracking,
            'tracking_no' => $trackingNo,
            'carrier'     => $carrier,
        ];
    }
}

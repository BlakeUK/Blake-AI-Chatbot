<?php
// src/Tracking/Detector.php
// Classifies whether a customer message is a tracking/order intent
// and extracts tracking/order numbers and verification data.

declare(strict_types=1);

namespace Tracking;

class Detector
{
    // Unambiguous tracking/order intent phrases - enough on their own.
    private const TRACKING_KEYWORDS = [
        'where is my order', 'track my order', 'tracking number', 'track my parcel',
        'where is my parcel', 'where is my package', 'where is my delivery',
        'has it shipped', 'has it been dispatched', 'has my order', 'when will it arrive',
        'when will my order', 'delivery update', 'missing order', 'order status',
        'dispatch status', 'delivery status',
        "where's my order", 'wheres my order', "where's my parcel", 'wheres my parcel',
        "where's my package", 'wheres my package', "where's my delivery", 'wheres my delivery',
        'where is my stuff', 'track my delivery', 'track my package',
    ];

    // Phrases that are ALSO everyday aerial/RF support language ("channels
    // not received", "LNB power not delivered"). They only count as tracking
    // intent when the message also mentions an order/delivery noun - see
    // ORDER_NOUN_PATTERN.
    private const AMBIGUOUS_KEYWORDS = [
        'not received', 'not delivered', 'not arrived', 'still not arrived',
        "hasn't arrived", 'has not arrived', "didn't arrive", 'did not arrive',
        "haven't received", 'have not received', 'not turned up',
    ];

    private const ORDER_NOUN_PATTERN =
        '/\\b(order|orders|parcel|package|delivery|shipment|consignment|courier|goods|purchase|dpd|royal mail|dx)\\b/i';

    // Context needed before a bare run of digits is treated as a tracking
    // number. Without it, product/part numbers, EANs and stock queries
    // ("is 1234567890 in stock?") were classified as DPD/Royal Mail.
    private const NUMBER_CONTEXT_PATTERN =
        '/\\b(track|tracking|consignment|parcel|package|order|delivery|dispatch|despatch|shipped|courier|dpd|royal ?mail|dx)\\b/i';

    // Carrier tracking number patterns, checked in order - the first match
    // across any carrier wins. Royal Mail's bare 9-digit domestic pattern
    // is deliberately NOT here: it's the loosest pattern of the bunch (any
    // 9-digit number - a phone extension, an order number, part of a
    // longer number sequence), so checking it alongside these specific
    // ones meant a message containing both a genuine DPD/DX number and any
    // incidental 9-digit number always misclassified as Royal Mail. See
    // ROYALMAIL_FALLBACK_PATTERN below, tried only once none of these match.
    private const CARRIER_PATTERNS = [
        'royalmail' => [
            '/\b([A-Z]{2}\d{9}GB)\b/i',           // RM parcel (e.g. AB123456789GB)
            '/\b([A-Z]{2}\d{8}\d?GB)\b/i',         // Signed for
        ],
        // Blake's actual DPD consignment number format (confirmed against
        // a real link - see Tracking\LinkBuilder::dpd()): 10 digits, no
        // prefix. The 14-digit patterns below predate that confirmation -
        // not proven wrong, just unconfirmed - kept as fallbacks rather
        // than removed outright.
        // DPD numbers are bare digits - see BARE_NUMERIC_PATTERNS, only used
        // with tracking context.
        'dpd' => [],
        // Not a DX-issued consignment code - Blake's DX tracking page (see
        // Tracking\LinkBuilder::dx()) looks orders up by Blake's own Sales
        // Order number instead, shown on sales orders (top right) and
        // despatch notes (top left). That's what a customer actually has
        // to hand, so that's what this detects.
        'dx' => [
            '/\b(SO\d{4,8}(?:-\d{1,3})?)\b/i',
        ],
    ];

    // Bare-digit carrier formats. Only applied when the message has
    // tracking context (NUMBER_CONTEXT_PATTERN / intent keyword) or is
    // nothing but the number itself (a reply to the tracking form prompt).
    private const BARE_NUMERIC_PATTERNS = [
        'dpd' => [
            '/\b(1[56]\d{12})\b/',                   // DPD parcel ID
            '/\b(\d{14})\b/',                        // 14-digit DPD
            '/\b(\d{10})\b/',                        // Blake's DPD consignment number
        ],
    ];

    // Fallback only - see the comment on CARRIER_PATTERNS above.
    private const ROYALMAIL_FALLBACK_PATTERN = '/\b(\d{9})\b/';

    /**
     * Returns ['is_tracking' => bool, 'tracking_no' => string|null, 'carrier' => string|null]
     */
    public static function analyse(string $message): array
    {
        $lower = strtolower(trim(str_replace(['’', '‘'], "'", $message)));

        $isTracking = false;
        foreach (self::TRACKING_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                $isTracking = true;
                break;
            }
        }
        if (!$isTracking && preg_match(self::ORDER_NOUN_PATTERN, $lower)) {
            foreach (self::AMBIGUOUS_KEYWORDS as $kw) {
                if (str_contains($lower, $kw)) {
                    $isTracking = true;
                    break;
                }
            }
        }

        $trackingNo = null;
        $carrier    = null;

        // Self-identifying formats (GB-suffixed Royal Mail, SO-prefixed DX).
        foreach (self::CARRIER_PATTERNS as $c => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message, $m)) {
                    return ['is_tracking' => true, 'tracking_no' => $m[1], 'carrier' => $c];
                }
            }
        }

        $numberOnly = (bool)preg_match('/^[\d\s]+$/', trim($message));
        $hasContext = $isTracking || $numberOnly || preg_match(self::NUMBER_CONTEXT_PATTERN, $message);
        if (!$hasContext) {
            return ['is_tracking' => false, 'tracking_no' => null, 'carrier' => null];
        }

        $compact = $numberOnly ? preg_replace('/\s+/', '', $message) : $message;
        foreach (self::BARE_NUMERIC_PATTERNS as $c => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $compact, $m)) {
                    return ['is_tracking' => true, 'tracking_no' => $m[1], 'carrier' => $c];
                }
            }
        }

        if (preg_match(self::ROYALMAIL_FALLBACK_PATTERN, $compact, $m)) {
            $trackingNo = $m[1];
            $carrier    = 'royalmail';
            $isTracking = true;
        }

        return [
            'is_tracking' => $isTracking,
            'tracking_no' => $trackingNo,
            'carrier'     => $carrier,
        ];
    }
}

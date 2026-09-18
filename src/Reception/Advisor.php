<?php
// src/Reception/Advisor.php
// Glue between chat and the predictor: postcode in message -> prediction ->
// prompt block + product search phrase. Returns null whenever anything is
// missing (no postcode, lookup failed, data not installed) so chat carries
// on exactly as before.

declare(strict_types=1);

namespace Reception;

class Advisor
{
    public const FREEVIEW_CHECKER = 'https://www.freeview.co.uk/corporate/detailed-transmitter-information';
    public const AERIAL_WIZARD    = 'https://www.blake-uk.com/aerial-buy-assistant-aerial.html';

    public const CATEGORY_URLS = [
        'log-periodic' => 'https://www.blake-uk.com/category/aerials-tv-log.html',
        'yagi'         => 'https://www.blake-uk.com/category/aerials-tv-yagi.html',
        'high-gain'    => 'https://www.blake-uk.com/category/aerials-tv-highgain.html',
        'amplifier'    => 'https://www.blake-uk.com/amplifiers-masthead.html',
        'satellite'    => 'https://www.blake-uk.com/category/aerials-satellite.html',
    ];

    private const TOPIC = '/\b(aerials?|antenna|tv|television|freeview|signal|reception|transmitter|channels?|pixelat\w*|dab|yagi|log.?periodic|high.?gain|recommend\w*)\b/i';

    // Test seam for the data directory.
    public static ?string $dataDir = null;

    // Only runs when a postcode is present AND the message (or the recent
    // conversation) is about TV reception - a postcode in a delivery or
    // address question must not trigger an aerial answer.
    public static function forMessage(string $message, string $recentText = ''): ?array
    {
        $postcode = Postcode::extract($message);
        if (!$postcode) return null;
        if (!preg_match(self::TOPIC, $message) && !preg_match(self::TOPIC, $recentText)) return null;

        $predictor = Predictor::fromDataDir(self::$dataDir ?? dirname(__DIR__, 2) . '/data/reception');
        if (!$predictor) return null;

        $loc = Postcode::lookup($postcode);
        if (!$loc) {
            return ['postcode' => $postcode, 'found' => false, 'prompt' => self::notFoundBlock($postcode), 'search' => null];
        }
        $pred = $predictor->predict($loc['lat'], $loc['lon'], 5);
        $rec  = Predictor::recommend($pred);
        return [
            'postcode'       => $postcode,
            'found'          => true,
            'location'       => $loc,
            'predictions'    => $pred,
            'recommendation' => $rec,
            'prompt'         => self::promptBlock($postcode, $rec),
            'search'         => $rec ? self::searchPhrase($rec) : null,
        ];
    }

    public static function searchPhrase(array $rec): string
    {
        $t = $rec['aerial']['type'];
        $words = ['log-periodic' => 'log periodic TV aerial', 'yagi' => 'yagi TV aerial', 'high-gain' => 'high gain TV aerial'][$t];
        return $words . ($rec['transmitter']['aerial_group'] ? ' group ' . $rec['transmitter']['aerial_group'] : '');
    }

    public static function promptBlock(string $postcode, ?array $rec): string
    {
        $out = "TV RECEPTION PREDICTION for {$postcode} (estimate from Ofcom transmitter data and terrain modelling; buildings and trees not modelled):\n";
        if (!$rec) {
            return $out . "- No usable terrestrial transmitter predicted. Suggest satellite (Freesat): " . self::CATEGORY_URLS['satellite']
                . "\n- Confirm with the Freeview checker: " . self::FREEVIEW_CHECKER . "\n";
        }
        $t = $rec['transmitter']; $a = $rec['aerial'];
        $out .= self::txLine('Recommended transmitter', $t);
        $out .= "- Predicted signal: {$a['signal']}\n";
        $out .= "- Recommended aerial: {$a['type']}" . ($a['amplifier'] ? ' with a low-noise masthead amplifier' : '')
              . ($t['aerial_group'] && $t['aerial_group'] !== 'W' ? ", aerial group {$t['aerial_group']} or wideband" : ', wideband') . "\n";
        $out .= "- Mount the aerial {$t['polarisation']}ly polarised, pointing {$t['bearing_compass']} ({$t['bearing_deg']} degrees)\n";
        $out .= "- Category link: " . self::CATEGORY_URLS[$a['type']] . "\n";
        if ($a['amplifier']) $out .= "- Masthead amplifiers: " . self::CATEGORY_URLS['amplifier'] . "\n";
        if ($a['signal'] === 'marginal') $out .= "- Satellite alternative: " . self::CATEGORY_URLS['satellite'] . "\n";
        if ($a['note']) $out .= "- Note: {$a['note']}\n";
        if (!$t['full_service']) $out .= "- This transmitter carries 3 of the 6 Freeview multiplexes (fewer channels).\n";
        if ($rec['terrain_note']) $out .= "- Hills affect this path, so results vary by exact property position.\n";
        if ($rec['alternative']) {
            $alt = $rec['alternative']; $aa = $rec['alternative_aerial'];
            $out .= self::txLine('Alternative transmitter', $alt);
            $out .= "  Alternative needs: {$aa['type']} aerial" . ($aa['amplifier'] ? ' with masthead amplifier' : '')
                  . ", {$alt['polarisation']} polarisation, pointing {$alt['bearing_compass']} ({$alt['bearing_deg']} degrees), signal {$aa['signal']}\n";
        }
        $out .= "- Confirm with the Freeview detailed transmitter checker: " . self::FREEVIEW_CHECKER . "\n";
        $out .= "- Blake UK Aerial Wizard: " . self::AERIAL_WIZARD . "\n";
        return $out;
    }

    private static function txLine(string $label, array $t): string
    {
        return "- {$label}: {$t['name']}, {$t['distance_km']} km {$t['bearing_compass']}, "
             . ($t['polarisation']) . ' polarisation, '
             . ($t['full_service'] ? 'all 6 multiplexes' : '3 multiplexes only')
             . ', channels ' . implode(', ', $t['channels']) . "\n";
    }

    private static function notFoundBlock(string $postcode): string
    {
        return "TV RECEPTION PREDICTION: the postcode {$postcode} could not be found. Ask the customer to check it, or use the Freeview checker: "
             . self::FREEVIEW_CHECKER . "\n";
    }
}

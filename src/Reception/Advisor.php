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

    // Deliberately loose: customers misspell "aerial" constantly (aeraIL,
    // ariel, arial, antena), so common misspellings are matched too.
    private const TOPIC = '/\b(a[eé]ri[ae]l?s?|aera\w*|ari[ae]ls?|antenn?as?|antena|tv|television|freeview|saorview|signal|reception|transmitter|channels?|pixelat\w*|breaking up|dab|yagi|log.?periodic|high.?gain|mast|dish|recommend\w*)\b/i';
    // A postcode in a delivery/billing question must never trigger an aerial answer.
    private const NOT_RECEPTION = '/\b(deliver\w*|dispatch\w*|ship\w*|postage|courier|carrier|tracking|invoice|billing|account|collect\w*|returns?|refund\w*)\b/i';

    // Test seam for the data directory.
    public static ?string $dataDir = null;

    // Only runs when a postcode is present AND the message (or the recent
    // conversation) is about TV reception - a postcode in a delivery or
    // address question must not trigger an aerial answer.
    public static function forMessage(string $message, string $recentText = ''): ?array
    {
        $postcode = Postcode::extract($message);
        if (!$postcode) return null;
        if (preg_match(self::NOT_RECEPTION, $message)) return null;
        // Topic words, or a short message that is essentially just a postcode
        // ("best aerial for WF3 1UG", "WF3 1UG?") - those are always about
        // reception, whatever the spelling.
        $shortEnough = str_word_count($message) <= 12;
        if (!preg_match(self::TOPIC, $message) && !preg_match(self::TOPIC, $recentText) && !$shortEnough) return null;

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
        // Very strong signal: a small aerial is the right answer, so bias the
        // product search towards the compact ones rather than the biggest.
        if (($rec['aerial']['signal'] ?? '') === 'very strong') $words = 'mini compact ' . $words;
        return $words . ($rec['transmitter']['aerial_group'] ? ' group ' . $rec['transmitter']['aerial_group'] : '');
    }

    // Which end of the range to recommend: fewest elements where the signal
    // is strong (a big aerial can overload the tuner), most where it is weak.
    public static function sizePreference(array $rec): string
    {
        return match ($rec['aerial']['signal'] ?? '') {
            'very strong' => 'smallest',
            'strong'      => 'small',
            'moderate'    => 'mid',
            default       => 'largest',
        };
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
        $size = self::sizePreference($rec);
        $out .= match ($size) {
            'smallest' => "- Aerial size: recommend the SMALLEST suitable aerial (fewest elements, e.g. a mini/compact log-periodic). Do not recommend a large high-gain or high-element aerial here: too much signal can overload a tuner.\n",
            'small'    => "- Aerial size: a small or mid-size aerial is plenty; no need for a large high-element aerial.\n",
            'mid'      => "- Aerial size: a mid-size aerial is appropriate.\n",
            default    => "- Aerial size: recommend a larger, higher-gain aerial (more elements) mounted as high as practical.\n",
        };
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

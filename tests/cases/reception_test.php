<?php
// tests/cases/reception_test.php
// TV reception predictor (src/Reception/): geodesy, postcode detection,
// propagation maths, aerial choice, chat wiring. Uses a synthetic transmitter
// set and synthetic terrain in a temp dir - never the real data or network.

declare(strict_types=1);

use Reception\{Geo, Postcode, Predictor, Terrain, Advisor};

// Synthetic terrain: 0.5 x 0.5 degree box around 53.0-53.5N, 1.5-1.0W, one
// tile, flat 50 m with an optional north-south ridge at lon -1.25.
function reception_fixture(bool $ridge): string
{
    $dir = sys_get_temp_dir() . '/rx_' . bin2hex(random_bytes(4));
    mkdir("$dir/terrain", 0777, true);
    $rows = 60; $cols = 60;
    file_put_contents("$dir/terrain/meta.json", json_encode([
        'lat_north' => 53.5, 'lon_west' => -1.5, 'rows' => $rows, 'cols' => $cols,
        'cells_per_deg_lat' => 120, 'cells_per_deg_lon' => 120, 'tile_rows' => $rows, 'tile_cols' => $cols,
    ]));
    $bin = '';
    for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) {
        $bin .= pack('v', ($ridge && $c === 30) ? 400 : 50);
    }
    file_put_contents("$dir/terrain/t0_0.bin.gz", gzencode($bin));
    file_put_contents("$dir/transmitters.json", json_encode(['sites' => [
        ['id' => '1', 'name' => 'Westmast', 'group' => 'Westmast', 'primary' => true, 'itv_region' => 'X', 'bbc_region' => 'X',
         'lat' => 53.25, 'lon' => -1.45, 'site_height' => 50, 'ant_height' => 50, 'polarisation' => 'H', 'aerial_group' => 'K',
         'muxes' => array_map(fn($m, $ch) => ['mux' => $m, 'ch' => $ch, 'erp_kw' => 10], ['PSB1','PSB2','PSB3','COM4','COM5','COM6'], [21,24,27,30,33,36])],
        ['id' => '2', 'name' => 'Eastrelay', 'group' => 'Westmast', 'primary' => false, 'itv_region' => 'X', 'bbc_region' => 'X',
         'lat' => 53.25, 'lon' => -1.02, 'site_height' => 50, 'ant_height' => 20, 'polarisation' => 'V', 'aerial_group' => 'B',
         'muxes' => array_map(fn($m, $ch) => ['mux' => $m, 'ch' => $ch, 'erp_kw' => 0.01], ['PSB1','PSB2','PSB3'], [40,43,46])],
    ]]));
    // FM and DAB sites for the radio predictor: one big regional transmitter
    // and one low-power vertical relay.
    file_put_contents("$dir/fm.json", json_encode(['band' => 'fm', 'sites' => [
        ['name' => 'Bigmast FM', 'area' => 'Testshire', 'lat' => 53.25, 'lon' => -1.45, 'site_height' => 50, 'ant_height' => 100,
         'polarisation' => 'M', 'erp_kw' => 120, 'freq_mhz' => 98.5, 'services' => ['BBC Radio 2 98.5 FM', 'Test FM 102.1 FM']],
        ['name' => 'Smallrelay FM', 'area' => 'Testtown', 'lat' => 53.25, 'lon' => -1.02, 'site_height' => 50, 'ant_height' => 20,
         'polarisation' => 'V', 'erp_kw' => 0.05, 'freq_mhz' => 106.9, 'services' => ['Town Radio 106.9 FM']],
    ]]));
    file_put_contents("$dir/dab.json", json_encode(['band' => 'dab', 'sites' => [
        ['name' => 'Bigmast DAB', 'area' => 'Testshire', 'lat' => 53.25, 'lon' => -1.45, 'site_height' => 50, 'ant_height' => 90,
         'polarisation' => 'V', 'erp_kw' => 10, 'freq_mhz' => 225.648, 'services' => ['BBC National DAB (225.648 MHz)', 'Digital One (222.064 MHz)']],
        ['name' => 'Smallrelay DAB', 'area' => 'Testtown', 'lat' => 53.25, 'lon' => -1.02, 'site_height' => 50, 'ant_height' => 20,
         'polarisation' => 'V', 'erp_kw' => 0.02, 'freq_mhz' => 223.936, 'services' => ['Local Test DAB (223.936 MHz)']],
    ]]));
    return $dir;
}

suite('Reception — geodesy');

test('OS grid conversion matches the Ordnance Survey worked example', function () {
    [$lat, $lon] = Geo::gridToOsgb36(651409.903, 313177.270);
    assert_true(abs($lat - 52.657570) < 0.00001 && abs($lon - 1.717922) < 0.00001, "got $lat,$lon");
});

test('grid to WGS84 lands on the postcodes.io position for S3 9PT', function () {
    [$lat, $lon] = Geo::gridToWgs84(435214, 388981);
    assert_true(abs($lat - 53.396509) < 0.0002 && abs($lon + 1.471896) < 0.0002, "got $lat,$lon");
});

test('bearing and compass point', function () {
    assert_equal('E', Geo::compass(Geo::bearing(53.0, -1.0, 53.0, 0.0)));
    assert_equal('N', Geo::compass(Geo::bearing(53.0, -1.0, 54.0, -1.0)));
    assert_true(abs(Geo::distanceKm(53.0, -1.0, 54.0, -1.0) - 111.2) < 0.5);
});

suite('Reception — postcode detection');

test('full postcodes are found and normalised', function () {
    assert_equal('S3 9PT', Postcode::extract('Any recommendations for S3 9PT'));
    assert_equal('SW1A 1AA', Postcode::extract('aerial for sw1a1aa please'));
    assert_equal('DN14 5AB', Postcode::extract('I live at DN14 5AB, weak signal'));
});

test('bare outcodes only count in short or postcode messages', function () {
    assert_equal('S3', Postcode::extract('S3'));
    assert_equal('HD9', Postcode::extract('my postcode area is HD9'));
    assert_null(Postcode::extract('do you sell RG6 cable for my aerial install'));
    assert_null(Postcode::extract('I want a TV aerial for a weak signal area'));
});

test('a postcode question is not mistaken for parcel tracking', function () {
    assert_false(\Tracking\Detector::analyse('Any recommendations for S3 9PT')['is_tracking']);
});

suite('Reception — propagation maths');

test('knife-edge loss follows ITU-R P.526', function () {
    assert_true(abs(Predictor::knifeEdge(0.0) - 6.03) < 0.05);
    assert_equal(0.0, Predictor::knifeEdge(-1.0));
    assert_true(Predictor::knifeEdge(2.0) > 18 && Predictor::knifeEdge(2.0) < 20);
});

test('free space field for 1 kW at 1 km is 106.9 dBuV/m', function () {
    assert_true(abs(Predictor::freeSpace(1, 1) - 106.9) < 0.001);
    assert_equal(0.0, Predictor::groundLoss(5));
    assert_true(Predictor::groundLoss(500) <= 18.0);
});

test('aerial groups are derived from channels', function () {
    assert_equal('A', Predictor::groupFor([21, 27, 34]));
    assert_equal('B', Predictor::groupFor([39, 45, 50]));
    assert_equal('K', Predictor::groupFor([22, 44]));
});

test('aerial type steps up as signal weakens', function () {
    assert_equal('log-periodic', Predictor::aerialFor(85)['type']);
    assert_equal('yagi', Predictor::aerialFor(70)['type']);
    assert_equal('high-gain', Predictor::aerialFor(60)['type']);
    assert_false(Predictor::aerialFor(60)['amplifier']);
    assert_true(Predictor::aerialFor(52)['amplifier']);
    assert_equal('marginal', Predictor::aerialFor(40)['signal']);
});

suite('Reception — predictor on synthetic terrain');

test('a ridge between transmitter and receiver adds terrain loss', function () {
    $flat  = Predictor::fromDataDir(reception_fixture(false));
    $ridge = Predictor::fromDataDir(reception_fixture(true));
    $a = $flat->predict(53.25, -1.10);
    $b = $ridge->predict(53.25, -1.10);
    $fa = array_values(array_filter($a, fn($p) => $p['name'] === 'Westmast'))[0];
    $fb = array_values(array_filter($b, fn($p) => $p['name'] === 'Westmast'))[0];
    // Flat path: only partial Fresnel-zone loss near grazing.
    assert_true($fa['terrain_loss_db'] < 3, 'flat loss ' . $fa['terrain_loss_db']);
    assert_true($fb['terrain_loss_db'] > $fa['terrain_loss_db'] + 10, 'ridge loss ' . $fb['terrain_loss_db']);
    assert_true($fb['field_dbuv'] < $fa['field_dbuv']);
    assert_equal('W', $fa['bearing_compass']);
    assert_equal('horizontal', $fa['polarisation']);
});

test('full-service transmitter preferred over a stronger relay while workable', function () {
    $rec = Predictor::recommend([
        ['name' => 'Relay', 'full_service' => false, 'field_dbuv' => 90.0, 'terrain_loss_db' => 0.0, 'aerial_group' => 'B'],
        ['name' => 'Main',  'full_service' => true,  'field_dbuv' => 75.0, 'terrain_loss_db' => 0.0, 'aerial_group' => 'K'],
    ]);
    assert_equal('Main', $rec['transmitter']['name']);
    assert_equal('log-periodic', $rec['alternative_aerial']['type']);
    assert_equal('Relay', $rec['alternative']['name']);
    assert_equal('yagi', $rec['aerial']['type']);
});

test('relay kept when the main transmitter is too weak', function () {
    $rec = Predictor::recommend([
        ['name' => 'Relay', 'full_service' => false, 'field_dbuv' => 90.0, 'terrain_loss_db' => 0.0, 'aerial_group' => 'B'],
        ['name' => 'Main',  'full_service' => true,  'field_dbuv' => 62.0, 'terrain_loss_db' => 20.0, 'aerial_group' => 'K'],
    ]);
    assert_equal('Relay', $rec['transmitter']['name']);
});

suite('Reception — chat wiring');

function reception_stub(): void
{
    Advisor::$dataDir = reception_fixture(false);
    Postcode::$fetcher = function (string $url) {
        if (str_contains($url, 'S39PT')) return ['result' => ['latitude' => 53.25, 'longitude' => -1.10, 'country' => 'England']];
        return null; // 404
    };
    db()->exec('DELETE FROM reception_postcodes');
}

test('postcode + aerial question produces a prediction block', function () {
    reception_stub();
    $r = Advisor::forMessage('Which aerial do I need for S3 9PT?');
    assert_true($r['found']);
    assert_str_contains('Westmast', $r['prompt']);
    assert_str_contains(Advisor::FREEVIEW_CHECKER, $r['prompt']);
    assert_str_contains('pointing W', $r['prompt']);
});

test('bare postcode reply uses the earlier aerial question for context', function () {
    reception_stub();
    // A short message that is essentially a postcode is treated as a
    // reception question on its own (customers often reply with just that).
    assert_true(Advisor::forMessage('S3 9PT')['found']);
    assert_true(Advisor::forMessage('S3 9PT', 'I want a TV aerial for a weak signal area')['found']);
    // A long message with no reception words still does not trigger it.
    assert_null(Advisor::forMessage('We are moving our office to S3 9PT next month and I need to update the billing records, purchase orders and the invoices you send us each quarter.'));
});

test('postcode in an unrelated question does not trigger a prediction', function () {
    reception_stub();
    assert_null(Advisor::forMessage('Do you deliver to S3 9PT?'));
});

test('misspelled aerial questions still trigger a prediction', function () {
    reception_stub();
    foreach (['Whats the best aeraIL FOR S3 9PT', 'which ariel do i need at S3 9PT', 'best antena for S3 9PT please'] as $m) {
        assert_true(Advisor::forMessage($m)['found'], $m);
    }
});

test('unknown postcode asks the customer to check it', function () {
    reception_stub();
    $r = Advisor::forMessage('best aerial for ZZ9 9ZZ');
    assert_false($r['found']);
    assert_str_contains('could not be found', $r['prompt']);
});

test('lookups are cached', function () {
    reception_stub();
    Advisor::forMessage('aerial for S3 9PT');
    Postcode::$fetcher = fn() => throw new \RuntimeException('should not fetch');
    assert_true(Advisor::forMessage('aerial for S3 9PT')['found']);
});

test('Responder puts the prediction in the prompt and treats it as grounded', function () {
    reception_stub();
    $ctx = \Chat\Responder::buildContext('Any recommendations for S3 9PT', null, 'I want a TV aerial for a weak signal area');
    $prompt = \Chat\Responder::buildPrompt($ctx, null, null);
    assert_str_contains('TV RECEPTION PREDICTION for S3 9PT', $prompt);
    assert_equal(0.75, \Chat\Responder::confidence([], [], [], $ctx['reception']));
});

test('missing reception data leaves chat unchanged', function () {
    Advisor::$dataDir = sys_get_temp_dir() . '/rx_missing_' . bin2hex(random_bytes(3));
    assert_null(Advisor::forMessage('aerial for S3 9PT'));
    $ctx = \Chat\Responder::buildContext('aerial for S3 9PT', null);
    assert_null($ctx['reception']);
});

test('very strong signal asks for the smallest aerial and biases the product search', function () {
    reception_stub();
    $r = Advisor::forMessage('best aerial for S3 9PT');
    $rec = $r['recommendation'];
    $rec['aerial']['signal'] = 'very strong';
    assert_equal('smallest', Advisor::sizePreference($rec));
    assert_str_contains('mini compact', Advisor::searchPhrase($rec));
    $block = Advisor::promptBlock('S3 9PT', $rec);
    assert_str_contains('SMALLEST suitable aerial', $block);
    $rec['aerial']['signal'] = 'very weak';
    assert_equal('largest', Advisor::sizePreference($rec));
    assert_str_contains('larger, higher-gain aerial', Advisor::promptBlock('S3 9PT', $rec));
});

test('products are ordered small-first in strong areas and large-first in weak ones', function () {
    $p = [
        ['product_code' => 'A', 'name' => '56 Element Log Periodic Group K Aerial', 'title' => ''],
        ['product_code' => 'B', 'name' => '20 Element Mini-Log Periodic Group K Aerial', 'title' => ''],
        ['product_code' => 'C', 'name' => '28 Element Log Periodic Group K Aerial', 'title' => ''],
        ['product_code' => 'D', 'name' => '4-Way Masthead Amplifier', 'title' => ''],
    ];
    assert_equal(['B', 'C', 'A', 'D'], array_column(\Chat\Responder::orderBySize($p, 'smallest'), 'product_code'));
    assert_equal(['A', 'C', 'B', 'D'], array_column(\Chat\Responder::orderBySize($p, 'largest'), 'product_code'));
    assert_equal(20, \Chat\Responder::elementCount($p[1]));
    assert_equal(null, \Chat\Responder::elementCount($p[3]));
});

suite('Reception — FM and DAB');

test('band detection: DAB, FM/radio and TV questions are told apart', function () {
    assert_equal('dab', Advisor::band('best DAB aerial for S3 9PT'));
    assert_equal('dab', Advisor::band('digital radio keeps breaking up at S3 9PT'));
    assert_equal('fm', Advisor::band('what FM aerial do I need for S3 9PT'));
    assert_equal('fm', Advisor::band('aerial for Radio 2 at S3 9PT'));
    assert_equal('fm', Advisor::band('poor radio reception at S3 9PT'));
    assert_equal('tv', Advisor::band('best aerial for S3 9PT'));
    assert_equal('tv', Advisor::band('my TV picture breaks up, and the radio in the van is fine'));
});

test('FM prediction: strongest transmitter, polarisation and a dipole close in', function () {
    reception_stub();
    $r = Advisor::forMessage('what FM aerial do I need at S3 9PT');
    assert_equal('fm', $r['band']);
    assert_true($r['found']);
    $rec = $r['recommendation'];
    assert_equal('Bigmast FM', $rec['transmitter']['name']);
    assert_equal('mixed', $rec['transmitter']['polarisation']);
    assert_true(in_array($rec['aerial']['signal'], ['very strong', 'strong'], true), $rec['aerial']['signal']);
    assert_str_contains('dipole', $rec['aerial']['elements']);
    assert_str_contains('FM radio dipole aerial', $r['search']);
    assert_str_contains('BBC Radio 2 98.5 FM', $r['prompt']);
    assert_str_contains('fm-radio-aerials.html', $r['prompt']);
});

test('DAB prediction: vertical polarisation and the ensembles are quoted', function () {
    reception_stub();
    $r = Advisor::forMessage('best DAB aerial for S3 9PT');
    assert_equal('dab', $r['band']);
    $rec = $r['recommendation'];
    assert_equal('Bigmast DAB', $rec['transmitter']['name']);
    assert_str_contains('vertically polarised', $r['prompt']);
    assert_str_contains('BBC National DAB', $r['prompt']);
    assert_str_contains('dab-radio-aerials.html', $r['prompt']);
});

test('weak radio signal asks for a bigger aerial mounted high', function () {
    $weak = \Reception\RadioPredictor::aerialForBand(42.0, 'fm');
    assert_equal('weak', $weak['signal']);
    assert_equal('largest', $weak['size']);
    assert_str_contains('high-gain outdoor FM Yagi', $weak['elements']);
    $dabWeak = \Reception\RadioPredictor::aerialForBand(30.0, 'dab');
    assert_equal('weak', $dabWeak['signal']);
    assert_str_contains('DAB Yagi', $dabWeak['elements']);
    $dabStrong = \Reception\RadioPredictor::aerialForBand(65.0, 'dab');
    assert_equal('very strong', $dabStrong['signal']);
    assert_equal('smallest', $dabStrong['size']);
});

test('radio product cards are filtered to that band\'s aerials', function () {
    $hits = [
        ['product_code' => 'DABY', 'name' => 'DAB Radio Aerial, 3 Element', 'title' => '', 'category_path' => '["Aerials","Radio","DAB"]'],
        ['product_code' => 'FMD', 'name' => 'FM Radio Dipole Aerial', 'title' => '', 'category_path' => '["Aerials","Radio","FM"]'],
        ['product_code' => 'TVLOG', 'name' => '20 Element Mini-Log Periodic Group K Aerial', 'title' => '', 'category_path' => '["Aerials","TV"]'],
    ];
    assert_equal(['DABY'], array_column(\Chat\Responder::matchingAerials($hits, 'dab'), 'product_code'));
    assert_equal(['FMD'], array_column(\Chat\Responder::matchingAerials($hits, 'fm'), 'product_code'));
});

<?php
// tests/cases/leaflet_test.php - generated technical data sheets: the two
// checks that stop a wrong or unverifiable sheet, and the PDF itself.
declare(strict_types=1);

suite('Products\Leaflet — generated technical data sheets');

function lf_page(string $code = 'BLA-TEST1', string $h1 = 'Test Aerial 20 Element Group K'): string
{
    return '<html><head><title>x</title></head><body><h1>' . $h1 . '</h1><p>Product code: ' . $code . '</p>'
        . '<div id="prod-desc">Product Description A test aerial for the suite. It exists only in tests. Priced at £17.11 inc VAT.</div>'
        . '<h4><a id="prod-tech">Technical Specification</a></h4>'
        . '<div class="product-page__list"><div class="product-page__list--column"><p>Aerial Group</p></div><div class="product-page__list--column"><p>K</p></div></div>'
        . '<div class="product-page__list"><div class="product-page__list--column"><p>Width</p></div><div class="product-page__list--column"><p>310mm</p></div></div>'
        . '<div class="product-page__list"><div class="product-page__list--column"><p>Price inc VAT</p></div><div class="product-page__list--column"><p>£17.11</p></div></div>'
        . '<a id="prod-down">Downloads</a>'
        . '<img src="https://cdn.blake-uk.com/1111aaaa-2222-3333-4444-555566667777/large/1000/1000/TEST.PNG">'
        . '<section class="related-products"><img src="https://cdn.blake-uk.com/9999bbbb-2222-3333-4444-555566667777/large/1000/1000/OTHER.PNG"></section>'
        . '</body></html>';
}

function lf_product(string $code = 'BLA-TEST1'): void
{
    db()->prepare("INSERT OR REPLACE INTO products (product_code,name,title,url,category_path,summary_bullets,description,price_inc_vat,active)
                   VALUES (?,?,?,?,?,?,?,?,1)")
        ->execute([$code, 'Test Aerial 20 Element Group K', 'Test Aerial 20 Element Group K with Twistable F',
                   'https://www.blake-uk.com/test-aerial.html', json_encode(['Aerials', 'TV']),
                   json_encode(['Twistable F connector', 'Group K channels 21-48']), 'A test aerial.', 17.11]);
}

test('refuses when the page does not match the product (no wrong data sheets)', function () {
    lf_product();
    \Products\Leaflet::$fetcher = fn($u) => lf_page('BLA-OTHER9', 'Completely Different Satellite Dish Mount');
    try {
        $r = \Products\Leaflet::verify('BLA-TEST1');
        assert_true(!$r['ok']);
        assert_str_contains("doesn't match BLA-TEST1", $r['error']);
    } finally { \Products\Leaflet::$fetcher = null; }
});

test('refuses when the page cannot be read or has no specification', function () {
    lf_product();
    \Products\Leaflet::$fetcher = fn($u) => null;
    try { assert_str_contains("could not be read", \Products\Leaflet::verify('BLA-TEST1')['error']); } finally { \Products\Leaflet::$fetcher = null; }
    \Products\Leaflet::$fetcher = fn($u) => '<html><body><h1>Test Aerial 20 Element Group K</h1>' . str_repeat('filler text ', 60) . '</body></html>';
    try { assert_str_contains('No technical specification', \Products\Leaflet::verify('BLA-TEST1')['error']); } finally { \Products\Leaflet::$fetcher = null; }
    assert_str_contains('No product with code', \Products\Leaflet::verify('NOPE-1')['error']);
});

test('verified sheet takes specs and images from that product only, and never prices', function () {
    lf_product();
    \Products\Leaflet::$fetcher = fn($u) => lf_page();
    try {
        $r = \Products\Leaflet::verify('BLA-TEST1');
        assert_true($r['ok']);
        $d = $r['data'];
        assert_equal(['Aerial Group' => 'K', 'Width' => '310mm'], $d['specs'], 'price row dropped');
        assert_true(!str_contains(json_encode($d), '17.11') && !str_contains(json_encode($d), '\u00a3'), 'no prices anywhere');
        assert_equal(['https://cdn.blake-uk.com/1111aaaa-2222-3333-4444-555566667777/large/1000/1000/TEST.PNG'], $d['images'], 'related product image excluded');
        assert_str_contains('A test aerial for the suite.', $d['intro']);
    } finally { \Products\Leaflet::$fetcher = null; }
});

test('renders a real PDF with the disclaimer and no price', function () {
    lf_product();
    \Products\Leaflet::$fetcher = fn($u) => lf_page();
    try {
        $r = \Products\Leaflet::generate('BLA-TEST1');
        assert_true($r['ok'], $r['error'] ?? '');
        assert_true(filesize($r['path']) > 3000);
        assert_equal('%PDF', substr((string)file_get_contents($r['path'], false, null, 0, 4), 0, 4));
        $text = shell_exec('pdftotext ' . escapeshellarg($r['path']) . ' - 2>/dev/null') ?: '';
        assert_str_contains('mission-critical', $text);
        assert_str_contains('BLA-TEST1', $text);
        assert_str_contains('Aerial Group', $text);
        assert_true(!str_contains($text, '17.11'), 'no price on the sheet');
        @unlink($r['path']);
    } finally { \Products\Leaflet::$fetcher = null; }
});

test('chat: a data sheet request offers the generated PDF', function () {
    lf_product();
    \Products\Leaflet::$fetcher = fn($u) => lf_page();
    \Knowledge\Embeddings::resetCaches();
    try {
        assert_true(\Chat\Responder::wantsLeaflet('can I have the datasheet for the BLA-TEST1'));
        assert_true(!\Chat\Responder::wantsLeaflet('which aerial do I need'));
        $ctx = \Chat\Responder::buildContext('can I have the technical data sheet for BLA-TEST1', null, '');
        $d = array_values(array_filter($ctx['downloads'], fn($x) => str_contains($x['title'], 'Technical data sheet')));
        assert_equal(1, count($d));
        assert_str_contains('/api/chat/leaflet.php?code=BLA-TEST1', $d[0]['url']);
        assert_str_contains('TECHNICAL DATA SHEET', \Chat\Responder::buildPrompt($ctx, null, null));
    } finally { \Products\Leaflet::$fetcher = null; }
});

test('published PDFs on the product page are offered before the generated sheet', function () {
    lf_product();
    $page = lf_page() . '<a id="prod-down">Downloads</a><div class="product-page__download-cont"><div class="product-page__list">'
          . '<div class="product-page__list--column"><a href="https://cdn.blake-uk.com/abc/download/TRUNC" target="_blank">Log-periodic_(BLA-LP).pdf (712KB)</a></div>'
          . '<div class="product-page__list--column"><form action="https://cdn.blake-uk.com/abc/download/Log-periodic-BLA-LP.pdf"><button>Download</button></form></div></div></div>';
    \Products\Leaflet::$fetcher = fn($u) => $page;
    \Products\Leaflet::$linkChecker = fn($u) => str_ends_with($u, '.pdf');
    \Knowledge\Embeddings::resetCaches();
    try {
        assert_equal(['https://cdn.blake-uk.com/abc/download/Log-periodic-BLA-LP.pdf' => 'Log periodic (BLA LP)'], \Products\Leaflet::pageDocuments($page), 'the working form URL, not the truncated link');
        \Products\Leaflet::$linkChecker = fn($u) => false;
        assert_equal([], \Products\Leaflet::pageDocuments($page), 'a link that does not work is never offered');
        \Products\Leaflet::$linkChecker = fn($u) => str_ends_with($u, '.pdf');
        $ctx = \Chat\Responder::buildContext('datasheet for BLA-TEST1', null, '');
        $titles = array_column($ctx['downloads'], 'title');
        assert_str_contains('Log periodic', $titles[0]);
        assert_str_contains('Technical data sheet', $titles[1]);
    } finally { \Products\Leaflet::$fetcher = null; \Products\Leaflet::$linkChecker = null; }
});

test('a data sheet asked for by description uses the product the search found', function () {
    db()->prepare("INSERT OR REPLACE INTO products (product_code,name,title,url,category_path,summary_bullets,description,active)
                   VALUES ('BLATLA11','Blake 1-Way TV & Radio Launch Amplifier','Class 1 FM/DAB/UHF Terrestrial Launch Amplifier','https://www.blake-uk.com/launch.html','[\"IRS\"]','[]','Launch amplifier for IRS systems.',1)")->execute();
    \Products\Leaflet::$fetcher = fn($u) => lf_page('BLATLA11', 'Blake 1-Way TV & Radio Launch Amplifier');
    \Knowledge\Embeddings::resetCaches();
    try {
        $ctx = \Chat\Responder::buildContext('do you have a datasheet on the launch amplifier that you do', null, '');
        assert_str_contains('BLATLA11', $ctx['leaflet_note'] ?? '');
        assert_true((bool)array_filter($ctx['downloads'], fn($d) => str_contains($d['url'], 'code=BLATLA11')));
    } finally { \Products\Leaflet::$fetcher = null; }
});

test('specification from an indexed technical PDF is used, but only where it matches the document verbatim', function () {
    lf_product();
    $doc = "FM/VHF/UHF Multiband Launch Amplifier Technical characteristics Features BLA-TEST1 Number of Inputs 1 "
         . "Noise Figure(UHF) 2.5dB (typ) / 4.0dB (max) Gain(UHF) Continuously adjustable 18-38dB Impedance 75 Ohm "
         . "Mains Power Requirement 230V 50Hz at 4.5W Weight 770g Price 92.98";
    db()->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES ('AMPLIFIER BLA-TEST1 Technical characteristics.pdf','application/pdf','/tmp/x','indexed')")->execute();
    $fid = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO knowledge_chunks (source_type, source_id, chunk_text) VALUES ('file', ?, ?)")->execute([$fid, $doc]);
    \Products\Leaflet::$extractor = fn($text, $code) => [
        ['label' => 'Noise Figure(UHF)', 'value' => '2.5dB (typ) / 4.0dB (max)'],
        ['label' => 'Gain(UHF)', 'value' => 'Continuously adjustable 18-38dB'],
        ['label' => 'Impedance', 'value' => '75 Ohm'],
        ['label' => 'Output Power', 'value' => '125dBuV'],            // not in the document: must be dropped
        ['label' => 'Price', 'value' => '92.98'],                      // price: must be dropped
    ];
    \Products\Leaflet::$fetcher = fn($u) => lf_page();
    try {
        $r = \Products\Leaflet::specsFromKnowledge('BLA-TEST1');
        assert_equal(['Noise Figure(UHF)' => '2.5dB (typ) / 4.0dB (max)', 'Gain(UHF)' => 'Continuously adjustable 18-38dB', 'Impedance' => '75 Ohm'], $r['specs']);
        assert_equal(['AMPLIFIER BLA-TEST1 Technical characteristics.pdf'], $r['files']);
        // and it reaches the sheet, with the page's own rows first
        $v = \Products\Leaflet::verify('BLA-TEST1');
        assert_true($v['ok']);
        assert_equal(['Aerial Group', 'Width', 'Noise Figure(UHF)', 'Gain(UHF)', 'Impedance'], array_keys($v['data']['specs']));
    } finally {
        \Products\Leaflet::$extractor = null; \Products\Leaflet::$fetcher = null;
        db()->exec("DELETE FROM knowledge_chunks WHERE source_id = {$fid} AND source_type='file'");
        db()->exec("DELETE FROM knowledge_files WHERE id = {$fid}");
        db()->exec("DELETE FROM settings WHERE key LIKE 'leaflet_specs_%'");
    }
});

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

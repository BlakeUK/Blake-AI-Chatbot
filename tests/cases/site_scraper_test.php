<?php
// tests/cases/site_scraper_test.php
// Products\SiteScraper against a real (trimmed) blake-uk.com product page.

suite('Products\SiteScraper');

function scraper_fixture(): string {
    return file_get_contents(ROOT . '/tests/fixtures/product_page_prorj45-6.html');
}

test('parses the product JSON-LD and page HTML into an import record', function () {
    $r = \Products\SiteScraper::parse(scraper_fixture(), 'https://www.blake-uk.com/x.html');
    assert_equal('PRORJ45-6', $r['product_code']);
    assert_true(str_starts_with($r['name'], 'CAT6 Push Through Connector'));
    assert_equal('https://www.blake-uk.com/push-through-rj45-cat6-connector.html', $r['url']);
    assert_equal(36.27, $r['price_exc_vat']);
    assert_equal(43.52, $r['price_inc_vat']);
    assert_equal('In stock', $r['stock_status']);
    assert_equal(['Networking Equipment'], $r['category_path']);
    assert_equal('160mm', $r['tech_specs']['Width']);
    assert_count(5, $r['summary_bullets']);
    assert_true(str_contains($r['description'], 'Easy to verify the correct wiring order'));
    assert_count(1, $r['documents']);
    assert_true(str_starts_with($r['documents'][0]['url'], 'https://cdn.blake-uk.com/'));
    assert_null($r['documents'][0]['size'], 'bogus "(1B)" size dropped');
    assert_equal(['PRORJ45-BOOT-GREY', 'PRORJ45TOOL'], $r['related_product_codes']);
    assert_true(in_array('5060041666509', $r['search_terms'], true), 'GTIN searchable');
});

test('non-product pages return null', function () {
    assert_null(\Products\SiteScraper::parse('<html><script type="application/ld+json">{"@type":"Organization","name":"Blake UK"}</script></html>', 'https://www.blake-uk.com/faq.html'));
    assert_null(\Products\SiteScraper::parse('<html>no data</html>', 'https://www.blake-uk.com/a.html'));
});

test('missing inc-VAT price is derived from exc VAT at 20%', function () {
    $html = '<script type="application/ld+json">{"@type":"Product","sku":"T-1","name":"Test","offers":{"price":10,"availability":"https://schema.org/OutOfStock"}}</script>';
    $r = \Products\SiteScraper::parse($html, 'https://www.blake-uk.com/t-1.html');
    \Products\Importer::import([$r], \Products\SiteScraper::BASE_URL);
    $p = \Knowledge\Search::byCode('T-1');
    assert_equal(12.0, (float)$p['price_inc_vat']);
    assert_equal('Out of stock', $p['stock_status']);
});

test('imports end to end with image, documents and search by name', function () {
    $r = \Products\SiteScraper::parse(scraper_fixture(), 'https://www.blake-uk.com/x.html');
    \Products\Importer::import([$r], \Products\SiteScraper::BASE_URL);
    $p = \Knowledge\Search::byCode('PRORJ45-6');
    assert_equal('https://www.blake-uk.com/productimagedim/PRORJ45-6/large/1/1000/1000/', $p['image_url']);
    $prompt = \Knowledge\Search::formatForPrompt($p, null);
    assert_true(str_contains($prompt, '£43.52 inc VAT (£36.27 exc VAT)'));
    assert_true(str_contains($prompt, 'Downloads: ') && str_contains($prompt, 'cdn.blake-uk.com'));
});

test('candidateUrls drops category, brand and non-.html URLs', function () {
    $c = \Products\SiteScraper::candidateUrls([
        'https://www.blake-uk.com', 'https://www.blake-uk.com/a13.html',
        'https://www.blake-uk.com/category/aerials.html', 'https://www.blake-uk.com/brand/spro.html',
    ]);
    assert_equal(['https://www.blake-uk.com/a13.html'], $c);
});

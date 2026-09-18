<?php
// tests/cases/importer_test.php
// Regression tests for src/Products/Importer.php - the single most
// bug-prone file in this project's history (three separate past fixes:
// XML attribute-vs-repeated-element parsing, negative prices displayed to
// customers, and an FTS5 crash on import). Bad product data here feeds
// straight into the RAG prompt, so these bugs reach customers directly.
//
// normalise() is private, so these go through the real public entry points
// (parseXml()/import()) and read the result back out of the DB - which
// also exercises the FTS trigger sync as a side effect.

declare(strict_types=1);

suite('Products\Importer — JSON feed');

test('imports a well-formed JSON product', function () {
    $result = \Products\Importer::import([[
        'product_code' => 'IMP-JSON-001',
        'name'         => 'Test Widget',
        'price_inc_vat'=> 9.99,
        'price_exc_vat'=> 8.33,
        'stock_status' => 'in_stock',
    ]]);
    assert_equal(1, $result['created']);
    assert_count(0, $result['errors']);

    $row = \Knowledge\Search::byCode('IMP-JSON-001');
    assert_equal('Test Widget', $row['name']);
    assert_equal(9.99, (float)$row['price_inc_vat']);
});

test('re-importing the same product_code updates rather than duplicates', function () {
    \Products\Importer::import([['product_code' => 'IMP-JSON-002', 'name' => 'v1', 'price_inc_vat' => 1]]);
    $result = \Products\Importer::import([['product_code' => 'IMP-JSON-002', 'name' => 'v2', 'price_inc_vat' => 2]]);

    assert_equal(0, $result['created']);
    assert_equal(1, $result['updated']);
    assert_equal('v2', \Knowledge\Search::byCode('IMP-JSON-002')['name']);
});

test('a negative price is stored as null/absent, never as a negative figure', function () {
    \Products\Importer::import([[
        'product_code' => 'IMP-JSON-003', 'name' => 'Negative Price Widget', 'price_inc_vat' => -5.00,
    ]]);
    $row = \Knowledge\Search::byCode('IMP-JSON-003');
    assert_true($row['price_inc_vat'] === null || (float)$row['price_inc_vat'] >= 0);
});

test('a name far past the sane length cap is truncated, not stored raw', function () {
    \Products\Importer::import([[
        'product_code' => 'IMP-JSON-004', 'name' => str_repeat('x', 1000),
    ]]);
    $row = \Knowledge\Search::byCode('IMP-JSON-004');
    assert_true(mb_strlen($row['name']) <= 300, 'expected name capped to <=300 chars, got ' . mb_strlen($row['name']));
});

test('a record missing product_code is skipped, not imported or errored', function () {
    $result = \Products\Importer::import([['name' => 'No Code Here']]);
    assert_equal(1, $result['skipped']);
    assert_equal(0, $result['created']);
});

test('a record missing name is skipped', function () {
    $result = \Products\Importer::import([['product_code' => 'IMP-JSON-005']]);
    assert_equal(1, $result['skipped']);
});

test('related_product_codes given as a flat array round-trips correctly', function () {
    \Products\Importer::import([[
        'product_code' => 'IMP-JSON-006', 'name' => 'Related Codes Widget',
        'related_product_codes' => ['IMP-JSON-001', 'IMP-JSON-002'],
    ]]);
    $row = \Knowledge\Search::byCode('IMP-JSON-006');
    assert_equal(['IMP-JSON-001', 'IMP-JSON-002'], json_decode($row['related_product_codes'], true));
});

test('an imported product is findable via FTS search immediately (trigger sync)', function () {
    \Products\Importer::import([[
        'product_code' => 'IMP-JSON-007', 'name' => 'Zorbing Widget Deluxe',
        'search_terms' => 'zorbing unique searchable term',
    ]]);
    $hits = \Knowledge\Search::products('zorbing', 5);
    assert_contains('IMP-JSON-007', array_column($hits, 'product_code'));
});

suite('Products\Importer — XML feed');

function parse_one_xml_product(string $xml): array
{
    libxml_use_internal_errors(true);
    $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
    assert_true($el !== false, 'fixture XML failed to parse');
    $products = \Products\Importer::parseXml($el);
    assert_count(1, $products, 'expected exactly one <product> in fixture XML');
    return $products[0];
}

test('an attribute-style leaf element is captured (e.g. <stock status="...">)', function () {
    $xml = <<<XML
    <products>
      <product>
        <product_code>IMP-XML-001</product_code>
        <name>Attribute Stock Widget</name>
        <stock status="in_stock" />
      </product>
    </products>
    XML;
    \Products\Importer::import([parse_one_xml_product($xml)]);
    assert_equal('in_stock', \Knowledge\Search::byCode('IMP-XML-001')['stock_status']);
});

test('repeated sibling elements become a list, not just the last one silently winning', function () {
    $xml = <<<XML
    <products>
      <product>
        <product_code>IMP-XML-002</product_code>
        <name>Repeated Category Widget</name>
        <category>Networking</category>
        <category>Cabling</category>
      </product>
    </products>
    XML;
    \Products\Importer::import([parse_one_xml_product($xml)]);
    // category_path isn't part of Search::byCode()'s RAG-facing projection,
    // so read it back directly to check what the importer actually stored.
    $row  = db()->query("SELECT category_path FROM products WHERE product_code = 'IMP-XML-002'")->fetch();
    $cats = json_decode($row['category_path'] ?? '[]', true) ?: [];
    assert_contains('Networking', $cats);
    assert_contains('Cabling', $cats);
});

test('the "<spec name=X>Y</spec>" tech-spec pattern flattens into a name=>value map', function () {
    $xml = <<<XML
    <products>
      <product>
        <product_code>IMP-XML-003</product_code>
        <name>Tech Spec Widget</name>
        <tech_specs>
          <spec name="Colour">Black</spec>
          <spec name="Weight">1.2kg</spec>
        </tech_specs>
      </product>
    </products>
    XML;
    \Products\Importer::import([parse_one_xml_product($xml)]);
    $row   = \Knowledge\Search::byCode('IMP-XML-003');
    $specs = json_decode($row['tech_specs'] ?? '{}', true) ?: [];
    assert_equal('Black', $specs['Colour'] ?? null);
    assert_equal('1.2kg', $specs['Weight'] ?? null);
});

test('related product codes wrapped as <relatedProductCodes><code>X</code></relatedProductCodes> parse correctly', function () {
    $xml = <<<XML
    <products>
      <product>
        <product_code>IMP-XML-004</product_code>
        <name>Related Wrapper Widget</name>
        <relatedProductCodes>
          <code>IMP-XML-001</code>
          <code>IMP-XML-002</code>
        </relatedProductCodes>
      </product>
    </products>
    XML;
    \Products\Importer::import([parse_one_xml_product($xml)]);
    $row = \Knowledge\Search::byCode('IMP-XML-004');
    assert_equal(['IMP-XML-001', 'IMP-XML-002'], json_decode($row['related_product_codes'], true));
});

test('HTML in a description is stripped before it reaches the RAG prompt', function () {
    $xml = <<<XML
    <products>
      <product>
        <product_code>IMP-XML-005</product_code>
        <name>HTML Description Widget</name>
        <description>&lt;script&gt;alert(1)&lt;/script&gt;Plain &lt;b&gt;text&lt;/b&gt; only.</description>
      </product>
    </products>
    XML;
    \Products\Importer::import([parse_one_xml_product($xml)]);
    $desc = \Knowledge\Search::byCode('IMP-XML-005')['description'];
    assert_false(str_contains($desc, '<script>'));
    assert_false(str_contains($desc, '<b>'));
    assert_str_contains('Plain text only.', $desc);
});

suite('Products\Importer — flexible feed shapes');

test('scalar and currency-string prices, link/permalink URLs and stock variants import', function () {
    \Products\Importer::import([
        ['sku' => 'FLEX-001', 'name' => 'Flex Aerial', 'price' => '£1,024.99', 'link' => '/flex-001.html', 'availability' => 'in stock'],
        ['sku' => 'FLEX-002', 'name' => 'Flex LNB', 'price' => 12.5, 'permalink' => 'https://www.blake-uk.com/flex-002.html', 'in_stock' => true, 'stock_quantity' => 7],
        ['id' => 'FLEX-003', 'title' => 'Flex Cable', 'regular_price' => '9.99', 'sale_price' => '8.49', 'stock' => 0],
    ]);
    $a = \Knowledge\Search::byCode('FLEX-001');
    assert_equal(1024.99, (float)$a['price_inc_vat']);
    assert_equal('https://www.blake-uk.com/flex-001.html', $a['url']);
    assert_equal('in stock', $a['stock_status']);
    $b = \Knowledge\Search::byCode('FLEX-002');
    assert_equal(12.5, (float)$b['price_inc_vat']);
    assert_equal('In stock (7 available)', $b['stock_status']);
    $c = \Knowledge\Search::byCode('FLEX-003');
    assert_equal(8.49, (float)$c['price_inc_vat']);
    assert_equal('Out of stock', $c['stock_status']);
});

test('ex-VAT only price derives inc VAT only when the feed states a VAT rate', function () {
    \Products\Importer::import([
        ['code' => 'FLEX-004', 'name' => 'With rate', 'price_exc_vat' => '10.00', 'vat_rate' => 20],
        ['code' => 'FLEX-005', 'name' => 'No rate', 'price_exc_vat' => '10.00'],
    ]);
    assert_equal(12.0, (float)\Knowledge\Search::byCode('FLEX-004')['price_inc_vat']);
    assert_null(\Knowledge\Search::byCode('FLEX-005')['price_inc_vat']);
});

test('extractProductList finds products under any common JSON root', function () {
    assert_equal(2, count(\Products\Importer::extractProductList(['data' => ['items' => [['sku' => 'X'], ['sku' => 'Y']]]])));
    assert_equal(1, count(\Products\Importer::extractProductList(['products' => [['sku' => 'X']]])));
    assert_equal(1, count(\Products\Importer::extractProductList([['sku' => 'X']])));
    assert_equal(1, count(\Products\Importer::extractProductList(['sku' => 'X', 'name' => 'single'])));
    assert_equal(0, count(\Products\Importer::extractProductList(['meta' => 'nothing'])));
});

test('Google Merchant RSS with g: namespaced fields imports', function () {
    $xml = simplexml_load_string('<?xml version="1.0"?><rss xmlns:g="http://base.google.com/ns/1.0"><channel><item>'
        . '<g:id>FLEX-GM1</g:id><title>Flex Mast</title><g:price>19.99 GBP</g:price>'
        . '<link>https://www.blake-uk.com/flex-gm1.html</link><g:availability>in_stock</g:availability>'
        . '<g:image_link>https://www.blake-uk.com/img/gm1.jpg</g:image_link></item></channel></rss>');
    \Products\Importer::import(\Products\Importer::parseXml($xml));
    $p = \Knowledge\Search::byCode('FLEX-GM1');
    assert_equal('Flex Mast', $p['name']);
    assert_equal(19.99, (float)$p['price_inc_vat']);
    assert_equal('https://www.blake-uk.com/flex-gm1.html', $p['url']);
    assert_equal('in_stock', $p['stock_status']);
    assert_equal('https://www.blake-uk.com/img/gm1.jpg', $p['image_url']);
});

test('feed base_url absolutises relative links', function () {
    \Products\Importer::import([['sku' => 'FLEX-006', 'name' => 'Rel', 'url' => 'rel.html']], 'https://example.test/');
    assert_equal('https://example.test/rel.html', \Knowledge\Search::byCode('FLEX-006')['url']);
    assert_equal('https://example.test', \Products\Importer::feedBaseUrl(['base_url' => 'https://example.test']));
});

test('javascript: links are rejected', function () {
    \Products\Importer::import([['sku' => 'FLEX-007', 'name' => 'Bad', 'url' => 'javascript:alert(1)']]);
    assert_null(\Knowledge\Search::byCode('FLEX-007')['url']);
});

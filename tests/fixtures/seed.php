<?php
// tests/fixtures/seed.php
// Known knowledge chunks + products for the fast regression suite. Content
// is deliberately realistic (Blake UK's actual product/support domain) so
// FTS5 ranking behaves the way it would on real data, not on toy strings
// that happen to match trivially.

declare(strict_types=1);

function seed_fixtures(): void
{
    $pdo = db();

    // Clean slate - DELETE (not TRUNCATE, SQLite has no such statement)
    // fires the FTS sync triggers, so knowledge_fts/products_fts start
    // empty too rather than accumulating across runs.
    $pdo->exec('DELETE FROM knowledge_chunks');
    $pdo->exec('DELETE FROM products');
    $pdo->exec('DELETE FROM knowledge_entries');
    $pdo->exec('DELETE FROM keyword_links');

    $chunk = $pdo->prepare('INSERT INTO knowledge_chunks (id, source_type, source_id, chunk_text, url, category) VALUES (?,?,?,?,?,?)');
    foreach (fixture_knowledge_chunks() as $c) {
        $chunk->execute([$c['id'], $c['source_type'], $c['source_id'], $c['chunk_text'], $c['url'], $c['category'] ?? null]);
    }

    $kwLink = $pdo->prepare('INSERT INTO keyword_links (id, keywords, title, url, active) VALUES (?,?,?,?,1)');
    foreach (fixture_keyword_links() as $k) {
        $kwLink->execute([$k['id'], json_encode($k['keywords']), $k['title'], $k['url']]);
    }

    $product = $pdo->prepare('
        INSERT INTO products (
            product_code, name, title, url, description, search_terms, category_path,
            price_inc_vat, price_exc_vat, stock_status, active,
            related_product_codes, alternative_product_codes, updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,?)
    ');
    foreach (fixture_products() as $p) {
        $product->execute([
            $p['product_code'], $p['name'], $p['title'], $p['url'], $p['description'], $p['search_terms'],
            json_encode($p['category_path']),
            $p['price_inc_vat'], $p['price_exc_vat'], $p['stock_status'],
            json_encode($p['related_product_codes']), json_encode($p['alternative_product_codes']),
            time(),
        ]);
    }
}

// Fixed, known IDs so tests can assert exactly which chunk came back rather
// than just "something came back".
function fixture_knowledge_chunks(): array
{
    return [
        [
            // source_id deliberately matches the fixture chunk's own id
            // (9001+), not a small 1/2/3/4 counter - these chunks have no
            // backing knowledge_entries row, but a small source_id would
            // collide with the real auto-incremented knowledge_entries.id
            // values other tests create (e.g. PageIndexer tests), and
            // PageIndexer's re-index upsert deletes existing chunks by
            // exactly (source_type, source_id) before re-inserting -
            // silently wiping these fixtures out from under later tests.
            'id' => 9001, 'source_type' => 'manual', 'source_id' => 9001,
            'chunk_text' => 'Returns can be made within 30 days of purchase for a full refund, provided the item is unused and in its original packaging. Faulty items are covered by a 2 year warranty and can be returned at any time within that period.',
            'url' => 'https://www.blake-uk.com/support/returns',
            'category' => null, // general policy, not range-specific
        ],
        [
            'id' => 9002, 'source_type' => 'manual', 'source_id' => 9002,
            'chunk_text' => 'Our aerial installation service covers TV aerials, satellite dishes and IRS communal systems across the UK. A typical rooftop aerial install takes one to two hours and includes a full signal test before the engineer leaves.',
            'url' => 'https://www.blake-uk.com/services/aerial-installation',
            'category' => 'TV Aerials & Reception',
        ],
        [
            'id' => 9003, 'source_type' => 'manual', 'source_id' => 9003,
            'chunk_text' => 'Blake UK CCTV systems support 4K resolution recording, night vision up to 30 metres, and remote viewing from the Blake UK mobile app. Storage is available on local SD card or via a networked video recorder. Installation of CCTV systems is available as an add-on service at checkout.',
            'url' => 'https://www.blake-uk.com/cctv',
            'category' => 'CCTV & Security',
        ],
        [
            'id' => 9004, 'source_type' => 'manual', 'source_id' => 9004,
            'chunk_text' => 'Standard UK delivery takes 2 to 4 working days. Next-day delivery is available at checkout for orders placed before 2pm on a working day. We currently ship to UK mainland addresses only.',
            'url' => 'https://www.blake-uk.com/support/delivery',
            'category' => null,
        ],
    ];
}

function fixture_products(): array
{
    return [
        [
            'product_code' => 'BLA-CBL-001', 'name' => 'Coaxial Cable 25m', 'title' => 'Coaxial Cable 25m White',
            'url' => 'https://www.blake-uk.com/products/bla-cbl-001', 'description' => 'Twin-screened coaxial cable, 25 metre reel, suitable for aerial and satellite installations. Premium quality construction.',
            'search_terms' => 'coax cable aerial satellite', 'price_inc_vat' => 12.99, 'price_exc_vat' => 10.83,
            'stock_status' => 'in_stock', 'category_path' => ['TV Aerials & Reception', 'Cabling'],
            'related_product_codes' => ['BLA-CON-002'], 'alternative_product_codes' => ['BLA-CBL-003'],
        ],
        [
            'product_code' => 'BLA-CON-002', 'name' => 'F-Type Connector Pack (10)', 'title' => 'F-Type Connector Pack',
            'url' => 'https://www.blake-uk.com/products/bla-con-002', 'description' => 'Pack of 10 compression F-type connectors for coaxial cable.',
            'search_terms' => 'f-type connector coax', 'price_inc_vat' => 4.50, 'price_exc_vat' => 3.75,
            'stock_status' => 'in_stock', 'category_path' => ['TV Aerials & Reception', 'Cabling'],
            'related_product_codes' => [], 'alternative_product_codes' => [],
        ],
        [
            'product_code' => 'BLA-CBL-003', 'name' => 'Coaxial Cable 50m', 'title' => 'Coaxial Cable 50m White',
            'url' => 'https://www.blake-uk.com/products/bla-cbl-003', 'description' => 'Twin-screened coaxial cable, 50 metre reel, suitable for aerial and satellite installations.',
            'search_terms' => 'coax cable aerial satellite long', 'price_inc_vat' => 19.99, 'price_exc_vat' => 16.66,
            'stock_status' => 'low_stock', 'category_path' => ['TV Aerials & Reception', 'Cabling'],
            'related_product_codes' => ['BLA-CON-002'], 'alternative_product_codes' => ['BLA-CBL-001'],
        ],
        [
            'product_code' => 'BLA-CCTV-100', 'name' => '4K CCTV Camera Kit', 'title' => '4K CCTV Camera Kit, 4 Camera',
            'url' => 'https://www.blake-uk.com/products/bla-cctv-100', 'description' => '4-camera 4K CCTV kit with night vision and remote app viewing. Premium build quality.',
            'search_terms' => 'cctv camera security 4k', 'price_inc_vat' => 249.99, 'price_exc_vat' => 208.33,
            'stock_status' => 'in_stock', 'category_path' => ['CCTV & Security'],
            'related_product_codes' => [], 'alternative_product_codes' => [],
        ],
    ];
}

function fixture_keyword_links(): array
{
    return [
        [
            'id' => 8001, 'title' => 'Warranty Policy',
            'keywords' => ['warranty', 'guarantee'],
            'url' => 'https://www.blake-uk.com/support/warranty',
        ],
        [
            'id' => 8002, 'title' => 'Installation Booking',
            'keywords' => ['book an installation', 'book installation', 'installer'],
            'url' => 'https://www.blake-uk.com/services/book',
        ],
    ];
}

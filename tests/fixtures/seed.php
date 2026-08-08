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

    $chunk = $pdo->prepare('INSERT INTO knowledge_chunks (id, source_type, source_id, chunk_text, url) VALUES (?,?,?,?,?)');
    foreach (fixture_knowledge_chunks() as $c) {
        $chunk->execute([$c['id'], $c['source_type'], $c['source_id'], $c['chunk_text'], $c['url']]);
    }

    $product = $pdo->prepare('
        INSERT INTO products (
            product_code, name, title, url, description, search_terms,
            price_inc_vat, price_exc_vat, stock_status, active,
            related_product_codes, alternative_product_codes, updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,1,?,?,?)
    ');
    foreach (fixture_products() as $p) {
        $product->execute([
            $p['product_code'], $p['name'], $p['title'], $p['url'], $p['description'], $p['search_terms'],
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
            'id' => 9001, 'source_type' => 'manual', 'source_id' => 1,
            'chunk_text' => 'Returns can be made within 30 days of purchase for a full refund, provided the item is unused and in its original packaging. Faulty items are covered by a 2 year warranty and can be returned at any time within that period.',
            'url' => 'https://www.blake-uk.com/support/returns',
        ],
        [
            'id' => 9002, 'source_type' => 'manual', 'source_id' => 2,
            'chunk_text' => 'Our aerial installation service covers TV aerials, satellite dishes and IRS communal systems across the UK. A typical rooftop aerial install takes one to two hours and includes a full signal test before the engineer leaves.',
            'url' => 'https://www.blake-uk.com/services/aerial-installation',
        ],
        [
            'id' => 9003, 'source_type' => 'manual', 'source_id' => 3,
            'chunk_text' => 'Blake UK CCTV systems support 4K resolution recording, night vision up to 30 metres, and remote viewing from the Blake UK mobile app. Storage is available on local SD card or via a networked video recorder.',
            'url' => 'https://www.blake-uk.com/cctv',
        ],
        [
            'id' => 9004, 'source_type' => 'manual', 'source_id' => 4,
            'chunk_text' => 'Standard UK delivery takes 2 to 4 working days. Next-day delivery is available at checkout for orders placed before 2pm on a working day. We currently ship to UK mainland addresses only.',
            'url' => 'https://www.blake-uk.com/support/delivery',
        ],
    ];
}

function fixture_products(): array
{
    return [
        [
            'product_code' => 'BLA-CBL-001', 'name' => 'Coaxial Cable 25m', 'title' => 'Coaxial Cable 25m White',
            'url' => 'https://www.blake-uk.com/products/bla-cbl-001', 'description' => 'Twin-screened coaxial cable, 25 metre reel, suitable for aerial and satellite installations.',
            'search_terms' => 'coax cable aerial satellite', 'price_inc_vat' => 12.99, 'price_exc_vat' => 10.83,
            'stock_status' => 'in_stock',
            'related_product_codes' => ['BLA-CON-002'], 'alternative_product_codes' => ['BLA-CBL-003'],
        ],
        [
            'product_code' => 'BLA-CON-002', 'name' => 'F-Type Connector Pack (10)', 'title' => 'F-Type Connector Pack',
            'url' => 'https://www.blake-uk.com/products/bla-con-002', 'description' => 'Pack of 10 compression F-type connectors for coaxial cable.',
            'search_terms' => 'f-type connector coax', 'price_inc_vat' => 4.50, 'price_exc_vat' => 3.75,
            'stock_status' => 'in_stock',
            'related_product_codes' => [], 'alternative_product_codes' => [],
        ],
        [
            'product_code' => 'BLA-CBL-003', 'name' => 'Coaxial Cable 50m', 'title' => 'Coaxial Cable 50m White',
            'url' => 'https://www.blake-uk.com/products/bla-cbl-003', 'description' => 'Twin-screened coaxial cable, 50 metre reel, suitable for aerial and satellite installations.',
            'search_terms' => 'coax cable aerial satellite long', 'price_inc_vat' => 19.99, 'price_exc_vat' => 16.66,
            'stock_status' => 'low_stock',
            'related_product_codes' => ['BLA-CON-002'], 'alternative_product_codes' => ['BLA-CBL-001'],
        ],
        [
            'product_code' => 'BLA-CCTV-100', 'name' => '4K CCTV Camera Kit', 'title' => '4K CCTV Camera Kit, 4 Camera',
            'url' => 'https://www.blake-uk.com/products/bla-cctv-100', 'description' => '4-camera 4K CCTV kit with night vision and remote app viewing.',
            'search_terms' => 'cctv camera security 4k', 'price_inc_vat' => 249.99, 'price_exc_vat' => 208.33,
            'stock_status' => 'in_stock',
            'related_product_codes' => [], 'alternative_product_codes' => [],
        ],
    ];
}

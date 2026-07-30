<?php
// src/Products/Importer.php
// Imports JSON or XML product feeds into the products DB tables + FTS index.

declare(strict_types=1);

namespace Products;

class Importer
{
    public static function import(array $products): array
    {
        $pdo     = db();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($products as $raw) {
            try {
                $p = self::normalise($raw);
                if (!$p['product_code'] || !$p['name']) {
                    $skipped++;
                    continue;
                }

                // Upsert product
                $existing = $pdo->prepare('SELECT id FROM products WHERE product_code = ?');
                $existing->execute([$p['product_code']]);
                $row = $existing->fetch();

                if ($row) {
                    $pdo->prepare('
                        UPDATE products SET
                            name=?, title=?, url=?, category_path=?, summary_bullets=?,
                            description=?, tech_specs=?, price_inc_vat=?, price_exc_vat=?,
                            stock_status=?, image_url=?, image_alt=?, search_terms=?,
                            active=1, updated_at=?
                        WHERE product_code=?
                    ')->execute([
                        $p['name'], $p['title'], $p['url'],
                        $p['category_path'], $p['summary_bullets'],
                        $p['description'], $p['tech_specs'],
                        $p['price_inc_vat'], $p['price_exc_vat'],
                        $p['stock_status'], $p['image_url'], $p['image_alt'],
                        $p['search_terms'], time(), $p['product_code'],
                    ]);
                    $productId = (int)$row['id'];
                    $updated++;
                } else {
                    $pdo->prepare('
                        INSERT INTO products (
                            product_code, name, title, url, category_path, summary_bullets,
                            description, tech_specs, price_inc_vat, price_exc_vat,
                            stock_status, image_url, image_alt, search_terms, active, updated_at
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)
                    ')->execute([
                        $p['product_code'], $p['name'], $p['title'], $p['url'],
                        $p['category_path'], $p['summary_bullets'], $p['description'],
                        $p['tech_specs'], $p['price_inc_vat'], $p['price_exc_vat'],
                        $p['stock_status'], $p['image_url'], $p['image_alt'],
                        $p['search_terms'], time(),
                    ]);
                    $productId = (int)$pdo->lastInsertId();
                    $created++;
                }

                // FTS index updated automatically by trigger on products

                // Variants
                foreach ($p['variants'] as $v) {
                    if (!$v['variant_code']) continue;
                    $pdo->prepare('
                        INSERT INTO product_variants (parent_code, variant_code, attributes, url, price_inc_vat, price_exc_vat)
                        VALUES (?,?,?,?,?,?)
                        ON CONFLICT(variant_code) DO UPDATE SET
                            attributes=excluded.attributes, url=excluded.url,
                            price_inc_vat=excluded.price_inc_vat, price_exc_vat=excluded.price_exc_vat
                    ')->execute([
                        $p['product_code'], $v['variant_code'],
                        $v['attributes'], $v['url'],
                        $v['price_inc_vat'], $v['price_exc_vat'],
                    ]);
                }

                // Documents
                $pdo->prepare('DELETE FROM product_documents WHERE product_code=?')->execute([$p['product_code']]);
                foreach ($p['documents'] as $d) {
                    $pdo->prepare('INSERT INTO product_documents (product_code, doc_type, title, url) VALUES (?,?,?,?)')
                        ->execute([$p['product_code'], $d['type'], $d['title'], $d['url']]);
                }

            } catch (\Throwable $e) {
                $errors[] = ($raw['product_code'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'total'   => count($products),
        ];
    }

    // ── XML parser ────────────────────────────────────────────────────────────
    public static function parseXml(\SimpleXMLElement $xml): array
    {
        $products = [];
        $items    = $xml->products->product ?? $xml->product ?? $xml->item ?? [];
        foreach ($items as $item) {
            $products[] = self::xmlToArray($item);
        }
        return $products;
    }

    private static function xmlToArray(\SimpleXMLElement $el): array
    {
        $out = [];
        foreach ($el as $key => $val) {
            $children = $val->children();
            if (count($children) > 0) {
                $out[(string)$key] = self::xmlToArray($val);
            } else {
                $out[(string)$key] = (string)$val;
            }
        }
        // Also handle attributes
        foreach ($el->attributes() as $k => $v) {
            $out['@' . $k] = (string)$v;
        }
        return $out;
    }

    // ── Normalise any feed shape into our schema ──────────────────────────────
    private static function normalise(array $r): array
    {
        $code = trim((string)($r['product_code'] ?? $r['productCode'] ?? $r['sku'] ?? $r['id'] ?? ''));
        $name = trim((string)($r['name'] ?? $r['title'] ?? ''));

        // Category path
        $cat = $r['category_path'] ?? $r['categoryPath'] ?? $r['category'] ?? [];
        if (is_string($cat)) $cat = array_filter(array_map('trim', explode('>', $cat)));

        // Summary bullets
        $bullets = $r['summary_bullets'] ?? $r['summaryBullets'] ?? $r['bullets'] ?? [];
        if (is_string($bullets)) $bullets = array_filter(array_map('trim', explode("\n", $bullets)));

        // Tech specs — flatten if nested
        $specs = $r['tech_specs'] ?? $r['techSpecs'] ?? $r['specifications'] ?? [];
        if (isset($specs['spec'])) {
            $flat = [];
            foreach ((array)$specs['spec'] as $s) {
                if (isset($s['@name'])) $flat[$s['@name']] = $s[0] ?? '';
            }
            $specs = $flat;
        }

        // Price
        $price_data = $r['price'] ?? [];
        $price_inc  = (float)($r['price_inc_vat'] ?? $price_data['inc_vat'] ?? $price_data['@incVat'] ?? 0);
        $price_exc  = (float)($r['price_exc_vat'] ?? $price_data['exc_vat'] ?? $price_data['@excVat'] ?? 0);

        // Stock
        $stock_data   = $r['stock'] ?? [];
        $stock_status = $r['stock_status'] ?? $stock_data['status'] ?? $stock_data['@status'] ?? null;

        // Images
        $images   = $r['images'] ?? [];
        $img_url  = $r['image_url'] ?? null;
        $img_alt  = $r['image_alt'] ?? null;
        if (!$img_url && is_array($images)) {
            $first   = is_array($images[0] ?? null) ? ($images[0]) : (array)($images['image'] ?? []);
            $img_url = $first['url'] ?? $first['@url'] ?? null;
            $img_alt = $first['alt'] ?? $first['@alt'] ?? null;
        }

        // Variants
        $variants_raw = $r['variants'] ?? [];
        $variants = [];
        foreach ((array)($variants_raw['variant'] ?? $variants_raw) as $v) {
            if (!is_array($v)) continue;
            $variants[] = [
                'variant_code' => $v['product_code'] ?? $v['productCode'] ?? $v['@productCode'] ?? '',
                'attributes'   => json_encode($v['attributes'] ?? $v['attribute'] ?? []),
                'url'          => $v['url'] ?? null,
                'price_inc_vat' => (float)($v['price_inc_vat'] ?? 0),
                'price_exc_vat' => (float)($v['price_exc_vat'] ?? 0),
            ];
        }

        // Documents
        $docs_raw = $r['documents'] ?? [];
        $documents = [];
        foreach ((array)($docs_raw['document'] ?? $docs_raw) as $d) {
            if (!is_array($d)) continue;
            $documents[] = [
                'type'  => $d['type'] ?? $d['@type'] ?? 'doc',
                'title' => $d['title'] ?? $d['@title'] ?? '',
                'url'   => $d['url'] ?? $d['@url'] ?? '',
            ];
        }

        // Search terms
        $terms = $r['search_terms'] ?? $r['searchTerms'] ?? [];
        if (is_array($terms)) {
            $terms_flat = array_values($terms['term'] ?? $terms);
            $terms = implode(' ', array_filter(array_map('strval', $terms_flat)));
        }

        return [
            'product_code'   => $code,
            'name'           => $name,
            'title'          => $r['title'] ?? $name,
            'url'            => $r['url'] ?? null,
            'category_path'  => json_encode(array_values((array)$cat)),
            'summary_bullets'=> json_encode(array_values((array)$bullets)),
            'description'    => strip_tags((string)($r['description_html'] ?? $r['descriptionHtml'] ?? $r['description'] ?? '')),
            'tech_specs'     => json_encode($specs),
            'price_inc_vat'  => $price_inc ?: null,
            'price_exc_vat'  => $price_exc ?: null,
            'stock_status'   => $stock_status,
            'image_url'      => $img_url,
            'image_alt'      => $img_alt,
            'search_terms'   => $terms,
            'variants'       => $variants,
            'documents'      => $documents,
        ];
    }

}

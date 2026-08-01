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

        // Element's own attributes, e.g. <price incVat="1.72" excVat="1.43" />
        foreach ($el->attributes() as $k => $v) {
            $out['@' . $k] = (string)$v;
        }

        // Count each child tag name first, so repeated siblings (multiple
        // <category> or <image> tags) become a list instead of the last one
        // silently overwriting the rest.
        $counts = [];
        foreach ($el as $key => $val) {
            $counts[(string)$key] = ($counts[(string)$key] ?? 0) + 1;
        }

        foreach ($el as $key => $val) {
            $key         = (string)$key;
            $hasChildren = count($val->children()) > 0;
            $hasAttrs    = count($val->attributes()) > 0;

            if ($hasChildren) {
                $value = self::xmlToArray($val);
            } elseif ($hasAttrs) {
                // Leaf element that still carries attributes, e.g.
                // <stock status="in_stock" /> or <attribute name="colour">Black</attribute>
                $value = self::xmlToArray($val);
                $text  = trim((string)$val);
                if ($text !== '') {
                    $value['#text'] = $text;
                }
            } else {
                $value = (string)$val;
            }

            if ($counts[$key] > 1) {
                $out[$key][] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // Normalises a value that might be a single item, a list of items, or
    // absent, into a plain numeric list — handles the XML ambiguity where one
    // <foo> child parses to an assoc array but two or more parse to a list.
    private static function asList($val): array
    {
        if ($val === null || $val === '') return [];
        if (!is_array($val)) return [$val];
        return array_is_list($val) ? $val : [$val];
    }

    // Unwraps the common XML "<plural><singular>...</singular></plural>"
    // shape (categoryPath -> category, images -> image, etc.) down to
    // whatever's inside the singular key, if present. JSON feeds that are
    // already flat pass straight through untouched.
    private static function unwrap($val, string $childKey)
    {
        if (is_array($val) && isset($val[$childKey])) return $val[$childKey];
        return $val;
    }

    // Flattens the "<x name="foo">bar</x>" repeated-element pattern (tech
    // specs, variant attributes) into a plain ["foo" => "bar"] map. Passes
    // already-flat JSON-style maps straight through unchanged.
    private static function flattenAttrs($val): array
    {
        $isAttrList = false;
        $flat = [];
        foreach (self::asList($val) as $item) {
            if (is_array($item) && isset($item['@name'])) {
                $flat[$item['@name']] = $item['#text'] ?? ($item[0] ?? '');
                $isAttrList = true;
            }
        }
        if ($isAttrList) return $flat;
        return is_array($val) ? $val : [];
    }

    // Bounds a text field to a sane maximum length. Product names/titles are
    // typically under 100 chars for anything real; this only ever bites on a
    // pathological or corrupted feed value, but name/title aren't truncated
    // anywhere downstream (unlike description), so an unbounded one would
    // bloat the Gemini prompt and break the widget's product card layout.
    private static function capLength(string $s, int $max = 300): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    // ── Normalise any feed shape into our schema ──────────────────────────────
    private static function normalise(array $r): array
    {
        $code = trim((string)($r['product_code'] ?? $r['productCode'] ?? $r['sku'] ?? $r['id']
            ?? $r['@id'] ?? $r['@code'] ?? $r['@sku'] ?? $r['@productCode'] ?? ''));
        $name  = self::capLength(trim((string)($r['name'] ?? $r['title'] ?? '')));
        $title = self::capLength(trim((string)($r['title'] ?? $name)));

        // Category path
        $cat = $r['category_path'] ?? $r['categoryPath'] ?? $r['category'] ?? [];
        if (is_string($cat)) {
            $cat = array_filter(array_map('trim', explode('>', $cat)));
        } else {
            $cat = self::asList(self::unwrap($cat, 'category'));
        }

        // Summary bullets
        $bullets = $r['summary_bullets'] ?? $r['summaryBullets'] ?? $r['bullets'] ?? [];
        if (is_string($bullets)) {
            $bullets = array_filter(array_map('trim', explode("\n", $bullets)));
        } else {
            $bullets = self::asList(self::unwrap($bullets, 'bullet'));
        }

        // Tech specs — flatten "<spec name=X>Y</spec>" or pass through a JSON map
        $specs = self::flattenAttrs(self::unwrap($r['tech_specs'] ?? $r['techSpecs'] ?? $r['specifications'] ?? [], 'spec'));

        // Price — negative values are never legitimate here and would look
        // like a broken bot if quoted back to a customer, so treat as absent
        // rather than storing/repeating a nonsense figure.
        $price_data = $r['price'] ?? [];
        $price_inc  = (float)($r['price_inc_vat'] ?? $price_data['inc_vat'] ?? $price_data['@incVat'] ?? 0);
        $price_exc  = (float)($r['price_exc_vat'] ?? $price_data['exc_vat'] ?? $price_data['@excVat'] ?? 0);
        if ($price_inc < 0) $price_inc = 0.0;
        if ($price_exc < 0) $price_exc = 0.0;

        // Stock
        $stock_data   = $r['stock'] ?? [];
        $stock_status = $r['stock_status'] ?? $stock_data['status'] ?? $stock_data['@status'] ?? null;

        // Images — take the first only (used for the single chat-card thumbnail)
        $imageList = self::asList(self::unwrap($r['images'] ?? [], 'image'));
        $firstImg  = is_array($imageList[0] ?? null) ? $imageList[0] : [];
        $img_url   = $r['image_url'] ?? ($firstImg['url'] ?? $firstImg['@url'] ?? null);
        $img_alt   = $r['image_alt'] ?? ($firstImg['alt'] ?? $firstImg['@alt'] ?? null);

        // Variants
        $variants = [];
        foreach (self::asList(self::unwrap($r['variants'] ?? [], 'variant')) as $v) {
            if (!is_array($v)) continue;
            $variants[] = [
                'variant_code'  => $v['product_code'] ?? $v['productCode'] ?? $v['@productCode'] ?? '',
                'attributes'    => json_encode(self::flattenAttrs($v['attributes'] ?? $v['attribute'] ?? [])),
                'url'           => $v['url'] ?? $v['@url'] ?? null,
                'price_inc_vat' => (float)($v['price_inc_vat'] ?? 0),
                'price_exc_vat' => (float)($v['price_exc_vat'] ?? 0),
            ];
        }

        // Documents
        $documents = [];
        foreach (self::asList(self::unwrap($r['documents'] ?? [], 'document')) as $d) {
            if (!is_array($d)) continue;
            $documents[] = [
                'type'  => $d['type'] ?? $d['@type'] ?? 'doc',
                'title' => $d['title'] ?? $d['@title'] ?? '',
                'url'   => $d['url'] ?? $d['@url'] ?? '',
            ];
        }

        // Search terms
        $termsList = self::asList(self::unwrap($r['search_terms'] ?? $r['searchTerms'] ?? [], 'term'));
        $terms     = implode(' ', array_filter(array_map('strval', $termsList)));

        return [
            'product_code'   => $code,
            'name'           => $name,
            'title'          => $title,
            'url'            => $r['url'] ?? null,
            'category_path'  => json_encode(array_values($cat)),
            'summary_bullets'=> json_encode(array_values($bullets)),
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

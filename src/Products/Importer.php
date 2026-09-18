<?php
// src/Products/Importer.php
// Imports JSON or XML product feeds into the products DB tables + FTS index.

declare(strict_types=1);

namespace Products;

class Importer
{
    // Base URL used to absolutise relative product/image/document links.
    // Set per import from the feed's own base_url when present.
    private const DEFAULT_BASE_URL = 'https://www.blake-uk.com';
    private static string $baseUrl = self::DEFAULT_BASE_URL;

    public static function import(array $products, ?string $baseUrl = null): array
    {
        self::$baseUrl = ($baseUrl !== null && preg_match('#^https?://#i', trim($baseUrl)))
            ? rtrim(trim($baseUrl), '/')
            : self::DEFAULT_BASE_URL;
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
                            related_product_codes=?, alternative_product_codes=?, comparison_product_codes=?,
                            slug=?, brand=?, currency=?, active=1, updated_at=?
                        WHERE product_code=?
                    ')->execute([
                        $p['name'], $p['title'], $p['url'],
                        $p['category_path'], $p['summary_bullets'],
                        $p['description'], $p['tech_specs'],
                        $p['price_inc_vat'], $p['price_exc_vat'],
                        $p['stock_status'], $p['image_url'], $p['image_alt'],
                        $p['search_terms'], $p['related_product_codes'],
                        $p['alternative_product_codes'], $p['comparison_product_codes'],
                        $p['slug'], $p['brand'], $p['currency'],
                        time(), $p['product_code'],
                    ]);
                    $productId = (int)$row['id'];
                    $updated++;
                } else {
                    $pdo->prepare('
                        INSERT INTO products (
                            product_code, name, title, url, category_path, summary_bullets,
                            description, tech_specs, price_inc_vat, price_exc_vat,
                            stock_status, image_url, image_alt, search_terms,
                            related_product_codes, alternative_product_codes, comparison_product_codes,
                            slug, brand, currency, active, updated_at
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)
                    ')->execute([
                        $p['product_code'], $p['name'], $p['title'], $p['url'],
                        $p['category_path'], $p['summary_bullets'], $p['description'],
                        $p['tech_specs'], $p['price_inc_vat'], $p['price_exc_vat'],
                        $p['stock_status'], $p['image_url'], $p['image_alt'],
                        $p['search_terms'], $p['related_product_codes'],
                        $p['alternative_product_codes'], $p['comparison_product_codes'],
                        $p['slug'], $p['brand'], $p['currency'], time(),
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
                    $pdo->prepare('INSERT INTO product_documents (product_code, doc_type, title, url, file_size) VALUES (?,?,?,?,?)')
                        ->execute([$p['product_code'], $d['type'], $d['title'], $d['url'], $d['size']]);
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
        // Blake feed (<products><product>), flat <product>/<item> lists,
        // Google Merchant RSS (<rss><channel><item>) and Atom (<feed><entry>).
        $candidates = [
            $xml->products->product ?? null, $xml->product ?? null, $xml->item ?? null,
            $xml->channel->item ?? null, $xml->entry ?? null, $xml->items->item ?? null,
            $xml->Products->Product ?? null, $xml->Product ?? null,
        ];
        foreach ($candidates as $items) {
            if ($items === null || count($items) === 0) continue;
            foreach ($items as $item) {
                $products[] = self::xmlToArray($item);
            }
            break;
        }
        return $products;
    }

    // Finds the product list inside a decoded JSON feed of unknown shape:
    // a bare list, {"products": [...]}, {"items": [...]}, {"data": {"products": [...]}},
    // {"rss": {"channel": {"item": [...]}}} or a single product object.
    public static function extractProductList(array $data, int $depth = 0): array
    {
        if ($data === []) return [];
        if (array_is_list($data)) {
            return is_array($data[0] ?? null) ? $data : [];
        }
        foreach (['products', 'items', 'item', 'product', 'entries', 'entry', 'data', 'results',
                  'records', 'catalog', 'catalogue', 'feed', 'channel', 'rss', 'productFeed'] as $k) {
            if (isset($data[$k]) && is_array($data[$k]) && $depth < 4) {
                $found = self::extractProductList($data[$k], $depth + 1);
                if ($found) return $found;
            }
        }
        // A single product object.
        foreach (['product_code', 'productCode', 'sku', 'id', 'code', 'mpn'] as $k) {
            if (isset($data[$k]) && !is_array($data[$k])) return [$data];
        }
        return [];
    }

    // Root-level base URL from a JSON or XML feed, if it declares one.
    public static function feedBaseUrl($root): ?string
    {
        if ($root instanceof \SimpleXMLElement) {
            foreach (['baseUrl', 'base_url', 'baseURL'] as $a) {
                if (isset($root[$a])) return (string)$root[$a];
            }
            return null;
        }
        if (is_array($root)) {
            $v = $root['base_url'] ?? $root['baseUrl'] ?? $root['base'] ?? null;
            return is_string($v) ? $v : null;
        }
        return null;
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

        // Namespaced children such as Google Merchant's <g:price>, which
        // SimpleXML otherwise skips entirely. Prefix dropped; an
        // un-namespaced child of the same name wins.
        foreach ($el->getNamespaces(true) as $ns) {
            foreach ($el->children($ns) as $key => $val) {
                $key = (string)$key;
                if (isset($out[$key])) continue;
                $out[$key] = count($val->children()) > 0 || count($val->children($ns)) > 0
                    ? self::xmlToArray($val)
                    : trim((string)$val);
            }
        }

        return $out;
    }

    // First non-empty value among $keys (plain or XML "@attr" form).
    private static function pick(array $r, array $keys)
    {
        foreach ($keys as $k) {
            foreach ([$k, '@' . $k] as $kk) {
                if (!array_key_exists($kk, $r)) continue;
                $v = $r[$kk];
                if ($v === null || $v === '' || $v === []) continue;
                return $v;
            }
        }
        return null;
    }

    // Parses a price in any common shape: 1.72, "1.72", "£1,234.50",
    // "24.99 GBP", {"amount": "1.72"}, {"value": 1.72}, {"#text": "1.72"}.
    // Returns null when nothing numeric is found.
    public static function parsePrice($v): ?float
    {
        if ($v === null || $v === '' || is_bool($v)) return null;
        if (is_array($v)) {
            $inner = self::pick($v, ['amount', 'value', 'price', 'inc_vat', 'incVat', '#text']);
            return $inner === null || is_array($inner) ? null : self::parsePrice($inner);
        }
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = str_replace([',', ' ', "\u{00A0}"], '', (string)$v);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
        return (float)$m[0];
    }

    // Makes a feed link absolute against the feed's base URL.
    private static function absoluteUrl($v): ?string
    {
        if (is_array($v)) $v = self::pick($v, ['href', 'url', '#text']);
        if (!is_string($v) || trim($v) === '') return null;
        $v = trim($v);
        if (preg_match('#^https?://#i', $v)) return $v;
        if (str_starts_with($v, '//')) return 'https:' . $v;
        if (str_starts_with($v, '/')) return self::$baseUrl . $v;
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $v)) return null; // javascript:, mailto: etc.
        return self::$baseUrl . '/' . $v;
    }

    // Builds a readable stock line for the prompt from whatever the feed
    // provides. An explicit status string is kept as given; booleans,
    // schema.org URLs and bare quantities are turned into words.
    public static function parseStock(array $r): ?string
    {
        $stock = $r['stock'] ?? null;
        $sub   = is_array($stock) ? $stock : [];

        $status = self::pick($r, ['stock_status', 'stockStatus', 'availability', 'in_stock', 'inStock', 'is_in_stock', 'available'])
            ?? self::pick($sub, ['status', 'availability', 'in_stock']);
        if ($status === null && is_scalar($stock) && !is_numeric($stock)) $status = $stock;

        $qty = self::pick($r, ['stock_quantity', 'stockQuantity', 'quantity', 'qty', 'inventory_quantity', 'inventory', 'stock_level', 'stockLevel'])
            ?? self::pick($sub, ['quantity', 'qty', 'level']);
        if ($qty === null && is_numeric($stock)) $qty = $stock;
        $qty = is_numeric($qty) ? (int)$qty : null;

        $lead = self::pick($r, ['lead_time', 'leadTime']) ?? self::pick($sub, ['lead_time', 'leadTime']);
        $lead = is_scalar($lead) ? trim((string)$lead) : '';

        if (is_bool($status)) {
            $status = $status ? 'In stock' : 'Out of stock';
        } elseif (is_scalar($status)) {
            $status = trim((string)$status);
            $low    = strtolower($status);
            if (in_array($low, ['true', 'yes', '1'], true))  $status = 'In stock';
            if (in_array($low, ['false', 'no', '0'], true)) $status = 'Out of stock';
            if (preg_match('#schema\.org/(\w+)#i', $status, $m)) {
                $status = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $m[1]));
            }
        } else {
            $status = null;
        }
        if (($status === null || $status === '') && $qty !== null) {
            $status = $qty > 0 ? 'In stock' : 'Out of stock';
        }
        if ($status === null || $status === '') return null;

        if ($qty !== null && $qty > 0 && !str_contains($status, (string)$qty)) $status .= " ({$qty} available)";
        if ($lead !== '') $status .= ", lead time {$lead}";
        return $status;
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

    // Parses a "list of product codes, however shaped" field into a plain
    // array of code strings. Handles a flat string array, the XML
    // <wrapper><code>X</code></wrapper> pattern, and an array of objects
    // carrying the code under product_code/code (the real site feed's
    // related_products/alternative_products/comparison_products don't show
    // their item shape in an empty example, so this covers both
    // possibilities rather than assuming one).
    private static function parseCodeList($raw, string $xmlChildKey = 'code'): array
    {
        $list = self::asList(self::unwrap($raw, $xmlChildKey));
        return array_values(array_filter(array_map(function ($c) {
            if (is_array($c)) {
                return trim((string)($c['#text'] ?? $c['product_code'] ?? $c['code'] ?? $c['@code'] ?? ''));
            }
            return trim((string)$c);
        }, $list)));
    }

    // ── Normalise any feed shape into our schema ──────────────────────────────
    private static function normalise(array $r): array
    {
        $codeRaw = self::pick($r, ['product_code', 'productCode', 'sku', 'SKU', 'code', 'id', 'mpn', 'item_id', 'item_group_id']);
        $code    = is_scalar($codeRaw) ? trim((string)$codeRaw) : '';
        $nameRaw = self::pick($r, ['name', 'title', 'product_name', 'productName']);
        $name    = self::capLength(trim(is_scalar($nameRaw) ? (string)$nameRaw : (string)($nameRaw['#text'] ?? '')));
        $title = self::capLength(trim(is_scalar($r['title'] ?? null) ? (string)$r['title'] : $name));

        // Category path
        $cat = $r['category_path'] ?? $r['categoryPath'] ?? $r['category'] ?? [];
        if (is_string($cat)) {
            $cat = array_filter(array_map('trim', explode('>', $cat)));
        } else {
            $cat = self::asList(self::unwrap($cat, 'category'));
        }

        // Summary bullets
        $bullets = $r['summary_bullets'] ?? $r['summaryBullets'] ?? $r['bullets'] ?? $r['bullet_points'] ?? [];
        if (is_string($bullets)) {
            $bullets = array_filter(array_map('trim', explode("\n", $bullets)));
        } else {
            $bullets = self::asList(self::unwrap($bullets, 'bullet'));
        }

        // Tech specs — flatten "<spec name=X>Y</spec>" or pass through a JSON map.
        // The real site feed splits this across two separate keys (tech_spec
        // and technical_information); merge both rather than picking one,
        // since a feed could plausibly use either or both. tech_spec wins on
        // a key collision, since technical_information reads more like
        // supplementary/marketing detail than the canonical spec sheet.
        $specsPrimary = self::flattenAttrs(self::unwrap(
            $r['tech_specs'] ?? $r['techSpecs'] ?? $r['specifications'] ?? $r['tech_spec'] ?? [], 'spec'
        ));
        $specsExtra = self::flattenAttrs(self::unwrap($r['technical_information'] ?? [], 'spec'));
        $specs = array_merge($specsExtra, $specsPrimary);

        // Price — negative values are never legitimate here and would look
        // like a broken bot if quoted back to a customer, so treat as absent
        // rather than storing/repeating a nonsense figure.
        // Accepts the Blake feed shape (price_inc_vat / price.{inc_vat,exc_vat}
        // / <price incVat excVat>) plus scalar and currency-string prices,
        // WooCommerce regular/sale prices, Google Merchant price/sale_price
        // and schema.org offers. A bare "price" is taken as inc VAT (retail
        // display price). The missing side is derived only when the feed
        // states its VAT rate - never assumed.
        $price_data = is_array($r['price'] ?? null) ? $r['price'] : [];
        $offers     = $r['offers'] ?? [];
        if (is_array($offers) && array_is_list($offers)) $offers = is_array($offers[0] ?? null) ? $offers[0] : [];

        $price_inc = self::parsePrice(self::pick($r, ['price_inc_vat', 'priceIncVat', 'price_inc', 'price_incl_vat', 'gross_price'])
            ?? self::pick($price_data, ['inc_vat', 'incVat', 'gross']));
        $price_exc = self::parsePrice(self::pick($r, ['price_exc_vat', 'priceExcVat', 'price_exc', 'price_ex_vat', 'price_excl_vat', 'net_price'])
            ?? self::pick($price_data, ['exc_vat', 'excVat', 'net']));
        if ($price_inc === null) {
            $regular = self::parsePrice(self::pick($r, ['regular_price', 'regularPrice', 'current_price', 'retail_price']) ?? (is_array($r['price'] ?? null) ? null : ($r['price'] ?? null))
                ?? self::pick($price_data, ['amount', 'value', '#text']) ?? self::pick(is_array($offers) ? $offers : [], ['price']));
            $sale    = self::parsePrice(self::pick($r, ['sale_price', 'salePrice', 'special_price']));
            $price_inc = ($sale !== null && $sale > 0 && ($regular === null || $sale < $regular)) ? $sale : $regular;
        }
        $vatRate = self::parsePrice(self::pick($r, ['vat_rate', 'vatRate', 'tax_rate']) ?? self::pick($price_data, ['vat_rate', 'vatRate']));
        if ($vatRate !== null && $vatRate >= 0 && $vatRate < 100) {
            if ($price_inc === null && $price_exc !== null) $price_inc = round($price_exc * (1 + $vatRate / 100), 2);
            if ($price_exc === null && $price_inc !== null) $price_exc = round($price_inc / (1 + $vatRate / 100), 2);
        }
        $price_inc = ($price_inc !== null && $price_inc > 0) ? $price_inc : 0.0;
        $price_exc = ($price_exc !== null && $price_exc > 0) ? $price_exc : 0.0;

        // Stock
        $stock_status = self::parseStock($r);
        if ($stock_status === null && is_array($offers)) $stock_status = self::parseStock($offers);

        // Images — take the first only (used for the single chat-card thumbnail).
        // Sort by sort_order when present, since the real feed orders images
        // explicitly rather than relying on array position.
        $imageList = self::asList(self::unwrap($r['images'] ?? [], 'image'));
        if (!$imageList && is_array($r['image'] ?? null)) $imageList = self::asList($r['image']);
        usort($imageList, function ($a, $b) {
            $sa = is_array($a) ? (int)($a['sort_order'] ?? $a['@sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
            $sb = is_array($b) ? (int)($b['sort_order'] ?? $b['@sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
            return $sa <=> $sb;
        });
        $firstImg  = is_array($imageList[0] ?? null) ? $imageList[0] : [];
        $img_url   = self::absoluteUrl(self::pick($r, ['image_url', 'imageUrl', 'image_link', 'imageLink', 'thumbnail'])
            ?? (is_string($r['image'] ?? null) ? $r['image'] : null)
            ?? (is_string($imageList[0] ?? null) ? $imageList[0] : null)
            ?? ($firstImg['url'] ?? $firstImg['@url'] ?? $firstImg['src'] ?? $firstImg['@src'] ?? null));
        $img_alt   = $r['image_alt'] ?? ($firstImg['alt'] ?? $firstImg['@alt'] ?? null);

        // Variants
        $variants = [];
        foreach (self::asList(self::unwrap($r['variants'] ?? [], 'variant')) as $v) {
            if (!is_array($v)) continue;
            $variants[] = [
                'variant_code'  => (string)(self::pick($v, ['product_code', 'productCode', 'sku', 'code', 'id']) ?? ''),
                'attributes'    => json_encode(self::flattenAttrs($v['attributes'] ?? $v['attribute'] ?? [])),
                'url'           => self::absoluteUrl(self::pick($v, ['url', 'link', 'permalink'])),
                'price_inc_vat' => max(0.0, self::parsePrice(self::pick($v, ['price_inc_vat', 'priceIncVat', 'price', 'regular_price'])) ?? 0.0),
                'price_exc_vat' => max(0.0, self::parsePrice(self::pick($v, ['price_exc_vat', 'priceExcVat'])) ?? 0.0),
            ];
        }

        // Documents — the real feed calls this "downloads" and uses "label"
        // instead of "title", plus an extra "size" field.
        $documents = [];
        foreach (self::asList(self::unwrap($r['documents'] ?? $r['downloads'] ?? [], 'document')) as $d) {
            if (!is_array($d)) continue;
            $documents[] = [
                'type'  => $d['type'] ?? $d['@type'] ?? 'doc',
                'title' => $d['title'] ?? $d['@title'] ?? $d['label'] ?? $d['@label'] ?? '',
                'url'   => self::absoluteUrl($d['url'] ?? $d['@url'] ?? $d['link'] ?? $d['href'] ?? null) ?? '',
                'size'  => $d['size'] ?? $d['@size'] ?? null,
            ];
        }

        // Search terms
        $termsList = self::asList(self::unwrap($r['search_terms'] ?? $r['searchTerms'] ?? [], 'term'));
        $terms     = implode(' ', array_filter(array_map('strval', $termsList)));

        // Related/alternative/comparison products - three distinct
        // relationship categories in the real feed, not just one.
        $related     = self::parseCodeList($r['related_product_codes'] ?? $r['relatedProductCodes'] ?? $r['related_products'] ?? []);
        $alternative = self::parseCodeList($r['alternative_product_codes'] ?? $r['alternative_products'] ?? []);
        $comparison  = self::parseCodeList($r['comparison_product_codes'] ?? $r['comparison_products'] ?? []);

        $slug = trim((string)($r['slug'] ?? ''));

        $brandRaw = $r['brand'] ?? [];
        $brand = is_array($brandRaw)
            ? [
                'name' => trim((string)($brandRaw['name'] ?? $brandRaw['@name'] ?? '')),
                'slug' => trim((string)($brandRaw['slug'] ?? $brandRaw['@slug'] ?? '')),
                'url'  => trim((string)($brandRaw['url'] ?? $brandRaw['@url'] ?? '')),
            ]
            : ['name' => trim((string)$brandRaw), 'slug' => '', 'url' => ''];

        $currency = trim((string)($r['currency'] ?? $price_data['currency'] ?? $price_data['@currency'] ?? '')) ?: null;

        return [
            'product_code'   => $code,
            'name'           => $name,
            'title'          => $title,
            'url'            => self::absoluteUrl(self::pick($r, ['url', 'link', 'permalink', 'product_url', 'productUrl', 'href', 'loc', 'canonical_url', 'web_url'])),
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
            'related_product_codes'     => json_encode($related),
            'alternative_product_codes' => json_encode($alternative),
            'comparison_product_codes'  => json_encode($comparison),
            'slug'           => $slug ?: null,
            'brand'          => ($brand['name'] || $brand['url']) ? json_encode($brand) : null,
            'currency'       => $currency,
            'variants'       => $variants,
            'documents'      => $documents,
        ];
    }

}

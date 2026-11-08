<?php
// Products, variants and images: reads for the public pages and validated writes for the admin.
defined('DROP01') || exit;

final class Products {
    public static function forWaitlist(): array {
        $rows = DB::all('SELECT * FROM products WHERE active = 1 AND on_waitlist = 1 ORDER BY sort, id');
        return self::withImages($rows);
    }

    public static function withImages(array $rows): array {
        if (!$rows) return [];
        $ids = array_column($rows, 'id');
        $imgs = [];
        foreach (DB::all('SELECT * FROM product_images WHERE product_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort, id') as $i) $imgs[$i['product_id']][] = $i;
        foreach ($rows as &$r) {
            $r['images'] = $imgs[$r['id']] ?? [];
            $first = $r['images'][0] ?? null;
            $r['image'] = $first ? ($first['path_sm'] ?: $first['path']) : '';
            $r['image_lg'] = $first ? $first['path'] : '';
        }
        return $rows;
    }

    public static function all(): array {
        $rows = self::withImages(DB::all('SELECT * FROM products ORDER BY sort, id'));
        $vars = [];
        foreach (DB::all('SELECT * FROM variants ORDER BY sort, id') as $v) $vars[$v['product_id']][] = $v;
        foreach ($rows as &$r) {
            $r['variants'] = $vars[$r['id']] ?? [];
            $r['stock'] = array_sum(array_map(fn($v) => $v['active'] ? (int) $v['stock'] : 0, $r['variants']));
        }
        return $rows;
    }

    public static function slugify(string $s): string {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
        return substr($s ?: 'product', 0, 100);
    }

    /** Euro text like "34,95" or "34.95" → cents. */
    public static function cents($v): int {
        if (is_int($v)) return $v;
        $s = str_replace([' ', '€'], '', (string) $v);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) $s = str_replace('.', '', $s);   // 1.234,50
        $s = str_replace(',', '.', $s);
        return (int) round(((float) $s) * 100);
    }

    /** Creates or updates a product with its variants. Returns the product id. */
    public static function save(array $in): int {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Give the product a name.');
        $id = (int) ($in['id'] ?? 0);
        $slug = self::slugify((string) ($in['slug'] ?? '') ?: $name);
        if (DB::val('SELECT 1 FROM products WHERE slug = ? AND id <> ?', [$slug, $id])) throw new InvalidArgumentException("The link name \"$slug\" is already used by another product.");
        $vat = (int) ($in['vat_rate'] ?? 21);
        $data = [
            'slug' => $slug, 'name' => mb_substr($name, 0, 160), 'description' => (string) ($in['description'] ?? ''),
            'price_cents' => self::cents($in['price'] ?? 0), 'cost_cents' => self::cents($in['cost'] ?? 0), 'vat_rate' => in_array($vat, [21, 9, 0], true) ? $vat : 21,
            'category' => mb_substr(trim((string) ($in['category'] ?? '')), 0, 80) ?: null, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 160) ?: null,
            'featured' => !empty($in['featured']) ? 1 : 0, 'reveal' => !empty($in['reveal']) ? 1 : 0, 'on_waitlist' => !empty($in['on_waitlist']) ? 1 : 0,
            'active' => array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1,
            'run_size' => max(0, (int) ($in['run_size'] ?? 0)), 'low_stock_at' => max(0, (int) ($in['low_stock_at'] ?? 5)),
        ];
        return DB::tx(function () use ($id, $data, $in) {
            if ($id) {
                DB::update('products', $data + ['is_demo' => 0], 'id = ?', [$id]);
            } else {
                $data['sort'] = (int) DB::val('SELECT COALESCE(MAX(sort), 0) + 1 FROM products');
                $id = DB::insert('products', $data + ['created_at' => DB::now()]);
            }
            self::saveVariants($id, (array) ($in['variants'] ?? []));
            return $id;
        });
    }

    /** Variants: [{id?, label, sku?, color_hex?, stock?, shelf?}]. A missing shelf key keeps the old shelf. Stock changes on existing variants are logged as 'adjust' movements. */
    private static function saveVariants(int $pid, array $vars): void {
        if (!$vars) $vars = [['label' => 'Default']];
        $keep = [];
        foreach (array_values($vars) as $i => $v) {
            $label = mb_substr(trim((string) ($v['label'] ?? '')), 0, 80) ?: 'Default';
            $row = ['label' => $label, 'sku' => mb_substr(trim((string) ($v['sku'] ?? '')), 0, 80) ?: null, 'color_hex' => preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($v['color_hex'] ?? '')) ? $v['color_hex'] : null,
                'active' => array_key_exists('active', $v) ? (!empty($v['active']) ? 1 : 0) : 1, 'sort' => $i];
            $stock = max(0, (int) ($v['stock'] ?? 0));
            $vid = (int) ($v['id'] ?? 0);
            $old = $vid ? DB::one('SELECT * FROM variants WHERE id = ? AND product_id = ?', [$vid, $pid]) : null;
            if (array_key_exists('shelf', $v)) $row['shelf'] = self::shelf((string) $v['shelf']);
            if ($old) {
                DB::update('variants', $row + ['stock' => $stock], 'id = ?', [$vid]);
                if ($stock !== (int) $old['stock']) self::movement($vid, $stock - (int) $old['stock'], 'adjust', 'product editor');
            } else {
                $vid = DB::insert('variants', $row + ['product_id' => $pid, 'stock' => $stock]);
                if ($stock) self::movement($vid, $stock, 'receive', 'initial stock');
            }
            $keep[] = $vid;
        }
        // Variants removed in the editor are deactivated, not deleted, so order history keeps working.
        DB::q('UPDATE variants SET active = 0 WHERE product_id = ? AND id NOT IN (' . implode(',', $keep) . ')', [$pid]);
    }

    /** A shelf code as typed ("a 1 " → "A1"); empty means no shelf. */
    public static function shelf(string $s): ?string {
        return mb_substr(mb_strtoupper(preg_replace('/\s+/u', '', $s)), 0, 20) ?: null;
    }

    public static function setShelf(int $variantId, string $shelf): ?string {
        if (!DB::val('SELECT 1 FROM variants WHERE id = ?', [$variantId])) throw new InvalidArgumentException('That variant does not exist.');
        DB::update('variants', ['shelf' => $s = self::shelf($shelf)], 'id = ?', [$variantId]);
        return $s;
    }

    public static function movement(int $variantId, int $delta, string $reason, ?string $ref = null): void {
        DB::insert('stock_movements', ['variant_id' => $variantId, 'delta' => $delta, 'reason' => $reason, 'ref' => $ref, 'by_admin_id' => $_SESSION['aid'] ?? null, 'created_at' => DB::now()]);
    }

    public static function delete(int $id): void {
        // Products that were ever ordered are only hidden, to keep invoices intact.
        if (DB::val('SELECT 1 FROM order_items WHERE product_id = ? LIMIT 1', [$id])) { DB::update('products', ['active' => 0, 'on_waitlist' => 0], 'id = ?', [$id]); return; }
        foreach (DB::all('SELECT path, path_sm FROM product_images WHERE product_id = ?', [$id]) as $i) { Images::delete($i['path']); Images::delete($i['path_sm']); }
        DB::tx(function () use ($id) {
            DB::q('DELETE FROM votes WHERE product_id = ?', [$id]);
            DB::q('DELETE FROM stock_movements WHERE variant_id IN (SELECT id FROM variants WHERE product_id = ?)', [$id]);
            DB::q('DELETE FROM product_images WHERE product_id = ?', [$id]);
            DB::q('DELETE FROM variants WHERE product_id = ?', [$id]);
            DB::q('DELETE FROM bundle_items WHERE product_id = ?', [$id]);
            DB::q('DELETE FROM product_materials WHERE product_id = ?', [$id]);
            DB::q('DELETE FROM products WHERE id = ?', [$id]);
        });
    }
}

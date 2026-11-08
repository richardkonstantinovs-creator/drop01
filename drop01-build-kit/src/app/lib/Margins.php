<?php
// Unit economics in plain arithmetic: what one sale of each product really costs (purchase, packaging, its share of delivery and payment fees),
// the profit, margin and markup, the lowest price that still breaks even and a price that hits the target margin; plus real per-product results from paid orders.
defined('DROP01') || exit;

final class Margins {
    /** Below this many paid orders in 90 days the plan uses neutral assumptions (1 item per order, no discounts, no delivery income). */
    public const MIN_ORDERS = 10;
    private const PAID = "o.payment_status IN ('paid','partially_refunded')";

    /** Settings → costs, clamped to sane ranges. Money in cents, fee_pct in percent (1.8 = 1.8%). */
    public static function costs(): array {
        $c = Settings::get('costs');
        return [
            'delivery_cents' => max(0, (int) $c['delivery_cents']), 'fee_cents' => max(0, (int) $c['fee_cents']), 'fee_pct' => max(0, min(20, round((float) $c['fee_pct'], 2))),
            'fixed_month_cents' => max(0, (int) $c['fixed_month_cents']), 'target_pct' => max(0, min(90, (int) $c['target_pct'])),
            'reorder_days' => max(1, min(120, (int) $c['reorder_days'])), 'cover_days' => max(1, min(365, (int) $c['cover_days'])),
        ];
    }

    /** Cost of one unit of a material: pack price / units in the pack (fractional cents). */
    public static function unitCost(array $m): float { return (int) $m['pack_cents'] / max(1, (int) $m['pack_qty']); }

    public static function materials(): array {
        $rows = DB::all('SELECT m.*, (SELECT COUNT(*) FROM product_materials pm JOIN products p ON p.id = pm.product_id WHERE pm.material_id = m.id) AS used FROM materials m ORDER BY m.per, m.name, m.id');
        foreach ($rows as &$m) { $m['unit'] = self::unitCost($m); $m['used'] = (int) $m['used']; }
        return $rows;
    }

    /** Materials that go into every order, in cents per order. */
    public static function orderMaterials(): float {
        $s = 0.0;
        foreach (DB::all("SELECT pack_cents, pack_qty FROM materials WHERE per = 'order' AND active = 1") as $m) $s += self::unitCost($m);
        return $s;
    }

    /** product_id => ['cents' => per unit, 'items' => [{material_id, name, qty, cents}]] for the active per-item materials. */
    public static function packaging(): array {
        $out = [];
        foreach (DB::all("SELECT pm.product_id, pm.material_id, pm.qty, m.name, m.pack_cents, m.pack_qty FROM product_materials pm JOIN materials m ON m.id = pm.material_id
            WHERE m.active = 1 AND m.per = 'item' ORDER BY m.name") as $r) {
            $c = self::unitCost($r) * (float) $r['qty'];
            $pid = (int) $r['product_id'];
            $out[$pid]['cents'] = ($out[$pid]['cents'] ?? 0) + $c;
            $out[$pid]['items'][] = ['material_id' => (int) $r['material_id'], 'name' => $r['name'], 'qty' => (float) $r['qty'], 'cents' => $c];
        }
        return $out;
    }

    /** Replaces the per-item materials of one product. $items: [{material_id, qty}] (qty 0 removes). */
    public static function setPackaging(int $productId, array $items): void {
        if (!DB::val('SELECT 1 FROM products WHERE id = ?', [$productId])) throw new InvalidArgumentException('Product not found.');
        DB::tx(function () use ($productId, $items) {
            DB::q('DELETE FROM product_materials WHERE product_id = ?', [$productId]);
            foreach ($items as $i) {
                $mid = (int) ($i['material_id'] ?? 0); $qty = round(max(0, min(999, (float) str_replace(',', '.', (string) ($i['qty'] ?? 0)))), 2);
                if (!$qty || !DB::val("SELECT 1 FROM materials WHERE id = ? AND per = 'item'", [$mid])) continue;
                DB::upsert('product_materials', ['product_id' => $productId, 'material_id' => $mid, 'qty' => $qty], ['product_id', 'material_id']);
            }
        });
    }

    public static function saveMaterial(array $in): int {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') throw new InvalidArgumentException('Give the material a name, e.g. “Mailer box S”.');
        $data = ['name' => $name, 'pack_cents' => max(0, Products::cents($in['pack_price'] ?? 0)), 'pack_qty' => max(1, min(100000, (int) ($in['pack_qty'] ?? 1))),
            'per' => ($in['per'] ?? '') === 'order' ? 'order' : 'item', 'active' => array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1];
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            if (!DB::val('SELECT 1 FROM materials WHERE id = ?', [$id])) throw new InvalidArgumentException('Material not found.');
            DB::update('materials', $data, 'id = ?', [$id]);
            if ($data['per'] === 'order') DB::q('DELETE FROM product_materials WHERE material_id = ?', [$id]);   // now in every order, not per product
            return $id;
        }
        return DB::insert('materials', $data + ['created_at' => DB::now()]);
    }

    public static function deleteMaterial(int $id): void {
        DB::tx(function () use ($id) { DB::q('DELETE FROM product_materials WHERE material_id = ?', [$id]); DB::q('DELETE FROM materials WHERE id = ?', [$id]); });
    }

    /**
     * The real order mix of the last 90 days: items per order, the share of the goods value given away as discounts,
     * and the delivery fee customers paid per order (excl. BTW). With fewer than MIN_ORDERS orders: 1 item, no discount, no delivery income.
     */
    public static function mix(): array {
        $since = DB::now(-90 * 86400);
        $o = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(o.subtotal_cents), 0) AS sub, COALESCE(SUM(o.discount_cents), 0) AS disc, COALESCE(SUM(o.delivery_cents), 0) AS del FROM orders o WHERE ' . self::PAID . ' AND o.paid_at >= ?', [$since]);
        $items = (int) DB::val('SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE ' . self::PAID . ' AND o.paid_at >= ?', [$since]);
        $n = (int) $o['n']; $real = $n >= self::MIN_ORDERS;
        return ['orders' => $n, 'real' => $real,
            'items_per_order' => $real && $items ? round($items / $n, 2) : 1.0,
            'discount_rate' => $real && (int) $o['sub'] ? round((int) $o['disc'] / (int) $o['sub'], 4) : 0.0,
            'delivery_income' => $real ? round((int) $o['del'] / 1.21 / $n, 2) : 0.0];
    }

    /** Everything unit() needs, loaded once. */
    public static function context(): array {
        return ['costs' => self::costs(), 'mix' => self::mix(), 'pack' => self::packaging(), 'order_materials' => self::orderMaterials()];
    }

    /**
     * Per-unit economics of one product, all in cents excl. BTW unless named "price".
     * The order costs (delivery minus the fee customers pay, never below 0; order packaging; payment fee) are spread over the average items per order.
     * margin = profit / what you keep after BTW and discounts; markup = profit / everything it costs you.
     */
    public static function unit(array $p, array $ctx, ?int $price = null, ?int $cost = null): array {
        $c = $ctx['costs']; $mix = $ctx['mix'];
        $price = $price ?? (int) $p['price_cents']; $cost = $cost ?? (int) $p['cost_cents'];
        $v = (int) $p['vat_rate'] / 100; $d = (float) $mix['discount_rate']; $ipo = max(1.0, (float) $mix['items_per_order']); $fp = $c['fee_pct'] / 100;
        $exVat = $price / (1 + $v);
        $kept = $exVat * (1 - $d);
        $pack = (float) ($ctx['pack'][(int) $p['id']]['cents'] ?? 0);
        // delivery fees only offset the delivery cost here: a price should not lean on fees that vanish above the free-delivery threshold
        $delivery = max(0, $c['delivery_cents'] - $mix['delivery_income']) / $ipo;
        $opack = $ctx['order_materials'] / $ipo;
        $feeFixed = $c['fee_cents'] / $ipo;
        $fee = $feeFixed + $price * (1 - $d) * $fp;
        $full = $cost + $pack + $delivery + $opack + $fee;
        $profit = $kept - $full;
        // price → profit is linear: profit = price × k − base, so the break-even and target prices are one division each
        $base = $cost + $pack + $delivery + $opack + $feeFixed;
        $k = (1 - $d) / (1 + $v) - (1 - $d) * $fp;
        $m = $c['target_pct'] / 100;
        $kt = (1 - $d) * (1 - $m) / (1 + $v) - (1 - $d) * $fp;
        return [
            'price' => $price, 'vat' => (int) round($price - $exVat), 'discount' => (int) round($exVat - $kept), 'kept' => (int) round($kept),
            'cost' => $cost, 'pack' => (int) round($pack), 'delivery' => (int) round($delivery), 'opack' => (int) round($opack), 'fee' => (int) round($fee),
            'full' => (int) round($full), 'profit' => (int) round($profit),
            'margin' => $kept > 0 ? round($profit / $kept * 100, 1) : null, 'markup' => $full > 0 ? (int) round($profit / $full * 100) : null,
            'min_price' => $k > 0 ? (int) ceil(max(0, $base) / $k) : null,
            'rec_price' => $kt > 0 ? self::nicePrice($base / $kt) : null,
        ];
    }

    /** Rounds a price up to the next €x,95 (shop-style). */
    public static function nicePrice(float $cents): int { return (int) max(95, ceil(($cents + 5) / 100) * 100 - 5); }

    /**
     * Real results per product from orders paid in the last $days days (0 = all time). Each order's own costs (delivery minus its delivery fee,
     * order packaging, payment fee) and its discount are shared over its lines by value. Purchase cost is the one saved on the order line
     * (the product's current cost if it was 0 then); packaging and delivery use today's rates.
     */
    public static function actuals(int $days, array $ctx): array {
        $c = $ctx['costs'];
        $since = $days ? DB::now(-$days * 86400) : '0000';
        $rows = DB::all('SELECT o.id AS oid, o.subtotal_cents, o.discount_cents, o.total_cents, o.vat_cents, oi.product_id, oi.qty, oi.unit_price_cents, oi.unit_cost_cents, oi.vat_rate, p.cost_cents AS cur_cost
            FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products p ON p.id = oi.product_id WHERE ' . self::PAID . ' AND o.paid_at >= ? ORDER BY o.id, oi.id', [$since]);
        $orders = [];
        foreach ($rows as $r) $orders[(int) $r['oid']][] = $r;
        $per = []; $tot = ['orders' => count($orders), 'revenue' => 0, 'kept' => 0, 'costs' => 0, 'profit' => 0];
        foreach ($orders as $lines) {
            $o = $lines[0]; $sub = max(1, (int) $o['subtotal_cents']);
            $goodsNet = []; $sumNet = 0.0;
            foreach ($lines as $i => $l) {
                $gross = (int) $l['unit_price_cents'] * (int) $l['qty'];
                $goodsNet[$i] = ($gross - (int) $o['discount_cents'] * $gross / $sub) / (1 + (int) $l['vat_rate'] / 100);
                $sumNet += $goodsNet[$i];
            }
            $deliveryNet = max(0, ((int) $o['total_cents'] - (int) $o['vat_cents']) - $sumNet);   // what the customer paid for delivery, excl. BTW
            $oc = ['delivery' => $c['delivery_cents'] - $deliveryNet, 'opack' => $ctx['order_materials'], 'fee' => $c['fee_cents'] + (int) $o['total_cents'] * $c['fee_pct'] / 100];
            $tot['revenue'] += (int) $o['total_cents'];
            foreach ($lines as $i => $l) {
                $pid = (int) $l['product_id']; $qty = (int) $l['qty'];
                $share = (int) $l['unit_price_cents'] * $qty / $sub;
                $unitCost = (int) $l['unit_cost_cents'] ?: (int) $l['cur_cost'];
                $parts = ['cost' => $unitCost * $qty, 'pack' => ($ctx['pack'][$pid]['cents'] ?? 0) * $qty,
                    'delivery' => $oc['delivery'] * $share, 'opack' => $oc['opack'] * $share, 'fee' => $oc['fee'] * $share];
                $a = &$per[$pid];
                $a['units'] = ($a['units'] ?? 0) + $qty; $a['orders'] = ($a['orders'] ?? 0) + 1;
                $a['kept'] = ($a['kept'] ?? 0) + $goodsNet[$i];
                foreach ($parts as $k => $x) $a[$k] = ($a[$k] ?? 0) + $x;
                unset($a);
            }
        }
        $out = [];
        foreach ($per as $pid => $a) {
            $costs = $a['cost'] + $a['pack'] + $a['delivery'] + $a['opack'] + $a['fee'];
            $profit = $a['kept'] - $costs;
            $tot['kept'] += $a['kept']; $tot['costs'] += $costs; $tot['profit'] += $profit;
            $out[$pid] = ['units' => $a['units'], 'orders' => $a['orders'], 'kept' => (int) round($a['kept']), 'cost' => (int) round($a['cost']), 'pack' => (int) round($a['pack']),
                'delivery' => (int) round($a['delivery']), 'opack' => (int) round($a['opack']), 'fee' => (int) round($a['fee']), 'costs' => (int) round($costs), 'profit' => (int) round($profit),
                'margin' => $a['kept'] > 0 ? round($profit / $a['kept'] * 100, 1) : null, 'per_unit' => (int) round($profit / $a['units'])];
        }
        foreach (['kept', 'costs', 'profit'] as $k) $tot[$k] = (int) round($tot[$k]);
        $tot['margin'] = $tot['kept'] > 0 ? round($tot['profit'] / $tot['kept'] * 100, 1) : null;
        $tot['per_order'] = $tot['orders'] ? (int) round($tot['profit'] / $tot['orders']) : null;
        return ['products' => $out, 'totals' => $tot];
    }

    /**
     * The Money → Margins report: every product with its plan (unit economics at today's price), its real results in the period,
     * sales speed, stock cover, a verdict and a reorder hint; plus period totals and the break-even against the fixed monthly costs.
     */
    public static function report(int $days): array {
        $ctx = self::context(); $c = $ctx['costs'];
        $act = self::actuals($days, $ctx);
        $first = DB::val('SELECT MIN(o.paid_at) FROM orders o WHERE ' . self::PAID);
        $now = time();
        $window = $days ?: ($first ? max(1, (int) ceil(($now - strtotime($first)) / 86400)) : 1);
        $products = [];
        foreach (Products::all() as $p) {
            $sold = $act['products'][(int) $p['id']] ?? null;
            if (!$p['active'] && !$sold) continue;
            // sales speed over the time the product could sell in this window (at least a week, so one early sale is not a trend)
            $age = max(1, (int) ceil(($now - strtotime($p['created_at'] ?: '@' . $now)) / 86400));
            $span = max(7, min($window, $age));
            $perDay = $sold ? $sold['units'] / $span : 0;
            $stock = (int) $p['stock'];
            $products[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'image' => $p['image'], 'active' => (int) $p['active'], 'vat_rate' => (int) $p['vat_rate'],
                'price_cents' => (int) $p['price_cents'], 'cost_cents' => (int) $p['cost_cents'], 'stock' => $stock,
                'stock_value' => $stock * (int) $p['cost_cents'],
                'unit' => self::unit($p, $ctx), 'sold' => $sold, 'per_week' => round($perDay * 7, 1),
                'days_left' => $perDay > 0 ? (int) floor($stock / $perDay) : null,
                'reorder' => $perDay > 0 && $stock / $perDay <= $c['reorder_days'] ? max(1, (int) ceil($perDay * $c['cover_days']) - $stock) : 0,
                'packaging' => $ctx['pack'][(int) $p['id']]['items'] ?? []];
        }
        // verdicts: margin against the target, speed against the median of the products that sold
        $speeds = array_values(array_filter(array_column($products, 'per_week')));
        sort($speeds);
        $median = $speeds ? $speeds[intdiv(count($speeds), 2)] : 0;
        foreach ($products as &$p) $p['verdict'] = self::verdict($p, $median, $c['target_pct']);
        unset($p);
        // plan per order when there are no real orders yet: the average product's profit × items per order
        $plan = array_filter($products, fn($p) => $p['active'] && $p['price_cents'] > 0);
        $planPerOrder = $plan ? (int) round(array_sum(array_map(fn($p) => $p['unit']['profit'], $plan)) / count($plan) * $ctx['mix']['items_per_order']) : null;
        $t = $act['totals'];
        $perOrder = $t['orders'] ? $t['per_order'] : $planPerOrder;
        $fixed = (int) round($c['fixed_month_cents'] * $window / 30.4);
        $t += ['days' => $window, 'fixed' => $fixed, 'after_fixed' => $t['profit'] - $fixed, 'plan_per_order' => $planPerOrder,
            'break_even' => $c['fixed_month_cents'] && $perOrder > 0 ? (int) ceil($c['fixed_month_cents'] / $perOrder) : null,
            'stock_value' => array_sum(array_column($products, 'stock_value'))];
        return ['costs' => $c, 'mix' => $ctx['mix'], 'order_materials' => (int) round($ctx['order_materials']), 'materials' => self::materials(),
            'products' => $products, 'totals' => $t];
    }

    /** One word for what to do with a product. */
    public static function verdict(array $p, float $median, int $target): string {
        if ($p['price_cents'] <= 0) return 'no_price';
        if (!$p['sold']) return $p['unit']['profit'] < 0 ? 'loss' : 'no_sales';
        $margin = $p['sold']['margin'] ?? -100;
        if ($p['sold']['profit'] < 0 || $p['unit']['profit'] < 0) return 'loss';
        $fast = $p['per_week'] >= $median;
        if ($margin >= $target) return $fast ? 'star' : 'slow';
        return $fast ? 'raise' : 'weak';
    }
}

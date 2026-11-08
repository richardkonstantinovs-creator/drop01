<?php
// Order maths in integer cents: lines, bundle discount, waitlist gift, delivery fee and the BTW split per rate. Pure functions.
defined('DROP01') || exit;

final class Pricing {
    /**
     * $lines: [['product_id','variant_id','name','unit_cents','cost_cents','qty','vat_rate']]
     * $bundle: ['product_ids'=>[...], 'discount_pct'=>15, 'name'=>…] or null
     * $code: ['type'=>'pct'|'fixed'|'free_delivery', 'value'=>…, 'code'=>…] from Discounts::resolve(), or null
     * Returns subtotal, discount (bundle + code), goods (after discount), delivery, total, vat per rate and in total.
     * The free-delivery threshold looks at the bag after the bundle but before a code, so a code never adds a delivery fee.
     */
    public static function quote(array $lines, ?array $bundle, ?array $zone, bool $gift = false, ?array $code = null): array {
        $sub = 0;
        foreach ($lines as $l) $sub += $l['unit_cents'] * $l['qty'];
        // bundle: one of each bundle product gets the % off, once per complete set
        $disc = 0; $bundleName = null;
        if ($bundle && $bundle['product_ids']) {
            $sets = PHP_INT_MAX; $setPrice = 0;
            foreach ($bundle['product_ids'] as $pid) {
                $have = 0; $price = null;
                foreach ($lines as $l) if ((int) $l['product_id'] === (int) $pid) { $have += $l['qty']; $price = $price === null ? $l['unit_cents'] : min($price, $l['unit_cents']); }
                if (!$have) { $sets = 0; break; }
                $sets = min($sets, $have); $setPrice += $price;
            }
            if ($sets > 0) { $disc = (int) round($setPrice * $sets * (int) $bundle['discount_pct'] / 100); $bundleName = $bundle['name']; }
        }
        $delivery = Delivery::fee($zone, $sub - $disc);
        $codeDisc = 0;
        if ($code) {
            $base = $sub - $disc;
            if ($code['type'] === 'pct') $codeDisc = (int) round($base * min(100, max(0, (int) $code['value'])) / 100);
            elseif ($code['type'] === 'fixed') $codeDisc = min($base, max(0, (int) $code['value']));
            elseif ($code['type'] === 'free_delivery') $delivery = 0;
        }
        $disc += $codeDisc;
        $goods = $sub - $disc;
        $total = $goods + $delivery;
        // BTW: prices include BTW. The discount is spread over the lines by value; delivery follows the main (highest-value) rate.
        $byRate = [];
        foreach ($lines as $l) {
            $gross = $l['unit_cents'] * $l['qty'];
            $share = $sub ? (int) round($disc * $gross / $sub) : 0;
            $byRate[(int) $l['vat_rate']] = ($byRate[(int) $l['vat_rate']] ?? 0) + $gross - $share;
        }
        if ($byRate) {   // rounding remainder of the discount goes to the largest rate bucket
            arsort($byRate); $main = array_key_first($byRate);
            $byRate[$main] += $goods - array_sum($byRate);
            if ($delivery) $byRate[$main] += $delivery;
        } elseif ($delivery) $byRate[21] = $delivery;
        $vat = [];
        foreach ($byRate as $rate => $gross) $vat[$rate] = ['gross' => $gross, 'vat' => $rate ? (int) round($gross * $rate / (100 + $rate)) : 0];
        krsort($vat);
        return ['subtotal' => $sub, 'discount' => $disc, 'code_discount' => $codeDisc, 'code' => $code['code'] ?? null, 'bundle' => $bundleName, 'goods' => $goods, 'delivery' => $delivery, 'total' => $total,
            'vat' => $vat, 'vat_total' => array_sum(array_column($vat, 'vat')), 'gift' => $gift,
            'free_from' => $zone ? ($zone['free_from_cents'] !== null ? (int) $zone['free_from_cents'] : (int) Settings::get('shop')['free_from_cents']) : (int) Settings::get('shop')['free_from_cents']];
    }

    /** The active bundle, if any: ['id','name','discount_pct','product_ids']. */
    public static function bundle(): ?array {
        $b = DB::one('SELECT * FROM bundles WHERE active = 1 ORDER BY id LIMIT 1');
        if (!$b) return null;
        $b['product_ids'] = array_map('intval', array_column(DB::all('SELECT bi.product_id FROM bundle_items bi JOIN products p ON p.id = bi.product_id WHERE bi.bundle_id = ? AND p.active = 1', [$b['id']]), 'product_id'));
        return count($b['product_ids']) >= 2 ? $b : null;
    }
}

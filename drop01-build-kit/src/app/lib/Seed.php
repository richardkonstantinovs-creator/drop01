<?php
// Install-time seed data: delivery zones from OWNER_INPUT.md and removable demo products (Settings → System → Remove demo data).
defined('DROP01') || exit;

final class Seed {
    /** Groningen zones from OWNER_INPUT.md. Specific districts are listed before the catch-all "rest of Groningen". */
    public static function zones(): void {
        if (DB::val('SELECT COUNT(*) FROM delivery_zones')) return;
        $z = [['Centrum', 9711, 9712, 395], ['De Hoogte', 9715, 9715, 395], ['Oosterpoort', 9724, 9724, 395], ['Helpman', 9726, 9726, 395],
            ['Corpus den Hoorn', 9728, 9728, 395], ['Beijum', 9737, 9737, 395], ['Selwerd', 9741, 9741, 395], ['Paddepoel', 9742, 9742, 395],
            ['Vinkhuizen', 9743, 9743, 395], ['Haren', 9750, 9752, 595], ['Rest of Groningen', 9700, 9759, 395]];
        foreach ($z as $i => [$n, $a, $b, $fee]) DB::insert('delivery_zones', ['name' => $n, 'postcode_from' => $a, 'postcode_to' => $b, 'fee_cents' => $fee, 'free_from_cents' => 3500, 'sort' => $i]);
    }

    /** Products from OWNER_INPUT.md, marked is_demo. The first four appear on the waitlist, sealed, like the prototype. */
    public static function demoProducts(): void {
        $p = [
            ['Item 01', 3495, 1950, 50, ['S' => 10, 'M' => 12, 'L' => 12, 'XL' => 8]],
            ['Item 02', 2495, 1120, 30, ['One size' => 6]],
            ['Item 03', 4900, 2800, 100, ['Holo edition' => 18]],
            ['Item 04', 1995, 890, 20, ['S' => 0, 'M' => 0, 'L' => 0]],
            ['Item 05', 1250, 410, 0, ['Gift box' => 4]],
            ['Item 06', 995, 360, 0, ['Mini' => 77]],
        ];
        $ids = [];
        foreach ($p as $i => [$name, $price, $cost, $run, $vars]) {
            $id = Products::save(['name' => $name, 'slug' => 'item-0' . ($i + 1), 'price' => $price, 'cost' => $cost, 'vat_rate' => 21, 'run_size' => $run,
                'note' => 'Reveal soon', 'reveal' => 0, 'category' => ['Bestseller', 'New', 'Bestseller', 'New', 'Gifts', 'Gifts'][$i], 'on_waitlist' => $i < 4 ? 1 : 0, 'featured' => $i < 3 ? 1 : 0, 'low_stock_at' => 5,
                'variants' => array_map(fn($l, $s) => ['label' => $l, 'stock' => $s], array_keys($vars), $vars)]);
            DB::update('products', ['is_demo' => 1], 'id = ?', [$id]);
            $ids[] = $id;
        }
        // the prototype's "The full drop" bundle: Item 01 + Item 03 + Item 06, 15% off
        $bid = DB::insert('bundles', ['name' => 'The full drop', 'discount_pct' => 15, 'active' => 1]);
        foreach ([0, 2, 5] as $k) DB::insert('bundle_items', ['bundle_id' => $bid, 'product_id' => $ids[$k]]);
    }
}

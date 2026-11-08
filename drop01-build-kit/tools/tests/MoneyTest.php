<?php
// Money → Margins tests: unit economics per product (packaging, delivery and fee shares, break-even and target price), real results from paid orders, verdicts and reorder hints.
defined('DROP01') || exit;

function money_fixture(): array {
    $f = shop_fixture();
    foreach (['materials', 'product_materials'] as $t) DB::q("DELETE FROM $t");
    Settings::set('costs', ['delivery_cents' => 300, 'fee_cents' => 25, 'fee_pct' => 0, 'fixed_month_cents' => 0, 'target_pct' => 30, 'reorder_days' => 14, 'cover_days' => 30]);
    $f['bag'] = Margins::saveMaterial(['name' => 'Shipping bag', 'pack_price' => '12,00', 'pack_qty' => 100, 'per' => 'order']);   // 12 ct per order
    $f['box'] = Margins::saveMaterial(['name' => 'Box', 'pack_price' => '50,00', 'pack_qty' => 50, 'per' => 'item']);             // €1 each
    Margins::setPackaging($f['a'], [['material_id' => $f['box'], 'qty' => 1], ['material_id' => $f['bag'], 'qty' => 2]]);
    return $f;
}

function test_unit_economics_of_a_product(): void {
    $f = money_fixture();
    $p = DB::one('SELECT * FROM products WHERE id = ?', [$f['a']]);   // Tee €34,95, cost €19,50, 21% BTW
    eq(count(Margins::packaging()[$f['a']]['items']), 1, 'per-order materials cannot be put on a product');
    $u = Margins::unit($p, Margins::context());
    eq([$u['vat'], $u['kept'], $u['cost'], $u['pack'], $u['delivery'], $u['opack'], $u['fee'], $u['full']], [607, 2888, 1950, 100, 300, 12, 25, 2387], 'cost breakdown');
    eq($u['profit'], 501); eq($u['margin'], 17.4); eq($u['markup'], 21);
    eq($u['min_price'], 2889, 'break-even price incl. BTW');
    eq($u['rec_price'], 4195, '30% margin, rounded up to x,95');
    // a % payment fee moves both prices; at the break-even price the profit is zero
    Settings::set('costs', ['fee_pct' => 2] + Settings::get('costs'));
    $ctx = Margins::context();
    $u = Margins::unit($p, $ctx);
    eq($u['fee'], 95, '€0,25 + 2% of €34,95');
    $z = Margins::unit($p, $ctx, $u['min_price']);
    ok($z['profit'] >= 0 && $z['profit'] <= 1, 'zero profit at the break-even price, got ' . $z['profit']);
    $t = Margins::unit($p, $ctx, $u['rec_price']);
    ok($t['margin'] >= 30 && $t['margin'] < 33, 'target margin reached at the recommended price, got ' . $t['margin']);
    eq(Margins::unit($p, $ctx, null, 1000)['profit'], $u['profit'] + 950, 'what-if purchase cost');
}

function test_real_results_verdicts_and_reorder(): void {
    $f = money_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 2]]));   // 2 × Tee = €69,90, free delivery
    Orders::markPaid((int) $o['id'], 'manual');
    $u = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'u@x.nl', 'phone' => '0699998888']));   // unpaid: not counted
    $r = Margins::report(30);
    $tee = array_values(array_filter($r['products'], fn($p) => $p['id'] === $f['a']))[0];
    $book = array_values(array_filter($r['products'], fn($p) => $p['id'] === $f['b']))[0];
    eq([$tee['sold']['units'], $tee['sold']['kept'], $tee['sold']['cost'], $tee['sold']['pack'], $tee['sold']['delivery'], $tee['sold']['fee']], [2, 5777, 3900, 200, 300, 25]);
    eq($tee['sold']['profit'], 1340, 'profit of the paid order');
    eq($r['totals']['orders'], 1); eq($r['totals']['profit'], 1340); eq($r['totals']['revenue'], 6990);
    eq($tee['verdict'], 'raise', 'sells, but 23% is under the 30% target');
    eq($book['verdict'], 'no_sales'); eq($book['sold'], null);
    // 2 sold in a week (new product), 2 left → 7 days of stock; 30 days of cover needs 9 → reorder 7
    eq($tee['per_week'], 2.0); eq($tee['days_left'], 7); eq($tee['reorder'], 7);
    // the cost saved on the order line counts, not today's cost
    DB::update('products', ['cost_cents' => 100], 'id = ?', [$f['a']]);
    eq(Margins::report(30)['products'][0]['sold']['cost'] ?? null, 3900, 'snapshot purchase cost');
    // fixed costs → orders needed per month
    Settings::set('costs', ['fixed_month_cents' => 13400] + Settings::get('costs'));
    eq(Margins::report(30)['totals']['break_even'], 10, '€134 fixed / €13,40 profit per order');
}

function test_materials_switching_to_every_order(): void {
    $f = money_fixture();
    Margins::saveMaterial(['id' => $f['box'], 'name' => 'Box', 'pack_price' => '50,00', 'pack_qty' => 50, 'per' => 'order']);
    eq(Margins::packaging(), [], 'a material used in every order is no longer per product');
    eq((int) round(Margins::orderMaterials()), 112);
    Margins::deleteMaterial($f['bag']);
    eq((int) round(Margins::orderMaterials()), 100);
    try { Margins::saveMaterial(['name' => ' ']); ok(false, 'a material needs a name'); } catch (InvalidArgumentException $e) { ok(str_contains($e->getMessage(), 'Give the material a name'), $e->getMessage()); }
}

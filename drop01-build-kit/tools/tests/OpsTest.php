<?php
// Daily operations tests: the packing list, customers moving their own delivery window, and cash handovers from couriers.
defined('DROP01') || exit;

function ops_fixture(): array {
    $f = shop_fixture();
    foreach (['cash_handovers', 'admins'] as $t) DB::q("DELETE FROM $t");
    $date = DB::val('SELECT date FROM delivery_slots WHERE id = ?', [$f['slot']]);
    $f['slot2'] = DB::insert('delivery_slots', ['date' => $date, 'start_time' => '12:00', 'end_time' => '15:00', 'capacity' => 10]);
    $f['date'] = $date;
    $f['owner'] = DB::insert('admins', ['name' => 'Owner', 'email' => 'o@x.nl', 'pass_hash' => 'x', 'role' => 'owner', 'created_at' => DB::now()]);
    $f['courier'] = DB::insert('admins', ['name' => 'Sam Courier', 'email' => 'c@x.nl', 'pass_hash' => 'x', 'role' => 'courier', 'created_at' => DB::now()]);
    return $f;
}

function test_packing_list_for_a_day(): void {
    $f = ops_fixture();
    $a = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 2], ['variant_id' => $f['book'], 'qty' => 1]]));
    $b = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 3]], ['email' => 'b@x.nl', 'phone' => '0622223333', 'name' => 'Bo', 'method' => 'cash', 'slot_id' => $f['slot2'], 'notes' => 'Ring twice']));
    $c = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'c@x.nl', 'phone' => '0633334444']));
    Orders::cancel((int) $c['id'], 'test');
    Orders::markPaid((int) $a['id'], 'manual');
    $d = Packing::day($f['date']);
    eq($d['total'], 2, 'cancelled orders are left out');
    eq(array_column($d['windows'], 'label'), ['12:00–15:00', '18:00–21:00'], 'windows in time order');
    eq(array_column($d['pick'], 'qty', 'name'), ['Book' => 4, 'Tee · M' => 2], 'pick totals over the day');
    $bo = $d['windows'][0]['orders'][0];
    eq($bo['collect'], (int) $b['total_cents'], 'cash to collect at the door'); eq($bo['notes'], 'Ring twice');
    Orders::setStatus((int) $a['id'], 'packing');
    $d = Packing::day($f['date']);
    eq($d['packed'], 1); eq(array_column($d['pick'], 'qty', 'name'), ['Book' => 3], 'packed orders drop out of the pick list');
}

function test_packing_follows_the_shelves(): void {
    $f = ops_fixture();
    eq(Products::shelf(' a 10 '), 'A10', 'shelf codes are tidied'); eq(Products::shelf('  '), null);
    Products::setShelf($f['book'], 'a10'); Products::setShelf($f['m'], 'A2');
    $a = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1], ['variant_id' => $f['l'], 'qty' => 1], ['variant_id' => $f['m'], 'qty' => 1]]));
    $d = Packing::day($f['date']);
    eq(array_column($d['pick'], 'shelf'), ['A2', 'A10', null], 'pick list walks the shelves: A2 before A10, no shelf last');
    eq(array_column($d['windows'][0]['orders'][0]['items'], 'shelf'), ['A2', 'A10', null], 'items in the bag in the same order');
    eq($d['labels']['out_for_delivery'] ?? null, Orders::STATUS['out_for_delivery'], 'status names come along');
    // the product editor keeps the shelf when it does not send one, and clears it when sent empty
    $tee = array_values(array_filter(Products::all(), fn($x) => $x['name'] === 'Tee'))[0];
    Products::save(['id' => $tee['id'], 'name' => 'Tee', 'price' => '34,95', 'cost' => '19,50', 'vat_rate' => 21, 'variants' => array_map(fn($v) => ['id' => $v['id'], 'label' => $v['label'], 'stock' => $v['stock']], $tee['variants'])]);
    eq(DB::val('SELECT shelf FROM variants WHERE id = ?', [$f['m']]), 'A2', 'saving without a shelf keeps it');
    Products::save(['id' => $tee['id'], 'name' => 'Tee', 'price' => '34,95', 'cost' => '19,50', 'vat_rate' => 21, 'variants' => array_map(fn($v) => ['id' => $v['id'], 'label' => $v['label'], 'stock' => $v['stock'], 'shelf' => ''], $tee['variants'])]);
    eq(DB::val('SELECT shelf FROM variants WHERE id = ?', [$f['m']]), null, 'an empty shelf clears it');
    $err = ''; try { Products::setShelf(999999, 'A1'); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    ok($err !== '', 'unknown variant refused');
}

function test_customer_moves_the_delivery_window(): void {
    $f = ops_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]]));
    Orders::markPaid((int) $o['id'], 'manual');
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot']]), 1);
    ok(Orders::canReschedule(DB::one('SELECT * FROM orders WHERE id = ?', [$o['id']])), 'can move before the cut-off');
    mk_fails(fn() => Orders::customerReschedule((int) $o['id'], $f['slot']), 'already your delivery window');
    Orders::customerReschedule((int) $o['id'], $f['slot2']);
    eq((int) DB::val('SELECT slot_id FROM orders WHERE id = ?', [$o['id']]), $f['slot2']);
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot']]), 0, 'old window freed');
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot2']]), 1, 'new window booked');
    eq((int) DB::val("SELECT COUNT(*) FROM messages WHERE order_id = ? AND template = 'rescheduled' AND channel = 'email'", [$o['id']]), 1, 'customer gets the new time by email');
    // a full window is refused
    DB::update('delivery_slots', ['capacity' => 0], 'id = ?', [$f['slot']]);
    mk_fails(fn() => Orders::customerReschedule((int) $o['id'], $f['slot']), 'filled up');
    DB::update('delivery_slots', ['capacity' => 10], 'id = ?', [$f['slot']]);
    // at most three changes
    Orders::customerReschedule((int) $o['id'], $f['slot']); Orders::customerReschedule((int) $o['id'], $f['slot2']);
    ok(!Orders::canReschedule(DB::one('SELECT * FROM orders WHERE id = ?', [$o['id']])), 'three changes is the limit');
    // on its way: no more changes; not home: the customer picks a new time and it goes back to packing
    $p = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'p@x.nl', 'phone' => '0644445555']));
    Orders::markPaid((int) $p['id'], 'manual'); Orders::setStatus((int) $p['id'], 'out_for_delivery');
    ok(!Orders::canReschedule(DB::one('SELECT * FROM orders WHERE id = ?', [$p['id']])), 'not while out for delivery');
    Courier::failed((int) $p['id'], 'Nobody home', null);
    Orders::customerReschedule((int) $p['id'], $f['slot2']);
    eq(DB::val('SELECT status FROM orders WHERE id = ?', [$p['id']]), 'packing');
    // the owner can switch it off
    $d = Settings::get('delivery'); $d['self_reschedule'] = false; Settings::set('delivery', $d);
    $q = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'q@x.nl', 'phone' => '0655556666']));
    mk_fails(fn() => Orders::customerReschedule((int) $q['id'], $f['slot2']), 'no longer be moved');
}

function test_cash_collected_and_handed_over(): void {
    $f = ops_fixture();
    $mk = fn($e, $ph) => Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => $e, 'phone' => $ph, 'method' => 'cash']));
    $a = $mk('a@x.nl', '0611110001'); $b = $mk('b@x.nl', '0611110002'); $c = $mk('c@x.nl', '0611110003');
    Courier::delivered((int) $a['id'], $f['courier'], true); Courier::delivered((int) $b['id'], $f['courier'], true);
    Courier::delivered((int) $c['id'], $f['owner'], true);
    $open = Cash::open();
    eq(count($open), 1, 'the owner keeps their own cash, only the courier is listed');
    eq($open[0]['courier'], 'Sam Courier'); eq($open[0]['total'], (int) $a['total_cents'] + (int) $b['total_cents']); eq(count($open[0]['orders']), 2);
    eq(count(Cash::open($f['owner'])), 0);
    $h = Cash::handover($f['courier'], [], (int) $a['total_cents'] + (int) $b['total_cents'] - 500, '€5 short', $f['owner']);
    eq((int) $h['expected_cents'] - (int) $h['received_cents'], 500, 'the difference is kept');
    eq(Cash::open(), [], 'nothing open after the handover');
    mk_fails(fn() => Cash::handover($f['courier'], [], 100, '', $f['owner']), 'no open cash');
    eq(Cash::history()[0]['note'], '€5 short');
    eq((int) DB::val("SELECT COUNT(*) FROM order_events WHERE type = 'cash_handed_over'"), 2);
}

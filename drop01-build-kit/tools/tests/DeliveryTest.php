<?php
// Tests for Phase 3: route ordering on 10 real Groningen addresses (PDOK coordinates), Google Maps links, manual reorder,
// courier actions (start, delivered with cash, not home, reschedule) with customer messages, and couriers seeing only their routes.
defined('DROP01') || exit;

/** Ten real addresses across Groningen and Haren, coordinates from PDOK Locatieserver (checked 2026-10-12). */
const GRONINGEN_STOPS = [
    ['Grote Markt', '1', '', '9712HN', 53.21856902, 6.56719613],
    ['Zonnelaan', '165', '', '9742BD', 53.22912757, 6.54309949],
    ['Verlengde Hereweg', '100', '', '9722AH', 53.19658922, 6.58150442],
    ['Oosterweg', '20', '', '9724CH', 53.21250387, 6.57488469],
    ['Korreweg', '100', '', '9715AH', 53.22930448, 6.5661552],
    ['Kerklaan', '10', '', '9717HE', 53.22383799, 6.55316838],
    ['Beijumerweg', '20', '', '9737AD', 53.25213515, 6.59301099],
    ['Rijksstraatweg', '150', '', '9752BM', 53.17366615, 6.6026394],
    ['Laan Corpus den Hoorn', '100', '', '9728JR', 53.19801672, 6.54790843],
    ['Damsterdiep', '263', '50', '9713ED', 53.22034011, 6.58520034],
];
const DEPOT = ['Peizerweg 68, Groningen', 53.21088588, 6.5513464];

/** Shop fixture + depot + one paid order per address in the fixture's window. Returns the fixture with 'orders' => [id, …]. */
function route_fixture(int $n = 10, string $method = 'manual'): array {
    $f = shop_fixture();
    DB::q('UPDATE variants SET stock = 50 WHERE id = ?', [$f['book']]);
    DB::q('UPDATE delivery_slots SET capacity = 20 WHERE id = ?', [$f['slot']]);
    Settings::set('company', ['depot' => DEPOT[0]] + Settings::get('company'));
    Settings::set('depot_geo', ['addr' => DEPOT[0], 'lat' => DEPOT[1], 'lng' => DEPOT[2]]);
    $f['orders'] = [];
    foreach (array_slice(GRONINGEN_STOPS, 0, $n) as $i => [$street, $no, $add, $pc, $lat, $lng]) {
        $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => "stop$i@x.nl", 'phone' => '06' . (20000000 + $i), 'name' => "Stop $i",
            'street' => $street, 'house_no' => $no, 'addition' => $add, 'postcode' => $pc, 'lat' => $lat, 'lng' => $lng, 'method' => $method]));
        if ($method !== 'cash') Orders::markPaid((int) $o['id'], 'manual', 'manual');
        $f['orders'][] = (int) $o['id'];
    }
    DB::q('DELETE FROM messages');
    return $f;
}

function test_route_on_ten_groningen_addresses_is_sensible(): void {
    $start = [DEPOT[1], DEPOT[2]];
    $pts = array_map(fn($s) => [$s[4], $s[5]], GRONINGEN_STOPS);
    $order = Route::optimize($start, $pts);
    eq(count(array_unique($order)), 10, 'every stop visited once');
    $len = Route::length($start, $pts, $order);
    // nearest neighbour alone, for comparison
    $left = array_keys($pts); $nn = []; $cur = $start;
    while ($left) { usort($left, fn($a, $b) => Route::km($cur, $pts[$a]) <=> Route::km($cur, $pts[$b])); $nn[] = $i = array_shift($left); $cur = $pts[$i]; }
    ok($len <= Route::length($start, $pts, $nn) + 1e-9, '2-opt is never longer than nearest neighbour');
    ok($len < Route::length($start, $pts, array_keys($pts)), 'shorter than the order the orders came in');
    // 2-opt optimum: no single segment reversal makes it shorter
    for ($i = 0; $i < 9; $i++) for ($k = $i + 1; $k < 10; $k++) {
        $t = $order; array_splice($t, $i, $k - $i + 1, array_reverse(array_slice($t, $i, $k - $i + 1)));
        ok(Route::length($start, $pts, $t) >= $len - 1e-7, "reversing $i..$k would improve the route");
    }
    // exact optimum by Held-Karp (open path from the depot), so "sensible" means: within 5% of the best possible route
    $best = held_karp($start, $pts);
    ok($len <= $best * 1.05, sprintf('route %.2f km vs optimum %.2f km', $len, $best));
    ok($len < 35, "route length $len km");
}

/** Shortest open path from $start through all $pts (dynamic programming over subsets; fine for ≤ 12 points). */
function held_karp(array $start, array $pts): float {
    $n = count($pts); $full = (1 << $n) - 1; $dp = [];
    for ($j = 0; $j < $n; $j++) $dp[1 << $j][$j] = Route::km($start, $pts[$j]);
    for ($m = 1; $m <= $full; $m++) foreach ($dp[$m] ?? [] as $j => $d)
        for ($k = 0; $k < $n; $k++) if (!($m & (1 << $k))) { $nm = $m | (1 << $k); $nd = $d + Route::km($pts[$j], $pts[$k]); if ($nd < ($dp[$nm][$k] ?? INF)) $dp[$nm][$k] = $nd; }
    return min($dp[$full]);
}

function test_route_for_a_window_with_etas_and_maps_links(): void {
    $f = route_fixture();
    $r = Route::forSlot($f['slot']);
    eq($r['total'], 10, 'ten stops on the route');
    eq($r['done'], 0);
    ok($r['depot']['set'], 'depot from Settings');
    $etas = array_column($r['stops'], 'eta_ts');
    $sorted = $etas; sort($sorted);
    eq($etas, $sorted, 'ETAs go up stop by stop');
    ok($r['km'] > 5 && $r['km'] < 50, 'road distance estimate ' . $r['km']);
    ok($r['minutes'] >= 10 * 4, 'time includes the stop minutes');
    eq(count($r['maps']), 1, 'ten stops fit one Google Maps link (9 waypoints + destination)');
    parse_str(parse_url($r['maps'][0]['url'], PHP_URL_QUERY), $q);
    eq(count(explode('|', $q['waypoints'])), 9);
    ok(str_contains($q['destination'], ', 97'), 'destination is a readable address');
    ok(!isset($q['origin']), 'the first link starts where the phone is');
    // twelve places → two links, the second starting where the first ended
    $links = Route::mapsLinks(array_map(fn($i) => "P$i", range(1, 12)));
    eq(count($links), 2);
    eq([$links[1]['from'], $links[1]['to']], [11, 12]);
    parse_str(parse_url($links[1]['url'], PHP_URL_QUERY), $q2);
    eq($q2['origin'], 'P10');
    // unpaid cash orders are on the route with "collect payment"
    $c = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'cash@x.nl', 'postcode' => '9711AB', 'lat' => 53.2172, 'lng' => 6.5683, 'method' => 'cash']));
    $stop = array_values(array_filter(Route::forSlot($f['slot'])['stops'], fn($s) => $s['id'] === (int) $c['id']))[0];
    eq($stop['collect'], (int) $c['total_cents'], 'collect payment at the door');
}

function test_manual_reorder_survives_and_reset_restores_the_optimum(): void {
    $f = route_fixture(5);
    $auto = array_column(Route::forSlot($f['slot'])['stops'], 'id');
    $manual = array_reverse($auto);
    Route::reorder($f['slot'], $manual);
    $r = Route::forSlot($f['slot']);
    ok($r['slot']['manual'], 'window marked as manually ordered');
    eq(array_column($r['stops'], 'id'), $manual, 'drag order is kept');
    // a new order joins where it costs the least, the manual order stays
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'late@x.nl', 'postcode' => '9712HN', 'house_no' => '3', 'lat' => 53.2186, 'lng' => 6.5674]));
    Orders::markPaid((int) $o['id'], 'manual', 'manual');
    $ids = array_column(Route::forSlot($f['slot'])['stops'], 'id');
    eq(array_values(array_diff($ids, [(int) $o['id']])), $manual, 'existing stops keep their manual order');
    Route::resetOrder($f['slot']);
    ok(!Route::forSlot($f['slot'])['slot']['manual'], 'back to automatic');
}

function test_courier_flow_delivered_not_home_reschedule_and_messages(): void {
    $f = route_fixture(4);
    $cash = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'cash@x.nl', 'phone' => '0633334444', 'postcode' => '9711AB', 'lat' => 53.2172, 'lng' => 6.5683, 'method' => 'cash']));
    $cid = (int) $cash['id'];
    DB::q('DELETE FROM messages');
    $wa = Courier::start($f['slot'], null);
    eq(count($wa), 5, 'all five stops go out, the unpaid cash order too');
    eq((int) DB::val("SELECT COUNT(*) FROM orders WHERE slot_id = ? AND status = 'out_for_delivery' AND out_at IS NOT NULL", [$f['slot']]), 5);
    eq((int) DB::val("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND template = 'out_for_delivery'"), 5, 'everyone gets an email with their ETA');
    $mail = json_decode(DB::val("SELECT body FROM messages WHERE template = 'out_for_delivery' AND order_id = ?", [$cid]), true);
    ok(preg_match('/Expect us \d\d:\d\d–\d\d:\d\d/', $mail['text']) === 1, 'ETA window in the email: ' . $mail['text']);
    ok(str_contains($mail['text'], 'have'), 'cash customers are asked to have the money ready');
    ok(str_starts_with($wa[$cid], 'https://wa.me/31633334444?text='), 'WhatsApp link per stop');

    // cash stop: delivered and paid at the door → paid, invoice, delivered
    Courier::delivered($cid, null, true);
    $o = DB::one('SELECT * FROM orders WHERE id = ?', [$cid]);
    eq([$o['status'], $o['payment_status'], $o['payment_method']], ['delivered', 'paid', 'cash']);
    ok(DB::val('SELECT 1 FROM invoices WHERE order_id = ?', [$cid]), 'invoice made when the cash was collected');
    ok(DB::val("SELECT 1 FROM messages WHERE order_id = ? AND template = 'delivered' AND channel = 'email'", [$cid]), 'delivered email');

    // not home → failed, counted, message; the route shows it as failed and keeps ETAs for the rest
    $miss = $f['orders'][0];
    Courier::failed($miss, 'Nobody home', null);
    eq(DB::val('SELECT status FROM orders WHERE id = ?', [$miss]), 'failed_delivery');
    eq((int) DB::val('SELECT failed_count FROM orders WHERE id = ?', [$miss]), 1);
    ok(DB::val("SELECT 1 FROM messages WHERE order_id = ? AND template = 'failed_delivery'", [$miss]), 'we-missed-you email');
    $r = Route::forSlot($f['slot']);
    eq([$r['done'], $r['total']], [2, 5]);
    eq(array_column(array_slice($r['stops'], 0, 2), 'state'), ['done', 'failed'], 'finished stops first, in the order they happened');
    ok(Reports::attention() && str_contains(json_encode(Reports::attention()), 'nobody home'), 'failed delivery shows under Needs your attention');

    // reschedule into another window: booking moves, status back to packing, customer told
    $tomorrow = DB::insert('delivery_slots', ['date' => (new DateTime('+3 days', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d'), 'start_time' => '12:00', 'end_time' => '15:00', 'capacity' => 5]);
    $before = (int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot']]);
    Courier::reschedule($miss, $tomorrow, null);
    eq(DB::val('SELECT status FROM orders WHERE id = ?', [$miss]), 'packing');
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot']]), $before - 1);
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$tomorrow]), 1);
    ok(DB::val("SELECT 1 FROM messages WHERE order_id = ? AND template = 'rescheduled' AND channel = 'email'", [$miss]), 'new-time email');
    eq(Route::forSlot($tomorrow)['total'], 1, 'it is on the new route');

    // done orders cannot be delivered twice
    try { Courier::delivered($cid, null); ok(false, 'second delivery accepted'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'delivered already'), $e->getMessage()); }
    // emails can be switched off per event
    Settings::set('delivery', ['notify' => ['on_my_way' => false]] + Settings::get('delivery'));
    Courier::onMyWay($f['orders'][1], null);
    eq((int) DB::val("SELECT COUNT(*) FROM messages WHERE order_id = ? AND template = 'on_my_way' AND channel = 'email'", [$f['orders'][1]]), 0, 'switched off');
    eq((int) DB::val("SELECT COUNT(*) FROM messages WHERE order_id = ? AND template = 'on_my_way' AND channel = 'whatsapp'", [$f['orders'][1]]), 1, 'WhatsApp link still ready');
}

function test_couriers_only_see_their_own_routes(): void {
    $f = route_fixture(2);
    $a = DB::insert('admins', ['name' => 'Anna', 'email' => 'a@x.nl', 'pass_hash' => 'x', 'role' => 'courier', 'created_at' => DB::now()]);
    $date = DB::val('SELECT date FROM delivery_slots WHERE id = ?', [$f['slot']]);
    eq(count(Route::forDate($date, $a)), 1, 'the only courier gets unassigned routes');
    $b = DB::insert('admins', ['name' => 'Bram', 'email' => 'b@x.nl', 'pass_hash' => 'x', 'role' => 'courier', 'created_at' => DB::now()]);
    eq(count(Route::forDate($date, $a)), 0, 'with two couriers, unassigned routes belong to nobody');
    DB::update('delivery_slots', ['courier_id' => $b], 'id = ?', [$f['slot']]);
    eq(count(Route::forDate($date, $b)), 1);
    eq(count(Route::forDate($date, $a)), 0);
    ok(Route::mayWork(['id' => $b, 'role' => 'courier'], $f['orders'][0]), 'Bram works his stops');
    ok(!Route::mayWork(['id' => $a, 'role' => 'courier'], $f['orders'][0]), 'Anna cannot touch them');
    ok(Route::mayWork(['id' => 1, 'role' => 'owner'], $f['orders'][0]), 'the owner can');
}

function test_eta_window_text(): void {
    $tz = new DateTimeZone(TZ_LOCAL);
    $slot = ['start' => '18:00', 'end' => '21:00'];
    eq(Courier::etaWindow((new DateTime('2026-11-27 18:52', $tz))->getTimestamp(), $slot), '18:30–19:00', 'ETA 18:52 → about 18:45, ± 15 min');
    eq(Courier::etaWindow((new DateTime('2026-11-27 18:05', $tz))->getTimestamp(), $slot), '18:00–18:15', 'never before the window opens');
    eq(Courier::etaWindow(null, $slot), '18:00–21:00');
}

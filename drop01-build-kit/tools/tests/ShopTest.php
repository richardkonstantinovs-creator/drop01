<?php
// Tests for the shop: money/BTW/bundles, zones, slots and cut-off, stock holds, orders, the Mollie webhook state machine, gifts, invoices.
defined('DROP01') || exit;

/** Small fixture: zones, one product with two variants, a cheap 9% product, a bundle, and the slot template. */
function shop_fixture(): array {
    foreach (['orders', 'order_items', 'order_events', 'payments', 'invoices', 'customers', 'addresses', 'delivery_slots', 'delivery_zones', 'bundles', 'bundle_items', 'referral_codes', 'messages', 'carts', 'address_cache'] as $t) DB::q("DELETE FROM $t");
    Seed::zones();
    $a = Products::save(['name' => 'Tee', 'price' => '34,95', 'cost' => '19,50', 'vat_rate' => 21, 'variants' => [['label' => 'M', 'stock' => 3], ['label' => 'L', 'stock' => 1]]]);
    $b = Products::save(['name' => 'Book', 'price' => '20,00', 'cost' => '8,00', 'vat_rate' => 9, 'variants' => [['label' => 'Default', 'stock' => 10]]]);
    $bid = DB::insert('bundles', ['name' => 'Duo', 'discount_pct' => 10, 'active' => 1]);
    DB::insert('bundle_items', ['bundle_id' => $bid, 'product_id' => $a]); DB::insert('bundle_items', ['bundle_id' => $bid, 'product_id' => $b]);
    $va = DB::all('SELECT id FROM variants WHERE product_id = ? ORDER BY id', [$a]);
    $vb = (int) DB::val('SELECT id FROM variants WHERE product_id = ?', [$b]);
    Settings::set('delivery', ['windows' => [['start' => '12:00', 'end' => '15:00', 'capacity' => 2], ['start' => '18:00', 'end' => '21:00', 'capacity' => 2]], 'days' => [1, 2, 3, 4, 5, 6, 7], 'cutoff_min' => 120, 'days_ahead' => 2, 'blackout' => []]);
    // a slot far enough ahead to be open whatever time the tests run
    $date = (new DateTime('+2 days', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d');
    $slot = DB::insert('delivery_slots', ['date' => $date, 'start_time' => '18:00', 'end_time' => '21:00', 'capacity' => 10]);
    Settings::set('mollie', ['test_mode' => true]); Settings::setSecret('mollie_test', 'test_' . str_repeat('x', 30));
    return ['a' => $a, 'b' => $b, 'm' => (int) $va[0]['id'], 'l' => (int) $va[1]['id'], 'book' => $vb, 'slot' => $slot];
}
function order_in(array $f, array $items, array $extra = []): array {
    return $extra + ['email' => 'buyer@x.nl', 'phone' => '0611112222', 'name' => 'Lotte Mulder', 'postcode' => '9742 BD', 'house_no' => '167', 'street' => 'Zonnelaan', 'city' => 'Groningen',
        'slot_id' => $f['slot'], 'items' => $items, 'method' => 'ideal'];
}
/** Fake Mollie: payments live in a static array; tests flip their status. */
function mollie_mock(): void {
    $GLOBALS['mock_pay'] = [];
    Mollie::$http = function ($method, $url, $h, $body) {
        if ($method === 'POST') { $b = json_decode($body, true); $id = 'tr_' . bin2hex(random_bytes(4));
            $GLOBALS['mock_pay'][$id] = $b + ['id' => $id, 'status' => 'open', '_links' => ['checkout' => ['href' => "https://mollie.test/checkout/$id"]]];
            return [201, $GLOBALS['mock_pay'][$id]]; }
        $id = basename($url);
        return isset($GLOBALS['mock_pay'][$id]) ? [200, $GLOBALS['mock_pay'][$id]] : [404, []];
    };
}

function test_pricing_bundle_btw_and_delivery(): void {
    $f = shop_fixture();
    $zone = Delivery::zoneFor('9742BD');
    $lines = [['product_id' => $f['a'], 'unit_cents' => 3495, 'qty' => 1, 'vat_rate' => 21], ['product_id' => $f['b'], 'unit_cents' => 2000, 'qty' => 2, 'vat_rate' => 9]];
    $q = Pricing::quote($lines, Pricing::bundle(), $zone);
    eq($q['subtotal'], 7495);
    eq($q['discount'], 550, 'bundle: 10% of one set (3495 + 2000)');
    eq($q['delivery'], 0, 'free from €35');
    eq($q['total'], 6945);
    eq(array_sum(array_column($q['vat'], 'gross')), 6945, 'BTW buckets add up to the total');
    eq(array_keys($q['vat']), [21, 9]);
    eq($q['vat'][21]['vat'], (int) round($q['vat'][21]['gross'] * 21 / 121));
    $small = Pricing::quote([['product_id' => $f['b'], 'unit_cents' => 2000, 'qty' => 1, 'vat_rate' => 9]], Pricing::bundle(), $zone);
    eq($small['discount'], 0, 'no bundle without all products'); eq($small['delivery'], 395); eq($small['total'], 2395);
    eq(Pricing::quote($lines, null, Delivery::zoneFor('9751AB'))['delivery'], 0, 'Haren also free above €35');
    eq(Pricing::quote([['product_id' => $f['b'], 'unit_cents' => 2000, 'qty' => 1, 'vat_rate' => 9]], null, Delivery::zoneFor('9751AB'))['delivery'], 595);
}

function test_postcode_zone_narrowest_wins(): void {
    shop_fixture();
    eq(Delivery::zoneFor('9711 LD')['name'], 'Centrum');
    eq(Delivery::zoneFor('9742BD')['name'], 'Paddepoel');
    eq(Delivery::zoneFor('9751')['name'], 'Haren');
    eq(Delivery::zoneFor('9733AB')['name'], 'Rest of Groningen', 'catch-all');
    eq(Delivery::zoneFor('1012AB'), null, 'Amsterdam is outside');
    eq(Delivery::normPostcode('9711 ld'), '9711LD'); eq(Delivery::normPostcode('0711AB'), null);
}

function test_slots_cutoff_and_capacity(): void {
    $f = shop_fixture();
    $tz = new DateTimeZone(TZ_LOCAL);
    $s = ['id' => 0, 'date' => '2026-11-20', 'start_time' => '18:00', 'end_time' => '21:00', 'capacity' => 2, 'booked' => 0, 'closed' => 0];
    ok(Delivery::describe($s, (new DateTime('2026-11-20 15:59', $tz))->getTimestamp())['open'], 'open before 16:00');
    ok(!Delivery::describe($s, (new DateTime('2026-11-20 16:00', $tz))->getTimestamp())['open'], 'closed at 16:00');
    // unpaid orders hold a place; paid ones count in booked
    DB::update('delivery_slots', ['capacity' => 2], 'id = ?', [$f['slot']]);
    Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]]));
    eq(Delivery::slot($f['slot'])['left'], 1, 'held by an unpaid order');
    Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'two@x.nl', 'phone' => '0622223333']));
    eq(Delivery::slot($f['slot'])['available'], false, 'full');
    try { Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'three@x.nl', 'phone' => '0633334444'])); throw new Exception('overbooked'); }
    catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'filled up'), $e->getMessage()); }
    Delivery::ensureSlots();
    ok((int) DB::val('SELECT COUNT(*) FROM delivery_slots') >= 6, 'template generates windows for the coming days');
}

function test_stock_hold_release_and_sale(): void {
    $f = shop_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 2]]));
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['m']]), 1, 'held');
    try { Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 2]], ['email' => 'b@x.nl', 'phone' => '0699998888'])); throw new Exception('sold held stock'); }
    catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'Only 1'), $e->getMessage()); }
    // the hold runs out → stock comes back, and the order is cancelled once unpaid too long
    DB::q("UPDATE stock_movements SET expires_at = ? WHERE reason = 'reserve'", [DB::now(-60)]);
    eq(Stock::releaseExpired(), 1);
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['m']]), 3, 'released');
    DB::update('orders', ['expires_at' => DB::now(-60)], 'id = ?', [$o['id']]);
    eq(Orders::expireUnpaid(), 1);
    eq(DB::val('SELECT status FROM orders WHERE id = ?', [$o['id']]), 'cancelled');
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['m']]), 3, 'no double release');
    // a hold that becomes a sale
    $o2 = Orders::create(order_in($f, [['variant_id' => $f['l'], 'qty' => 1]]));
    Orders::markPaid((int) $o2['id'], 'manual');
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['l']]), 0);
    eq((int) DB::val("SELECT COUNT(*) FROM stock_movements WHERE ref = ? AND reason = 'sale'", ['order:' . $o2['id']]), 1);
    eq((int) DB::val('SELECT SUM(delta) FROM stock_movements WHERE variant_id = ?', [$f['l']]), 0, 'movement log matches stock');
}

function test_order_totals_come_from_the_database(): void {
    $f = shop_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 1, 'unit_cents' => 1], ['variant_id' => $f['book'], 'qty' => 1]]));
    eq((int) $o['subtotal_cents'], 5495, 'browser prices ignored');
    eq((int) $o['discount_cents'], 550);
    eq((int) $o['total_cents'], 4945);
    eq(DB::val('SELECT name FROM customers WHERE id = ?', [$o['customer_id']]), 'Lotte Mulder');
    ok((int) $o['number'] >= 1001, 'numbering starts at 1001');
    try { Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['postcode' => '1012AB'])); throw new Exception('outside zone allowed'); }
    catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'deliver'), $e->getMessage()); }
}

function test_mollie_is_off_by_default(): void {
    shop_fixture();
    eq(Mollie::enabled(), false); eq(Mollie::configured(), false, 'even with a key stored');
    eq(ShopApi::config()['payOnline'], false, 'the shop offers Tikkie only');
    try { Mollie::getPayment('tr_abc'); throw new Exception('called Mollie while off'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'switched off'), $e->getMessage()); }
}

/** The Mollie code is kept (switched off) for later; this checks it still behaves when switched on. */
function test_mollie_webhook_state_machine(): void {
    $f = shop_fixture(); mollie_mock();
    $GLOBALS['__drop01_cfg']['mollie_enabled'] = true;
    $_SERVER['HTTP_HOST'] = 'shop.test';
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 1]]));
    $url = Orders::startMolliePayment($o, 'ideal');
    ok(str_starts_with($url, 'https://mollie.test/checkout/'), 'hosted checkout url');
    $id = array_key_first($GLOBALS['mock_pay']);
    $p = $GLOBALS['mock_pay'][$id];
    eq($p['amount'], ['currency' => 'EUR', 'value' => '38.90'], '34.95 + 3.95 delivery (below €35)'); eq($p['method'], 'ideal');
    ok(str_contains($p['redirectUrl'], '/order/' . $o['number'] . '?t='), 'redirect to order page with token');
    eq($p['webhookUrl'], 'http://shop.test/api/mollie/webhook');
    // open → nothing happens
    Orders::handleMollieWebhook($id);
    eq(DB::val('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]), 'unpaid');
    // paid → paid flow, twice is still once
    $GLOBALS['mock_pay'][$id]['status'] = 'paid'; $GLOBALS['mock_pay'][$id]['method'] = 'ideal';
    Orders::handleMollieWebhook($id); Orders::handleMollieWebhook($id);
    $r = DB::one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
    eq([$r['status'], $r['payment_status'], $r['payment_method']], ['paid', 'paid', 'ideal']);
    eq((int) DB::val('SELECT booked FROM delivery_slots WHERE id = ?', [$f['slot']]), 1, 'slot booked once');
    eq((int) DB::val("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND type = 'paid'", [$o['id']]), 1, 'idempotent');
    eq((int) DB::val('SELECT COUNT(*) FROM invoices WHERE order_id = ?', [$o['id']]), 1);
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['m']]), 2);
    // failed payment on another order → cancelled and stock back
    $o2 = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 1]], ['email' => 'f@x.nl', 'phone' => '0644445555']));
    Orders::startMolliePayment($o2, null);
    $id2 = array_key_last($GLOBALS['mock_pay']);
    $GLOBALS['mock_pay'][$id2]['status'] = 'failed';
    Orders::handleMollieWebhook($id2);
    eq(DB::val('SELECT status FROM orders WHERE id = ?', [$o2['id']]), 'cancelled');
    eq((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$f['m']]), 2, 'stock released');
    // wrong amount is never accepted as paid
    $o3 = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'g@x.nl', 'phone' => '0655556666']));
    Orders::startMolliePayment($o3, null); $id3 = array_key_last($GLOBALS['mock_pay']);
    $GLOBALS['mock_pay'][$id3]['status'] = 'paid'; $GLOBALS['mock_pay'][$id3]['amount']['value'] = '0.01';
    Orders::handleMollieWebhook($id3);
    eq(DB::val('SELECT payment_status FROM orders WHERE id = ?', [$o3['id']]), 'unpaid', 'amount mismatch rejected');
    Mollie::$http = null;
    unset($GLOBALS['__drop01_cfg']['mollie_enabled']);
}

function test_gift_only_for_matching_waitlist_members_and_redeemed_once(): void {
    $f = shop_fixture();
    $w = Waitlist::signup(['email' => 'fan@x.nl', 'phone' => '0677778888', 'cc' => '+31']);
    $stranger = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'nope@x.nl', 'phone' => '0612121212']));
    eq($stranger['gift_code'], null, 'no gift for non-members');
    $fan = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'other@x.nl', 'phone' => '06 7777 8888']));
    eq($fan['gift_code'], $w['code'], 'matched on phone');
    $again = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'fan@x.nl', 'phone' => '0699990000']));
    eq($again['gift_code'], $w['code'], 'still unredeemed while unpaid');
    Orders::markPaid((int) $fan['id'], 'manual');
    eq((int) DB::val('SELECT gift_redeemed_order_id FROM waitlist_signups WHERE id = ?', [$w['id']]), (int) $fan['id'], 'redeemed on paid');
    Orders::markPaid((int) $again['id'], 'manual');
    eq(DB::val('SELECT gift_code FROM orders WHERE id = ?', [$again['id']]), null, 'second order loses the gift');
    eq(Waitlist::giftFor('fan@x.nl', null), null);
}

function test_invoice_numbers_and_pdf(): void {
    $f = shop_fixture();
    DB::q('DELETE FROM invoices');
    $o1 = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 1], ['variant_id' => $f['book'], 'qty' => 1]]));
    $o2 = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['email' => 'h@x.nl', 'phone' => '0688889999']));
    Orders::markPaid((int) $o1['id'], 'manual'); Orders::markPaid((int) $o2['id'], 'manual');
    $y = (new DateTime('now', new DateTimeZone(TZ_LOCAL)))->format('Y');
    eq(array_column(DB::all('SELECT number FROM invoices ORDER BY id'), 'number'), ["$y-0001", "$y-0002"]);
    $pdf = file_get_contents(Invoice::file(DB::one('SELECT * FROM invoices WHERE order_id = ?', [$o1['id']])));
    ok(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF'), 'valid PDF');
    ok(str_contains($pdf, "($y-0001)") && str_contains($pdf, '(21%)') && str_contains($pdf, '(9%)'), 'number and both BTW rates printed');
    ok(str_contains($pdf, "\x80" . '49,45'), 'euro sign in WinAnsi and the total');
    $split = Invoice::split(Orders::get((int) $o1['id']));
    eq(array_sum(array_column($split, 'gross')), 4945, 'BTW split adds up');
    eq((int) DB::val('SELECT vat_cents FROM orders WHERE id = ?', [$o1['id']]), array_sum(array_column($split, 'vat')), 'order BTW = invoice BTW');
}

function test_referral_code_and_customer_gdpr(): void {
    $f = shop_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['name' => 'Zoë de Vries']));
    Orders::markPaid((int) $o['id'], 'manual');
    eq(DB::val('SELECT code FROM referral_codes WHERE customer_id = ?', [$o['customer_id']]), 'ZOE-D01');
    $exp = Customers::export((int) $o['customer_id']);
    eq($exp['customer']['email'], 'buyer@x.nl'); eq(count($exp['orders']), 1);
    Customers::delete((int) $o['customer_id']);
    $c = DB::one('SELECT * FROM customers WHERE id = ?', [$o['customer_id']]);
    eq([$c['email'], $c['phone']], [null, null]);
    eq((int) DB::val('SELECT total_cents FROM orders WHERE id = ?', [$o['id']]), 2395, 'order kept for the books');
    eq(DB::val('SELECT street FROM addresses WHERE id = ?', [$o['address_id']]), null, 'address wiped');
}

function test_pdok_lookup_exact_and_cached(): void {
    shop_fixture();
    $calls = 0;
    Pdok::$http = function ($url) use (&$calls) { $calls++;
        ok(str_contains($url, 'fq=postcode:9742BD') && str_contains($url, 'fq=huisnummer:167'), 'exact filter query');
        return [['straatnaam' => 'Zonnelaan', 'woonplaatsnaam' => 'Groningen', 'centroide_ll' => 'POINT(6.54295029 53.22932876)', 'huisnummer' => 167]]; };
    $a = Pdok::lookup('9742 bd', '167');
    eq([$a['street'], $a['city'], $a['lat'], $a['lng']], ['Zonnelaan', 'Groningen', 53.22932876, 6.54295029]);
    Pdok::lookup('9742BD', '167');
    eq($calls, 1, 'cached');
    Pdok::$http = fn($u) => [];
    eq(Pdok::lookup('9742BD', '22'), null, 'unknown house');
    Pdok::$http = null;
}

function test_tikkie_order_flow(): void {
    $f = shop_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['method' => 'tikkie']));
    eq($o['payment_method'], 'tikkie');
    ok(strtotime($o['expires_at']) > time() + 23 * 3600, 'Tikkie orders wait 24 hours');
    $held = DB::one("SELECT expires_at FROM stock_movements WHERE reason = 'reserve' AND ref = ?", ['order:' . $o['id']]);
    ok(strtotime($held['expires_at']) > time() + 23 * 3600, 'and keep their stock that long');
    eq(Stock::releaseExpired(), 0);
    Orders::markPaid((int) $o['id'], 'tikkie', 'tikkie');
    $r = DB::one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
    eq([$r['status'], $r['payment_status'], $r['payment_method']], ['paid', 'paid', 'tikkie']);
    $wa = Orders::sheet((int) $o['id'])['wa']['payment_link'];
    ok(str_starts_with($wa, 'https://wa.me/31611112222?text='), 'WhatsApp click-to-chat link: ' . $wa);
}

function test_one_person_cannot_hold_a_whole_drop(): void {
    $f = shop_fixture();
    DB::q('UPDATE variants SET stock = 30');
    Settings::set('shop', array_replace(Settings::get('shop'), ['max_per_item' => 5]));
    // the same size sent 40 times merges into one line of at most 20
    eq(Cart::lines(array_fill(0, 40, ['variant_id' => $f['book'], 'qty' => 20]))[0]['qty'], 20);
    // more than 5 of one product, also spread over sizes, is refused for shop orders
    $fail = function (array $in, string $want) { try { Orders::create($in); ok(false, 'should fail: ' . $want); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), $want), $e->getMessage()); } };
    $fail(order_in($f, [['variant_id' => $f['m'], 'qty' => 3], ['variant_id' => $f['l'], 'qty' => 3]], ['method' => 'tikkie']), 'up to 5 of Tee');
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 3], ['variant_id' => $f['l'], 'qty' => 2]], ['method' => 'tikkie']));
    // a second unpaid Tikkie order with the same phone (other email) waits for the first one
    $fail(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['method' => 'tikkie', 'email' => 'other@x.nl']), 'waiting for payment');
    // the owner can still add anything by hand
    Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 8]], ['method' => 'tikkie', 'admin' => true]));
    // once the first is paid, the customer can order again
    Orders::markPaid((int) $o['id'], 'tikkie', 'tikkie');
    DB::q("UPDATE orders SET status = 'cancelled' WHERE payment_status = 'unpaid'");
    Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['method' => 'tikkie']));
}

function test_links_use_the_site_address_not_the_request(): void {
    $f = shop_fixture();
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['method' => 'tikkie']));
    eq(Http::baseUrl(), 'http://shop.test', 'not set yet: the request');
    Settings::set('site_url', 'https://drop01.nl');
    $_SERVER['HTTP_HOST'] = 'evil.test';
    ok(str_starts_with(Orders::trackUrl($o), 'https://drop01.nl/order/'), 'a forged Host header changes nothing');
    $_SERVER['HTTP_HOST'] = 'bad host/<x>';
    eq(Http::requestBase(), 'http://localhost', 'junk Host header');
    $_SERVER['HTTP_HOST'] = 'shop.test';
}

function test_offsite_copy_needs_address_and_password_and_reports_errors(): void {
    Settings::set('offsite', ['email' => '', 'last' => null]);
    ok(!Backup::offsiteReady(), 'off by default');
    $r = Backup::offsite();
    ok(!$r['ok'] && str_contains($r['error'], 'password'), $r['error']);
    Settings::set('offsite', ['email' => 'safe@gmail.test', 'last' => null]); Settings::setSecret('backup_pass', 'long-backup-pass');
    Settings::set('smtp', ['host' => '127.0.0.1', 'port' => 1, 'secure' => 'none', 'user' => '', 'from' => 'shop@shop.test', 'from_name' => 'Shop']);
    $r = Backup::offsite();
    ok(!$r['ok'] && str_contains($r['error'], 'Email not sent') && $r['size'] > 0, 'zip made, mail failed: ' . $r['error']);
    eq(Settings::get('offsite')['last']['ok'], false, 'the result is kept for System');
    eq(glob(Backup::dir() . '/.offsite-*') ?: [], [], 'no temp files left');
}

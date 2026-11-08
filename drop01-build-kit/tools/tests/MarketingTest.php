<?php
// Marketing tests: discount codes at checkout, the referral programme, back-in-stock, abandoned-cart reminders and broadcasts.
defined('DROP01') || exit;

function mk_fixture(): array {
    $f = shop_fixture();
    foreach (['discount_codes', 'abandoned_carts', 'broadcasts', 'restock_requests', 'list_signups', 'waitlist_signups'] as $t) DB::q("DELETE FROM $t");
    return $f;
}
function mk_fails(callable $fn, string $needle): void {
    try { $fn(); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), $needle), "expected “{$needle}”, got “{$e->getMessage()}”"); return; }
    throw new Exception("expected an error with “{$needle}”");
}

function test_discount_codes_at_checkout(): void {
    $f = mk_fixture();
    Discounts::save(['code' => 'drop10', 'type' => 'pct', 'value' => '10', 'active' => 1]);
    Discounts::save(['code' => 'FIVE', 'type' => 'fixed', 'value' => '5', 'min' => '30', 'max_uses' => '1', 'active' => 1]);
    Discounts::save(['code' => 'SHIPFREE', 'type' => 'free_delivery', 'value' => '', 'active' => 1]);
    Discounts::save(['code' => 'OLD', 'type' => 'pct', 'value' => '50', 'ends' => '2020-01-01', 'active' => 1]);
    Discounts::save(['code' => 'OFF', 'type' => 'pct', 'value' => '50', 'active' => 0]);
    mk_fails(fn() => Discounts::save(['code' => 'DROP10', 'type' => 'pct', 'value' => '5', 'active' => 1]), 'already exists');
    mk_fails(fn() => Discounts::save(['code' => 'BIG', 'type' => 'pct', 'value' => '150', 'active' => 1]), 'between 1 and 100');

    // 10% after the 10% bundle: (3495 + 2000) − 550 = 4945 → −495; delivery stays free (threshold looks at the bag before the code)
    $o = Orders::create(order_in($f, [['variant_id' => $f['m'], 'qty' => 1], ['variant_id' => $f['book'], 'qty' => 1]], ['code' => ' drop10 ']));
    eq($o['discount_code'], 'DROP10'); eq((int) $o['code_discount_cents'], 495); eq((int) $o['discount_cents'], 1045);
    eq((int) $o['delivery_cents'], 0); eq((int) $o['total_cents'], 4450);
    eq(array_sum(array_column(Invoice::split(Orders::get((int) $o['id'])), 'gross')), 4450, 'BTW buckets add up with a code');

    mk_fails(fn() => Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['code' => 'FIVE'])), 'works from €30,00');
    mk_fails(fn() => Discounts::resolve('old', null, null, 9999), 'expired');
    mk_fails(fn() => Discounts::resolve('off', null, null, 9999), 'expired');
    mk_fails(fn() => Discounts::resolve('nope', null, null, 9999), 'don’t know');

    $free = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['code' => 'shipfree']));
    eq((int) $free['delivery_cents'], 0, 'free delivery code'); eq((int) $free['total_cents'], 2000);

    $five = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 2]], ['code' => 'FIVE']));
    eq((int) $five['total_cents'], 3500);
    eq((int) DB::val("SELECT used FROM discount_codes WHERE code = 'FIVE'"), 0, 'not counted before payment');
    Orders::markPaid((int) $five['id'], 'manual'); Orders::markPaid((int) $five['id'], 'manual');
    eq((int) DB::val("SELECT used FROM discount_codes WHERE code = 'FIVE'"), 1, 'counted once');
    mk_fails(fn() => Discounts::resolve('FIVE', null, null, 9999), 'used up');
    Orders::cancel((int) $five['id'], 'test');
    eq((int) DB::val("SELECT used FROM discount_codes WHERE code = 'FIVE'"), 0, 'a cancelled paid order gives the use back');

    $stats = array_column(Discounts::all(), null, 'code');
    eq($stats['DROP10']['label'], '10% off'); eq($stats['FIVE']['label'], '€5,00 off');
}

function test_referral_friend_discount_and_reward(): void {
    $f = mk_fixture();
    $a = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 2]]));
    Orders::markPaid((int) $a['id'], 'manual');
    eq(DB::val('SELECT code FROM referral_codes WHERE customer_id = ?', [$a['customer_id']]), 'LOTTE-D01');
    mk_fails(fn() => Discounts::resolve('LOTTE-D01', 'buyer@x.nl', null, 4000), 'your own code');

    $b = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 2]], ['email' => 'friend@x.nl', 'phone' => '0677778888', 'name' => 'Sam Friend', 'code' => 'lotte-d01']));
    eq($b['referral_code'], 'LOTTE-D01'); eq($b['discount_code'], null);
    eq((int) $b['code_discount_cents'], 500); eq((int) $b['total_cents'], 3500);
    Orders::markPaid((int) $b['id'], 'manual');
    eq((int) DB::val("SELECT uses FROM referral_codes WHERE code = 'LOTTE-D01'"), 1);
    mk_fails(fn() => Discounts::resolve('LOTTE-D01', 'friend@x.nl', null, 4000), 'first order');

    eq((int) DB::val('SELECT COUNT(*) FROM discount_codes'), 0, 'no reward before delivery');
    Orders::setStatus((int) $b['id'], 'packing'); Orders::setStatus((int) $b['id'], 'delivered');
    $r = DB::one("SELECT * FROM discount_codes WHERE source = 'referral'");
    ok($r && str_starts_with($r['code'], 'THANKS-'), 'reward code created');
    eq((int) $r['value'], 500); eq((int) $r['max_uses'], 1); eq((int) $r['customer_id'], (int) $a['customer_id']); eq((int) $r['ref_order_id'], (int) $b['id']);
    eq(Referrals::reward((int) $b['id']), null, 'one reward per order');
    eq((int) DB::val("SELECT COUNT(*) FROM messages WHERE template = 'referral_reward' AND to_addr = 'buyer@x.nl'"), 1, 'referrer gets an email');
    mk_fails(fn() => Discounts::resolve($r['code'], 'friend@x.nl', null, 4000), 'belongs to someone else');
    eq(Discounts::resolve(strtolower($r['code']), 'buyer@x.nl', null, 1000)['value'], 500, 'the referrer can use it');
    $s = Referrals::stats();
    eq($s['friends'], 1); eq($s['rewards'], 1); eq($s['friend_off'], 500);
}

function test_back_in_stock_notifies_once(): void {
    $f = mk_fixture();
    DB::q('UPDATE variants SET stock = 0 WHERE id = ?', [$f['book']]);
    DB::insert('restock_requests', ['product_id' => $f['b'], 'email' => 'want@x.nl', 'created_at' => DB::now()]);
    DB::insert('restock_requests', ['product_id' => $f['b'], 'phone' => '+31600000001', 'created_at' => DB::now()]);
    eq(Restock::notify(), 0, 'still sold out');
    eq(count(Restock::overview()['waiting']), 1);
    Stock::adjust($f['book'], 5, 'receive');
    eq((int) DB::val('SELECT COUNT(*) FROM restock_requests WHERE notified_at IS NOT NULL'), 2, 'both told');
    $m = DB::one("SELECT * FROM messages WHERE template = 'back_in_stock' AND channel = 'email'");
    eq($m['to_addr'], 'want@x.nl'); ok(str_contains($m['subject'], 'Book is back'), $m['subject']);
    $wa = Restock::overview()['whatsapp'];
    eq(count($wa), 1); ok(str_contains($wa[0]['link'], 'wa.me/31600000001') && str_contains($wa[0]['text'], '/p/book'), 'WhatsApp message with the product link');
    eq(Restock::notify(), 0, 'once only');
    eq(count(Restock::overview()['waiting']), 0);
}

function test_abandoned_cart_reminder(): void {
    $f = mk_fixture();
    $items = [['variant_id' => $f['book'], 'qty' => 1]];
    $A = 'aaaaaaaaaaaaaaaa1111'; $B = 'bbbbbbbbbbbbbbbb2222'; $C = 'cccccccccccccccc3333'; $D = 'dddddddddddddddd4444';
    Cart::save($A, $items, ['email' => 'Cart@X.nl', 'name' => 'Kim Bos', 'consent' => true]);
    Cart::save($B, $items, ['email' => 'nocons@x.nl', 'name' => 'No', 'consent' => false]);
    eq(DB::val('SELECT email FROM carts WHERE id = ?', [$B]), null, 'no contact kept without consent');
    Cart::save($A, [['variant_id' => $f['book'], 'qty' => 2]]);
    eq(DB::val('SELECT email FROM carts WHERE id = ?', [$A]), 'cart@x.nl', 'a plain bag sync keeps the contact');
    eq(Cart::remindAbandoned(), 0, 'not within the first hour');
    DB::q('UPDATE carts SET updated_at = ?', [DB::now(-2 * 3600)]);
    eq(Cart::remindAbandoned(), 1);
    $m = DB::one("SELECT * FROM messages WHERE template = 'abandoned_cart'");
    eq($m['to_addr'], 'cart@x.nl');
    $body = json_decode($m['body'], true);
    ok(str_contains($body['html'], '?bag=' . $A) && str_contains($body['html'], 'Still thinking, Kim?') && str_contains($body['html'], 'unsubscribe?e='), 'reminder content');
    eq(Cart::remindAbandoned(), 0, 'one reminder per bag');
    Cart::save($C, $items, ['email' => 'cart@x.nl', 'consent' => true]);
    DB::q('UPDATE carts SET updated_at = ? WHERE id = ?', [DB::now(-2 * 3600), $C]);
    eq(Cart::remindAbandoned(), 0, 'no second email to the same person within 7 days');
    // an order from the reminded bag counts as recovered
    $o = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 2]], ['email' => 'cart@x.nl']));
    Cart::ordered($A, (int) $o['id']);
    eq((int) DB::val('SELECT recovered_order_id FROM abandoned_carts WHERE session_id = ?', [$A]), (int) $o['id']);
    Orders::markPaid((int) $o['id'], 'manual');
    $s = Cart::stats(); eq($s['reminded'], 1); eq($s['recovered'], 1); eq($s['revenue'], (int) $o['total_cents']);
    // bags that turned into an order never get a reminder
    Cart::save($D, $items, ['email' => 'other@x.nl', 'consent' => true]);
    Cart::ordered($D, (int) $o['id']);
    DB::q('UPDATE carts SET updated_at = ? WHERE id = ?', [DB::now(-2 * 3600), $D]);
    eq(Cart::remindAbandoned(), 0);
    eq(Cart::items($A)[0]['qty'], 2, 'the link restores the bag');
}

function test_broadcast_segments_throttle_and_unsubscribe(): void {
    mk_fixture();
    Customers::findOrCreate('yes@x.nl', null, 'Ann Yes', 'web', true);
    Customers::findOrCreate('no@x.nl', null, 'Bob No', 'web', false);
    foreach ([['wl@x.nl', null], ['yes@x.nl', null], ['gone@x.nl', DB::now()]] as $i => [$e, $un])
        DB::insert('waitlist_signups', ['email' => $e, 'phone' => '+3161234567' . $i, 'position' => $i + 1, 'consent' => 1, 'unsubscribed_at' => $un, 'created_at' => DB::now()]);
    DB::insert('list_signups', ['email' => 'next@x.nl', 'source' => 'shop', 'created_at' => DB::now()]);
    $all = Broadcasts::recipients('everyone'); ksort($all);
    eq(array_keys($all), ['next@x.nl', 'wl@x.nl', 'yes@x.nl'], 'consent only, unsubscribed out, one per address');
    eq($all['yes@x.nl'], 'Ann');
    eq(count(Broadcasts::recipients('customers')), 1); eq(count(Broadcasts::recipients('regular')), 0); eq(count(Broadcasts::recipients('list')), 1);
    mk_fails(fn() => Broadcasts::clean(['segment' => 'everyone', 'subject' => '', 'body' => 'x']), 'subject');
    mk_fails(fn() => Broadcasts::clean(['segment' => 'everyone', 'subject' => 'S', 'body' => 'x', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']), 'https://');
    $b = Broadcasts::clean(['segment' => 'everyone', 'subject' => 'Drop 02 is coming', 'heading' => 'Hi {first_name}', 'body' => "Line one\n\nLine <two>", 'button_label' => 'Shop', 'button_url' => 'https://shop.test/']);
    [$id, $n] = Broadcasts::send($b, 1);
    eq($n, 3); eq((int) DB::val('SELECT COUNT(*) FROM messages WHERE broadcast_id = ?', [$id]), 3);
    $html = json_decode(DB::val('SELECT body FROM messages WHERE broadcast_id = ? AND to_addr = ?', [$id, 'yes@x.nl']), true)['html'];
    ok(str_contains($html, 'Hi Ann') && str_contains($html, 'Line &lt;two&gt;') && str_contains($html, 'unsubscribe?e=yes%40x.nl'), 'personal, escaped, with unsubscribe');
    // hourly cap: two already went out this hour, cap 2 → the third waits
    Settings::set('marketing', ['abandoned_cart' => true, 'per_hour' => 2]);
    DB::q("UPDATE messages SET status = 'sent', sent_at = ? WHERE broadcast_id = ? AND to_addr <> 'yes@x.nl'", [DB::now(), $id]);
    Messages::flushQueue();
    eq(DB::val('SELECT status FROM messages WHERE broadcast_id = ? AND to_addr = ?', [$id, 'yes@x.nl']), 'queued', 'held back by the hourly cap');
    eq(Broadcasts::history()[0]['waiting'], 1);
    // unsubscribe: signed link, removes from every list, cancels what is still queued
    parse_str((string) parse_url(Messages::unsubscribeEmailUrl('yes@x.nl'), PHP_URL_QUERY), $q);
    ok(Messages::checkUnsubscribeEmail('yes@x.nl', $q['t']) && !Messages::checkUnsubscribeEmail('wl@x.nl', $q['t']), 'signed per address');
    Messages::unsubscribeEmail('yes@x.nl');
    eq(DB::val('SELECT status FROM messages WHERE broadcast_id = ? AND to_addr = ?', [$id, 'yes@x.nl']), 'cancelled');
    ok(!isset(Broadcasts::recipients('everyone')['yes@x.nl']), 'gone from every list');
    eq((int) DB::val("SELECT marketing_consent FROM customers WHERE email = 'yes@x.nl'"), 0);
}

function test_shop_funnel_counts_sessions_and_paid_web_orders(): void {
    $f = mk_fixture();
    DB::q('DELETE FROM events'); DB::q('DELETE FROM events_daily');
    Analytics::record('shopperAAAA', [['type' => 'product_view'], ['type' => 'product_view'], ['type' => 'add_to_cart'], ['type' => 'checkout_started'], ['type' => 'payment_started']]);
    Analytics::record('shopperBBBB', [['type' => 'product_view'], ['type' => 'add_to_cart']]);
    Analytics::record('shopperCCCC', [['type' => 'product_view'], ['type' => 'pageview']]);
    $web = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]]));
    Orders::markPaid((int) $web['id'], 'manual');
    $unpaid = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]]));
    $phone = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]], ['channel' => 'phone']));
    Orders::markPaid((int) $phone['id'], 'manual');
    $old = Orders::create(order_in($f, [['variant_id' => $f['book'], 'qty' => 1]]));
    Orders::markPaid((int) $old['id'], 'manual');
    DB::update('orders', ['created_at' => DB::now(-40 * 86400)], 'id = ?', [$old['id']]);
    $d = Dashboard::shop(30);
    eq(array_column($d['steps'], 0), ['view', 'bag', 'checkout', 'order', 'paid']);
    eq(array_column($d['steps'], 2), [3, 2, 1, 1, 1], 'sessions per step; only paid web orders from the period');
    eq(Dashboard::shop(0)['steps'][4][2], 2, 'all time includes the older order');
}

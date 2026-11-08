<?php
// Shop pages and JSON API: catalogue config, bag sync and restore, PDOK address, slots, gift and discount-code check, checkout + Mollie, order status,
// the Mollie webhook, notify-me, the Drop 02 list and the real "live activity" feed.
defined('DROP01') || exit;

final class ShopApi {
    public static function routes(Router $r): void {
        $r->get('/p/{slug}', fn($p) => self::page(['product' => $p['slug']]));
        $r->get('/checkout', fn() => self::page(['view' => 'checkout']));
        $r->get('/order/{number}', fn($p) => self::orderPage((int) $p['number']));
        $r->get('/shop', fn() => self::page([]));

        $r->get('/api/shop/config', fn() => Http::json(self::config()));
        $r->post('/api/cart', [self::class, 'cart']);
        $r->get('/api/cart/{id}', [self::class, 'restoreCart']);
        $r->post('/api/code-check', [self::class, 'codeCheck']);
        $r->get('/api/address', [self::class, 'address']);
        $r->get('/api/slots', fn() => Http::json(['slots' => Delivery::upcoming()]));
        $r->post('/api/gift-check', [self::class, 'giftCheck']);
        $r->post('/api/checkout', [self::class, 'checkout']);
        $r->get('/api/orders/{number}/status', [self::class, 'status']);
        $r->post('/api/orders/{number}/pay', [self::class, 'retryPay']);
        $r->post('/api/orders/{number}/reschedule', [self::class, 'reschedule']);
        $r->post('/api/mollie/webhook', [self::class, 'webhook']);
        $r->post('/api/restock', [self::class, 'restock']);
        $r->post('/api/list', [self::class, 'list']);
        $r->get('/api/activity', [self::class, 'activity']);
    }

    /** Everything the shop page needs, rendered into window.SHOP. */
    public static function config(): array {
        Stock::releaseExpired();
        Orders::expireUnpaid();
        $likes = [];
        foreach (DB::all('SELECT product_id, COUNT(*) AS n FROM votes WHERE value = 1 GROUP BY product_id') as $v) $likes[(int) $v['product_id']] = (int) $v['n'];
        $products = [];
        foreach (Products::all() as $i => $p) {
            if (!$p['active']) continue;
            $vars = array_values(array_filter($p['variants'], fn($v) => $v['active']));
            $products[] = [
                'id' => (int) $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'cat' => $p['category'] ?: '', 'price' => (int) $p['price_cents'], 'vat' => (int) $p['vat_rate'],
                'stock' => max(0, array_sum(array_map(fn($v) => (int) $v['stock'], $vars))), 'run' => (int) $p['run_size'], 'low' => (int) $p['low_stock_at'],
                'likes' => $likes[(int) $p['id']] ?? 0, 'featured' => (bool) $p['featured'], 'desc' => (string) $p['description'], 'art' => $i % 6,
                'sizes' => array_values(array_unique(array_map(fn($v) => $v['label'], $vars))),
                'colors' => array_values(array_unique(array_filter(array_map(fn($v) => $v['color_hex'], $vars)))),
                'variants' => array_map(fn($v) => ['id' => (int) $v['id'], 'size' => $v['label'], 'color' => $v['color_hex'], 'stock' => max(0, (int) $v['stock'])], $vars),
                'images' => array_map(fn($im) => ['sm' => $im['path_sm'] ?: $im['path'], 'lg' => $im['path']], $p['images']),
            ];
        }
        $b = Pricing::bundle();
        $c = Settings::get('company'); $shop = Settings::get('shop');
        return [
            'store' => Settings::get('store_name'), 'logo' => Settings::get('logo_text'),
            'products' => $products,
            'bundle' => $b ? ['name' => $b['name'], 'pct' => (int) $b['discount_pct'], 'ids' => $b['product_ids']] : null,
            'zones' => array_map(fn($z) => ['name' => $z['name'], 'from' => (int) $z['postcode_from'], 'to' => (int) $z['postcode_to'], 'fee' => (int) $z['fee_cents'],
                'free' => $z['free_from_cents'] === null ? (int) $shop['free_from_cents'] : (int) $z['free_from_cents']], Delivery::zones()),
            'freeFrom' => (int) $shop['free_from_cents'], 'maxPerItem' => (int) $shop['max_per_item'], 'slots' => Delivery::upcoming(),
            'announce' => $shop['announce'], 'endsAt' => $shop['ends_at'] ? date('c', (new DateTime($shop['ends_at'], new DateTimeZone(TZ_LOCAL)))->getTimestamp()) : null,
            'company' => ['name' => $c['legal_name'] ?: Settings::get('store_name'), 'kvk' => $c['kvk'], 'btw' => $c['btw'], 'whatsapp' => $c['whatsapp'], 'email' => $c['email']],
            'gift' => ['title' => Settings::get('gift')['title']], 'referral' => Settings::get('referral'),
            'tikkieText' => Settings::get('tikkie_text'), 'payOnline' => Mollie::configured(), 'testMode' => (bool) (Settings::get('mollie')['test_mode'] ?? true),
            'today' => (new DateTime('now', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d'),
            't' => Texts::forJs(['shop.', 'common.']),
        ];
    }

    public static function page(array $start): void {
        header('Cache-Control: no-cache');
        View::render('shop', ['cfg' => self::config(), 'start' => $start]);
    }

    /** The "It's yours." page. Needs the order token from the link, so order numbers cannot be guessed. */
    public static function orderPage(int $number): void {
        $o = Orders::byNumber($number);
        $t = (string) ($_GET['t'] ?? '');
        if (!$o || !hash_equals((string) DB::val('SELECT token FROM orders WHERE id = ?', [$o['id']]), $t)) {
            http_response_code(404);
            View::render('page', ['title' => t('shop.order.nf_title'), 'body' => '<p>' . th('shop.order.nf_text') . '</p>']);
            return;
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        View::render('shop', ['cfg' => self::config(), 'start' => ['view' => 'ok', 'order' => self::publicOrder($o, $t)]]);
    }

    /** What the customer may see about their own order. */
    public static function publicOrder(array $o, string $token): array {
        return ['number' => (int) $o['number'], 't' => $token, 'status' => $o['status'], 'paid' => $o['payment_status'] === 'paid', 'method' => $o['payment_method'],
            'method_label' => $o['payment_method_label'], 'slot' => $o['slot_label'], 'slot_date' => $o['slot_date'], 'start' => $o['start_time'], 'end' => $o['end_time'],
            'zone' => $o['zone'], 'total' => (int) $o['total_cents'], 'gift' => (bool) $o['gift_code'], 'pay_url' => $o['payment_status'] === 'unpaid' && $o['status'] === 'new' ? ($o['tikkie_url'] ?: null) : null,
            'items' => array_map(fn($i) => ['name' => $i['name_snapshot'], 'qty' => (int) $i['qty'], 'product_id' => (int) $i['product_id'], 'variant_id' => (int) $i['variant_id'], 'cents' => (int) $i['unit_price_cents'] * (int) $i['qty']], $o['items']),
            'slot_id' => (int) $o['slot_id'], 'needs_new_slot' => (bool) (int) $o['needs_new_slot'], 'can_move' => Orders::canReschedule($o),
            'address' => trim(trim($o['street'] . ' ' . $o['house_no'] . ($o['addition'] ? '-' . $o['addition'] : '')) . ($o['city'] ? ', ' . $o['city'] : ''), ', '), 'first' => explode(' ', trim((string) $o['customer_name']))[0] ?? '',
            'referral' => $o['payment_status'] === 'paid' ? DB::val('SELECT code FROM referral_codes WHERE customer_id = ?', [$o['customer_id']]) : null,
            'discount' => (int) $o['discount_cents'], 'code' => $o['discount_code'] ?: $o['referral_code'],
            'delivered' => $o['status'] === 'delivered', 'cancelled' => $o['status'] === 'cancelled', 'telegram' => Telegram::orderLink((int) $o['number'])];
    }

    public static function cart(): void {
        $in = Http::body();
        RateLimit::enforce('cart', 60, 60);
        Cart::save((string) ($in['id'] ?? ''), (array) ($in['items'] ?? []), isset($in['contact']) ? (array) $in['contact'] : null);
        Http::json(['ok' => true]);
    }

    /** "Back to your bag" from the reminder email: the saved items, which the shop puts back in the bag. */
    public static function restoreCart(array $p): void {
        RateLimit::enforce('cart-restore', 20, 600);
        $items = array_map(fn($l) => ['variant_id' => $l['variant_id'], 'qty' => min($l['qty'], $l['stock'])],
            array_values(array_filter(Cart::lines(Cart::items((string) $p['id'])), fn($l) => $l['stock'] > 0)));
        Http::json(['items' => $items]);
    }

    /** Checks a discount or friend's code against the bag. The order is checked again when it is placed. */
    public static function codeCheck(): void {
        RateLimit::enforce('code', 20, 600);
        $in = Http::body();
        $email = Waitlist::normEmail((string) ($in['email'] ?? '')) ?: null;
        $phone = Waitlist::normPhone((string) ($in['phone'] ?? ''), '+31') ?: null;
        $lines = Cart::lines((array) ($in['items'] ?? []));
        try { $c = Discounts::resolve((string) ($in['code'] ?? ''), $email, $phone, Pricing::quote($lines, Pricing::bundle(), null)['goods']); }
        catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Http::json(['code' => $c['code'], 'type' => $c['type'], 'value' => $c['value'], 'label' => $c['label'], 'kind' => $c['kind']]);
    }

    public static function address(): void {
        RateLimit::enforce('address', 40, 60);
        $pc = Delivery::normPostcode((string) ($_GET['pc'] ?? ''));
        if (!$pc || strlen($pc) !== 6) Http::error(t('err.postcode'), 422);
        $zone = Delivery::zoneFor($pc);
        $a = ($_GET['no'] ?? '') !== '' ? Pdok::lookup($pc, (string) $_GET['no'], (string) ($_GET['add'] ?? '')) : null;
        Http::json(['address' => $a, 'zone' => $zone ? ['name' => $zone['name'], 'fee' => (int) $zone['fee_cents']] : null]);
    }

    public static function giftCheck(): void {
        RateLimit::enforce('gift', 12, 60);
        $in = Http::body();
        $email = Waitlist::normEmail((string) ($in['email'] ?? ''));
        $phone = Waitlist::normPhone((string) ($in['phone'] ?? ''), '+31');
        Http::json(['gift' => (bool) Waitlist::giftFor($email, $phone)]);
    }

    public static function checkout(): void {
        $in = Http::body();
        RateLimit::enforce('checkout', 12, 600);
        RateLimit::enforce('checkout-day', 10, 86400);   // one network can't place endless unpaid orders
        if (!empty($in['website'])) Http::error(t('err.try_again'), 422);
        $method = (string) ($in['method'] ?? 'ideal');
        if (!in_array($method, ['ideal', 'card', 'applepay', 'tikkie'], true)) $method = 'ideal';
        if ($method !== 'tikkie' && !Mollie::configured()) Http::error(t('err.online_off'), 422);
        try {
            $o = Orders::create(['email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '', 'name' => $in['name'] ?? '', 'postcode' => $in['postcode'] ?? '', 'house_no' => $in['house_no'] ?? '',
                'addition' => $in['addition'] ?? '', 'street' => $in['street'] ?? '', 'city' => $in['city'] ?? '', 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null,
                'slot_id' => $in['slot_id'] ?? 0, 'items' => $in['items'] ?? [], 'method' => $method, 'channel' => 'web', 'marketing' => !empty($in['marketing']), 'code' => (string) ($in['code'] ?? '')]);
        } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        if (!empty($in['sid'])) Analytics::record((string) $in['sid'], [['type' => 'payment_started', 'path' => '/checkout', 'value' => $method]]);
        if (!empty($in['cart_id'])) Cart::ordered((string) $in['cart_id'], (int) $o['id']);
        $track = Orders::trackUrl($o);
        if ($method === 'tikkie') {
            Orders::event((int) $o['id'], 'awaiting_tikkie', []);
            Http::json(['ok' => true, 'redirect' => $track]);
        }
        try { $url = Orders::startMolliePayment($o, $method); }
        catch (Throwable $e) {
            Log::error('mollie create: ' . $e->getMessage());
            Orders::cancel((int) $o['id'], 'Payment could not start');
            Http::error(t('err.pay_start'), 502);
        }
        Http::json(['ok' => true, 'redirect' => $url]);
    }

    /** Polled by the return page until the webhook has arrived. */
    public static function status(array $p): void {
        $o = Orders::byNumber((int) $p['number']);
        if (!$o || !hash_equals((string) DB::val('SELECT token FROM orders WHERE id = ?', [$o['id']]), (string) ($_GET['t'] ?? ''))) Http::error('Not found', 404);
        // the webhook may be late (or blocked on a test machine): ask Mollie directly once in a while
        if (Mollie::enabled() && $o['payment_status'] === 'unpaid' && $o['status'] === 'new' && RateLimit::hit('status-poll', 6, 60, 'o' . $o['id'])) {
            $pid = DB::val("SELECT provider_id FROM payments WHERE order_id = ? AND provider = 'mollie' ORDER BY id DESC LIMIT 1", [$o['id']]);
            if ($pid) { try { Orders::handleMollieWebhook($pid); $o = Orders::byNumber((int) $p['number']); } catch (Throwable $e) { Log::error('poll: ' . $e->getMessage()); } }
        }
        Http::json(self::publicOrder($o, (string) $_GET['t']));
    }

    /** The customer moves their delivery to another window from the order page (until the cut-off). */
    public static function reschedule(array $p): void {
        $o = Orders::byNumber((int) $p['number']);
        $in = Http::body();
        if (!$o || !hash_equals((string) DB::val('SELECT token FROM orders WHERE id = ?', [$o['id']]), (string) ($in['t'] ?? ''))) Http::error('Not found', 404);
        RateLimit::enforce('reschedule', 10, 600);
        try { Orders::customerReschedule((int) $o['id'], (int) ($in['slot_id'] ?? 0)); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Http::json(self::publicOrder(Orders::byNumber((int) $p['number']), (string) $in['t']));
    }

    /** "Try again" on the return page after a cancelled or failed payment. */
    public static function retryPay(array $p): void {
        $o = Orders::byNumber((int) $p['number']);
        $in = Http::body();
        if (!$o || !hash_equals((string) DB::val('SELECT token FROM orders WHERE id = ?', [$o['id']]), (string) ($in['t'] ?? ''))) Http::error('Not found', 404);
        if ($o['payment_status'] !== 'unpaid' || $o['status'] !== 'new') Http::error(t('err.pay_closed'), 422);
        RateLimit::enforce('retry-pay', 5, 600);
        try { Http::json(['redirect' => Orders::startMolliePayment(DB::one('SELECT * FROM orders WHERE id = ?', [$o['id']]), null)]); }
        catch (Throwable $e) { Http::error(t('err.pay_start'), 502); }
    }

    /** Mollie posts only an id. We answer 200 quickly and always re-fetch the payment from Mollie. */
    public static function webhook(): void {
        $id = (string) ($_POST['id'] ?? (Http::body()['id'] ?? ''));
        if (!Mollie::enabled() || !preg_match('/^tr_[A-Za-z0-9]+$/', $id)) { http_response_code(200); exit; }
        try { Orders::handleMollieWebhook($id); }
        catch (Throwable $e) { Log::error('webhook ' . $id . ': ' . $e->getMessage()); http_response_code(500); exit; }   // 500 → Mollie retries later
        http_response_code(200);
        exit;
    }

    public static function restock(): void {
        RateLimit::enforce('restock', 8, 600);
        $in = Http::body();
        $pid = (int) DB::val('SELECT id FROM products WHERE slug = ?', [(string) ($in['product'] ?? '')]);
        if (!$pid) Http::error('Unknown product', 404);
        $c = trim((string) ($in['contact'] ?? ''));
        $email = Waitlist::normEmail($c); $phone = $email ? null : Waitlist::normPhone($c, '+31');
        if (!$email && !$phone) Http::error(t('err.contact'), 422);
        if (!DB::val('SELECT 1 FROM restock_requests WHERE product_id = ? AND (email = ? OR phone = ?) AND notified_at IS NULL', [$pid, (string) $email, (string) $phone]))
            DB::insert('restock_requests', ['product_id' => $pid, 'email' => $email, 'phone' => $phone, 'created_at' => DB::now()]);
        if (!empty($in['sid'])) Analytics::record((string) $in['sid'], [['type' => 'notify_me', 'product' => $in['product']]]);
        Http::json(['ok' => true]);
    }

    public static function list(): void {
        RateLimit::enforce('list', 6, 600);
        $email = Waitlist::normEmail((string) (Http::body()['email'] ?? ''));
        if (!$email) Http::error(t('err.email'), 422);
        if (!DB::val('SELECT 1 FROM list_signups WHERE email = ?', [$email])) DB::insert('list_signups', ['email' => $email, 'source' => 'shop', 'created_at' => DB::now()]);
        Http::json(['ok' => true]);
    }

    /** Real paid orders in the last 24 h: district and product only, never names. Empty → the pill stays hidden. */
    public static function activity(): void {
        header('Cache-Control: public, max-age=60');
        $rows = DB::all("SELECT o.paid_at, z.name AS zone, (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id ORDER BY oi.unit_price_cents DESC LIMIT 1) AS pid
            FROM orders o JOIN addresses a ON a.id = o.address_id JOIN delivery_zones z ON z.id = a.zone_id
            WHERE o.payment_status = 'paid' AND o.paid_at >= ? AND o.is_demo = 0 ORDER BY o.paid_at DESC LIMIT 12", [DB::now(-86400)]);
        $out = [];
        foreach ($rows as $r) {
            $p = DB::one('SELECT slug, name FROM products WHERE id = ?', [$r['pid']]);
            if ($p) $out[] = ['zone' => $r['zone'], 'product' => $p['slug'], 'name' => $p['name'], 'min' => max(1, (int) round((time() - strtotime($r['paid_at'])) / 60))];
        }
        Http::json(['items' => $out]);
    }
}

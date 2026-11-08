<?php
// Orders: creation with stock holds, Mollie payments (idempotent), paid flow (sale, slot, gift, invoice, messages),
// status changes with a timeline, cancellation and unpaid timeouts.
defined('DROP01') || exit;

final class Orders {
    public const STATUS = ['new' => 'New', 'paid' => 'Paid', 'packing' => 'Packing', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered',
        'failed_delivery' => 'Not home', 'cancelled' => 'Cancelled', 'returned' => 'Returned'];
    public const FLOW = ['new', 'paid', 'packing', 'out_for_delivery', 'delivered'];
    public const METHODS = ['ideal' => 'iDEAL', 'applepay' => 'Apple Pay', 'creditcard' => 'Card', 'card' => 'Card / Apple Pay', 'bancontact' => 'Bancontact',
        'tikkie' => 'Tikkie', 'cash' => 'Cash on delivery', 'manual' => 'Paid manually', 'link' => 'Payment link'];

    /**
     * Creates an order and holds the stock. Throws RuntimeException with a customer-friendly message.
     * $in: email, phone, name, postcode, house_no, addition, street, city, lat, lng, slot_id, items[{variant_id,qty}],
     *      method (ideal|card|applepay|tikkie|cash|manual|link), channel, marketing, notes, code (discount or friend's code), admin (bool: allow closed slots)
     */
    public static function create(array $in): array {
        Stock::releaseExpired();
        $email = $in['email'] ? Waitlist::normEmail((string) $in['email']) : null;
        $phone = $in['phone'] ? Waitlist::normPhone((string) $in['phone'], '+31') : null;
        if (empty($in['admin']) && (!$email || !$phone)) throw new RuntimeException(t(!$email ? 'err.email' : 'err.phone'));
        $lines = Cart::lines((array) ($in['items'] ?? []));
        if (!$lines) throw new RuntimeException(t('err.bag_empty'));
        foreach ($lines as $l) if ($l['qty'] > $l['stock']) throw new RuntimeException($l['stock'] ? t('err.only_left', ['n' => $l['stock'], 'product' => $l['name']]) : t('err.sold_out', ['product' => $l['name']]));
        if (empty($in['admin'])) self::checkLimits($lines, $email, $phone);
        $pc = Delivery::normPostcode((string) ($in['postcode'] ?? ''));
        if (!$pc || strlen($pc) !== 6) throw new RuntimeException(t('err.postcode'));
        $zone = Delivery::zoneFor($pc);
        if (!$zone) throw new RuntimeException(t('err.no_zone'));
        $houseNo = preg_replace('/\D/', '', (string) ($in['house_no'] ?? ''));
        if ($houseNo === '') throw new RuntimeException(t('err.house'));
        $slot = Delivery::slot((int) ($in['slot_id'] ?? 0));
        if (!$slot) throw new RuntimeException(t('err.slot'));
        if (empty($in['admin']) && !$slot['available']) throw new RuntimeException(t('err.slot_full'));
        $method = (string) ($in['method'] ?? 'ideal');
        if (!isset(self::METHODS[$method])) $method = 'ideal';

        $giftSignup = Waitlist::giftFor($email, $phone);
        $bundle = Pricing::bundle();
        $code = null;
        if (trim((string) ($in['code'] ?? '')) !== '') $code = Discounts::resolve((string) $in['code'], $email, $phone, Pricing::quote($lines, $bundle, $zone)['goods']);
        $q = Pricing::quote($lines, $bundle, $zone, (bool) $giftSignup, $code);
        $shop = Settings::get('shop');
        $offline = in_array($method, ['tikkie', 'cash', 'manual', 'link'], true);
        $holdMin = $method === 'tikkie' || $method === 'link' ? (int) $shop['tikkie_timeout_hours'] * 60 : ($offline ? 7 * 24 * 60 : (int) $shop['unpaid_timeout_min']);

        $order = DB::tx(function () use ($in, $email, $phone, $lines, $pc, $zone, $houseNo, $slot, $method, $giftSignup, $q, $holdMin, $code) {
            $cid = Customers::findOrCreate($email, $phone, mb_substr(trim((string) ($in['name'] ?? '')), 0, 160), (string) ($in['channel'] ?? 'web'), !empty($in['marketing']));
            $addr = ['customer_id' => $cid, 'postcode' => $pc, 'house_no' => $houseNo, 'addition' => mb_substr(strtoupper(trim((string) ($in['addition'] ?? ''))), 0, 10) ?: null,
                'street' => mb_substr(trim((string) ($in['street'] ?? '')), 0, 160) ?: null, 'city' => mb_substr(trim((string) ($in['city'] ?? '')), 0, 80) ?: 'Groningen',
                'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null, 'zone_id' => (int) $zone['id']];
            $aid = DB::insert('addresses', $addr);
            $num = max((int) Settings::get('shop')['order_start'], (int) DB::val('SELECT COALESCE(MAX(number), 0) FROM orders') + 1);
            $id = DB::insert('orders', [
                'number' => $num, 'token' => Crypto::token(16), 'customer_id' => $cid, 'address_id' => $aid, 'slot_id' => $slot['id'],
                'status' => 'new', 'payment_status' => 'unpaid', 'payment_method' => $method,
                'subtotal_cents' => $q['subtotal'], 'discount_cents' => $q['discount'], 'delivery_cents' => $q['delivery'], 'total_cents' => $q['total'], 'vat_cents' => $q['vat_total'],
                'gift_code' => $giftSignup['gift_code'] ?? null, 'code_discount_cents' => $q['code_discount'],
                'discount_code' => $code && $code['kind'] === 'code' ? $code['code'] : null, 'referral_code' => $code && $code['kind'] === 'referral' ? $code['code'] : null, 'channel' => in_array($in['channel'] ?? 'web', ['web', 'whatsapp', 'instagram', 'phone', 'other'], true) ? ($in['channel'] ?? 'web') : 'web',
                'marketing_consent' => !empty($in['marketing']) ? 1 : 0, 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000) ?: null,
                'created_at' => DB::now(), 'expires_at' => DB::now($holdMin * 60),
            ]);
            foreach ($lines as $l) DB::insert('order_items', ['order_id' => $id, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'name_snapshot' => $l['name'],
                'unit_price_cents' => $l['unit_cents'], 'unit_cost_cents' => $l['cost_cents'], 'qty' => $l['qty'], 'vat_rate' => $l['vat_rate']]);
            self::event($id, 'created', ['channel' => $in['channel'] ?? 'web', 'method' => $method, 'total' => $q['total']] + ($q['bundle'] ? ['bundle' => $q['bundle']] : []) + ($giftSignup ? ['gift' => $giftSignup['gift_code']] : []) + ($code ? ['code' => $code['code'], 'code_off' => $q['code_discount']] : []));
            return DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
        });
        Stock::reserve((int) $order['id'], $lines, $holdMin);
        return $order;
    }

    /**
     * Shop orders only, so nobody can hold a whole drop with unpaid orders: at most `max_per_item` of one product (all sizes together),
     * and one unpaid Tikkie / payment-link order at a time per email or phone (online payments time out within the hour anyway).
     */
    public static function checkLimits(array $lines, ?string $email, ?string $phone): void {
        $max = (int) (Settings::get('shop')['max_per_item'] ?? 0);
        if ($max > 0) {
            $per = [];
            foreach ($lines as $l) $per[$l['product_id']] = ($per[$l['product_id']] ?? 0) + $l['qty'];
            foreach ($per as $pid => $n) if ($n > $max)
                throw new RuntimeException(t('err.max_item', ['n' => $max, 'product' => (string) DB::val('SELECT name FROM products WHERE id = ?', [$pid])]));
        }
        $open = DB::val("SELECT 1 FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.status = 'new' AND o.payment_status = 'unpaid' AND o.payment_method IN ('tikkie', 'link') AND (o.expires_at IS NULL OR o.expires_at > ?) AND (c.email = ? OR c.phone = ?) LIMIT 1",
            [DB::now(), (string) $email, (string) $phone]);
        if ($open) throw new RuntimeException(t('err.unpaid_open'));
    }

    // ---------------------------------------------------------------- payments

    /** Creates the Mollie payment for an order and returns the hosted checkout URL. $method: ideal|applepay|card|null. */
    public static function startMolliePayment(array $order, ?string $method): string {
        $mm = ['ideal' => 'ideal', 'applepay' => 'applepay', 'card' => ['creditcard', 'applepay'], 'creditcard' => 'creditcard'][$method] ?? null;
        $base = Http::baseUrl();
        $p = Mollie::createPayment((int) $order['total_cents'], Settings::get('store_name') . ' order #' . $order['number'],
            $base . '/order/' . $order['number'] . '?t=' . $order['token'], $base . '/api/mollie/webhook', ['order_id' => (int) $order['id'], 'number' => (int) $order['number']], $mm);
        DB::insert('payments', ['order_id' => $order['id'], 'provider' => 'mollie', 'provider_id' => $p['id'], 'status' => $p['status'] ?? 'open', 'amount_cents' => $order['total_cents'],
            'method' => $p['method'] ?? (is_string($mm) ? $mm : null), 'raw' => json_encode($p), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        $url = Mollie::checkoutUrl($p);
        if (!$url) throw new RuntimeException('Mollie did not return a checkout page.');
        DB::update('orders', ['pay_url' => $url], 'id = ?', [$order['id']]);
        self::event((int) $order['id'], 'payment_started', ['provider' => 'mollie', 'id' => $p['id']]);
        return $url;
    }

    /** Webhook: never trust the POST, always re-fetch. Safe to call any number of times. */
    public static function handleMollieWebhook(string $paymentId): void {
        $p = Mollie::getPayment($paymentId);
        $pay = DB::one("SELECT * FROM payments WHERE provider = 'mollie' AND provider_id = ?", [$p['id']]);
        $orderId = (int) ($pay['order_id'] ?? ($p['metadata']['order_id'] ?? 0));
        if (!$orderId || !DB::val('SELECT 1 FROM orders WHERE id = ?', [$orderId])) return;
        $amountOk = isset($p['amount']['value']) && (int) round((float) $p['amount']['value'] * 100) === (int) DB::val('SELECT total_cents FROM orders WHERE id = ?', [$orderId]);
        if ($pay) DB::update('payments', ['status' => $p['status'], 'method' => $p['method'] ?? $pay['method'], 'raw' => json_encode($p), 'updated_at' => DB::now()], 'id = ?', [$pay['id']]);
        else DB::insert('payments', ['order_id' => $orderId, 'provider' => 'mollie', 'provider_id' => $p['id'], 'status' => $p['status'], 'amount_cents' => (int) round((float) ($p['amount']['value'] ?? 0) * 100),
            'method' => $p['method'] ?? null, 'raw' => json_encode($p), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        if ($p['status'] === 'paid' && $amountOk) self::markPaid($orderId, 'mollie', $p['method'] ?? null);
        elseif (in_array($p['status'], ['failed', 'canceled', 'expired'], true)) {
            $o = DB::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            // only cancel if this was the latest attempt; a customer may have retried with a new payment
            $latest = DB::val("SELECT provider_id FROM payments WHERE order_id = ? AND provider = 'mollie' ORDER BY id DESC LIMIT 1", [$orderId]);
            if ($o['payment_status'] === 'unpaid' && $o['status'] === 'new' && $latest === $p['id']) {
                self::event($orderId, 'payment_' . $p['status'], ['id' => $p['id']]);
                if ($p['status'] !== 'canceled') self::cancel($orderId, 'Payment ' . $p['status']);
            }
        } elseif ($p['status'] === 'paid' && !$amountOk) self::event($orderId, 'payment_amount_mismatch', ['id' => $p['id'], 'amount' => $p['amount'] ?? null]);
    }

    /** The paid flow. Idempotent: a second call does nothing. */
    public static function markPaid(int $id, string $provider, ?string $method = null, ?int $adminId = null): bool {
        $done = DB::tx(function () use ($id, $provider, $method, $adminId) {
            $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
            if (!$o || $o['payment_status'] === 'paid') return false;
            $wasCancelled = $o['status'] === 'cancelled';
            $okStock = Stock::sellOrder($id);
            DB::update('orders', ['payment_status' => 'paid', 'paid_at' => DB::now(), 'expires_at' => null, 'status' => in_array($o['status'], ['new', 'cancelled'], true) ? 'paid' : $o['status'],
                'payment_method' => $method && isset(self::METHODS[$method]) ? $method : $o['payment_method'],
                'cash_by' => $method === 'cash' && $adminId ? $adminId : null], 'id = ?', [$id]);   // who holds the cash (Delivery → cash)
            // slot: re-check capacity now that it really counts
            $s = DB::one('SELECT * FROM delivery_slots WHERE id = ?', [$o['slot_id']]);
            if ($s) {
                DB::q('UPDATE delivery_slots SET booked = booked + 1 WHERE id = ?', [$s['id']]);
                if ((int) $s['booked'] + 1 > (int) $s['capacity']) { DB::update('orders', ['needs_new_slot' => 1], 'id = ?', [$id]); self::event($id, 'needs_new_slot', ['slot' => $s['id']]); }
            }
            // waitlist gift: redeemed now, unless another order got it first
            if ($o['gift_code']) {
                $ok = DB::q('UPDATE waitlist_signups SET gift_redeemed_order_id = ? WHERE gift_code = ? AND gift_redeemed_order_id IS NULL', [$id, $o['gift_code']])->rowCount();
                if (!$ok && (int) DB::val('SELECT gift_redeemed_order_id FROM waitlist_signups WHERE gift_code = ?', [$o['gift_code']]) !== $id) {
                    DB::update('orders', ['gift_code' => null], 'id = ?', [$id]); self::event($id, 'gift_already_used', ['code' => $o['gift_code']]);
                }
            }
            Discounts::redeem($o);
            self::event($id, 'paid', ['provider' => $provider, 'method' => $method], $adminId);
            if ($wasCancelled) self::event($id, 'paid_after_cancel', []);
            if (!$okStock) self::event($id, 'oversold', []);
            return true;
        });
        if (!$done) return false;
        Referrals::ensure((int) DB::val('SELECT customer_id FROM orders WHERE id = ?', [$id]));
        try { Invoice::create($id); } catch (Throwable $e) { Log::error("invoice $id: " . $e->getMessage()); }
        try { self::sendConfirmation($id); } catch (Throwable $e) { Log::error("confirmation $id: " . $e->getMessage()); }
        return true;
    }

    public static function sendConfirmation(int $id): void {
        $o = self::get($id);
        if (!$o['email']) return;
        $inv = DB::one('SELECT * FROM invoices WHERE order_id = ?', [$id]);
        $mid = Messages::queueEmail('order_confirmation', $o['email'], ['order' => $o, 'url' => self::trackUrl($o)], ['order_id' => $id, 'customer_id' => $o['customer_id']],
            $inv ? ['Invoice-' . $inv['number'] . '.pdf' => Invoice::file($inv)] : []);
        Messages::deliverLater($mid);
        if ($o['phone']) Messages::logWhatsapp($id, (int) $o['customer_id'], $o['phone'], 'order_confirmation', self::waText($o, 'order_confirmation'));
    }

    public static function trackUrl(array $o): string { return Http::baseUrl() . '/order/' . $o['number'] . '?t=' . $o['token']; }

    /** Prefilled WhatsApp texts the owner can send with one tap. */
    public static function waText(array $o, string $what, array $v = []): string {
        $x = ['first' => explode(' ', trim((string) $o['customer_name']))[0] ?: t('email.there'), 'store' => Settings::get('store_name'), 'number' => $o['number'],
            'slot' => $o['slot_label'], 'eta' => $v['eta'] ?? $o['slot_label'], 'total' => eur((int) $o['total_cents']),
            'url' => $what === 'payment_link' ? ($o['tikkie_url'] ?: ($o['pay_url'] ?: self::trackUrl($o))) : self::trackUrl($o)];
        $k = in_array($what, ['order_confirmation', 'payment_link', 'out_for_delivery', 'on_my_way', 'delivered', 'failed_delivery', 'rescheduled'], true) ? $what : 'other';
        return t('wa.' . $k, $x);
    }

    // ---------------------------------------------------------------- status

    public static function setStatus(int $id, string $status, ?int $adminId = null): void {
        if (!isset(self::STATUS[$status]) || $status === 'cancelled') throw new InvalidArgumentException('Unknown status');
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new RuntimeException('Order not found');
        if ($o['status'] === $status) return;
        $upd = ['status' => $status];
        if ($status === 'delivered') $upd['delivered_at'] = DB::now();
        if ($status === 'out_for_delivery') $upd['out_at'] = DB::now();
        DB::update('orders', $upd, 'id = ?', [$id]);
        self::event($id, 'status', ['from' => $o['status'], 'to' => $status], $adminId);
        if ($status === 'delivered') { try { Referrals::reward($id); } catch (Throwable $e) { Log::error("referral reward $id: " . $e->getMessage()); } }
    }

    public static function cancel(int $id, string $why, ?int $adminId = null): void {
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o || $o['status'] === 'cancelled') return;
        Stock::releaseOrder($id, 'cancel');
        if ($o['payment_status'] === 'paid') {       // a paid order: give the stock and slot back too
            foreach (DB::all("SELECT variant_id, qty FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL", [$id]) as $i) Stock::adjust((int) $i['variant_id'], (int) $i['qty'], 'return', 'cancel #' . $o['number']);
            DB::q('UPDATE delivery_slots SET booked = CASE WHEN booked > 0 THEN booked - 1 ELSE 0 END WHERE id = ?', [$o['slot_id']]);
            if ($o['gift_code']) DB::q('UPDATE waitlist_signups SET gift_redeemed_order_id = NULL WHERE gift_code = ? AND gift_redeemed_order_id = ?', [$o['gift_code'], $id]);
            Discounts::release($o);
        }
        DB::update('orders', ['status' => 'cancelled', 'expires_at' => null], 'id = ?', [$id]);
        self::event($id, 'cancelled', ['why' => $why], $adminId);
    }

    /** Cron + lazy: unpaid orders past their time are cancelled and their stock released. */
    public static function expireUnpaid(): int {
        $n = 0;
        foreach (DB::all("SELECT id FROM orders WHERE status = 'new' AND payment_status = 'unpaid' AND expires_at IS NOT NULL AND expires_at < ?", [DB::now()]) as $r) {
            self::cancel((int) $r['id'], 'Not paid in time'); $n++;
        }
        return $n;
    }

    public const SELF_CHANGES = 3;

    /** May the customer move this order to another window from the order page? */
    public static function canReschedule(array $o): bool {
        if (empty(Settings::get('delivery')['self_reschedule'])) return false;
        if (!in_array($o['status'], ['new', 'paid', 'packing', 'failed_delivery'], true)) return false;
        if ((int) DB::val("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND type = 'rescheduled_by_customer'", [$o['id']]) >= self::SELF_CHANGES) return false;
        if ($o['status'] === 'failed_delivery' || (int) $o['needs_new_slot']) return true;
        $s = $o['slot_id'] ? Delivery::slot((int) $o['slot_id']) : null;
        return !$s || $s['open'];   // until the current window's cut-off: after that the bag is being packed for it
    }

    /** The customer picked another window. Throws RuntimeException with a message for them. */
    public static function customerReschedule(int $id, int $slotId): void {
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o || !self::canReschedule($o)) throw new RuntimeException(t('err.move_closed'));
        if ((int) $o['slot_id'] === $slotId && !(int) $o['needs_new_slot'] && $o['status'] !== 'failed_delivery') throw new RuntimeException(t('err.move_same'));
        $s = Delivery::slot($slotId);
        if (!$s || !$s['available']) throw new RuntimeException(t('err.move_full'));
        if ($o['status'] === 'failed_delivery') Courier::reschedule($id, $slotId, null);
        else {
            self::changeSlot($id, $slotId, null);
            DB::update('orders', ['route_pos' => null], 'id = ?', [$id]);
            Courier::notify($id, 'rescheduled');
        }
        self::event($id, 'rescheduled_by_customer', ['to' => $s['label']]);
    }

    public static function changeSlot(int $id, int $slotId, ?int $adminId): void {
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]); $s = DB::one('SELECT * FROM delivery_slots WHERE id = ?', [$slotId]);
        if (!$o || !$s) throw new RuntimeException('Not found');
        DB::tx(function () use ($o, $s, $id, $adminId) {
            if ($o['payment_status'] === 'paid') {
                DB::q('UPDATE delivery_slots SET booked = CASE WHEN booked > 0 THEN booked - 1 ELSE 0 END WHERE id = ?', [$o['slot_id']]);
                DB::q('UPDATE delivery_slots SET booked = booked + 1 WHERE id = ?', [$s['id']]);
            }
            DB::update('orders', ['slot_id' => $s['id'], 'needs_new_slot' => 0], 'id = ?', [$id]);
            self::event($id, 'slot_changed', ['to' => Delivery::label($s)], $adminId);
        });
    }

    public static function event(int $id, string $type, array $data = [], ?int $adminId = null): void {
        DB::insert('order_events', ['order_id' => $id, 'type' => $type, 'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'by_admin_id' => $adminId ?? ($_SESSION['aid'] ?? null), 'created_at' => DB::now()]);
    }

    // ---------------------------------------------------------------- reads

    public static function get(int $id): ?array {
        $o = DB::one('SELECT o.*, c.name AS customer_name, c.email, c.phone, c.marketing_consent AS c_consent, a.postcode, a.house_no, a.addition, a.street, a.city, a.lat, a.lng, z.name AS zone,
                s.date AS slot_date, s.start_time, s.end_time
            FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN addresses a ON a.id = o.address_id LEFT JOIN delivery_zones z ON z.id = a.zone_id
            LEFT JOIN delivery_slots s ON s.id = o.slot_id WHERE o.id = ?', [$id]);
        if (!$o) return null;
        $o['items'] = DB::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
        $o['slot_label'] = $o['slot_date'] ? Delivery::label(['date' => $o['slot_date'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time']]) : '';
        $o['payment_method_label'] = self::METHODS[$o['payment_method']] ?? ucfirst((string) $o['payment_method']);
        $o['status_label'] = self::STATUS[$o['status']] ?? $o['status'];
        return $o;
    }

    public static function byNumber(int $number): ?array {
        $id = DB::val('SELECT id FROM orders WHERE number = ?', [$number]);
        return $id ? self::get((int) $id) : null;
    }

    /** Full sheet for the admin: order + timeline + messages + payments + invoice + customer history. */
    public static function sheet(int $id): ?array {
        $o = self::get($id);
        if (!$o) return null;
        $o['events'] = DB::all('SELECT e.*, a.name AS by_name FROM order_events e LEFT JOIN admins a ON a.id = e.by_admin_id WHERE e.order_id = ? ORDER BY e.id', [$id]);
        $o['messages'] = DB::all('SELECT id, channel, template, to_addr, subject, status, error, created_at, sent_at FROM messages WHERE order_id = ? ORDER BY id', [$id]);
        $o['payments'] = DB::all('SELECT provider, provider_id, status, amount_cents, method, created_at FROM payments WHERE order_id = ? ORDER BY id', [$id]);
        $o['invoice'] = DB::one('SELECT number, created_at FROM invoices WHERE order_id = ?', [$id]);
        $o['customer_orders'] = (int) DB::val("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND payment_status = 'paid'", [$o['customer_id']]);
        $o['customer_spent'] = (int) DB::val("SELECT COALESCE(SUM(total_cents), 0) FROM orders WHERE customer_id = ? AND payment_status = 'paid'", [$o['customer_id']]);
        $o['vat_split'] = Invoice::split($o);
        $o['has_chat'] = $o['customer_id'] && DB::val('SELECT 1 FROM conversations WHERE customer_id = ?', [$o['customer_id']]);
        $o['wa'] = [];
        foreach (['order_confirmation', 'payment_link', 'out_for_delivery', 'delivered', 'failed_delivery', 'other'] as $t) $o['wa'][$t] = $o['phone'] ? Messages::waLink($o['phone'], self::waText($o, $t)) : null;
        unset($o['token']);
        $o['track_url'] = self::trackUrl(DB::one('SELECT number, token FROM orders WHERE id = ?', [$id]));
        return $o;
    }
}

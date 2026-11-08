<?php
// Admin JSON API for the CRM: Today numbers, orders (list, sheet, status, payment, Tikkie, slot, notes, invoice, manual orders),
// customers (segments, sheet, GDPR export/delete), stock adjustments with history, bundles and delivery slots.
defined('DROP01') || exit;

final class CrmApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/today/shop', function () { Auth::require('today'); Http::json(Reports::today() + ['attention' => Reports::attention()]); });

        $r->get('/api/admin/orders', [self::class, 'orders']);
        $r->get('/api/admin/orders/{id}', fn($p) => self::sheet((int) $p['id']));
        $r->post('/api/admin/orders/new', [self::class, 'newOrder']);
        $r->post('/api/admin/orders/{id}/status', [self::class, 'status']);
        $r->post('/api/admin/orders/{id}/cancel', [self::class, 'cancel']);
        $r->post('/api/admin/orders/{id}/paid', [self::class, 'markPaid']);
        $r->post('/api/admin/orders/{id}/tikkie', [self::class, 'tikkie']);
        $r->post('/api/admin/orders/{id}/paylink', [self::class, 'payLink']);
        $r->post('/api/admin/orders/{id}/slot', [self::class, 'slot']);
        $r->post('/api/admin/orders/{id}/note', [self::class, 'note']);
        $r->post('/api/admin/orders/{id}/wa', [self::class, 'waSent']);
        $r->get('/api/admin/orders/{id}/invoice', [self::class, 'invoice']);
        $r->get('/api/admin/slots', function () { Auth::require('orders'); Http::json(['slots' => Delivery::upcoming()]); });
        $r->get('/api/admin/address', function () { Auth::require('orders'); $pc = Delivery::normPostcode((string) ($_GET['pc'] ?? '')); $z = $pc ? Delivery::zoneFor($pc) : null;
            Http::json(['address' => $pc ? Pdok::lookup($pc, (string) ($_GET['no'] ?? ''), (string) ($_GET['add'] ?? '')) : null, 'zone' => $z ? $z['name'] : null]); });

        $r->get('/api/admin/customers', [self::class, 'customers']);
        $r->get('/api/admin/customers/{id}', [self::class, 'customer']);
        $r->post('/api/admin/customers/{id}/note', [self::class, 'customerNote']);
        $r->get('/api/admin/customers/{id}/export', [self::class, 'export']);
        $r->post('/api/admin/customers/{id}/delete', [self::class, 'deleteCustomer']);

        $r->post('/api/admin/stock/adjust', [self::class, 'adjust']);
        $r->get('/api/admin/stock/{vid}/history', [self::class, 'history']);
        $r->get('/api/admin/bundles', function () { Auth::require('stock'); Http::json(['bundles' => self::bundles()]); });
        $r->post('/api/admin/bundles/save', [self::class, 'saveBundle']);
    }

    private static function write(string $area): array { $u = Auth::require($area); Auth::checkCsrf(); return $u; }

    // ---------------------------------------------------------------- orders

    public static function orders(): void {
        Auth::require('orders');
        Stock::releaseExpired(); Orders::expireUnpaid();
        $st = (string) ($_GET['status'] ?? 'all'); $q = trim((string) ($_GET['q'] ?? ''));
        $off = max(0, (int) ($_GET['offset'] ?? 0)); $lim = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
        $where = ['1=1']; $p = [];
        if (isset(Orders::STATUS[$st])) { $where[] = 'o.status = ?'; $p[] = $st; }
        if ($q !== '') {
            $like = '%' . mb_strtolower(ltrim($q, '#')) . '%';
            $where[] = "(CAST(o.number AS CHAR) LIKE ? OR LOWER(COALESCE(c.name,'')) LIKE ? OR LOWER(COALESCE(a.street,'')) LIKE ? OR LOWER(COALESCE(c.email,'')) LIKE ? OR COALESCE(c.phone,'') LIKE ?)";
            array_push($p, $like, $like, $like, $like, '%' . preg_replace('/\D/', '', $q) . '%');
        }
        $w = implode(' AND ', $where);
        $base = "FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN addresses a ON a.id = o.address_id LEFT JOIN delivery_zones z ON z.id = a.zone_id LEFT JOIN delivery_slots s ON s.id = o.slot_id";
        $rows = DB::all("SELECT o.id, o.number, o.status, o.payment_status, o.payment_method, o.total_cents, o.created_at, o.channel, o.needs_new_slot, c.name, a.street, a.house_no, z.name AS zone,
            s.date AS slot_date, s.start_time, s.end_time $base WHERE $w ORDER BY o.id DESC LIMIT $lim OFFSET $off", $p);
        foreach ($rows as &$o) {
            $o['items'] = array_map(fn($i) => $i['qty'] . '× ' . $i['name_snapshot'], DB::all('SELECT qty, name_snapshot FROM order_items WHERE order_id = ?', [$o['id']]));
            $o['slot'] = $o['slot_date'] ? Delivery::label(['date' => $o['slot_date'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time']]) : '';
            $o['method'] = Orders::METHODS[$o['payment_method']] ?? $o['payment_method'];
        }
        $counts = ['all' => (int) DB::val('SELECT COUNT(*) FROM orders')];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $c) $counts[$c['status']] = (int) $c['n'];
        Http::json(['rows' => $rows, 'counts' => $counts, 'labels' => Orders::STATUS, 'total' => (int) DB::val("SELECT COUNT(*) $base WHERE $w", $p)]);
    }

    public static function sheet(int $id): void {
        Auth::require('orders');
        $o = Orders::sheet($id);
        if (!$o) Http::error('Order not found', 404);
        Http::json(['order' => $o, 'labels' => Orders::STATUS, 'flow' => Orders::FLOW, 'tikkieOk' => true]);
    }

    public static function status(array $p): void {
        $u = self::write('orders');
        $st = (string) (Http::body()['status'] ?? '');
        try { Orders::setStatus((int) $p['id'], $st, (int) $u['id']); } catch (Throwable $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('order.status', 'order', $p['id'], ['status' => $st]);
        $wa = in_array($st, ['out_for_delivery', 'delivered', 'failed_delivery'], true) ? Courier::notify((int) $p['id'], $st) : null;
        Http::json(['ok' => true, 'order' => Orders::sheet((int) $p['id']), 'wa' => $wa]);
    }

    public static function cancel(array $p): void {
        $u = self::write('orders');
        Orders::cancel((int) $p['id'], mb_substr((string) (Http::body()['why'] ?? 'Cancelled by the shop'), 0, 200), (int) $u['id']);
        Auth::audit('order.cancel', 'order', $p['id']);
        Http::json(['ok' => true, 'order' => Orders::sheet((int) $p['id'])]);
    }

    public static function markPaid(array $p): void {
        $u = self::write('orders');
        $m = (string) (Http::body()['method'] ?? 'manual');
        if (!in_array($m, ['cash', 'manual', 'tikkie'], true)) $m = 'manual';
        Orders::markPaid((int) $p['id'], $m, $m, (int) $u['id']);
        Auth::audit('order.paid', 'order', $p['id'], ['method' => $m]);
        Http::json(['ok' => true, 'order' => Orders::sheet((int) $p['id'])]);
    }

    /** "Paste Tikkie link → send via WhatsApp": stores the link and returns the prefilled WhatsApp message. */
    public static function tikkie(array $p): void {
        self::write('orders');
        $url = trim((string) (Http::body()['url'] ?? ''));
        if (!preg_match('#^https://[\w.-]*(tikkie\.me|abnamro\.nl|tikkie\.nl)/\S+$#i', $url)) Http::error('Paste the Tikkie link, it starts with https://tikkie.me/…', 422);
        DB::update('orders', ['tikkie_url' => $url, 'payment_method' => 'tikkie'], 'id = ?', [(int) $p['id']]);
        $o = Orders::sheet((int) $p['id']);
        Orders::event((int) $p['id'], 'tikkie_link', []);
        if ($o['phone']) Messages::logWhatsapp((int) $o['id'], (int) $o['customer_id'], $o['phone'], 'payment_link', Orders::waText($o, 'payment_link'));
        Auth::audit('order.tikkie', 'order', $p['id']);
        Http::json(['ok' => true, 'wa' => $o['wa']['payment_link'], 'order' => Orders::sheet((int) $p['id'])]);
    }

    /** New Mollie payment link for an unpaid order: emailed to the customer and offered as a WhatsApp message. */
    public static function payLink(array $p): void {
        self::write('orders');
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [(int) $p['id']]);
        if (!$o || $o['payment_status'] !== 'unpaid' || $o['status'] === 'cancelled') Http::error('This order is paid or cancelled.', 422);
        if (!Mollie::configured()) Http::error('Add your Mollie key in Settings → Payments first.', 422);
        try { $url = Orders::startMolliePayment($o, null); } catch (Throwable $e) { Http::error('Mollie: ' . $e->getMessage(), 502); }
        DB::update('orders', ['expires_at' => DB::now((int) Settings::get('shop')['tikkie_timeout_hours'] * 3600)], 'id = ?', [$o['id']]);
        $s = Orders::sheet((int) $o['id']);
        if ($s['email']) Messages::deliverLater(Messages::queueEmail('payment_link', $s['email'], ['order' => $s, 'url' => $url], ['order_id' => $o['id'], 'customer_id' => $o['customer_id']]));
        if ($s['phone']) Messages::logWhatsapp((int) $o['id'], (int) $o['customer_id'], $s['phone'], 'payment_link', Orders::waText($s, 'payment_link'));
        Auth::audit('order.paylink', 'order', $o['id']);
        Http::json(['ok' => true, 'url' => $url, 'wa' => Orders::sheet((int) $o['id'])['wa']['payment_link'], 'order' => Orders::sheet((int) $o['id'])]);
    }

    public static function slot(array $p): void {
        $u = self::write('orders');
        try { Orders::changeSlot((int) $p['id'], (int) (Http::body()['slot_id'] ?? 0), (int) $u['id']); } catch (Throwable $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('order.slot', 'order', $p['id']);
        Http::json(['ok' => true, 'order' => Orders::sheet((int) $p['id'])]);
    }

    public static function note(array $p): void {
        $u = self::write('orders');
        $t = mb_substr(trim((string) (Http::body()['text'] ?? '')), 0, 1000);
        if ($t === '') Http::error('Write a note first.', 422);
        Orders::event((int) $p['id'], 'note', ['text' => $t], (int) $u['id']);
        Http::json(['ok' => true, 'order' => Orders::sheet((int) $p['id'])]);
    }

    /** The owner tapped a WhatsApp button: log it on the timeline. */
    public static function waSent(array $p): void {
        $u = self::write('orders');
        $t = preg_replace('/[^a-z_]/', '', (string) (Http::body()['template'] ?? 'other'));
        DB::q("UPDATE messages SET status = 'sent', sent_at = ? WHERE order_id = ? AND channel = 'whatsapp' AND template = ? AND status = 'ready'", [DB::now(), (int) $p['id'], $t]);
        Orders::event((int) $p['id'], 'whatsapp_opened', ['template' => $t], (int) $u['id']);
        Http::json(['ok' => true]);
    }

    public static function invoice(array $p): void {
        Auth::require('orders');
        $inv = DB::one('SELECT * FROM invoices WHERE order_id = ?', [(int) $p['id']]);
        if (!$inv) {
            if (DB::val('SELECT payment_status FROM orders WHERE id = ?', [(int) $p['id']]) !== 'paid') Http::error('Invoices are made when the order is paid.', 422);
            $inv = Invoice::create((int) $p['id']);
        }
        $f = Invoice::file($inv);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="Invoice-' . $inv['number'] . '.pdf"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    /** "+ New order" for WhatsApp, Instagram or phone orders. */
    public static function newOrder(): void {
        $u = self::write('orders');
        $in = Http::body();
        $pay = (string) ($in['payment'] ?? 'tikkie');
        if (!in_array($pay, ['link', 'tikkie', 'cash', 'manual'], true)) $pay = 'tikkie';
        if ($pay === 'link' && !Mollie::configured()) Http::error('A Mollie payment link needs your Mollie key (Settings → Payments). Choose Tikkie or cash instead.', 422);
        if (empty($in['email']) && empty($in['phone'])) Http::error('Add a phone number or email for the customer.', 422);
        try {
            $o = Orders::create(['email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '', 'name' => $in['name'] ?? '', 'postcode' => $in['postcode'] ?? '', 'house_no' => $in['house_no'] ?? '',
                'addition' => $in['addition'] ?? '', 'street' => $in['street'] ?? '', 'city' => $in['city'] ?? '', 'lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null,
                'slot_id' => $in['slot_id'] ?? 0, 'items' => $in['items'] ?? [], 'method' => $pay, 'channel' => $in['channel'] ?? 'whatsapp', 'notes' => $in['notes'] ?? '', 'admin' => true]);
        } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Orders::event((int) $o['id'], 'created_by_admin', [], (int) $u['id']);
        $extra = [];
        if ($pay === 'manual') Orders::markPaid((int) $o['id'], 'manual', 'manual', (int) $u['id']);
        if ($pay === 'link') {
            try { $extra['url'] = Orders::startMolliePayment($o, null); } catch (Throwable $e) { Http::error('Order created (#' . $o['number'] . ') but Mollie failed: ' . $e->getMessage(), 502); }
            $s = Orders::sheet((int) $o['id']);
            if ($s['email']) Messages::deliverLater(Messages::queueEmail('payment_link', $s['email'], ['order' => $s, 'url' => $extra['url']], ['order_id' => $o['id'], 'customer_id' => $o['customer_id']]));
            $extra['wa'] = $s['wa']['payment_link'];
        }
        Auth::audit('order.new', 'order', $o['id'], ['payment' => $pay]);
        Http::json(['ok' => true, 'id' => (int) $o['id'], 'order' => Orders::sheet((int) $o['id'])] + $extra);
    }

    // ---------------------------------------------------------------- customers

    public static function customers(): void {
        Auth::require('customers');
        $seg = (string) ($_GET['segment'] ?? 'all');
        if (!isset(Customers::SEGMENTS[$seg])) $seg = 'all';
        Http::json(Customers::list($seg, trim((string) ($_GET['q'] ?? '')), max(0, (int) ($_GET['offset'] ?? 0)), 30) + ['labels' => Customers::SEGMENTS]);
    }

    public static function customer(array $p): void {
        Auth::require('customers');
        $c = Customers::get((int) $p['id']);
        if (!$c) Http::error('Not found', 404);
        $c['wa'] = $c['phone'] ? Messages::waLink($c['phone'], 'Hi ' . (explode(' ', (string) $c['name'])[0] ?: 'there') . '! ') : null;
        $c['chats'] = DB::all("SELECT channel, MAX(id) AS id FROM conversations WHERE customer_id = ? GROUP BY channel", [$c['id']]);
        Http::json(['customer' => $c]);
    }

    public static function customerNote(array $p): void {
        self::write('customers');
        DB::update('customers', ['notes' => mb_substr((string) (Http::body()['notes'] ?? ''), 0, 4000)], 'id = ?', [(int) $p['id']]);
        Auth::audit('customer.note', 'customer', $p['id']);
        Http::json(['ok' => true]);
    }

    public static function export(array $p): void {
        Auth::require('customers');
        Auth::audit('customer.export', 'customer', $p['id']);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="customer-' . (int) $p['id'] . '-data.json"');
        echo json_encode(Customers::export((int) $p['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function deleteCustomer(array $p): void {
        $u = self::write('customers');
        if ($u['role'] !== 'owner') Http::error('Only the owner can delete customer data.', 403);
        Customers::delete((int) $p['id']);
        Auth::audit('customer.delete', 'customer', $p['id']);
        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- stock

    public static function adjust(): void {
        self::write('stock');
        $in = Http::body();
        $vid = (int) ($in['variant_id'] ?? 0); $delta = (int) ($in['delta'] ?? 0);
        if (!$vid || !$delta) Http::error('Choose a variant and a number.', 422);
        try { Stock::adjust($vid, $delta, (string) ($in['reason'] ?? 'adjust'), (string) ($in['note'] ?? '')); } catch (Throwable $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('stock.adjust', 'variant', $vid, ['delta' => $delta, 'reason' => $in['reason'] ?? 'adjust']);
        Http::json(['ok' => true, 'products' => Products::all()]);
    }

    public static function history(array $p): void {
        Auth::require('stock');
        Http::json(['rows' => DB::all('SELECT m.delta, m.reason, m.ref, m.created_at, a.name AS by_name FROM stock_movements m LEFT JOIN admins a ON a.id = m.by_admin_id WHERE m.variant_id = ? ORDER BY m.id DESC LIMIT 100', [(int) $p['vid']])]);
    }

    private static function bundles(): array {
        $out = DB::all('SELECT * FROM bundles ORDER BY id');
        foreach ($out as &$b) $b['product_ids'] = array_map('intval', array_column(DB::all('SELECT product_id FROM bundle_items WHERE bundle_id = ?', [$b['id']]), 'product_id'));
        return $out;
    }

    public static function saveBundle(): void {
        self::write('stock');
        $in = Http::body();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: 'The full drop';
        $pct = max(0, min(90, (int) ($in['discount_pct'] ?? 15)));
        $ids = array_values(array_unique(array_map('intval', (array) ($in['product_ids'] ?? []))));
        if (!empty($in['active']) && count($ids) < 2) Http::error('A bundle needs at least two products.', 422);
        $id = (int) ($in['id'] ?? 0);
        DB::tx(function () use (&$id, $name, $pct, $ids, $in) {
            $row = ['name' => $name, 'discount_pct' => $pct, 'active' => !empty($in['active']) ? 1 : 0];
            if ($id) DB::update('bundles', $row, 'id = ?', [$id]); else $id = DB::insert('bundles', $row);
            if (!empty($in['active'])) DB::q('UPDATE bundles SET active = 0 WHERE id <> ?', [$id]);   // the shop shows one bundle
            DB::q('DELETE FROM bundle_items WHERE bundle_id = ?', [$id]);
            foreach ($ids as $pid) DB::insert('bundle_items', ['bundle_id' => $id, 'product_id' => $pid]);
        });
        Auth::audit('bundle.save', 'bundle', $id);
        Http::json(['ok' => true, 'bundles' => self::bundles()]);
    }
}

<?php
// Admin JSON API for Delivery and courier mode: routes per window, start route, stop actions (delivered, not home, on my way,
// reschedule, photo proof), drag-reorder, courier assignment and the zone overview. Couriers only reach their own routes.
defined('DROP01') || exit;

final class DeliveryApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/delivery', [self::class, 'day']);
        $r->post('/api/admin/delivery/{slot}/start', [self::class, 'start']);
        $r->post('/api/admin/delivery/{slot}/reorder', [self::class, 'reorder']);
        $r->post('/api/admin/delivery/{slot}/reset', [self::class, 'reset']);
        $r->post('/api/admin/delivery/{slot}/courier', [self::class, 'assign']);
        $r->post('/api/admin/stops/{id}/delivered', [self::class, 'delivered']);
        $r->post('/api/admin/stops/{id}/failed', [self::class, 'failed']);
        $r->post('/api/admin/stops/{id}/onway', [self::class, 'onWay']);
        $r->post('/api/admin/stops/{id}/reschedule', [self::class, 'reschedule']);
        $r->get('/api/admin/stops/{id}/proof', [self::class, 'proof']);
    }

    private static function write(): array { $u = Auth::require('delivery'); Auth::checkCsrf(); return $u; }

    /** Couriers may only touch their own windows. */
    private static function slotFor(array $u, int $slotId): array {
        $s = DB::one('SELECT * FROM delivery_slots WHERE id = ?', [$slotId]);
        if (!$s) Http::error('Window not found.', 404);
        if ($u['role'] === 'courier' && (int) $s['courier_id'] !== (int) $u['id'] && !($s['courier_id'] === null && Route::soleCourier() === (int) $u['id']))
            Http::error('This route belongs to another courier.', 403);
        return $s;
    }
    private static function stopFor(array $u, int $id): int {
        $slot = DB::val('SELECT slot_id FROM orders WHERE id = ?', [$id]);
        if (!$slot) Http::error('Order not found.', 404);
        if (!Route::mayWork($u, $id)) Http::error('This stop belongs to another courier.', 403);
        return (int) $slot;
    }

    /** One day: the day chips, a route per window, couriers to assign and orders per zone this week. */
    public static function day(): void {
        $u = Auth::require('delivery');
        Stock::releaseExpired(); Orders::expireUnpaid();
        $tz = new DateTimeZone(TZ_LOCAL);
        $today = new DateTime('today', $tz);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : $today->format('Y-m-d');
        $courier = $u['role'] === 'courier' ? (int) $u['id'] : null;
        $days = [];
        for ($i = 0, $d = clone $today; $i <= (int) Settings::get('delivery')['days_ahead']; $i++, $d->modify('+1 day')) {
            $ds = $d->format('Y-m-d');
            $n = (int) DB::val('SELECT COUNT(*) FROM orders o JOIN delivery_slots s ON s.id = o.slot_id WHERE s.date = ? AND ' . Route::STOP_SQL
                . ($courier ? ' AND (s.courier_id = ? OR (s.courier_id IS NULL AND ? = 1))' : ''), $courier ? [$ds, $courier, (int) (Route::soleCourier() === $courier)] : [$ds]);
            $days[] = ['date' => $ds, 'label' => ($i < 2 ? Delivery::dayName($ds) . ' · ' : '') . $d->format('D j'), 'stops' => $n];
        }
        $failed = DB::all("SELECT o.id, o.number, c.name, a.street, a.house_no FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN addresses a ON a.id = o.address_id
            JOIN delivery_slots s ON s.id = o.slot_id WHERE o.status = 'failed_delivery' AND s.date < ? ORDER BY o.id", [$date]);
        Http::json(['date' => $date, 'days' => $days, 'routes' => Route::forDate($date, $courier),
            'couriers' => $courier ? [] : DB::all("SELECT id, name FROM admins WHERE role IN ('courier','owner','manager') AND active = 1 ORDER BY role = 'courier' DESC, name"),
            'zones' => $courier ? [] : Route::zonesWeek($date), 'fees' => $courier ? [] : Delivery::zones(), 'free_from' => (int) Settings::get('shop')['free_from_cents'],
            'earlier_failed' => $courier ? [] : $failed, 'courier_view' => (bool) $courier,
            'cash' => $courier ? Cash::open($courier) : (Auth::can('orders', $u) ? Cash::open() : [])]);
    }

    public static function start(array $p): void {
        $u = self::write(); $s = self::slotFor($u, (int) $p['slot']);
        try { $wa = Courier::start((int) $s['id'], (int) $u['id']); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('route.start', 'slot', $s['id'], ['stops' => count($wa)]);
        Http::json(['ok' => true, 'started' => count($wa), 'route' => Route::forSlot((int) $s['id'])]);
    }

    public static function reorder(array $p): void {
        $u = self::write(); $s = self::slotFor($u, (int) $p['slot']);
        $ids = array_values(array_filter(array_map('intval', (array) (Http::body()['ids'] ?? []))));
        if (!$ids) Http::error('Nothing to reorder.', 422);
        Route::reorder((int) $s['id'], $ids);
        Auth::audit('route.reorder', 'slot', $s['id']);
        Http::json(['ok' => true, 'route' => Route::forSlot((int) $s['id'])]);
    }

    public static function reset(array $p): void {
        $u = self::write(); $s = self::slotFor($u, (int) $p['slot']);
        Route::resetOrder((int) $s['id']);
        Auth::audit('route.reset', 'slot', $s['id']);
        Http::json(['ok' => true, 'route' => Route::forSlot((int) $s['id'])]);
    }

    public static function assign(array $p): void {
        $u = self::write();
        if ($u['role'] === 'courier') Http::error('Ask the owner to change couriers.', 403);
        $s = self::slotFor($u, (int) $p['slot']);
        $cid = (int) (Http::body()['courier_id'] ?? 0);
        if ($cid && !DB::val('SELECT 1 FROM admins WHERE id = ? AND active = 1', [$cid])) Http::error('That person is not on the team.', 422);
        DB::update('delivery_slots', ['courier_id' => $cid ?: null], 'id = ?', [$s['id']]);
        Auth::audit('route.courier', 'slot', $s['id'], ['courier' => $cid]);
        Http::json(['ok' => true, 'route' => Route::forSlot((int) $s['id'])]);
    }

    /** Multipart (photo) or JSON: collected=1 when the courier took the payment at the door. */
    public static function delivered(array $p): void {
        $u = self::write(); $id = (int) $p['id']; $slot = self::stopFor($u, $id);
        $in = Http::body();
        try { $wa = Courier::delivered($id, (int) $u['id'], !empty($in['collected']), $_FILES['photo'] ?? null); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('stop.delivered', 'order', $id, ['collected' => !empty($in['collected'])]);
        Http::json(['ok' => true, 'wa' => $wa, 'route' => Route::forSlot($slot)]);
    }

    public static function failed(array $p): void {
        $u = self::write(); $id = (int) $p['id']; $slot = self::stopFor($u, $id);
        $why = mb_substr(trim((string) (Http::body()['why'] ?? '')), 0, 200) ?: 'Nobody home';
        try { $wa = Courier::failed($id, $why, (int) $u['id']); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('stop.failed', 'order', $id, ['why' => $why]);
        Http::json(['ok' => true, 'wa' => $wa, 'route' => Route::forSlot($slot)]);
    }

    public static function onWay(array $p): void {
        $u = self::write(); $id = (int) $p['id']; $slot = self::stopFor($u, $id);
        try { $wa = Courier::onMyWay($id, (int) $u['id']); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Http::json(['ok' => true, 'wa' => $wa, 'route' => Route::forSlot($slot)]);
    }

    public static function reschedule(array $p): void {
        $u = self::write();
        if ($u['role'] === 'courier') Http::error('Ask the owner to pick a new window.', 403);
        $id = (int) $p['id']; $old = self::stopFor($u, $id);
        try { $wa = Courier::reschedule($id, (int) (Http::body()['slot_id'] ?? 0), (int) $u['id']); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('stop.reschedule', 'order', $id);
        Http::json(['ok' => true, 'wa' => $wa, 'route' => Route::forSlot($old)]);
    }

    public static function proof(array $p): void {
        $u = Auth::require();
        if (!Auth::can('delivery', $u) && !Auth::can('orders', $u)) Http::error('Your role does not have access to this.', 403);
        if ($u['role'] === 'courier' && !Route::mayWork($u, (int) $p['id'])) Http::error('This stop belongs to another courier.', 403);
        $f = Courier::proofFile((array) DB::one('SELECT proof_path FROM orders WHERE id = ?', [(int) $p['id']]));
        if (!$f) Http::error('No photo for this delivery.', 404);
        header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($f));
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}

<?php
// Admin JSON API for daily operations: the packing list per delivery day, shelf codes set while packing, and cash handovers from couriers.
defined('DROP01') || exit;

final class OpsApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/packing', [self::class, 'packing']);
        $r->post('/api/admin/shelf', [self::class, 'shelf']);
        $r->get('/api/admin/cash', [self::class, 'cash']);
        $r->post('/api/admin/cash/handover', [self::class, 'handover']);
    }

    public static function packing(): void {
        Auth::require('orders');
        Stock::releaseExpired(); Orders::expireUnpaid();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : (new DateTime('today', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d');
        Http::json(Packing::day($date));
    }

    /** Sets where a variant lies (e.g. A1), straight from the packing screen. */
    public static function shelf(): void {
        Auth::require('stock'); Auth::checkCsrf();
        $in = Http::body();
        try { $s = Products::setShelf((int) ($in['variant_id'] ?? 0), (string) ($in['shelf'] ?? '')); }
        catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('stock.shelf', 'variant', (int) $in['variant_id'], ['shelf' => $s]);
        Http::json(['ok' => true, 'shelf' => $s]);
    }

    /** Couriers see their own open cash; the owner and managers see everyone's plus the handover history. */
    public static function cash(): void {
        $u = Auth::require('delivery');
        if ($u['role'] === 'courier' || !Auth::can('orders', $u)) Http::json(['open' => Cash::open((int) $u['id']), 'history' => []]);
        Http::json(['open' => Cash::open(), 'history' => Cash::history()]);
    }

    public static function handover(): void {
        $u = Auth::require('orders'); Auth::checkCsrf();
        if ($u['role'] === 'courier') Http::error('Only the owner confirms cash.', 403);
        $in = Http::body();
        try { $h = Cash::handover((int) ($in['courier_id'] ?? 0), (array) ($in['order_ids'] ?? []), Products::cents((string) ($in['received'] ?? '')), (string) ($in['note'] ?? ''), (int) $u['id']); }
        catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('cash.handover', 'cash_handover', $h['id'], ['courier' => $h['courier_id'], 'expected' => $h['expected_cents'], 'received' => $h['received_cents']]);
        Http::json(['ok' => true, 'handover' => $h, 'open' => Cash::open(), 'history' => Cash::history()]);
    }
}

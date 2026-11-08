<?php
// Admin JSON API for Money → Margins: the per-product profit report, the cost settings, packaging materials and what-if prices.
defined('DROP01') || exit;

final class MoneyApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/money/margins', [self::class, 'margins']);
        $r->get('/api/admin/money/quote', [self::class, 'quote']);
        $r->post('/api/admin/money/costs', [self::class, 'saveCosts']);
        $r->post('/api/admin/money/material', [self::class, 'saveMaterial']);
        $r->post('/api/admin/money/material/delete', [self::class, 'deleteMaterial']);
        $r->post('/api/admin/money/packaging', [self::class, 'packaging']);
        $r->post('/api/admin/money/price', [self::class, 'price']);
    }

    private static function days(): int {
        $d = (int) ($_GET['days'] ?? 30);
        return in_array($d, [7, 30, 90, 365, 0], true) ? $d : 30;
    }

    public static function margins(): void {
        Auth::require('money');
        Http::json(Margins::report(self::days()));
    }

    /** What-if: the unit economics of a product at another price and/or purchase cost. */
    public static function quote(): void {
        Auth::require('money');
        $p = DB::one('SELECT * FROM products WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        if (!$p) Http::error('Product not found.', 404);
        $price = isset($_GET['price']) && $_GET['price'] !== '' ? Products::cents((string) $_GET['price']) : null;
        $cost = isset($_GET['cost']) && $_GET['cost'] !== '' ? Products::cents((string) $_GET['cost']) : null;
        Http::json(['unit' => Margins::unit($p, Margins::context(), $price, $cost)]);
    }

    public static function saveCosts(): void {
        Auth::require('money'); Auth::checkCsrf();
        $in = Http::body();
        $c = [
            'delivery_cents' => max(0, Products::cents($in['delivery'] ?? 0)), 'fee_cents' => max(0, Products::cents($in['fee'] ?? 0)),
            'fee_pct' => max(0, min(20, round((float) str_replace(',', '.', (string) ($in['fee_pct'] ?? 0)), 2))),
            'fixed_month_cents' => max(0, Products::cents($in['fixed_month'] ?? 0)), 'target_pct' => max(0, min(90, (int) ($in['target_pct'] ?? 30))),
            'reorder_days' => max(1, min(120, (int) ($in['reorder_days'] ?? 14))), 'cover_days' => max(1, min(365, (int) ($in['cover_days'] ?? 30))),
        ];
        Settings::set('costs', $c);
        Auth::audit('money.costs', 'settings', null, $c);
        Http::json(['ok' => true]);
    }

    public static function saveMaterial(): void {
        Auth::require('money'); Auth::checkCsrf();
        try { $id = Margins::saveMaterial(Http::body()); } catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('money.material', 'material', $id, Http::body());
        Http::json(['ok' => true, 'id' => $id]);
    }

    public static function deleteMaterial(): void {
        Auth::require('money'); Auth::checkCsrf();
        $id = (int) (Http::body()['id'] ?? 0);
        Margins::deleteMaterial($id);
        Auth::audit('money.material.delete', 'material', $id);
        Http::json(['ok' => true]);
    }

    public static function packaging(): void {
        Auth::require('money'); Auth::checkCsrf();
        $in = Http::body();
        try { Margins::setPackaging((int) ($in['product_id'] ?? 0), (array) ($in['items'] ?? [])); } catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('money.packaging', 'product', (int) $in['product_id'], $in['items'] ?? []);
        Http::json(['ok' => true]);
    }

    /** "Use this price": changes the shop price (needs Stock access too, since it changes the shop). */
    public static function price(): void {
        Auth::require('money'); Auth::require('stock'); Auth::checkCsrf();
        $in = Http::body();
        $id = (int) ($in['product_id'] ?? 0); $price = Products::cents($in['price'] ?? 0);
        $old = DB::val('SELECT price_cents FROM products WHERE id = ?', [$id]);
        if ($old === null) Http::error('Product not found.', 404);
        if ($price <= 0) Http::error('Enter a price above €0.', 422);
        DB::update('products', ['price_cents' => $price], 'id = ?', [$id]);
        Auth::audit('product.price', 'product', $id, ['from' => (int) $old, 'to' => $price]);
        Http::json(['ok' => true]);
    }
}

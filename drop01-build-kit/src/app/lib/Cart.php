<?php
// Cart: turns browser bag items [{variant_id, qty}] into priced lines from the database, keeps the server copy of each bag,
// and sends one abandoned-cart reminder (only with marketing consent from the checkout).
defined('DROP01') || exit;

final class Cart {
    /** Resolves items to lines. Prices, names and stock always come from the database, never from the browser. */
    public static function lines(array $items): array {
        $out = []; $seen = [];
        foreach (array_slice($items, 0, 40) as $it) {
            $vid = (int) ($it['variant_id'] ?? 0); $qty = max(0, min(20, (int) ($it['qty'] ?? 0)));
            if (!$vid || !$qty) continue;
            $r = DB::one('SELECT v.id AS variant_id, v.label, v.color_hex, v.stock, v.active AS v_active, p.id AS product_id, p.name, p.slug, p.price_cents, p.cost_cents, p.vat_rate, p.active
                FROM variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$vid]);
            if (!$r || !$r['active'] || !$r['v_active']) continue;
            if (isset($seen[$vid])) { $out[$seen[$vid]]['qty'] = min(20, $out[$seen[$vid]]['qty'] + $qty); continue; }
            $label = $r['label'] !== 'Default' && $r['label'] !== 'One size' ? $r['label'] : '';
            $seen[$vid] = count($out);
            $out[] = ['product_id' => (int) $r['product_id'], 'variant_id' => $vid, 'name' => $r['name'] . ($label ? ' · ' . $label : ''), 'unit_cents' => (int) $r['price_cents'],
                'cost_cents' => (int) $r['cost_cents'], 'qty' => $qty, 'vat_rate' => (int) $r['vat_rate'], 'stock' => (int) $r['stock'], 'slug' => $r['slug']];
        }
        return $out;
    }

    public static function validId(string $id): bool { return (bool) preg_match('/^[a-f0-9-]{16,40}$/i', $id); }

    /**
     * Saves the bag server-side (session cart mirrored from localStorage).
     * $contact (from the checkout): ['email','phone','name','consent']. Without it the stored contact stays as it is.
     */
    public static function save(string $id, array $items, ?array $contact = null): void {
        if (!self::validId($id)) return;
        $clean = array_values(array_filter(array_map(fn($i) => ['variant_id' => (int) ($i['variant_id'] ?? 0), 'qty' => max(0, min(20, (int) ($i['qty'] ?? 0)))], array_slice($items, 0, 40)), fn($i) => $i['variant_id'] && $i['qty']));
        $row = ['id' => strtolower($id), 'items' => json_encode($clean), 'updated_at' => DB::now()];
        if ($contact !== null) {
            $consent = !empty($contact['consent']);
            // without consent we keep no contact details on the cart at all
            $row += ['email' => $consent ? (Waitlist::normEmail((string) ($contact['email'] ?? '')) ?: null) : null,
                'phone' => $consent ? (Waitlist::normPhone((string) ($contact['phone'] ?? ''), '+31') ?: null) : null,
                'name' => $consent ? (mb_substr(trim((string) ($contact['name'] ?? '')), 0, 160) ?: null) : null, 'consent' => $consent ? 1 : 0];
        }
        DB::upsert('carts', $row, ['id']);
    }

    /** The checkout went through: no reminder for this bag, and a reminded bag counts as recovered. */
    public static function ordered(string $id, int $orderId): void {
        if (!self::validId($id)) return;
        DB::update('carts', ['ordered_at' => DB::now()], 'id = ?', [strtolower($id)]);
        DB::q('UPDATE abandoned_carts SET recovered_order_id = ? WHERE session_id = ? AND recovered_order_id IS NULL', [$orderId, strtolower($id)]);
    }

    /** Items of a saved bag, for the "Back to your bag" link in the reminder email. */
    public static function items(string $id): array {
        if (!self::validId($id)) return [];
        return json_decode((string) DB::val('SELECT items FROM carts WHERE id = ?', [strtolower($id)]), true) ?: [];
    }

    /**
     * Cron: one reminder per bag, 1–48 h after it was last touched, only with consent, never twice to the same email within 7 days,
     * and not if that person ordered since. Returns the number of reminders queued.
     */
    public static function remindAbandoned(): int {
        if (!Settings::get('marketing')['abandoned_cart']) return 0;
        $rows = DB::all("SELECT * FROM carts WHERE consent = 1 AND email IS NOT NULL AND ordered_at IS NULL AND updated_at < ? AND updated_at > ?
            AND NOT EXISTS (SELECT 1 FROM abandoned_carts a WHERE a.session_id = carts.id) ORDER BY updated_at LIMIT 50", [DB::now(-3600), DB::now(-48 * 3600)]);
        $n = 0;
        foreach ($rows as $c) {
            $lines = array_values(array_filter(self::lines(json_decode((string) $c['items'], true) ?: []), fn($l) => $l['stock'] > 0));
            $skip = !$lines
                || DB::val('SELECT 1 FROM abandoned_carts WHERE email = ? AND reminded_at > ?', [$c['email'], DB::now(-7 * 86400)])
                || DB::val('SELECT 1 FROM orders o JOIN customers cu ON cu.id = o.customer_id WHERE cu.email = ? AND o.created_at > ?', [$c['email'], $c['updated_at']]);
            // the row is written either way, so each bag is looked at once
            DB::insert('abandoned_carts', ['session_id' => $c['id'], 'email' => $c['email'], 'phone' => $c['phone'], 'cart' => $c['items'], 'reminded_at' => $skip ? null : DB::now(), 'created_at' => DB::now()]);
            if ($skip) continue;
            $url = Http::baseUrl() . '/?bag=' . rawurlencode($c['id']) . '&utm_source=email&utm_medium=reminder&utm_campaign=abandoned_cart';
            Messages::queueEmail('abandoned_cart', $c['email'], ['name' => $c['name'], 'lines' => $lines, 'url' => $url, 'unsubscribe' => Messages::unsubscribeEmailUrl($c['email'])], ['customer_id' => self::customerId($c['email'])]);
            $n++;
        }
        return $n;
    }

    private static function customerId(string $email): ?int {
        $id = DB::val('SELECT id FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]);
        return $id ? (int) $id : null;
    }

    /** Numbers and the latest reminders for Marketing → Carts. */
    public static function stats(): array {
        $rec = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_cents), 0) AS revenue FROM abandoned_carts a JOIN orders o ON o.id = a.recovered_order_id
            WHERE a.reminded_at IS NOT NULL AND o.payment_status IN ('paid','partially_refunded')");
        $recent = DB::all('SELECT a.id, a.email, a.cart, a.reminded_at, a.recovered_order_id, o.number, o.total_cents, o.payment_status FROM abandoned_carts a
            LEFT JOIN orders o ON o.id = a.recovered_order_id WHERE a.reminded_at IS NOT NULL ORDER BY a.id DESC LIMIT 30');
        foreach ($recent as &$r) {
            $lines = self::lines(json_decode((string) $r['cart'], true) ?: []);
            $r['items'] = array_map(fn($l) => $l['qty'] . '× ' . $l['name'], $lines);
            $r['value'] = array_sum(array_map(fn($l) => $l['unit_cents'] * $l['qty'], $lines));
            unset($r['cart']);
        }
        return ['reminded' => (int) DB::val('SELECT COUNT(*) FROM abandoned_carts WHERE reminded_at IS NOT NULL'),
            'recovered' => (int) $rec['n'], 'revenue' => (int) $rec['revenue'],
            'waiting' => (int) DB::val('SELECT COUNT(*) FROM carts WHERE consent = 1 AND email IS NOT NULL AND ordered_at IS NULL AND updated_at > ? AND NOT EXISTS (SELECT 1 FROM abandoned_carts a WHERE a.session_id = carts.id)', [DB::now(-48 * 3600)]),
            'recent' => $recent];
    }
}

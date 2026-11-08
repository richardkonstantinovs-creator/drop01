<?php
// Back-in-stock: when a sold-out product has stock again, everyone who tapped "Notify me" hears about it once —
// by email automatically, or as a WhatsApp click-to-chat message the owner sends from Marketing.
defined('DROP01') || exit;

final class Restock {
    /** Sends the pending notifications for products that are available again. Cheap when there is nothing to do. Returns the count. */
    public static function notify(): int {
        $rows = DB::all('SELECT r.*, p.name, p.slug FROM restock_requests r JOIN products p ON p.id = r.product_id
            WHERE r.notified_at IS NULL AND p.active = 1 AND (SELECT COALESCE(SUM(v.stock), 0) FROM variants v WHERE v.product_id = p.id AND v.active = 1 AND v.stock > 0) > 0
            ORDER BY r.id LIMIT 200');
        $n = 0; $store = Settings::get('store_name');
        foreach ($rows as $r) {
            $url = Http::baseUrl() . '/p/' . rawurlencode($r['slug']) . '?utm_source=' . ($r['email'] ? 'email' : 'whatsapp') . '&utm_campaign=back_in_stock';
            if ($r['email']) {
                Messages::queueEmail('back_in_stock', $r['email'], ['product' => $r['name'], 'url' => $url], ['customer_id' => self::customerId($r['email'], null)]);
            } elseif ($r['phone']) {
                Messages::logWhatsapp(null, self::customerId(null, $r['phone']), $r['phone'], 'back_in_stock', "Hi! {$r['name']} is back at $store. Grab yours before it's gone: $url");
            } else continue;
            DB::update('restock_requests', ['notified_at' => DB::now()], 'id = ?', [$r['id']]);
            $n++;
        }
        if ($n) Messages::flushLater();
        return $n;
    }

    private static function customerId(?string $email, ?string $phone): ?int {
        $id = $email ? DB::val('SELECT id FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]) : DB::val('SELECT id FROM customers WHERE phone = ? ORDER BY id LIMIT 1', [$phone]);
        return $id ? (int) $id : null;
    }

    /** Marketing → Back in stock: who waits for what, WhatsApp messages still to send, and what went out lately. */
    public static function overview(): array {
        $waiting = DB::all("SELECT p.id, p.name, p.slug, COUNT(r.id) AS people, SUM(CASE WHEN r.email IS NOT NULL THEN 1 ELSE 0 END) AS emails,
                (SELECT COALESCE(SUM(v.stock), 0) FROM variants v WHERE v.product_id = p.id AND v.active = 1 AND v.stock > 0) AS stock
            FROM restock_requests r JOIN products p ON p.id = r.product_id WHERE r.notified_at IS NULL GROUP BY p.id, p.name, p.slug ORDER BY people DESC");
        $wa = DB::all("SELECT id, to_addr, body, created_at FROM messages WHERE channel = 'whatsapp' AND template = 'back_in_stock' AND status = 'ready' ORDER BY id DESC LIMIT 100");
        foreach ($wa as &$m) { $b = json_decode((string) $m['body'], true) ?: []; $m['text'] = $b['text'] ?? ''; $m['link'] = $b['link'] ?? ''; unset($m['body']); }
        $sent = DB::all('SELECT p.name, COUNT(r.id) AS people, MAX(r.notified_at) AS at FROM restock_requests r JOIN products p ON p.id = r.product_id
            WHERE r.notified_at IS NOT NULL AND r.notified_at > ? GROUP BY p.id, p.name ORDER BY at DESC LIMIT 20', [DB::now(-30 * 86400)]);
        return ['waiting' => $waiting, 'whatsapp' => $wa, 'sent' => $sent];
    }
}

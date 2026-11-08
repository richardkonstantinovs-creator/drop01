<?php
// Customers: find-or-create by email/phone, segments, stats, GDPR export and delete (orders are kept anonymised for tax law).
defined('DROP01') || exit;

final class Customers {
    public static function findOrCreate(?string $email, ?string $phone, string $name, string $source, bool $consent): int {
        $c = null;
        if ($email) $c = DB::one('SELECT * FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]);
        if (!$c && $phone) $c = DB::one('SELECT * FROM customers WHERE phone = ? ORDER BY id LIMIT 1', [$phone]);
        if ($c) {
            $upd = array_filter(['name' => $name ?: null, 'email' => $c['email'] ?: $email, 'phone' => $c['phone'] ?: $phone]);
            if ($consent && !$c['marketing_consent']) $upd += ['marketing_consent' => 1, 'consent_at' => DB::now()];
            if ($upd) DB::update('customers', $upd, 'id = ?', [$c['id']]);
            return (int) $c['id'];
        }
        $src = $source;
        if ($email && DB::val('SELECT 1 FROM waitlist_signups WHERE email = ?', [$email])) $src = 'Waitlist';
        return DB::insert('customers', ['name' => $name ?: null, 'email' => $email, 'phone' => $phone, 'source' => $src, 'marketing_consent' => $consent ? 1 : 0,
            'consent_at' => $consent ? DB::now() : null, 'created_at' => DB::now()]);
    }

    public const SEGMENTS = ['all' => 'All', 'regular' => 'Regulars', 'new' => 'New', 'inactive' => 'Inactive 30d+', 'waitlist' => 'Waitlist only'];

    /** Customers with order stats. Paid orders count; cancelled ones do not. */
    public static function list(string $segment, string $q, int $offset, int $limit): array {
        $stats = "SELECT c.*, COUNT(o.id) AS orders, COALESCE(SUM(o.total_cents), 0) AS spent, MIN(o.created_at) AS first_order, MAX(o.created_at) AS last_order
            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id AND o.payment_status IN ('paid','partially_refunded') WHERE (c.email IS NOT NULL OR c.phone IS NOT NULL) GROUP BY c.id";
        $rows = DB::all("SELECT * FROM ($stats) s ORDER BY COALESCE(last_order, created_at) DESC");
        $d30 = DB::now(-30 * 86400);
        $seg = fn($r) => $r['orders'] >= 3 ? 'regular' : ($r['orders'] >= 1 && $r['first_order'] >= $d30 ? 'new' : ($r['orders'] >= 1 && $r['last_order'] < $d30 ? 'inactive' : ($r['orders'] ? 'other' : 'none')));
        $all = [];
        foreach ($rows as $r) { $r['segment'] = $seg($r); $all[] = $r; }
        // Waitlist only: signed up, never ordered (shown as lightweight rows)
        $wl = DB::all("SELECT w.id, w.email, w.phone, w.created_at, w.source FROM waitlist_signups w WHERE NOT EXISTS (SELECT 1 FROM customers c JOIN orders o ON o.customer_id = c.id AND o.payment_status = 'paid' WHERE c.email = w.email OR c.phone = w.phone) ORDER BY w.id DESC");
        $counts = ['all' => count($all), 'regular' => 0, 'new' => 0, 'inactive' => 0, 'waitlist' => count($wl)];
        foreach ($all as $r) if (isset($counts[$r['segment']])) $counts[$r['segment']]++;
        if ($segment === 'waitlist') $list = array_map(fn($w) => ['id' => null, 'waitlist_id' => (int) $w['id'], 'name' => null, 'email' => $w['email'], 'phone' => $w['phone'], 'orders' => 0, 'spent' => 0, 'segment' => 'waitlist', 'source' => $w['source'], 'last_order' => null, 'created_at' => $w['created_at']], $wl);
        else $list = $segment === 'all' ? $all : array_values(array_filter($all, fn($r) => $r['segment'] === $segment));
        if ($q !== '') { $ql = mb_strtolower($q); $qd = preg_replace('/\D/', '', $q);
            $list = array_values(array_filter($list, fn($r) => str_contains(mb_strtolower(($r['name'] ?? '') . ' ' . ($r['email'] ?? '')), $ql) || ($qd !== '' && str_contains((string) $r['phone'], $qd)))); }
        return ['counts' => $counts, 'total' => count($list), 'rows' => array_slice($list, $offset, $limit)];
    }

    public static function get(int $id): ?array {
        $c = DB::one('SELECT * FROM customers WHERE id = ?', [$id]);
        if (!$c) return null;
        $c['orders'] = DB::all("SELECT id, number, status, payment_status, total_cents, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC", [$id]);
        $paid = array_filter($c['orders'], fn($o) => in_array($o['payment_status'], ['paid', 'partially_refunded'], true));
        $c['spent'] = array_sum(array_column($paid, 'total_cents')); $c['order_count'] = count($paid);
        $c['addresses'] = DB::all('SELECT * FROM addresses WHERE customer_id = ? ORDER BY id DESC', [$id]);
        $c['waitlist'] = $c['email'] ? DB::one('SELECT gift_code, gift_redeemed_order_id, created_at FROM waitlist_signups WHERE email = ?', [$c['email']]) : null;
        $c['referral'] = DB::val('SELECT code FROM referral_codes WHERE customer_id = ?', [$id]);
        $c['referral_uses'] = (int) DB::val('SELECT uses FROM referral_codes WHERE customer_id = ?', [$id]);
        $c['codes'] = DB::all('SELECT code, type, value, used, max_uses, active FROM discount_codes WHERE customer_id = ? ORDER BY id DESC', [$id]);
        return $c;
    }

    /** GDPR export: everything stored about the person, as JSON. */
    public static function export(int $id): array {
        $c = DB::one('SELECT * FROM customers WHERE id = ?', [$id]);
        if (!$c) return [];
        $orders = DB::all('SELECT * FROM orders WHERE customer_id = ?', [$id]);
        foreach ($orders as &$o) { $o['items'] = DB::all('SELECT name_snapshot, qty, unit_price_cents, vat_rate FROM order_items WHERE order_id = ?', [$o['id']]); unset($o['token']); }
        return ['exported_at' => DB::now(), 'customer' => $c, 'addresses' => DB::all('SELECT * FROM addresses WHERE customer_id = ?', [$id]), 'orders' => $orders,
            'waitlist' => $c['email'] ? DB::all('SELECT email, phone, gift_code, created_at, consent, unsubscribed_at FROM waitlist_signups WHERE email = ? OR phone = ?', [$c['email'], $c['phone']]) : [],
            'messages' => DB::all('SELECT channel, template, to_addr, status, created_at FROM messages WHERE customer_id = ?', [$id]),
            'chats' => array_map(fn($cv) => $cv + ['messages' => DB::all('SELECT direction, body, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY id', [$cv['id']])],
                DB::all('SELECT id, channel, address, name, handle, subject, created_at FROM conversations WHERE customer_id = ? OR (channel = ? AND address = ?)', [$id, 'email', strtolower((string) $c['email'])]))];
    }

    /** GDPR delete: personal data goes; orders stay (Dutch tax law: 7 years) but no longer point to a person. */
    public static function delete(int $id): void {
        DB::tx(function () use ($id) {
            $c = DB::one('SELECT * FROM customers WHERE id = ?', [$id]);
            if (!$c) return;
            DB::q('UPDATE addresses SET customer_id = NULL, house_no = ?, addition = NULL, street = NULL, lat = NULL, lng = NULL WHERE customer_id = ?', ['-', $id]);
            DB::q("UPDATE orders SET notes = NULL WHERE customer_id = ?", [$id]);
            DB::q('DELETE FROM referral_codes WHERE customer_id = ?', [$id]);
            DB::q('UPDATE discount_codes SET active = 0, customer_id = NULL WHERE customer_id = ?', [$id]);   // personal codes die with the person
            if ($c['email']) { DB::q('DELETE FROM carts WHERE email = ?', [$c['email']]); DB::q('DELETE FROM abandoned_carts WHERE email = ?', [$c['email']]); DB::q('DELETE FROM list_signups WHERE email = ?', [$c['email']]); }
            DB::q('UPDATE messages SET to_addr = ?, body = NULL WHERE customer_id = ?', ['deleted', $id]);
            if ($c['email'] || $c['phone']) DB::q('DELETE FROM waitlist_signups WHERE email = ? OR phone = ?', [$c['email'], $c['phone']]);
            Inbox::forgetCustomer($id, $c['email']);
            DB::update('customers', ['name' => 'Deleted customer', 'email' => null, 'phone' => null, 'notes' => null, 'marketing_consent' => 0, 'consent_at' => null, 'source' => null], 'id = ?', [$id]);
        });
    }
}

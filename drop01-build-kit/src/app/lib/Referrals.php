<?php
// Referral programme: every paying customer gets a code ("NAME-D01"). A friend gets money off their first order with it,
// and when that order is delivered the referrer gets a personal one-time discount code by email.
defined('DROP01') || exit;

final class Referrals {
    public static function ensure(int $customerId): ?string {
        if (!$customerId) return null;
        if ($c = DB::val('SELECT code FROM referral_codes WHERE customer_id = ?', [$customerId])) return $c;
        $name = (string) DB::val('SELECT name FROM customers WHERE id = ?', [$customerId]);
        $first = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', explode(' ', trim($name))[0] ?? '') ?: '')) ?: 'FRIEND';
        $suffix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) (Settings::get('gift')['prefix'] ?? 'D01'))) ?: 'D01';
        $base = substr($first, 0, 12) . '-' . $suffix;
        $code = $base;
        for ($i = 2; DB::val('SELECT 1 FROM referral_codes WHERE code = ?', [$code]) || DB::val('SELECT 1 FROM discount_codes WHERE code = ?', [$code]); $i++) $code = $base . $i;
        DB::insert('referral_codes', ['customer_id' => $customerId, 'code' => $code]);
        return $code;
    }

    /**
     * The friend's order was delivered: the referrer gets a one-time code worth the referrer amount, only usable by them.
     * Idempotent (one reward per referred order). Returns the new code or null.
     */
    public static function reward(int $orderId): ?string {
        $o = DB::one('SELECT id, number, referral_code, payment_status FROM orders WHERE id = ?', [$orderId]);
        if (!$o || !$o['referral_code'] || $o['payment_status'] !== 'paid') return null;
        if (DB::val('SELECT 1 FROM discount_codes WHERE ref_order_id = ?', [$orderId])) return null;
        $r = DB::one('SELECT r.customer_id, c.name, c.email FROM referral_codes r JOIN customers c ON c.id = r.customer_id WHERE r.code = ?', [$o['referral_code']]);
        $amount = (int) Settings::get('referral')['referrer_cents'];
        if (!$r || $amount <= 0) return null;
        $code = Discounts::suggest('THANKS');
        DB::insert('discount_codes', ['code' => $code, 'type' => 'fixed', 'value' => $amount, 'min_cents' => 0, 'max_uses' => 1, 'used' => 0, 'active' => 1,
            'customer_id' => $r['customer_id'], 'source' => 'referral', 'ref_order_id' => $orderId, 'note' => 'Referral reward for order #' . $o['number'], 'created_at' => DB::now()]);
        Orders::event($orderId, 'referral_reward', ['code' => $code, 'to' => $r['name'] ?: $r['email']]);
        if ($r['email']) {
            $mid = Messages::queueEmail('referral_reward', $r['email'], ['name' => $r['name'], 'code' => $code, 'amount' => $amount, 'url' => Http::baseUrl() . '/'],
                ['customer_id' => (int) $r['customer_id']]);
            Messages::deliverLater($mid);
        }
        return $code;
    }

    /** Numbers and lists for Marketing → Referrals. */
    public static function stats(): array {
        $paid = "o.payment_status IN ('paid','partially_refunded')";
        $orders = DB::all("SELECT o.id, o.number, o.status, o.total_cents, o.code_discount_cents, o.created_at, o.referral_code, c.name, rc.name AS referrer
            FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN referral_codes r ON r.code = o.referral_code LEFT JOIN customers rc ON rc.id = r.customer_id
            WHERE o.referral_code IS NOT NULL AND $paid ORDER BY o.id DESC LIMIT 30");
        $top = DB::all("SELECT r.code, r.uses, c.name, c.id AS customer_id FROM referral_codes r JOIN customers c ON c.id = r.customer_id WHERE r.uses > 0 ORDER BY r.uses DESC, r.id LIMIT 10");
        $rewards = DB::one("SELECT COUNT(*) AS n, SUM(used) AS used FROM discount_codes WHERE source = 'referral'");
        return [
            'codes' => (int) DB::val('SELECT COUNT(*) FROM referral_codes'),
            'friends' => (int) DB::val("SELECT COUNT(*) FROM orders o WHERE o.referral_code IS NOT NULL AND $paid"),
            'revenue' => (int) DB::val("SELECT COALESCE(SUM(o.total_cents), 0) FROM orders o WHERE o.referral_code IS NOT NULL AND $paid"),
            'friend_off' => (int) DB::val("SELECT COALESCE(SUM(o.code_discount_cents), 0) FROM orders o WHERE o.referral_code IS NOT NULL AND $paid"),
            'rewards' => (int) ($rewards['n'] ?? 0), 'rewards_used' => (int) ($rewards['used'] ?? 0),
            'settings' => Settings::get('referral'), 'orders' => $orders, 'top' => $top,
        ];
    }
}

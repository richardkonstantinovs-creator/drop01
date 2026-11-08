<?php
// Discount codes at checkout: admin codes (% off, € off, free delivery) and friends' referral codes ("NAME-D01"),
// checked again when the order is placed, counted when it is paid and given back when a paid order is cancelled.
defined('DROP01') || exit;

final class Discounts {
    public const TYPES = ['pct' => '% off', 'fixed' => '€ off', 'free_delivery' => 'Free delivery'];

    public static function norm(string $code): string { return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code)); }

    /**
     * Finds a code and checks it for this buyer and bag. Throws RuntimeException with a message for the customer.
     * $goodsCents: the bag after the bundle discount (for the minimum order). Email/phone may be unknown while typing.
     * Returns ['kind'=>'code'|'referral', 'code', 'type', 'value', 'label', 'id'].
     */
    public static function resolve(string $raw, ?string $email, ?string $phone, int $goodsCents): array {
        $code = self::norm($raw);
        if ($code === '') throw new RuntimeException(t('err.code_empty'));
        $d = DB::one('SELECT * FROM discount_codes WHERE code = ?', [$code]);
        if ($d) {
            $now = DB::now();
            if (!$d['active'] || ($d['ends_at'] && $d['ends_at'] < $now)) throw new RuntimeException(t('err.code_expired'));
            if ($d['starts_at'] && $d['starts_at'] > $now) throw new RuntimeException(t('err.code_not_yet'));
            if ($d['max_uses'] !== null && (int) $d['used'] >= (int) $d['max_uses']) throw new RuntimeException(t('err.code_used_up'));
            if ((int) $d['min_cents'] > $goodsCents) throw new RuntimeException(t('err.code_min', ['min' => eur((int) $d['min_cents'])]));
            if ($d['customer_id'] && ($email || $phone) && !self::isCustomer((int) $d['customer_id'], $email, $phone))
                throw new RuntimeException(t('err.code_other'));
            return ['kind' => 'code', 'id' => (int) $d['id'], 'code' => $code, 'type' => $d['type'], 'value' => (int) $d['value'], 'label' => self::label($d), 'personal' => (bool) $d['customer_id']];
        }
        $r = DB::one('SELECT r.*, c.email, c.phone FROM referral_codes r LEFT JOIN customers c ON c.id = r.customer_id WHERE r.code = ?', [$code]);
        $amount = (int) Settings::get('referral')['friend_cents'];
        if (!$r || $amount <= 0) throw new RuntimeException(t('err.code_unknown'));
        if (($email && $r['email'] === $email) || ($phone && $r['phone'] === $phone)) throw new RuntimeException(t('err.code_own'));
        if (($email || $phone) && self::hasOrdered($email, $phone)) throw new RuntimeException(t('err.code_first'));
        return ['kind' => 'referral', 'id' => (int) $r['id'], 'code' => $code, 'type' => 'fixed', 'value' => $amount, 'label' => 'Friend’s code ' . $code, 'personal' => false];
    }

    /** "15% off", "€5,00 off", "Free delivery". */
    public static function label(array $d): string {
        return match ($d['type']) { 'pct' => (int) $d['value'] . '% off', 'fixed' => eur((int) $d['value']) . ' off', default => 'Free delivery' };
    }

    private static function isCustomer(int $id, ?string $email, ?string $phone): bool {
        $c = DB::one('SELECT email, phone FROM customers WHERE id = ?', [$id]);
        return $c && (($email && $c['email'] === $email) || ($phone && $c['phone'] === $phone));
    }

    /** A paid (or delivered) order exists for this email or phone. */
    public static function hasOrdered(?string $email, ?string $phone): bool {
        return (bool) DB::val("SELECT 1 FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.payment_status IN ('paid','partially_refunded','refunded')
            AND ((c.email IS NOT NULL AND c.email = ?) OR (c.phone IS NOT NULL AND c.phone = ?)) LIMIT 1", [(string) $email, (string) $phone]);
    }

    /** Paid: count the use. Called inside Orders::markPaid's transaction. */
    public static function redeem(array $o): void {
        if ($o['discount_code']) DB::q('UPDATE discount_codes SET used = used + 1 WHERE code = ?', [$o['discount_code']]);
        if ($o['referral_code']) DB::q('UPDATE referral_codes SET uses = uses + 1 WHERE code = ?', [$o['referral_code']]);
    }

    /** A paid order was cancelled: the use comes back. */
    public static function release(array $o): void {
        if ($o['discount_code']) DB::q('UPDATE discount_codes SET used = CASE WHEN used > 0 THEN used - 1 ELSE 0 END WHERE code = ?', [$o['discount_code']]);
        if ($o['referral_code']) DB::q('UPDATE referral_codes SET uses = CASE WHEN uses > 0 THEN uses - 1 ELSE 0 END WHERE code = ?', [$o['referral_code']]);
    }

    // ---------------------------------------------------------------- admin

    /** All admin-made and reward codes with usage stats from paid orders. */
    public static function all(): array {
        $rows = DB::all('SELECT d.*, c.name AS customer_name FROM discount_codes d LEFT JOIN customers c ON c.id = d.customer_id ORDER BY d.active DESC, d.id DESC');
        $stats = [];
        foreach (DB::all("SELECT discount_code, COUNT(*) AS n, SUM(total_cents) AS revenue, SUM(code_discount_cents) AS given FROM orders
            WHERE discount_code IS NOT NULL AND payment_status IN ('paid','partially_refunded') GROUP BY discount_code") as $s) $stats[$s['discount_code']] = $s;
        foreach ($rows as &$r) {
            $s = $stats[$r['code']] ?? null;
            $r['orders'] = (int) ($s['n'] ?? 0); $r['revenue'] = (int) ($s['revenue'] ?? 0); $r['given'] = (int) ($s['given'] ?? 0);
            $r['label'] = self::label($r);
            $r['expired'] = $r['ends_at'] && $r['ends_at'] < DB::now();
        }
        return $rows;
    }

    /** Creates or updates an admin code. $in: id, code, type, value (number: % or euros), min (euros), max_uses, starts, ends (local dates), active, note. */
    public static function save(array $in): int {
        $id = (int) ($in['id'] ?? 0);
        $code = self::norm((string) ($in['code'] ?? ''));
        if (strlen($code) < 3 || strlen($code) > 30) throw new RuntimeException('A code needs 3 to 30 letters or digits.');
        $type = (string) ($in['type'] ?? 'pct');
        if (!isset(self::TYPES[$type])) throw new RuntimeException('Pick what the code gives.');
        $value = $type === 'pct' ? (int) $in['value'] : ($type === 'fixed' ? Products::cents((string) $in['value']) : 0);
        if ($type === 'pct' && ($value < 1 || $value > 100)) throw new RuntimeException('A percentage is between 1 and 100.');
        if ($type === 'fixed' && $value < 1) throw new RuntimeException('Enter the amount off, e.g. 5.');
        if (DB::val('SELECT 1 FROM discount_codes WHERE code = ? AND id <> ?', [$code, $id]) || DB::val('SELECT 1 FROM referral_codes WHERE code = ?', [$code]))
            throw new RuntimeException('This code already exists.');
        $old = $id ? DB::one('SELECT * FROM discount_codes WHERE id = ?', [$id]) : null;
        if ($id && !$old) throw new RuntimeException('Code not found.');
        if ($old && (int) $old['used'] > 0 && $old['code'] !== $code) throw new RuntimeException('This code has been used, so its name can’t change. Make a new one instead.');
        $max = trim((string) ($in['max_uses'] ?? '')) === '' ? null : max(1, (int) $in['max_uses']);
        $row = ['code' => $code, 'type' => $type, 'value' => $value, 'min_cents' => trim((string) ($in['min'] ?? '')) === '' ? 0 : Products::cents((string) $in['min']),
            'max_uses' => $max, 'starts_at' => self::date((string) ($in['starts'] ?? ''), false), 'ends_at' => self::date((string) ($in['ends'] ?? ''), true),
            'active' => !empty($in['active']) ? 1 : 0, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 160) ?: null];
        if ($old) { DB::update('discount_codes', $row, 'id = ?', [$id]); return $id; }
        return DB::insert('discount_codes', $row + ['source' => 'admin', 'created_at' => DB::now()]);
    }

    /** "2026-11-27" (Amsterdam) → start or end of that day in UTC. */
    private static function date(string $d, bool $end): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $t = new DateTime($d . ($end ? ' 23:59:59' : ' 00:00:00'), new DateTimeZone(TZ_LOCAL));
        return gmdate('Y-m-d\TH:i:s\Z', $t->getTimestamp());
    }

    /** A free, readable code suggestion like DROP-7KQ2. */
    public static function suggest(string $prefix = 'DROP'): string {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do { $c = $prefix . '-'; for ($i = 0; $i < 4; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)]; }
        while (DB::val('SELECT 1 FROM discount_codes WHERE code = ?', [$c]) || DB::val('SELECT 1 FROM referral_codes WHERE code = ?', [$c]));
        return $c;
    }
}

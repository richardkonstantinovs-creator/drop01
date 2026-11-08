<?php
// Delivery zones (4-digit postcode ranges) and time slots generated from the weekly template in Settings, with cut-off and capacity.
defined('DROP01') || exit;

final class Delivery {
    /** Normalises "9711 ld" → "9711LD"; null when it is not a Dutch postcode. */
    public static function normPostcode(string $pc): ?string {
        $pc = strtoupper(preg_replace('/\s+/', '', $pc));
        return preg_match('/^[1-9]\d{3}[A-Z]{2}$/', $pc) ? $pc : (preg_match('/^[1-9]\d{3}$/', $pc) ? $pc : null);
    }

    public static function zones(): array { return DB::all('SELECT * FROM delivery_zones WHERE active = 1 ORDER BY (postcode_to - postcode_from), sort, id'); }

    /** The most specific active zone for a postcode (narrowest range wins), or null when we do not deliver there. */
    public static function zoneFor(string $pc, ?array $zones = null): ?array {
        $n = (int) substr(preg_replace('/\D/', '', $pc), 0, 4);
        if ($n < 1000) return null;
        foreach ($zones ?? self::zones() as $z) if ($n >= (int) $z['postcode_from'] && $n <= (int) $z['postcode_to']) return $z;
        return null;
    }

    /** Delivery fee for an order value after discounts. */
    public static function fee(?array $zone, int $goodsCents): int {
        if (!$zone) return 0;
        $free = $zone['free_from_cents'] !== null ? (int) $zone['free_from_cents'] : (int) Settings::get('shop')['free_from_cents'];
        return ($free > 0 && $goodsCents >= $free) ? 0 : (int) $zone['fee_cents'];
    }

    /** Creates the slot rows for the coming days from the template (existing rows are left alone, so manual edits survive). */
    public static function ensureSlots(?int $now = null): void {
        $d = Settings::get('delivery');
        $tz = new DateTimeZone(TZ_LOCAL);
        $day = (new DateTime('@' . ($now ?? time())))->setTimezone($tz)->setTime(0, 0);
        for ($i = 0; $i <= (int) $d['days_ahead']; $i++) {
            $date = $day->format('Y-m-d');
            if (in_array((int) $day->format('N'), array_map('intval', $d['days']), true) && !in_array($date, (array) $d['blackout'], true)) {
                foreach ($d['windows'] as $w) {
                    if (!DB::val('SELECT 1 FROM delivery_slots WHERE date = ? AND start_time = ?', [$date, $w['start']]))
                        DB::insert('delivery_slots', ['date' => $date, 'start_time' => $w['start'], 'end_time' => $w['end'], 'capacity' => (int) $w['capacity']]);
                }
            }
            $day->modify('+1 day');
        }
    }

    /** Unpaid orders that still hold a place in a slot (not yet counted in `booked`). */
    private static function holds(int $slotId): int {
        return (int) DB::val("SELECT COUNT(*) FROM orders WHERE slot_id = ? AND payment_status = 'unpaid' AND status = 'new' AND (expires_at IS NULL OR expires_at > ?)", [$slotId, DB::now()]);
    }

    /** A slot as the shop shows it: open (before cut-off), places left. */
    public static function describe(array $s, ?int $now = null): array {
        $tz = new DateTimeZone(TZ_LOCAL);
        $start = new DateTime($s['date'] . ' ' . $s['start_time'], $tz);
        $cut = (clone $start)->modify('-' . (int) Settings::get('delivery')['cutoff_min'] . ' minutes');
        $left = max(0, (int) $s['capacity'] - (int) $s['booked'] - self::holds((int) $s['id']));
        $open = !$s['closed'] && ($now ?? time()) < $cut->getTimestamp();
        return ['id' => (int) $s['id'], 'date' => $s['date'], 'start' => $s['start_time'], 'end' => $s['end_time'], 'capacity' => (int) $s['capacity'],
            'booked' => (int) $s['booked'], 'left' => $left, 'open' => $open, 'available' => $open && $left > 0, 'cutoff' => $cut->format('c'), 'label' => self::label($s, $now)];
    }

    /** Upcoming slots (today + days_ahead), oldest first. */
    public static function upcoming(?int $now = null): array {
        self::ensureSlots($now);
        $today = (new DateTime('@' . ($now ?? time())))->setTimezone(new DateTimeZone(TZ_LOCAL))->format('Y-m-d');
        $rows = DB::all('SELECT * FROM delivery_slots WHERE date >= ? ORDER BY date, start_time LIMIT 60', [$today]);
        return array_values(array_filter(array_map(fn($s) => self::describe($s, $now), $rows), fn($s) => $s['open'] || $s['date'] === $today));
    }

    /** "Today 18:00–21:00", "Tomorrow 12:00–15:00", "Sun 11 Oct 12:00–15:00". */
    public static function label(array $s, ?int $now = null): string {
        return self::dayName($s['date'], $now) . ' ' . $s['start_time'] . '–' . $s['end_time'];
    }
    public static function dayName(string $date, ?int $now = null): string {
        $tz = new DateTimeZone(TZ_LOCAL);
        $today = (new DateTime('@' . ($now ?? time())))->setTimezone($tz)->format('Y-m-d');
        $tomorrow = (new DateTime('@' . ($now ?? time())))->setTimezone($tz)->modify('+1 day')->format('Y-m-d');
        return $date === $today ? t('common.today') : ($date === $tomorrow ? t('common.tomorrow') : (new DateTime($date, $tz))->format('D j M'));
    }

    public static function slot(int $id): ?array { $s = DB::one('SELECT * FROM delivery_slots WHERE id = ?', [$id]); return $s ? self::describe($s) : null; }
}

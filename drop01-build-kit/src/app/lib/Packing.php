<?php
// Packing list for a delivery day: every order per window with what goes in the bag, what to collect at the door,
// and the totals to pick from stock for everything not packed yet, in shelf order (A1, A2, … B1) so one walk past the shelves picks it all.
defined('DROP01') || exit;

final class Packing {
    /** Statuses that count as packed (the bag is ready or already gone). */
    public const PACKED = ['packing', 'out_for_delivery', 'delivered', 'failed_delivery'];

    public static function day(string $date): array {
        $rows = DB::all("SELECT o.id, o.number, o.status, o.payment_status, o.payment_method, o.total_cents, o.gift_code, o.notes, o.route_pos,
                c.name, c.phone, a.street, a.house_no, a.addition, a.postcode, a.city, z.name AS zone, s.id AS slot_id, s.start_time, s.end_time
            FROM orders o JOIN delivery_slots s ON s.id = o.slot_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN addresses a ON a.id = o.address_id
            LEFT JOIN delivery_zones z ON z.id = a.zone_id
            WHERE s.date = ? AND o.status <> 'cancelled' ORDER BY s.start_time, COALESCE(o.route_pos, 999999), o.number", [$date]);
        $windows = []; $pick = []; $gifts = 0;
        foreach ($rows as $o) {
            $o['items'] = DB::all('SELECT i.qty, i.name_snapshot, i.variant_id, v.shelf, (SELECT COALESCE(NULLIF(m.path_sm, \'\'), m.path) FROM product_images m WHERE m.product_id = i.product_id ORDER BY m.sort, m.id LIMIT 1) AS image
                FROM order_items i LEFT JOIN variants v ON v.id = i.variant_id WHERE i.order_id = ? ORDER BY i.id', [$o['id']]);
            usort($o['items'], [self::class, 'byShelf']);
            $o['packed'] = in_array($o['status'], self::PACKED, true);
            $o['address'] = trim($o['street'] . ' ' . $o['house_no'] . ($o['addition'] ? '-' . $o['addition'] : ''));
            $o['unpaid'] = $o['payment_status'] !== 'paid';
            $o['collect'] = $o['unpaid'] && $o['payment_method'] === 'cash' ? (int) $o['total_cents'] : 0;
            if (!$o['packed']) {
                foreach ($o['items'] as $i) {
                    $k = $i['variant_id'] ? 'v' . $i['variant_id'] : 'n' . $i['name_snapshot'];
                    $pick[$k] ??= ['name' => $i['name_snapshot'], 'shelf' => $i['shelf'], 'variant_id' => $i['variant_id'] ? (int) $i['variant_id'] : null, 'image' => $i['image'], 'qty' => 0];
                    $pick[$k]['qty'] += (int) $i['qty'];
                }
                if ($o['gift_code']) $gifts++;
            }
            $key = $o['start_time'] . '–' . $o['end_time'];
            $windows[$key] ??= ['label' => $key, 'slot_id' => (int) $o['slot_id'], 'orders' => []];
            $windows[$key]['orders'][] = $o;
        }
        $pick = array_values($pick);
        usort($pick, [self::class, 'byShelf']);
        $packed = count(array_filter($rows, fn($o) => in_array($o['status'], self::PACKED, true)));
        return ['date' => $date, 'label' => Delivery::dayName($date) . ' · ' . (new DateTime($date, new DateTimeZone(TZ_LOCAL)))->format('j M'),
            'windows' => array_values($windows), 'pick' => $pick, 'labels' => Orders::STATUS,
            'gifts' => $gifts, 'total' => count($rows), 'packed' => $packed, 'days' => self::days()];
    }

    /** Shelf order: A2 before A10, items without a shelf last, then by name. */
    public static function byShelf(array $a, array $b): int {
        $na = (string) $a['shelf'] === '';
        $nb = (string) $b['shelf'] === '';
        if ($na !== $nb) return $na <=> $nb;
        return strnatcasecmp((string) $a['shelf'], (string) $b['shelf']) ?: strnatcasecmp($a['name_snapshot'] ?? $a['name'], $b['name_snapshot'] ?? $b['name']);
    }

    /** Today and the coming delivery days, with the number of orders still to pack. */
    private static function days(): array {
        $tz = new DateTimeZone(TZ_LOCAL);
        $out = [];
        for ($i = 0, $d = new DateTime('today', $tz); $i <= (int) Settings::get('delivery')['days_ahead']; $i++, $d->modify('+1 day')) {
            $ds = $d->format('Y-m-d');
            $out[] = ['date' => $ds, 'label' => ($i < 2 ? Delivery::dayName($ds) : $d->format('D j')),
                'todo' => (int) DB::val("SELECT COUNT(*) FROM orders o JOIN delivery_slots s ON s.id = o.slot_id WHERE s.date = ? AND o.status IN ('new','paid')", [$ds])];
        }
        return $out;
    }
}

<?php
// Delivery routes: one per window. Stops in driving order (nearest neighbour from the depot, then 2-opt), ETAs, Google Maps links.
defined('DROP01') || exit;

final class Route {
    /** Google Maps directions URLs take at most 9 waypoints (+ the destination), so long routes are split into legs. */
    public const MAPS_WAYPOINTS = 9;
    private const ROAD_FACTOR = 1.35;   // straight line → city streets

    /** Orders that belong on a route: paid ones, plus cash-on-delivery orders that are paid at the door. */
    public const STOP_SQL = "(o.status IN ('paid','packing','out_for_delivery','delivered','failed_delivery') OR (o.status = 'new' AND o.payment_method = 'cash'))";

    // ---------------------------------------------------------------- geometry

    /** Distance in km between two [lat, lng] points. */
    public static function km(array $a, array $b): float {
        $r = 6371.0; $p1 = deg2rad($a[0]); $p2 = deg2rad($b[0]);
        $dp = $p2 - $p1; $dl = deg2rad($b[1] - $a[1]);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($h)));
    }

    /** Length of an open path that starts at $start and visits $pts in the given order. */
    public static function length(array $start, array $pts, array $order): float {
        $d = 0.0; $prev = $start;
        foreach ($order as $i) { $d += self::km($prev, $pts[$i]); $prev = $pts[$i]; }
        return $d;
    }

    /** Visiting order (indexes into $pts): nearest neighbour from $start, then 2-opt until nothing improves. The route does not return to the depot. */
    public static function optimize(array $start, array $pts): array {
        $n = count($pts);
        if ($n <= 1) return array_keys($pts);
        $left = array_keys($pts); $order = []; $cur = $start;
        while ($left) {
            $best = null; $bd = INF;
            foreach ($left as $k => $i) { $d = self::km($cur, $pts[$i]); if ($d < $bd - 1e-9) { $bd = $d; $best = $k; } }
            $order[] = $left[$best]; $cur = $pts[$left[$best]]; unset($left[$best]);
        }
        // 2-opt on the open path: reverse order[i..k] when that shortens it ($order changes inside the loop, so no captured copies)
        for ($pass = 0, $improved = true; $improved && $pass < 50; $pass++) {
            $improved = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $a = $i === 0 ? $start : $pts[$order[$i - 1]]; $b = $pts[$order[$i]]; $c = $pts[$order[$k]]; $d = $k + 1 < $n ? $pts[$order[$k + 1]] : null;
                    $before = self::km($a, $b) + ($d ? self::km($c, $d) : 0);
                    $after = self::km($a, $c) + ($d ? self::km($b, $d) : 0);
                    if ($after < $before - 1e-7) { array_splice($order, $i, $k - $i + 1, array_reverse(array_slice($order, $i, $k - $i + 1))); $improved = true; }
                }
            }
        }
        return $order;
    }

    // ---------------------------------------------------------------- depot

    /** [lat, lng] of the depot (Settings → Store → Depot address), geocoded with PDOK once. Falls back to the Grote Markt. */
    public static function depot(): array {
        $addr = trim((string) (Settings::get('company')['depot'] ?? ''));
        $geo = Settings::get('depot_geo');
        if ($addr !== '' && (!$geo || ($geo['addr'] ?? '') !== $addr)) {
            $hit = Pdok::geocodeText($addr);
            if ($hit) { $geo = ['addr' => $addr, 'lat' => $hit['lat'], 'lng' => $hit['lng']]; Settings::set('depot_geo', $geo); }
        }
        if ($addr !== '' && $geo && ($geo['addr'] ?? '') === $addr) return ['lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng'], 'set' => true, 'label' => $addr];
        return ['lat' => 53.2194, 'lng' => 6.5665, 'set' => false, 'label' => 'Groningen centre (set your depot in Settings → Store)'];
    }

    // ---------------------------------------------------------------- routes

    /** All routes (one per window) for a date, with stops in driving order. $courierId limits it to that courier's routes. */
    public static function forDate(string $date, ?int $courierId = null): array {
        Delivery::ensureSlots();
        $slots = DB::all('SELECT s.*, a.name AS courier_name FROM delivery_slots s LEFT JOIN admins a ON a.id = s.courier_id WHERE s.date = ? ORDER BY s.start_time', [$date]);
        $soleCourier = self::soleCourier();
        $out = [];
        foreach ($slots as $s) {
            if ($courierId !== null && (int) $s['courier_id'] !== $courierId && !($s['courier_id'] === null && $soleCourier === $courierId)) continue;
            $r = self::build($s);
            if ($r['stops'] || $courierId === null) $out[] = $r;
        }
        return $out;
    }

    /** With exactly one active courier, routes without a courier are theirs. */
    public static function soleCourier(): ?int {
        $ids = DB::all("SELECT id FROM admins WHERE role = 'courier' AND active = 1");
        return count($ids) === 1 ? (int) $ids[0]['id'] : null;
    }

    public static function forSlot(int $slotId): ?array {
        $s = DB::one('SELECT s.*, a.name AS courier_name FROM delivery_slots s LEFT JOIN admins a ON a.id = s.courier_id WHERE s.id = ?', [$slotId]);
        return $s ? self::build($s) : null;
    }

    /** Is this order on a route the user may work on? Couriers only see their own routes. */
    public static function mayWork(array $u, int $orderId): bool {
        if ($u['role'] !== 'courier') return true;
        $c = DB::one('SELECT s.courier_id FROM orders o JOIN delivery_slots s ON s.id = o.slot_id WHERE o.id = ?', [$orderId]);
        if (!$c) return false;
        return (int) $c['courier_id'] === (int) $u['id'] || ($c['courier_id'] === null && self::soleCourier() === (int) $u['id']);
    }

    private static function build(array $s): array {
        $rows = DB::all('SELECT o.id, o.number, o.status, o.payment_status, o.payment_method, o.total_cents, o.notes, o.route_pos, o.delivered_at, o.proof_path, o.failed_count, o.out_at,
                (SELECT MAX(e.created_at) FROM order_events e WHERE e.order_id = o.id AND e.type = \'status\') AS done_at,
                (SELECT MAX(e.id) FROM order_events e WHERE e.order_id = o.id AND e.type = \'status\') AS done_ev,
                c.name, c.phone, a.id AS address_id, a.postcode, a.house_no, a.addition, a.street, a.city, a.lat, a.lng, z.name AS zone,
                (SELECT SUM(qty) FROM order_items i WHERE i.order_id = o.id) AS pieces
            FROM orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN addresses a ON a.id = o.address_id LEFT JOIN delivery_zones z ON z.id = a.zone_id
            WHERE o.slot_id = ? AND ' . self::STOP_SQL . ' ORDER BY o.id', [$s['id']]);
        // stops without a map position: try PDOK once more (it may have been down at checkout)
        foreach ($rows as &$r) {
            if ($r['lat'] === null && $r['postcode']) {
                $a = Pdok::lookup((string) $r['postcode'], (string) $r['house_no'], (string) $r['addition']);
                if ($a) { DB::update('addresses', ['lat' => $a['lat'], 'lng' => $a['lng'], 'street' => $r['street'] ?: $a['street'], 'city' => $r['city'] ?: $a['city']], 'id = ?', [$r['address_id']]);
                    $r['lat'] = $a['lat']; $r['lng'] = $a['lng']; $r['street'] = $r['street'] ?: $a['street']; $r['city'] = $r['city'] ?: $a['city']; }
            }
        }
        unset($r);
        $depot = self::depot();
        $start = [$depot['lat'], $depot['lng']];
        $done = array_values(array_filter($rows, fn($r) => in_array($r['status'], ['delivered', 'failed_delivery'], true)));
        usort($done, fn($a, $b) => (int) $a['done_ev'] <=> (int) $b['done_ev']);   // in the order they happened
        $todo = array_values(array_filter($rows, fn($r) => !in_array($r['status'], ['delivered', 'failed_delivery'], true)));
        $from = $start;
        foreach ($done as $d) if ($d['lat'] !== null) $from = [(float) $d['lat'], (float) $d['lng']];

        $geo = array_values(array_filter($todo, fn($r) => $r['lat'] !== null));
        $nogeo = array_values(array_filter($todo, fn($r) => $r['lat'] === null));
        if ($s['route_manual']) {
            // the owner's order; stops added later go where they cost the least distance
            $placed = array_values(array_filter($geo, fn($r) => $r['route_pos'] !== null));
            usort($placed, fn($a, $b) => (int) $a['route_pos'] <=> (int) $b['route_pos']);
            foreach (array_filter($geo, fn($r) => $r['route_pos'] === null) as $new) $placed = self::insertCheapest($from, $placed, $new);
            $ordered = $placed;
        } else {
            $pts = array_map(fn($r) => [(float) $r['lat'], (float) $r['lng']], $geo);
            $ordered = array_map(fn($i) => $geo[$i], self::optimize($from, $pts));
        }
        $ordered = array_merge($ordered, $nogeo);
        return self::describe($s, $depot, $done, $ordered);
    }

    private static function insertCheapest(array $from, array $list, array $new): array {
        $p = [(float) $new['lat'], (float) $new['lng']];
        $best = count($list); $bd = INF;
        for ($i = 0; $i <= count($list); $i++) {
            $a = $i === 0 ? $from : [(float) $list[$i - 1]['lat'], (float) $list[$i - 1]['lng']];
            $b = $i < count($list) ? [(float) $list[$i]['lat'], (float) $list[$i]['lng']] : null;
            $d = self::km($a, $p) + ($b ? self::km($p, $b) - self::km($a, $b) : 0);
            if ($d < $bd) { $bd = $d; $best = $i; }
        }
        array_splice($list, $best, 0, [$new]);
        return $list;
    }

    /** Stops with ETAs, distance and time, progress and the Google Maps links. */
    private static function describe(array $s, array $depot, array $done, array $todo): array {
        $d = Settings::get('delivery');
        $speed = max(5, (int) ($d['speed_kmh'] ?? 25)); $stopMin = max(0, (int) ($d['stop_min'] ?? 4));
        $tz = new DateTimeZone(TZ_LOCAL);
        $t = (new DateTime($s['date'] . ' ' . $s['start_time'], $tz))->getTimestamp();
        $now = time();
        $prev = [$depot['lat'], $depot['lng']];
        $km = 0.0; $stops = [];
        $mk = function (array $r, string $state, ?int $eta) use ($d) {
            $addr = trim(($r['street'] ?: 'House') . ' ' . $r['house_no'] . ($r['addition'] ? ' ' . $r['addition'] : ''));
            return ['id' => (int) $r['id'], 'number' => (int) $r['number'], 'state' => $state, 'status' => $r['status'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone'],
                'address' => $addr, 'postcode' => (string) $r['postcode'], 'city' => (string) ($r['city'] ?: 'Groningen'), 'zone' => (string) $r['zone'],
                'lat' => $r['lat'] !== null ? (float) $r['lat'] : null, 'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
                'pieces' => (int) $r['pieces'], 'notes' => (string) $r['notes'], 'collect' => $r['payment_status'] !== 'paid' ? (int) $r['total_cents'] : 0,
                'method' => Orders::METHODS[$r['payment_method']] ?? (string) $r['payment_method'], 'photo' => (bool) $r['proof_path'], 'failed' => (int) $r['failed_count'],
                'eta' => $eta ? (new DateTime('@' . $eta))->setTimezone(new DateTimeZone(TZ_LOCAL))->format('H:i') : null, 'eta_ts' => $eta,
                'delivered_at' => $r['delivered_at'], 'out' => $r['status'] === 'out_for_delivery',
                'nav' => self::mapsUrl([self::place($r)], null, (string) ($d['travel'] ?? 'driving'))];
        };
        foreach ($done as $r) {
            if ($r['lat'] !== null) { $p = [(float) $r['lat'], (float) $r['lng']]; $km += self::km($prev, $p) * self::ROAD_FACTOR; $prev = $p; }
            $at = $r['done_at'] ? strtotime($r['done_at']) : null;
            if ($at) $t = max($t, $at);
            $stops[] = $mk($r, $r['status'] === 'delivered' ? 'done' : 'failed', $at);
        }
        if ($done && $s['date'] === (new DateTime('now', $tz))->format('Y-m-d')) $t = max($t, $now);   // the route is running: ETAs from now
        foreach ($todo as $r) {
            if ($r['lat'] !== null) { $p = [(float) $r['lat'], (float) $r['lng']]; $leg = self::km($prev, $p) * self::ROAD_FACTOR; $km += $leg; $prev = $p; $t += (int) round($leg / $speed * 3600); }
            $stops[] = $mk($r, 'todo', $t);
            $t += $stopMin * 60;
        }
        $total = count($stops); $nDone = count($done);
        $minutes = (int) round($km / $speed * 60 + $total * $stopMin);
        $places = array_map([self::class, 'place'], array_filter($todo, fn($r) => $r['lat'] !== null || $r['street']));
        return ['slot' => ['id' => (int) $s['id'], 'date' => $s['date'], 'start' => $s['start_time'], 'end' => $s['end_time'], 'label' => Delivery::label($s),
                'capacity' => (int) $s['capacity'], 'booked' => (int) $s['booked'], 'courier_id' => $s['courier_id'] !== null ? (int) $s['courier_id'] : null, 'courier' => $s['courier_name'] ?? null,
                'manual' => (bool) $s['route_manual']],
            'depot' => $depot, 'stops' => $stops, 'done' => $nDone, 'total' => $total, 'km' => round($km, 1), 'minutes' => $minutes,
            'maps' => self::mapsLinks($places, (string) ($d['travel'] ?? 'driving')),
            'startable' => count(array_filter($todo, fn($r) => in_array($r['status'], ['paid', 'packing', 'new'], true)))];
    }

    /** How Google Maps should find a stop: the street address (readable for the courier), or lat,lng. */
    public static function place(array $r): string {
        if (!empty($r['street'])) return trim($r['street'] . ' ' . $r['house_no'] . ($r['addition'] ? $r['addition'] : '')) . ', ' . $r['postcode'] . ' ' . ($r['city'] ?: 'Groningen');
        return $r['lat'] . ',' . $r['lng'];
    }

    /** Directions URL. Without an origin Google Maps starts from where the phone is. */
    public static function mapsUrl(array $places, ?string $origin, string $travel = 'driving'): string {
        $dest = array_pop($places);
        $q = ['api' => '1', 'destination' => $dest, 'travelmode' => in_array($travel, ['driving', 'bicycling', 'walking'], true) ? $travel : 'driving'];
        if ($origin) $q['origin'] = $origin;
        if ($places) $q['waypoints'] = implode('|', $places);
        return 'https://www.google.com/maps/dir/?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** Remaining stops as one or more Maps links of ≤ 9 waypoints + destination; each leg starts where the previous one ended. */
    public static function mapsLinks(array $places, string $travel = 'driving'): array {
        $places = array_values($places); $links = []; $origin = null;
        for ($i = 0; $i < count($places); $i += self::MAPS_WAYPOINTS + 1) {
            $chunk = array_slice($places, $i, self::MAPS_WAYPOINTS + 1);
            $links[] = ['from' => $i + 1, 'to' => $i + count($chunk), 'url' => self::mapsUrl($chunk, $origin, $travel)];
            $origin = end($chunk);
        }
        return $links;
    }

    /** Saves a manual stop order for a window (drag to reorder). */
    public static function reorder(int $slotId, array $orderIds): void {
        DB::tx(function () use ($slotId, $orderIds) {
            foreach (array_values($orderIds) as $i => $id) DB::update('orders', ['route_pos' => $i], 'id = ? AND slot_id = ?', [(int) $id, $slotId]);
            DB::update('delivery_slots', ['route_manual' => 1], 'id = ?', [$slotId]);
        });
    }

    public static function resetOrder(int $slotId): void {
        DB::q('UPDATE orders SET route_pos = NULL WHERE slot_id = ?', [$slotId]);
        DB::update('delivery_slots', ['route_manual' => 0], 'id = ?', [$slotId]);
    }

    /** Orders per zone for the week (Mon–Sun) that contains $date. */
    public static function zonesWeek(string $date): array {
        $d = new DateTime($date, new DateTimeZone(TZ_LOCAL));
        $mon = (clone $d)->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
        $sun = (new DateTime($mon))->modify('+6 days')->format('Y-m-d');
        return DB::all('SELECT z.name, COUNT(*) AS n FROM orders o JOIN delivery_slots s ON s.id = o.slot_id JOIN addresses a ON a.id = o.address_id JOIN delivery_zones z ON z.id = a.zone_id
            WHERE s.date BETWEEN ? AND ? AND ' . self::STOP_SQL . ' GROUP BY z.name ORDER BY n DESC, z.name', [$mon, $sun]);
    }
}

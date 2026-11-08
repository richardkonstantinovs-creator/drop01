<?php
// Numbers for the Today screen: sales, orders, deliveries, profit vs the same weekday last week, attention list, month to date.
defined('DROP01') || exit;

final class Reports {
    /** UTC bounds of an Amsterdam calendar day. */
    public static function dayBounds(string $day): array {
        $tz = new DateTimeZone(TZ_LOCAL);
        return [gmdate('Y-m-d\TH:i:s\Z', (new DateTime($day, $tz))->getTimestamp()), gmdate('Y-m-d\TH:i:s\Z', (new DateTime($day . ' +1 day', $tz))->getTimestamp())];
    }

    /** Revenue (incl. BTW), BTW, cost of goods and profit (excl. BTW, minus cost) of orders paid in [from, to). */
    public static function sales(string $from, string $to): array {
        $r = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS revenue, COALESCE(SUM(vat_cents), 0) AS vat FROM orders WHERE payment_status IN ('paid','partially_refunded') AND paid_at >= ? AND paid_at < ?", [$from, $to]);
        $cogs = (int) DB::val("SELECT COALESCE(SUM(oi.unit_cost_cents * oi.qty), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.payment_status IN ('paid','partially_refunded') AND o.paid_at >= ? AND o.paid_at < ?", [$from, $to]);
        $net = (int) $r['revenue'] - (int) $r['vat'];
        return ['orders' => (int) $r['n'], 'revenue' => (int) $r['revenue'], 'vat' => (int) $r['vat'], 'cogs' => $cogs, 'profit' => $net - $cogs, 'margin' => $net > 0 ? (int) round(($net - $cogs) / $net * 100) : null];
    }

    public static function today(): array {
        $today = Analytics::day();
        $lw = date('Y-m-d', strtotime("$today 12:00 UTC") - 7 * 86400);
        [$a, $b] = self::dayBounds($today); [$la, $lb] = self::dayBounds($lw);
        $t = self::sales($a, $b); $l = self::sales($la, $lb);
        $ordersToday = (int) DB::val("SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ? AND status <> 'cancelled'", [$a, $b]);
        $ordersLw = (int) DB::val("SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ? AND status <> 'cancelled'", [$la, $lb]);
        $deliv = DB::one("SELECT SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN o.status IN ('paid','packing','out_for_delivery','failed_delivery') THEN 1 ELSE 0 END) AS todo
            FROM orders o JOIN delivery_slots s ON s.id = o.slot_id WHERE s.date = ? AND o.payment_status = 'paid'", [$today]);

        // 7 days of sales
        $days = [];
        for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("$today 12:00 UTC") - $i * 86400); [$x, $y] = self::dayBounds($d); $days[] = ['d' => $d, 'v' => self::sales($x, $y)['revenue']]; }

        Delivery::ensureSlots();
        $windows = array_map(fn($s) => Delivery::describe($s) + ['orders' => (int) DB::val("SELECT COUNT(*) FROM orders WHERE slot_id = ? AND payment_status = 'paid' AND status <> 'cancelled'", [$s['id']])],
            DB::all('SELECT * FROM delivery_slots WHERE date = ? ORDER BY start_time', [$today]));

        $month = (new DateTime('first day of this month', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d');
        [$ma] = self::dayBounds($month);
        $m = self::sales($ma, $b);
        $exp = DB::one('SELECT COALESCE(SUM(amount_cents), 0) AS a, COALESCE(SUM(vat_cents), 0) AS v FROM expenses WHERE date >= ? AND date <= ?', [$month, $today]);
        $best = DB::all("SELECT oi.name_snapshot AS name, oi.product_id, SUM(oi.qty) AS sold FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE o.payment_status = 'paid' AND o.paid_at >= ? GROUP BY oi.product_id, oi.name_snapshot ORDER BY sold DESC LIMIT 4", [DB::now(-7 * 86400)]);

        return [
            'kpi' => ['sales' => $t['revenue'], 'salesLw' => $l['revenue'], 'orders' => $ordersToday, 'ordersLw' => $ordersLw,
                'done' => (int) ($deliv['done'] ?? 0), 'left' => (int) ($deliv['todo'] ?? 0), 'profit' => $t['profit'], 'margin' => $t['margin'], 'profitLw' => $l['profit']],
            'lastWeekday' => (new DateTime($lw))->format('D'),
            'days' => $days, 'windows' => $windows,
            'month' => ['label' => (new DateTime($month))->format('F'), 'range' => (new DateTime($month))->format('j') . '–' . (new DateTime($today))->format('j M'),
                'revenue' => $m['revenue'], 'costs' => $m['cogs'] + (int) $exp['a'], 'profit' => $m['profit'] - ((int) $exp['a'] - (int) $exp['v']), 'btw' => $m['vat'] - (int) $exp['v']],
            'best' => array_map(fn($r) => ['name' => preg_replace('/ · .*$/', '', $r['name']), 'sold' => (int) $r['sold']], $best),
        ];
    }

    /** "Needs your attention" items for orders and stock, each with a deep link. [kind, title, subtitle, link] */
    public static function attention(): array {
        $out = [];
        $unpaid = DB::all("SELECT number FROM orders WHERE status = 'new' AND payment_status = 'unpaid' AND created_at < ? ORDER BY id", [DB::now(-30 * 60)]);
        if ($unpaid) $out[] = ['w', count($unpaid) . ' order' . (count($unpaid) > 1 ? 's' : '') . ' not paid yet', implode(', ', array_map(fn($r) => '#' . $r['number'], array_slice($unpaid, 0, 4))) . ' · waiting over 30 min', 'orders:new'];
        $failed = DB::all("SELECT o.id, o.number, c.name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.status = 'failed_delivery'");
        foreach (array_slice($failed, 0, 3) as $f) $out[] = ['b', 'Delivery failed — nobody home', '#' . $f['number'] . ' · ' . ($f['name'] ?: ''), 'order:' . $f['id']];
        foreach (DB::all("SELECT id, number FROM orders WHERE needs_new_slot = 1 AND status NOT IN ('cancelled','delivered')") as $o) $out[] = ['b', 'Order needs a new delivery window', '#' . $o['number'] . ' · the window filled up while they paid', 'order:' . $o['id']];
        $low = DB::all("SELECT p.name, SUM(v.stock) AS s, p.low_stock_at FROM products p JOIN variants v ON v.product_id = p.id AND v.active = 1 WHERE p.active = 1 GROUP BY p.id, p.name, p.low_stock_at HAVING SUM(v.stock) <= p.low_stock_at");
        if ($low) {
            $out[] = ['w', count($low) . ' product' . (count($low) > 1 ? 's' : '') . ' running low', implode(' · ', array_map(fn($r) => $r['name'] . ((int) $r['s'] <= 0 ? ' sold out' : ' (' . $r['s'] . ')'), array_slice($low, 0, 4))), 'stock']; }
        foreach (DB::all('SELECT * FROM delivery_slots WHERE date >= ? AND date <= ? ORDER BY date, start_time', [Analytics::day(), date('Y-m-d', strtotime('+2 days'))]) as $s) {
            $d = Delivery::describe($s);
            if ($d['open'] && $d['capacity'] && ($d['capacity'] - $d['left']) / $d['capacity'] >= .9) $out[] = ['n', 'Window almost full', ($d['capacity'] - $d['left']) . ' of ' . $d['capacity'] . ' booked · ' . $d['label'], 'settings:delivery'];
        }
        // cash a courier took at the door on an earlier day and has not handed over yet
        $tz = new DateTimeZone(TZ_LOCAL);
        $today = gmdate('Y-m-d\TH:i:s\Z', (new DateTime('today', $tz))->getTimestamp());
        foreach (Cash::open() as $c) if ($c['since'] < $today) {
            $n = count($c['orders']);
            $out[] = ['w', eur($c['total']) . ' cash with ' . $c['courier'], "$n order" . ($n > 1 ? 's' : '') . ' paid at the door since ' . Delivery::dayName((new DateTime($c['since']))->setTimezone($tz)->format('Y-m-d')) . ' · confirm when you have it', 'delivery:cash'];
        }
        $rs = (int) DB::val('SELECT COUNT(*) FROM restock_requests WHERE notified_at IS NULL');
        if ($rs) $out[] = ['n', "$rs restock request" . ($rs > 1 ? 's' : ''), 'People asked to hear when sold-out items are back', 'marketing:restock'];
        return $out;
    }
}

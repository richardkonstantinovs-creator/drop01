<?php
// Waitlist dashboard and shop funnel numbers: every figure is an SQL aggregate over events (raw + rolled-up daily rows) and signups.
defined('DROP01') || exit;

final class Dashboard {
    /** range: 1 = today, 7, 30, 0 = all. Returns the payload for the admin Waitlist tab. */
    public static function waitlist(int $range): array {
        $today = Analytics::day();
        $first = min(array_filter([DB::val('SELECT MIN(day) FROM events'), DB::val('SELECT MIN(day) FROM events_daily'), self::signupFirstDay()]) ?: [$today]);
        $span = $range ?: max(1, min(400, (int) ((strtotime($today) - strtotime($first)) / 86400) + 1));
        $from = self::dayOffset($today, -($span - 1));
        $prevFrom = self::dayOffset($from, -$span); $prevTo = self::dayOffset($from, -1);
        // the trend chart shows at least a week, so "Today" still has context
        $chartFrom = $range === 1 ? self::dayOffset($today, -6) : $from;

        $visitsByDay = self::sessions('pageview', $chartFrom, $today, 'day');
        $signupsByDay = self::signupsByDay($chartFrom, $today);
        $days = [];
        for ($d = $chartFrom; $d <= $today; $d = self::dayOffset($d, 1)) $days[] = ['d' => $d, 'visits' => $visitsByDay[$d] ?? 0, 'signups' => $signupsByDay[$d] ?? 0];

        $visits = self::total(self::sessions('pageview', $from, $today));
        $signups = array_sum(self::signupsByDay($from, $today));
        $hasPrev = $range === 1 || $range === 7;
        $pv = $hasPrev ? self::total(self::sessions('pageview', $prevFrom, $prevTo)) : null;
        $ps = $hasPrev ? array_sum(self::signupsByDay($prevFrom, $prevTo)) : null;

        $shown = self::total(self::sessions('intro_shown', $from, $today));
        $opened = self::total(self::sessions('intro_opened', $from, $today));
        $pShown = $hasPrev ? self::total(self::sessions('intro_shown', $prevFrom, $prevTo)) : null;
        $pOpened = $hasPrev ? self::total(self::sessions('intro_opened', $prevFrom, $prevTo)) : null;
        $avg = self::avgTime($from, $today);
        $pAvg = $hasPrev ? self::avgTime($prevFrom, $prevTo) : null;

        $gift = Settings::get('gift');
        $claimed = Waitlist::giftsIssued();
        $limit = (int) ($gift['limit'] ?? 0);
        $weekGifts = (int) DB::val('SELECT COUNT(*) FROM waitlist_signups WHERE gift_code IS NOT NULL AND created_at >= ?', [DB::now(-7 * 86400)]);

        return [
            'range' => $range, 'from' => $from, 'to' => $today,
            'total' => Waitlist::total(), 'inRange' => $signups,
            'gift' => ['claimed' => $claimed, 'limit' => $limit ?: null, 'left' => $limit ? max(0, $limit - $claimed) : null,
                'etaDays' => ($limit && $weekGifts > 0 && $claimed < $limit && ($eta = (int) ceil(($limit - $claimed) / ($weekGifts / 7))) <= 365) ? $eta : null],
            'dropDate' => date('c', Settings::dropTs()),
            'live' => (int) DB::val('SELECT COUNT(DISTINCT session_id) FROM events WHERE ts >= ?', [DB::now(-300)]),
            'kpis' => [
                'visitors' => ['v' => $visits, 'prev' => $pv],
                'unique' => ['v' => self::uniqueVisitors($from, $today), 'prev' => $hasPrev ? self::uniqueVisitors($prevFrom, $prevTo) : null],
                'conversion' => ['v' => $visits ? round($signups / $visits * 100, 1) : 0, 'prev' => ($hasPrev && $pv) ? round($ps / $pv * 100, 1) : null],
                'opened' => ['v' => $shown ? round($opened / $shown * 100) : null, 'prev' => ($hasPrev && $pShown) ? round($pOpened / $pShown * 100) : null],
                'avgTime' => ['v' => $avg, 'prev' => $pAvg],
            ],
            'days' => $days,
            'funnel' => [
                ['Landed on the site', $visits],
                ['Opened the pack', $opened],
                ['Typed an email', self::total(self::sessions('email_started', $from, $today))],
                ['Added a phone', self::total(self::sessions('phone_added', $from, $today))],
                ['Joined the waitlist', self::total(self::sessions('signup', $from, $today))],
            ],
            'sources' => self::share(self::sessions('pageview', $from, $today, 'source'), Analytics::SOURCES),
            'devices' => self::share(self::sessions('pageview', $from, $today, 'device'), ['iPhone', 'Android', 'Desktop']),
            'countries' => self::share(self::sessions('pageview', $from, $today, 'country')),
            'products' => self::products(),
        ];
    }

    /** Shop funnel for Marketing: sessions per step (viewed → bag → checkout → order placed), then paid web orders. days: 7, 30, 90, 0 = all. */
    public static function shop(int $days): array {
        $today = Analytics::day();
        $firstOrder = DB::val("SELECT MIN(created_at) FROM orders WHERE channel = 'web'");
        $first = min(array_filter([DB::val('SELECT MIN(day) FROM events'), DB::val('SELECT MIN(day) FROM events_daily'), $firstOrder ? Analytics::day(strtotime($firstOrder)) : null]) ?: [$today]);
        $from = $days ? self::dayOffset($today, -($days - 1)) : $first;
        $n = fn(string $t) => self::total(self::sessions($t, $from, $today));
        $paid = 0;
        foreach (DB::all('SELECT created_at FROM orders WHERE channel = ? AND paid_at IS NOT NULL AND created_at >= ?', ['web', self::dayOffset($from, -1) . 'T00:00:00Z']) as $r) {
            if (Analytics::day(strtotime($r['created_at'])) >= $from) $paid++;
        }
        return ['days' => $days, 'from' => $from, 'to' => $today, 'steps' => [
            ['view', 'Viewed a product', $n('product_view')],
            ['bag', 'Added to the bag', $n('add_to_cart')],
            ['checkout', 'Opened checkout', $n('checkout_started')],
            ['order', 'Placed the order', $n('payment_started')],
            ['paid', 'Paid', $paid],
        ]];
    }

    private static function dayOffset(string $day, int $n): string { return date('Y-m-d', strtotime("$day 12:00 UTC") + $n * 86400); }

    private static function signupFirstDay(): ?string {
        $t = DB::val('SELECT MIN(created_at) FROM waitlist_signups');
        return $t ? Analytics::day(strtotime($t)) : null;
    }

    /** Distinct sessions with an event of $type, optionally grouped by day/source/device/country. */
    public static function sessions(string $type, string $from, string $to, ?string $by = null): array {
        $col = $by ? (in_array($by, ['day', 'source', 'device', 'country'], true) ? $by : 'day') : "'all'";
        $out = [];
        foreach (DB::all("SELECT $col AS g, COUNT(DISTINCT session_id) AS n FROM events WHERE type = ? AND day BETWEEN ? AND ? GROUP BY $col", [$type, $from, $to]) as $r) $out[(string) $r['g']] = (int) $r['n'];
        foreach (DB::all("SELECT $col AS g, SUM(sessions) AS n FROM events_daily WHERE type = ? AND day BETWEEN ? AND ? GROUP BY $col", [$type, $from, $to]) as $r) $out[(string) $r['g']] = ($out[(string) $r['g']] ?? 0) + (int) $r['n'];
        unset($out['']);
        return $out;
    }

    private static function total(array $g): int { return array_sum($g); }

    /** Unique visitors: the visitor hash rotates daily, so this is the sum of each day's unique visitors. */
    private static function uniqueVisitors(string $from, string $to): int {
        $raw = (int) DB::val('SELECT COALESCE(SUM(n), 0) FROM (SELECT COUNT(DISTINCT visitor_hash) AS n FROM events WHERE type = ? AND day BETWEEN ? AND ? GROUP BY day) t', ['pageview', $from, $to]);
        return $raw + (int) DB::val('SELECT COALESCE(SUM(visitors), 0) FROM events_daily WHERE type = ? AND day BETWEEN ? AND ?', ['pageview', $from, $to]);
    }

    /** Average seconds between a session's first and last event, over sessions with at least two events. */
    private static function avgTime(string $from, string $to): ?int {
        $rows = DB::all('SELECT MIN(ts) AS a, MAX(ts) AS b FROM events WHERE day BETWEEN ? AND ? GROUP BY session_id HAVING COUNT(*) > 1', [$from, $to]);
        if (!$rows) return null;
        $sum = 0; $n = 0;
        foreach ($rows as $r) { $d = strtotime($r['b']) - strtotime($r['a']); if ($d > 0 && $d < 3 * 3600) { $sum += $d; $n++; } }
        return $n ? (int) round($sum / $n) : null;
    }

    private static function signupsByDay(string $from, string $to): array {
        // created_at is UTC; widen by a day either side and bucket in Amsterdam time in PHP (portable across SQLite/MySQL)
        $rows = DB::all('SELECT created_at FROM waitlist_signups WHERE created_at >= ? AND created_at <= ?', [$from . 'T00:00:00Z', self::dayOffset($to, 1) . 'T23:59:59Z']);
        $out = [];
        foreach ($rows as $r) { $d = Analytics::day(strtotime($r['created_at'])); if ($d >= $from && $d <= $to) $out[$d] = ($out[$d] ?? 0) + 1; }
        return $out;
    }

    /** [[label, fraction], ...] sorted by size; known labels first only when present. */
    private static function share(array $g, array $order = []): array {
        $tot = array_sum($g);
        if (!$tot) return [];
        arsort($g);
        $out = [];
        foreach ($g as $k => $n) $out[] = [(string) $k, round($n / $tot, 4), $n];
        return $out;
    }

    private static function products(): array {
        $min = (int) Settings::get('most_wanted_min');
        $rows = DB::all('SELECT p.id, p.slug, p.name, p.run_size, p.reveal,
            (SELECT COUNT(*) FROM votes v WHERE v.product_id = p.id AND v.value = 1) AS likes,
            (SELECT COUNT(*) FROM votes v WHERE v.product_id = p.id AND v.value = -1) AS dislikes
            FROM products p WHERE p.on_waitlist = 1 AND p.active = 1 ORDER BY p.sort, p.id');
        $best = null; $bestN = $min - 1;
        foreach ($rows as $r) if ((int) $r['likes'] > $bestN) { $bestN = (int) $r['likes']; $best = $r['id']; }
        return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int) $r['run_size'], 'likes' => (int) $r['likes'], 'dislikes' => (int) $r['dislikes'], 'best' => $r['id'] === $best], $rows);
    }
}

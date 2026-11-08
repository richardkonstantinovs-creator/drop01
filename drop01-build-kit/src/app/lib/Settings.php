<?php
// Key/value store settings (JSON values) with defaults, plus encrypted secrets (SMTP password, Mollie keys).
defined('DROP01') || exit;

final class Settings {
    private static ?array $cache = null;

    /** Every setting the app knows, with its default. The admin edits these; the public pages read them. */
    public static function defaults(): array {
        return [
            'store_name'      => 'Drop 01',
            'logo_text'       => 'DROP/01',
            'store_mode'      => 'waitlist',          // waitlist | shop
            'waitlist_after_launch' => 'keep',        // keep | redirect (what /waitlist does in shop mode)
            'drop_date'       => '2026-11-27T18:00',  // local Amsterdam time
            'gift'            => ['title' => 'A free mystery gift', 'text' => 'Packed with your first order, on us.', 'limit' => 500, 'prefix' => 'D01'],
            'proof_min'       => 25,                  // hide "X already in line" until there are this many real signups
            'most_wanted_min' => 3,
            'marquee'         => ['Free gift for the waitlist', 'Limited run', 'Drop 01', 'Early access first'],
            'countries'       => [['NL','+31'],['BE','+32'],['DE','+49'],['FR','+33'],['GB','+44'],['ES','+34'],['IT','+39'],['PL','+48'],['US','+1'],['CA','+1'],['UA','+380'],['KZ','+7'],['RU','+7'],['TR','+90'],['AE','+971']],
            'company'         => ['legal_name' => '', 'kvk' => '', 'btw' => '', 'address' => '', 'depot' => '', 'email' => '', 'whatsapp' => ''],
            'smtp'            => ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'from' => '', 'from_name' => ''],
            'imap'            => ['enabled' => false, 'host' => '', 'port' => 993, 'secure' => 'ssl', 'user' => '', 'who' => 'all'],   // Inbox: read customer emails; empty user/password = the SMTP login
            'telegram'        => ['bot' => '', 'name' => '', 'mode' => '', 'welcome' => "Hi! You're chatting with {store}. Write your question here and we reply as soon as we can."],   // mode: webhook | poll
            'mollie'          => ['test_mode' => true],
            'tikkie_text'     => 'We send you a Tikkie payment link on WhatsApp within 30 minutes (09:00–21:00). Your order is reserved until then.',
            // shop
            'shop' => [
                'free_from_cents' => 3500,         // free delivery threshold (zones can override)
                'ends_at' => '',                   // optional "drop closes" countdown, local time; empty hides the timer
                'order_start' => 1001,             // first order number
                'reservation_min' => 15,           // stock held while paying
                'unpaid_timeout_min' => 60,        // unpaid online orders are cancelled after this
                'tikkie_timeout_hours' => 24,      // Tikkie / payment-link orders wait longer
                'max_per_item' => 5,               // most pieces of one product (all sizes together) in one shop order
                'announce' => ['Free delivery in Groningen from €35', '✦ Order before 16:00 → at your door tonight', '✦ Pay with iDEAL', '✦ 14-day free returns'],
            ],
            'delivery' => [
                'windows' => [['start' => '12:00', 'end' => '15:00', 'capacity' => 10], ['start' => '15:00', 'end' => '18:00', 'capacity' => 10], ['start' => '18:00', 'end' => '21:00', 'capacity' => 10]],
                'days' => [1, 2, 3, 4, 5, 6, 7],   // ISO weekdays with deliveries
                'cutoff_min' => 120,               // a window closes this many minutes before it starts (18:00 → 16:00)
                'days_ahead' => 3,
                'blackout' => [],                  // ['2026-12-25', ...]
                'speed_kmh' => 25,                 // average speed in town, for ETAs
                'stop_min' => 4,                   // minutes per stop (park, ring, hand over)
                'travel' => 'driving',             // Google Maps mode: driving | bicycling | walking
                'self_reschedule' => true,         // customers may change their window on the order page (before cut-off)
                'notify' => ['out_for_delivery' => true, 'on_my_way' => true, 'delivered' => true, 'failed_delivery' => true, 'rescheduled' => true],   // automatic emails
            ],
            'referral' => ['friend_cents' => 500, 'referrer_cents' => 500],
            // Money → unit economics: what one order costs you besides the goods, and what you aim for. Costs excl. the BTW you get back.
            'costs' => ['delivery_cents' => 0, 'fee_cents' => 0, 'fee_pct' => 0, 'fixed_month_cents' => 0, 'target_pct' => 30, 'reorder_days' => 14, 'cover_days' => 30],
            'marketing' => ['abandoned_cart' => true, 'per_hour' => 200],   // reminder email for left bags; broadcast emails sent per hour (SMTP limits)
            'legal_reviewed'  => false,
            'cron_last_run'   => null,
            'site_url'        => '',   // https://yourdomain.nl — the address put in emails and links (never taken from a request header); empty = filled in on install / next login
            'offsite'         => ['email' => '', 'last' => null],   // nightly encrypted copy of the database by email; last = {at, ok, error, size}
            'notify_signups'  => false,
        ];
    }

    private static function load(): void {
        if (self::$cache !== null) return;
        self::$cache = [];
        foreach (DB::all('SELECT k, v FROM settings') as $r) self::$cache[$r['k']] = json_decode($r['v'], true);
    }

    public static function get(string $key, $fallback = null) {
        self::load();
        if (array_key_exists($key, self::$cache)) {
            $v = self::$cache[$key];
            $d = self::defaults()[$key] ?? null;
            // merge new default sub-keys into older stored objects
            if (is_array($v) && is_array($d) && array_keys($d) !== range(0, count($d) - 1)) $v = array_replace($d, $v);
            return $v;
        }
        return self::defaults()[$key] ?? $fallback;
    }

    public static function set(string $key, $value): void {
        DB::upsert('settings', ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['k']);
        self::load();
        self::$cache[$key] = $value;
    }

    public static function flush(): void { self::$cache = null; }

    // ---- secrets: stored encrypted with the app key so a leaked DB file alone does not leak passwords

    public static function setSecret(string $key, string $plain): void {
        self::set('secret.' . $key, $plain === '' ? '' : Crypto::encrypt($plain));
    }
    public static function secret(string $key): string {
        $v = self::get('secret.' . $key, '');
        return $v ? (Crypto::decrypt($v) ?? '') : '';
    }
    public static function hasSecret(string $key): bool { return (string) self::get('secret.' . $key, '') !== ''; }

    /** The drop date as a timestamp (stored as local Amsterdam time). */
    public static function dropTs(): int {
        return (new DateTime(self::get('drop_date'), new DateTimeZone(TZ_LOCAL)))->getTimestamp();
    }
}

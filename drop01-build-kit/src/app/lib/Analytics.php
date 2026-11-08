<?php
// Cookieless analytics: daily-rotating visitor hash, source/device/country derivation and event storage.
defined('DROP01') || exit;

final class Analytics {
    public const TYPES = ['pageview', 'leave', 'intro_shown', 'intro_opened', 'intro_completed', 'email_started', 'phone_added', 'signup',
        'vote', 'product_view', 'add_to_cart', 'checkout_started', 'payment_started', 'purchase', 'postcode_check', 'notify_me'];
    public const SOURCES = ['Instagram', 'TikTok', 'WhatsApp', 'Google', 'Direct', 'Other'];

    /** Amsterdam calendar day for a timestamp. */
    public static function day(?int $ts = null): string {
        return (new DateTime('@' . ($ts ?? time())))->setTimezone(new DateTimeZone(TZ_LOCAL))->format('Y-m-d');
    }

    /** Today's salt. Old salts are deleted as soon as a new day starts, so hashes cannot be linked across days. */
    public static function salt(): string {
        $day = self::day();
        $s = DB::val('SELECT salt FROM daily_salts WHERE day = ?', [$day]);
        if ($s) return $s;
        $s = Crypto::token(32);
        DB::upsert('daily_salts', ['day' => $day, 'salt' => $s], ['day']);
        DB::q('DELETE FROM daily_salts WHERE day <> ?', [$day]);
        return DB::val('SELECT salt FROM daily_salts WHERE day = ?', [$day]) ?: $s;
    }

    public static function visitorHash(): string { return hash('sha256', self::salt() . Http::ip() . Http::ua()); }

    public static function device(string $ua): string {
        if (preg_match('/iPhone|iPod/i', $ua)) return 'iPhone';
        if (preg_match('/Android/i', $ua)) return 'Android';
        return 'Desktop';
    }

    public static function isBot(string $ua): bool { return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget/i', $ua); }

    /** Region from a CDN country header or the Accept-Language region ("nl-NL" → NL). Null when unknown. */
    public static function country(): ?string {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE'] as $h) {
            if (!empty($_SERVER[$h]) && preg_match('/^[A-Z]{2}$/', $_SERVER[$h]) && $_SERVER[$h] !== 'XX') return $_SERVER[$h];
        }
        if (preg_match('/^[a-z]{2,3}-([A-Z]{2})\b/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', $m)) return strtoupper($m[1]);
        return null;
    }

    /** Source from UTM first, then the referrer host. Internal referrers count as Direct. */
    public static function source(?string $utm, ?string $refHost): string {
        if ($utm) {
            $u = strtolower($utm);
            if ($u === 'ig' || str_contains($u, 'instagram')) return 'Instagram';
            if (str_contains($u, 'tiktok')) return 'TikTok';
            if ($u === 'wa' || str_contains($u, 'whatsapp')) return 'WhatsApp';
            if (str_contains($u, 'google')) return 'Google';
            return 'Other';
        }
        $h = strtolower((string) $refHost);
        $self = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        if ($h === '' || $h === $self) return 'Direct';
        if (preg_match('/(^|\.)instagram\.com$/', $h)) return 'Instagram';
        if (preg_match('/(^|\.)tiktok\.com$/', $h)) return 'TikTok';
        if (preg_match('/(^|\.)(whatsapp\.com|whatsapp\.net|wa\.me)$/', $h)) return 'WhatsApp';
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $h)) return 'Google';
        return 'Other';
    }

    /** Stores a batch from POST /api/e. Each event: {type, path?, ref?, utm?{source,medium,campaign}, product?, value?}. */
    public static function record(string $sessionId, array $events): int {
        $ua = Http::ua();
        if (self::isBot($ua) || !preg_match('/^[A-Za-z0-9_-]{8,40}$/', $sessionId)) return 0;
        $vh = self::visitorHash();
        $dev = self::device($ua);
        $cty = self::country();
        $now = DB::now();
        $day = self::day();
        $n = 0;
        foreach (array_slice($events, 0, 25) as $ev) {
            $type = (string) ($ev['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) continue;
            $utm = is_array($ev['utm'] ?? null) ? $ev['utm'] : [];
            $refHost = null;
            if (!empty($ev['ref'])) $refHost = substr((string) (parse_url((string) $ev['ref'], PHP_URL_HOST) ?? ''), 0, 120) ?: null;
            $pid = null;
            if (!empty($ev['product'])) $pid = DB::val('SELECT id FROM products WHERE slug = ?', [substr((string) $ev['product'], 0, 120)]);
            DB::insert('events', [
                'ts' => $now, 'day' => $day, 'visitor_hash' => $vh, 'session_id' => $sessionId, 'type' => $type,
                'path' => substr((string) ($ev['path'] ?? ''), 0, 200) ?: null, 'ref_host' => $refHost,
                'source' => $type === 'pageview' ? self::source($utm['source'] ?? null, $refHost) : null,
                'utm_source' => self::clip($utm['source'] ?? null), 'utm_medium' => self::clip($utm['medium'] ?? null), 'utm_campaign' => self::clip($utm['campaign'] ?? null),
                'device' => $dev, 'country' => $cty, 'product_id' => $pid ? (int) $pid : null, 'value' => self::clip($ev['value'] ?? null),
            ]);
            $n++;
        }
        return $n;
    }

    private static function clip($v): ?string { return $v === null || $v === '' ? null : substr((string) $v, 0, 80); }

    /** Cron: roll raw events older than 13 months into events_daily, then delete them. */
    public static function rollup(): int {
        $cut = (new DateTime('now', new DateTimeZone(TZ_LOCAL)))->modify('-13 months')->format('Y-m-d');
        $rows = DB::all("SELECT day, type, COALESCE(source,'') AS source, COALESCE(device,'') AS device, COALESCE(country,'') AS country,
            COUNT(DISTINCT session_id) AS sessions, COUNT(DISTINCT visitor_hash) AS visitors, COUNT(*) AS n
            FROM events WHERE day < ? GROUP BY day, type, COALESCE(source,''), COALESCE(device,''), COALESCE(country,'')", [$cut]);
        DB::tx(function () use ($rows, $cut) {
            foreach ($rows as $r) DB::upsert('events_daily', $r, ['day', 'type', 'source', 'device', 'country']);
            DB::q('DELETE FROM events WHERE day < ?', [$cut]);
        });
        return count($rows);
    }
}

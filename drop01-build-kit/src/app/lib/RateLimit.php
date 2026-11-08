<?php
// Fixed-window rate limiter stored in the database, keyed by action + IP hash (or any id).
defined('DROP01') || exit;

final class RateLimit {
    /** Returns true when the call is allowed; counts the hit. */
    public static function hit(string $action, int $max, int $windowSec, ?string $id = null): bool {
        $k = substr($action . ':' . ($id ?? Crypto::ipHash()), 0, 191);
        $now = time();
        $win = $now - ($now % $windowSec);
        $row = DB::one('SELECT window_start, hits FROM rate_limits WHERE k = ?', [$k]);
        if (!$row || (int) $row['window_start'] !== $win) {
            DB::upsert('rate_limits', ['k' => $k, 'window_start' => $win, 'hits' => 1], ['k']);
            return true;
        }
        if ((int) $row['hits'] >= $max) return false;
        DB::q('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$k]);
        return true;
    }

    public static function enforce(string $action, int $max, int $windowSec, ?string $id = null): void {
        if (!self::hit($action, $max, $windowSec, $id)) Http::error(t('err.too_many'), 429);
    }

    /** Housekeeping for cron: drop windows older than a day. */
    public static function purge(): void { DB::q('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]); }
}

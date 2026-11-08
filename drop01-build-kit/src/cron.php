<?php
// Scheduled jobs (run every 5 minutes by cPanel cron): new Inbox email and Telegram (poll mode), back-in-stock and abandoned-cart emails, email queue, salt rotation, clean-up, nightly backup (+ the emailed off-site copy), analytics roll-up.
// Runs only from the command line, or over HTTP with ?token=<cron_token> from data/config.php.
define('DROP01', 1);
require __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain');
    $tok = (string) ($_GET['token'] ?? '');
    if (!is_installed() || !config('cron_token') || !hash_equals((string) config('cron_token'), $tok)) { http_response_code(403); exit("Forbidden\n"); }
}
if (!is_installed()) exit("Not installed\n");

$lock = fopen(data_dir() . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Already running\n");

DB::pdo();
$log = [];
$job = function (string $name, callable $fn) use (&$log) {
    try { $r = $fn(); $log[] = "$name: " . (is_scalar($r) ? $r : 'ok'); }
    catch (Throwable $e) { $log[] = "$name: ERROR " . $e->getMessage(); Log::error("cron $name: " . $e->getMessage()); }
};

$job('migrations', fn() => implode(',', DB::migrate()) ?: 'none');
$job('stock_holds', fn() => Stock::releaseExpired());
$job('unpaid_orders', fn() => Orders::expireUnpaid());
$job('slots', function () { Delivery::ensureSlots(); return 'ok'; });
$job('back_in_stock', fn() => Restock::notify());
$job('abandoned_carts', fn() => Cart::remindAbandoned());
$job('inbox', function () { $r = Inbox::poll(true); return isset($r['skipped']) ? 'busy' : 'email ' . ($r['email'] ?? 'off') . ', telegram ' . ($r['telegram'] ?? 'off'); });
$job('emails', fn() => Messages::flushQueue());
$job('salt', fn() => Analytics::salt() ? 'ok' : '');
$job('rate_limits', function () { RateLimit::purge(); return 'ok'; });
$job('login_attempts', fn() => DB::q('DELETE FROM login_attempts WHERE created_at < ?', [DB::now(-30 * 86400)])->rowCount());

// nightly (first run after 03:00 Amsterdam time): backup + analytics roll-up
$local = new DateTime('now', new DateTimeZone(TZ_LOCAL));
$today = $local->format('Y-m-d');
if ((int) $local->format('G') >= 3 && Settings::get('nightly_done') !== $today) {
    $job('backup', function () { $f = Backup::create('nightly'); Backup::prune(14); return basename($f); });
    $job('offsite_backup', function () { if (!Backup::offsiteReady()) return 'off'; $r = Backup::offsite(); if (!$r['ok']) throw new RuntimeException($r['error']); return 'sent'; });
    $job('analytics_rollup', fn() => Analytics::rollup());
    Settings::set('nightly_done', $today);
}

Settings::set('cron_last_run', DB::now());
flock($lock, LOCK_UN);
echo implode("\n", $log), "\n";

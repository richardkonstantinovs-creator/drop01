<?php
// Tiny test runner (no PHPUnit): runs every test_* function in tools/tests/*Test.php against a fresh temporary SQLite database.
// Usage: tools/php tools/tests/run.php
define('DROP01', 1);
require __DIR__ . '/../../src/app/bootstrap.php';

$tmp = sys_get_temp_dir() . '/d01test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/backups', 0700, true);
$GLOBALS['__drop01_cfg'] = ['db' => ['driver' => 'sqlite', 'path' => "$tmp/app.sqlite"], 'data_dir' => $tmp, 'app_key' => str_repeat('k', 64), 'cron_token' => 't'];
$_SERVER += ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'HTTP_HOST' => 'shop.test'];
DB::pdo();
DB::migrate();

function eq($a, $b, string $msg = ''): void { if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true)); }
function ok($c, string $msg): void { if (!$c) throw new Exception($msg); }
/** Empties the tables a test touches so tests do not depend on each other. */
function fresh(): void {
    foreach (['waitlist_signups', 'votes', 'products', 'variants', 'stock_movements', 'product_images', 'events', 'events_daily', 'settings', 'rate_limits', 'daily_salts', 'admins', 'login_attempts'] as $t) DB::q("DELETE FROM $t");
    Settings::flush();
}

foreach (glob(__DIR__ . '/*Test.php') as $f) require $f;
$pass = 0; $fail = 0;
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_')) continue;
    fresh();
    try { $fn(); $pass++; echo "  ✓ $fn\n"; }
    catch (Throwable $e) { $fail++; echo "  ✕ $fn — " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

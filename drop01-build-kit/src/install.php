<?php
// First-run installer: checks the server, writes data/config.php, creates the database, the owner account, email + Telegram for the Inbox and demo data, then locks itself.
if (!defined('DROP01')) define('DROP01', 1);
require __DIR__ . '/app/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$done = null; $errors = [];
if (is_installed() || basename(__FILE__) !== 'install.php') {
    install_page('Already installed', '<p>This store is already installed. For safety the installer cannot run twice.</p><p><a class="btn" href="/admin">Open the admin</a></p>');
    exit;
}

// double-submit token against cross-site form posts
if (empty($_COOKIE['d01inst']) || !preg_match('/^[a-f0-9]{32}$/', $_COOKIE['d01inst'])) {
    $tok = bin2hex(random_bytes(16));
    setcookie('d01inst', $tok, ['path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => Http::isHttps()]);
} else $tok = $_COOKIE['d01inst'];

$checks = Requirements::check();
$v = $_POST + ['store' => 'Drop 01', 'name' => '', 'email' => '', 'db' => 'sqlite', 'my_host' => 'localhost', 'my_name' => '', 'my_user' => '',
    'smtp_host' => '', 'smtp_port' => '587', 'smtp_user' => '', 'smtp_from' => '', 'imap_host' => '', 'tg_token' => '', 'demo' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($tok, (string) ($_POST['tok'] ?? ''))) $errors[] = 'The form expired. Reload the page and try again.';
    if (!Requirements::allRequiredOk()) $errors[] = 'Fix the red items in the checklist first.';
    $email = strtolower(trim((string) $v['email']));
    if (trim((string) $v['store']) === '') $errors[] = 'Enter your store name.';
    if (trim((string) $v['name']) === '') $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email for your admin login.';
    if (strlen((string) ($_POST['password'] ?? '')) < 10) $errors[] = 'Choose a password of at least 10 characters.';
    if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) $errors[] = 'The two passwords are not the same.';
    if (trim((string) $v['tg_token']) !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', trim((string) $v['tg_token']))) $errors[] = 'The Telegram bot token looks wrong. It looks like 123456789:AAH… — copy the whole line from @BotFather, or leave it empty.';
    if (!$errors) {
        try { $done = run_install($v, $email); }
        catch (Throwable $e) { $errors[] = 'Install failed: ' . $e->getMessage(); Log::error('install: ' . $e->getMessage()); }
    }
}

if ($done) {
    $cronPhp = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINDIR . '/php';
    $cmd = $cronPhp . ' ' . __DIR__ . '/cron.php >/dev/null 2>&1';
    $locked = @rename(__FILE__, __DIR__ . '/install.lock.php');
    install_page('Your store is installed', '
      <p class="ok">✓ Database created · ✓ Owner account ' . e($done['email']) . ' · ✓ Data stored in <code>' . e($done['data']) . '</code></p>
      ' . ($done['telegram'] ? '<p class="ok">✓ Telegram bot <b>@' . e($done['telegram']) . '</b> connected. Customer messages to it appear in the admin under <b>Inbox</b>.</p>' : '') . '
      ' . ($done['telegram_error'] ? '<p class="warn">Telegram is not connected yet: ' . e($done['telegram_error']) . ' You can connect it later in the admin → Settings → Telegram.</p>' : '') . '
      <h2>Last step: the cron job</h2>
      <p>This runs background jobs every 5 minutes: emails, backups, clean-up. In cPanel open <b>Cron Jobs</b>, choose <b>Once Per Five Minutes (*/5 * * * *)</b>, and paste this command:</p>
      <pre id="cmd">' . e($cmd) . '</pre>
      <p class="sub">If cPanel shows a different PHP path in its examples, use that path instead of the first part.</p>
      ' . ($locked ? '' : '<p class="warn">Delete <code>install.php</code> in File Manager now. The installer already refuses to run again, but removing it is tidier.</p>') . '
      <p><a class="btn" href="/admin">Open the admin and log in</a></p>');
    exit;
}

$rows = '';
foreach ($checks as $c) $rows .= '<li class="' . ($c['ok'] ? 'ok' : ($c['optional'] ? 'opt' : 'bad')) . '"><b>' . ($c['ok'] ? '✓' : ($c['optional'] ? '!' : '✕')) . '</b> ' . e($c['name']) . ($c['ok'] ? '' : '<small>' . e($c['fix']) . '</small>') . '</li>';
$err = $errors ? '<div class="errors">' . implode('', array_map(fn($x) => '<p>' . e($x) . '</p>', $errors)) . '</div>' : '';
$f = fn($k) => e($v[$k] ?? '');
install_page('Install your store', $err . '
  <h2>1 · Server check</h2><ul class="checks">' . $rows . '</ul>
  <form method="post" autocomplete="off">
    <input type="hidden" name="tok" value="' . e($tok) . '">
    <h2>2 · Your store</h2>
    <label>Store name<input name="store" value="' . $f('store') . '" required></label>
    <h2>3 · Your admin login</h2>
    <label>Your name<input name="name" value="' . $f('name') . '" required autocomplete="name"></label>
    <label>Email<input name="email" type="email" value="' . $f('email') . '" required autocomplete="email"></label>
    <label>Password (at least 10 characters)<input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
    <label>Password again<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>
    <h2>4 · Database</h2>
    <label class="radio"><input type="radio" name="db" value="sqlite" ' . ($v['db'] !== 'mysql' ? 'checked' : '') . '> SQLite (recommended, nothing to set up)</label>
    <label class="radio"><input type="radio" name="db" value="mysql" ' . ($v['db'] === 'mysql' ? 'checked' : '') . '> MySQL (advanced: create it first in cPanel → MySQL Databases)</label>
    <div class="mysql"><label>MySQL host<input name="my_host" value="' . $f('my_host') . '"></label><label>Database name<input name="my_name" value="' . $f('my_name') . '"></label>
      <label>User<input name="my_user" value="' . $f('my_user') . '"></label><label>Password<input name="my_pass" type="password"></label></div>
    <h2>5 · Email (optional now)</h2>
    <p class="sub">Used to send gift codes and order emails, and to answer customers from the Inbox. You can do this later in Settings → Email.</p>
    <label>SMTP server<input name="smtp_host" value="' . $f('smtp_host') . '" placeholder="smtpout.secureserver.net"></label>
    <label>Port<input name="smtp_port" value="' . $f('smtp_port') . '" inputmode="numeric"></label>
    <label>Login (usually your email)<input name="smtp_user" value="' . $f('smtp_user') . '"></label>
    <label>Password<input name="smtp_pass" type="password" autocomplete="new-password"></label>
    <label>Send emails from<input name="smtp_from" type="email" value="' . $f('smtp_from') . '" placeholder="hello@yourdomain.com"></label>
    <label>Incoming mail server (IMAP), to read customer emails in the Inbox<input name="imap_host" value="' . $f('imap_host') . '" placeholder="imap.secureserver.net or mail.yourdomain.com"><small>Same login and password as above, port 993 (SSL). Empty = no customer emails in the Inbox for now.</small></label>
    <h2>6 · Telegram bot (optional now)</h2>
    <p class="sub">Customers write to your bot in Telegram and you answer in the admin Inbox, next to email. To get a bot: open Telegram → search <b>@BotFather</b> → send <code>/newbot</code> → choose a name (e.g. “' . $f('store') . '”) and a username ending in <code>bot</code> → BotFather replies with a token. Paste it here.</p>
    <label>Bot token<input name="tg_token" value="' . $f('tg_token') . '" placeholder="123456789:AAH…" autocomplete="off" spellcheck="false"></label>
    <h2>7 · Demo data</h2>
    <label class="radio"><input type="checkbox" name="demo" value="1" ' . ($v['demo'] ? 'checked' : '') . '> Add the demo products (Item 01–06) so you can try everything. Remove them later with one click.</label>
    <button class="btn" type="submit">Install</button>
  </form>');

/** Does the actual install. Returns ['email','data','telegram','telegram_error']. */
function run_install(array $v, string $email): array {
    // Prefer a data folder outside public_html (not reachable from the web at all), else public_html/data.
    $inside = ROOT . '/data';
    $outside = dirname(ROOT) . '/' . basename(ROOT) . '-drop01-data';
    $dataDir = $inside;
    if (is_writable(dirname(ROOT)) && (is_dir($outside) || @mkdir($outside, 0700, true)) && is_writable($outside)) $dataDir = $outside;
    foreach ([$inside, $dataDir, "$dataDir/backups", "$dataDir/logs", "$dataDir/sessions", "$dataDir/private"] as $d) if (!is_dir($d)) @mkdir($d, 0700, true);
    foreach (array_unique([$inside, $dataDir]) as $d) {
        @file_put_contents("$d/.htaccess", "Require all denied\n");
        @file_put_contents("$d/index.html", '');
    }
    $db = $v['db'] === 'mysql'
        ? ['driver' => 'mysql', 'host' => trim($v['my_host']) ?: 'localhost', 'name' => trim($v['my_name']), 'user' => trim($v['my_user']), 'pass' => (string) ($_POST['my_pass'] ?? '')]
        : ['driver' => 'sqlite', 'path' => "$dataDir/app.sqlite"];
    $cfg = ['db' => $db, 'data_dir' => $dataDir, 'app_key' => bin2hex(random_bytes(32)), 'cron_token' => bin2hex(random_bytes(20)), 'installed_at' => gmdate('c')];

    DB::pdo($db);   // connect before writing config, so a wrong MySQL password does not leave a half install
    $php = "<?php\n// Store configuration written by install.php. Keep it secret. Never upload it anywhere.\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) throw new RuntimeException('Could not write data/config.php. Check that the data folder is writable.');
    @chmod(CONFIG_FILE, 0600);

    // reload config so the app classes see it
    config_reload();
    DB::reset(); DB::pdo($db);
    DB::migrate();
    Settings::flush();
    Settings::set('store_name', mb_substr(trim($v['store']), 0, 80));
    $logo = mb_strtoupper(mb_substr(trim($v['store']), 0, 24));
    Settings::set('logo_text', $logo === 'DROP 01' ? 'DROP/01' : $logo);   // "DROP/01" shows the part after / in holographic foil
    DB::insert('admins', ['name' => mb_substr(trim($v['name']), 0, 120), 'email' => $email, 'pass_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'role' => 'owner', 'created_at' => DB::now()]);
    if (trim($v['smtp_host']) !== '') {
        Settings::set('smtp', ['host' => trim($v['smtp_host']), 'port' => (int) $v['smtp_port'] ?: 587, 'secure' => (int) $v['smtp_port'] === 465 ? 'ssl' : 'tls',
            'user' => trim($v['smtp_user']), 'from' => trim($v['smtp_from']) ?: trim($v['smtp_user']), 'from_name' => trim($v['store'])]);
        if (($_POST['smtp_pass'] ?? '') !== '') Settings::setSecret('smtp_pass', (string) $_POST['smtp_pass']);
        if (trim($v['imap_host']) !== '') Settings::set('imap', array_replace(Settings::get('imap'), ['enabled' => true, 'host' => trim($v['imap_host']), 'port' => 993, 'secure' => 'ssl']));
    }
    // Telegram: a wrong token or no internet must not break the install; the owner can connect later in Settings.
    $tg = null; $tgErr = null;
    if (trim($v['tg_token']) !== '') {
        try { $tg = Telegram::connect(trim($v['tg_token']))['bot']; } catch (Throwable $e) { $tgErr = $e->getMessage(); }
    }
    Settings::set('site_url', Http::requestBase());   // the address the owner installed on; editable in Settings → Store
    Seed::zones();
    if (!empty($v['demo'])) Seed::demoProducts();
    return ['email' => $email, 'data' => $dataDir, 'telegram' => $tg, 'telegram_error' => $tgErr];
}

function install_page(string $title, string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . '</title><style>
:root{--bg:#0E0E12;--card:#15151B;--card2:#1B1B23;--line:rgba(243,241,236,.08);--line2:rgba(243,241,236,.14);--ink:#F3F1EC;--ink2:rgba(243,241,236,.66);--ink3:rgba(243,241,236,.42);--good:#5BC98C;--warn:#E8B44A;--bad:#E5606A;--holo:linear-gradient(100deg,#FFB3D9,#FFE7A3 30%,#B8FFD9 55%,#A8DCFF 78%,#D4B8FF)}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.app{max-width:560px;margin:0 auto;padding:24px 16px 80px}.mark{font-weight:800;font-size:22px;letter-spacing:-.02em}.mark span{background:var(--holo);-webkit-background-clip:text;background-clip:text;color:transparent}
h1{font-size:30px;line-height:1;margin:18px 0 6px;letter-spacing:-.02em}h2{font-size:15px;margin:26px 0 10px}
.checks{list-style:none;padding:0;margin:0;background:var(--card);border:1px solid var(--line);border-radius:16px}.checks li{padding:10px 14px;border-top:1px solid var(--line)}.checks li:first-child{border:0}
.checks b{display:inline-block;width:18px}.checks .ok b{color:var(--good)}.checks .bad b{color:var(--bad)}.checks .opt b{color:var(--warn)}.checks small{display:block;margin:4px 0 0 18px;color:var(--ink2)}
label{display:block;margin:0 0 12px;font-weight:600;font-size:14px}label small{display:block;font-weight:400;color:var(--ink3);margin-top:4px}
input:not([type=radio]):not([type=checkbox]){display:block;width:100%;margin-top:6px;font:inherit;color:var(--ink);background:var(--card2);border:1px solid var(--line2);border-radius:12px;padding:12px}
input:focus{outline:2px solid #A8DCFF;outline-offset:1px}.radio{font-weight:500;display:flex;gap:10px;align-items:flex-start}.radio input{margin-top:4px}
.mysql{padding:12px;border:1px dashed var(--line2);border-radius:14px;margin:4px 0 12px}
.btn{display:inline-block;border:0;border-radius:14px;padding:14px 20px;font:inherit;font-weight:800;background:var(--holo);color:#121216;text-decoration:none;cursor:pointer;margin-top:12px}
.errors{background:rgba(229,96,106,.1);border:1px solid rgba(229,96,106,.4);border-radius:14px;padding:4px 14px;margin:14px 0}.errors p{margin:10px 0;color:#FFB4AC}
pre{white-space:pre-wrap;word-break:break-all;background:var(--card2);border:1px solid var(--line2);border-radius:12px;padding:12px;font-size:13px}
.ok{color:var(--good)}.warn{color:var(--warn)}.sub{color:var(--ink3);font-size:13px}code{font-size:13px}
</style></head><body><div class="app"><div class="mark">DROP/<span>01</span></div><h1>' . e($title) . '</h1>' . $body . '</div></body></html>';
}

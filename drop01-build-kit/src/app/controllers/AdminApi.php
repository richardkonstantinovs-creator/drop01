<?php
// Admin JSON API (/api/admin/...): login, Today, Waitlist dashboard + CSV, products, settings, email test, team, 2FA and System.
// Every state-changing call needs the session CSRF token (X-CSRF header) and is written to the audit log.
defined('DROP01') || exit;

final class AdminApi {
    public static function routes(Router $r): void {
        $r->post('/api/admin/login', [self::class, 'login']);
        $r->post('/api/admin/logout', function () { Auth::checkCsrf(); Auth::audit('logout'); Auth::logout(); Http::json(['ok' => true]); });
        $r->get('/api/admin/me', function () { $u = Auth::require(); Http::json(['user' => self::publicUser($u), 'csrf' => Auth::csrf()]); });

        $r->get('/api/admin/today', [self::class, 'today']);
        $r->get('/api/admin/waitlist', function () { Auth::require('waitlist'); Http::json(Dashboard::waitlist(self::range())); });
        $r->get('/api/admin/waitlist/signups', [self::class, 'signups']);
        $r->get('/api/admin/waitlist/export.csv', [self::class, 'exportCsv']);

        $r->get('/api/admin/products', function () { Auth::require('stock'); Http::json(['products' => Products::all()]); });
        $r->post('/api/admin/products/save', [self::class, 'saveProduct']);
        $r->post('/api/admin/products/order', [self::class, 'orderProducts']);
        $r->post('/api/admin/products/{id}/delete', [self::class, 'deleteProduct']);
        $r->post('/api/admin/products/{id}/image', [self::class, 'uploadImage']);
        $r->post('/api/admin/images/{id}/delete', [self::class, 'deleteImage']);

        $r->get('/api/admin/settings', [self::class, 'settings']);
        $r->post('/api/admin/settings', [self::class, 'saveSettings']);
        $r->post('/api/admin/email/test', [self::class, 'testEmail']);

        $r->get('/api/admin/team', function () { Auth::require('team'); Http::json(['team' => DB::all('SELECT id, name, email, role, active, last_login_at, (totp_secret IS NOT NULL) AS twofa FROM admins ORDER BY id'), 'roles' => Auth::ROLES]); });
        $r->post('/api/admin/team/save', [self::class, 'saveTeam']);
        $r->post('/api/admin/2fa/start', [self::class, 'twofaStart']);
        $r->post('/api/admin/2fa/confirm', [self::class, 'twofaConfirm']);
        $r->post('/api/admin/2fa/disable', [self::class, 'twofaDisable']);
        $r->post('/api/admin/password', [self::class, 'changePassword']);
        $r->post('/api/admin/reauth', function () { Auth::require(); Auth::checkCsrf(); Auth::reauth((string) (Http::body()['password'] ?? '')) ? Http::json(['ok' => true]) : Http::error('That password is not right.', 422); });
        $r->get('/api/admin/reauth', function () { Auth::require(); Auth::requireRecent(); Http::json(['ok' => true]); });

        $r->get('/api/admin/system', [self::class, 'system']);
        $r->post('/api/admin/system/migrate', function () { self::write('system'); $v = DB::migrate(); Auth::audit('migrate', null, null, $v); Http::json(['ok' => true, 'applied' => $v]); });
        $r->post('/api/admin/system/backup', function () { self::write('system'); Auth::requireRecent(); $f = Backup::create('manual'); Auth::audit('backup'); Http::json(['ok' => true, 'name' => basename($f)]); });
        $r->get('/api/admin/system/backup', [self::class, 'downloadBackup']);
        $r->post('/api/admin/system/offsite', [self::class, 'saveOffsite']);
        $r->post('/api/admin/system/offsite/send', function () { self::write('system'); $r = Backup::offsite(); Auth::audit('backup.offsite', null, null, ['ok' => $r['ok']]); $r['ok'] ? Http::json($r) : Http::error($r['error'], 422); });
        $r->post('/api/admin/system/demo/remove', [self::class, 'removeDemo']);
    }

    /** Auth + CSRF for writes. */
    private static function write(string $area): array { $u = Auth::require($area); Auth::checkCsrf(); return $u; }
    private static function range(): int { $r = (int) ($_GET['range'] ?? 7); return in_array($r, [0, 1, 7, 30], true) ? $r : 7; }
    private static function publicUser(array $u): array {
        return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'twofa' => !empty($u['totp_secret']),
            'access' => Auth::ACCESS[$u['role']] ?? []];
    }

    public static function login(): void {
        $in = Http::body();
        RateLimit::enforce('login', 30, 600);
        [$ok, $msg] = Auth::login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['totp'] ?? ''));
        if (!$ok && $msg === 'totp') Http::json(['ok' => false, 'needTotp' => true]);
        if (!$ok) Http::error($msg, 401);
        $applied = [];
        try { $applied = DB::migrate(); } catch (Throwable $e) { Log::error('migrate on login: ' . $e->getMessage()); }
        if (Settings::get('site_url', '') === '') Settings::set('site_url', Http::requestBase());   // stores installed before site_url: the address the team logs in on
        Http::json(['ok' => true, 'csrf' => Auth::csrf(), 'user' => self::publicUser(Auth::user()), 'migrated' => $applied]);
    }

    /** Today tab in phase 1: real waitlist numbers and a real "Needs your attention" list. */
    public static function today(): void {
        $u = Auth::require();
        $today = Analytics::day();
        $lastWeek = date('Y-m-d', strtotime("$today 12:00 UTC") - 7 * 86400);
        $su = fn(string $d) => (int) DB::val('SELECT COUNT(*) FROM waitlist_signups WHERE created_at >= ? AND created_at < ?',
            [gmdate('Y-m-d\TH:i:s\Z', (new DateTime($d, new DateTimeZone(TZ_LOCAL)))->getTimestamp()), gmdate('Y-m-d\TH:i:s\Z', (new DateTime($d . ' +1 day', new DateTimeZone(TZ_LOCAL)))->getTimestamp())]);
        $vis = fn(string $d) => array_sum(Dashboard::sessions('pageview', $d, $d));
        $att = [];
        if (!Mailer::configured()) $att[] = ['w', 'Email is not set up', 'Signups do not get their gift code by email yet', 'settings:email'];
        $failed = (int) DB::val("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND status IN ('failed','not_sent','retry')");
        if ($failed && Mailer::configured()) $att[] = ['b', "$failed email" . ($failed > 1 ? 's' : '') . ' not delivered', 'Check the email settings and send a test', 'settings:email'];
        $cron = Settings::get('cron_last_run');
        if (!$cron || strtotime($cron) < time() - 15 * 60) $att[] = ['b', 'Scheduled jobs are not running', $cron ? 'Last run ' . self::ago($cron) : 'Add the cron job from Settings → System', 'system'];
        if (!Settings::get('legal_reviewed')) $att[] = ['w', 'Legal texts are drafts', 'Have Privacy, Terms and Returns checked, then mark them reviewed', 'settings:legal'];
        if (Auth::can('inbox', $u) && ($un = Inbox::unread())) $att[] = ['m', "$un chat" . ($un > 1 ? 's' : '') . ' waiting for a reply', 'Customers wrote by email or Telegram', 'inbox'];
        if (Auth::can('settings', $u) && MailIn::enabled() && ($ie = ((array) Settings::get('imap_state', []))['error'] ?? null)) $att[] = ['b', 'Customer emails are not coming in', mb_substr($ie, 0, 90), 'settings:email'];
        $c = Settings::get('company');
        if (!$c['legal_name'] || !$c['kvk']) $att[] = ['w', 'Company details missing', 'Name, KvK and BTW appear on legal pages and invoices', 'settings:store'];
        $demo = (int) DB::val('SELECT COUNT(*) FROM products WHERE is_demo = 1');
        if ($demo) $att[] = ['n', "$demo demo product" . ($demo > 1 ? 's' : '') . ' still live', 'Replace them with your real products or remove demo data', 'stock'];
        $noPhoto = (int) DB::val('SELECT COUNT(*) FROM products p WHERE p.active = 1 AND p.on_waitlist = 1 AND p.reveal = 1 AND NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)');
        if ($noPhoto) $att[] = ['n', "$noPhoto revealed product" . ($noPhoto > 1 ? 's have' : ' has') . ' no photo', 'Revealed products look best with a photo', 'stock'];
        $left = Waitlist::giftsLeft();
        if ($left !== null && $left <= 25) $att[] = [$left === 0 ? 'b' : 'w', $left === 0 ? 'All waitlist gifts are claimed' : "Only $left gifts left", 'Raise the gift limit in Settings → Waitlist if you have more', 'settings:waitlist'];
        Http::json([
            'user' => $u['name'], 'mode' => Settings::get('store_mode'),
            'signupsToday' => $su($today), 'signupsLastWeek' => $su($lastWeek),
            'visitorsToday' => $vis($today), 'visitorsLastWeek' => $vis($lastWeek),
            'total' => Waitlist::total(), 'giftsLeft' => $left, 'live' => (int) DB::val('SELECT COUNT(DISTINCT session_id) FROM events WHERE ts >= ?', [DB::now(-300)]),
            'dropDate' => date('c', Settings::dropTs()), 'attention' => $att,
            'recent' => array_map(fn($r) => ['email' => Waitlist::maskEmail($r['email']), 'source' => $r['source'], 'ago' => self::ago($r['created_at'])],
                DB::all('SELECT email, source, created_at FROM waitlist_signups ORDER BY id DESC LIMIT 5')),
        ]);
    }

    private static function ago(string $iso): string {
        $m = max(0, (int) round((time() - strtotime($iso)) / 60));
        return $m < 1 ? 'just now' : ($m < 60 ? "{$m}m ago" : ($m < 1440 ? round($m / 60) . 'h ago' : round($m / 1440) . 'd ago'));
    }

    public static function signups(): void {
        Auth::require('waitlist');
        $q = trim((string) ($_GET['q'] ?? ''));
        $off = max(0, (int) ($_GET['offset'] ?? 0)); $lim = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $where = '1=1'; $p = [];
        if ($q !== '') {
            $where = '(email LIKE ? OR phone LIKE ? OR gift_code LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], strtolower($q)) . '%';
            $digits = preg_replace('/\D/', '', $q);
            $p = [$like, $digits !== '' ? '%' . $digits . '%' : $like, '%' . strtoupper($q) . '%'];
        }
        $total = (int) DB::val("SELECT COUNT(*) FROM waitlist_signups WHERE $where", $p);
        $rows = DB::all("SELECT id, email, phone, country, gift_code, position, source, device, consent, unsubscribed_at, likes, created_at FROM waitlist_signups WHERE $where ORDER BY id DESC LIMIT $lim OFFSET $off", $p);
        foreach ($rows as &$r) { $r['ago'] = self::ago($r['created_at']); $r['phone_pretty'] = Waitlist::prettyPhone($r['phone']); $r['likes'] = json_decode((string) $r['likes'], true) ?: []; }
        Http::json(['total' => $total, 'all' => Waitlist::total(), 'rows' => $rows]);
    }

    /** UTF-8 with BOM so Excel opens accents and + signs correctly. */
    public static function exportCsv(): void {
        Auth::require('waitlist');
        Auth::requireRecent();
        Auth::audit('export', 'waitlist');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . Products::slugify(Settings::get('store_name')) . '-waitlist-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['position', 'email', 'phone', 'country', 'gift_code', 'gift_redeemed', 'source', 'device', 'consent', 'unsubscribed', 'liked_products', 'joined_amsterdam_time']);
        $st = DB::q('SELECT * FROM waitlist_signups ORDER BY position');
        $tz = new DateTimeZone(TZ_LOCAL);
        while ($r = $st->fetch()) {
            // A leading quote stops spreadsheet apps from treating "+31…" or "=…" as a formula.
            $safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
            fputcsv($out, [$r['position'], $safe($r['email']), Waitlist::prettyPhone($r['phone']), $r['country'], $r['gift_code'], $r['gift_redeemed_order_id'] ? 'yes' : 'no', $r['source'], $r['device'],
                $r['consent'] ? 'yes' : 'no', $r['unsubscribed_at'] ? 'yes' : 'no', implode(' ', json_decode((string) $r['likes'], true) ?: []),
                (new DateTime($r['created_at']))->setTimezone($tz)->format('Y-m-d H:i')]);
        }
        exit;
    }

    // ---------------------------------------------------------------- products

    public static function saveProduct(): void {
        self::write('stock');
        try { $id = Products::save(Http::body()); }
        catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('product.save', 'product', $id, ['name' => Http::body()['name'] ?? '']);
        Restock::notify();
        Http::json(['ok' => true, 'id' => $id, 'products' => Products::all()]);
    }

    public static function orderProducts(): void {
        self::write('stock');
        foreach (array_values((array) (Http::body()['ids'] ?? [])) as $i => $id) DB::update('products', ['sort' => $i], 'id = ?', [(int) $id]);
        Auth::audit('product.order');
        Http::json(['ok' => true]);
    }

    public static function deleteProduct(array $p): void {
        self::write('stock');
        Products::delete((int) $p['id']);
        Auth::audit('product.delete', 'product', $p['id']);
        Http::json(['ok' => true, 'products' => Products::all()]);
    }

    public static function uploadImage(array $p): void {
        self::write('stock');
        $pid = (int) $p['id'];
        if (!DB::val('SELECT 1 FROM products WHERE id = ?', [$pid])) Http::error('Product not found', 404);
        if (empty($_FILES['photo'])) Http::error('No photo received. The file may be larger than the server allows (' . ini_get('upload_max_filesize') . ').', 422);
        try { $img = Images::store($_FILES['photo']); }
        catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        $sort = (int) DB::val('SELECT COALESCE(MAX(sort), -1) + 1 FROM product_images WHERE product_id = ?', [$pid]);
        $id = DB::insert('product_images', ['product_id' => $pid, 'path' => $img['path'], 'path_sm' => $img['path_sm'], 'w' => $img['w'], 'h' => $img['h'], 'sort' => $sort]);
        DB::update('products', ['is_demo' => 0], 'id = ?', [$pid]);
        Auth::audit('image.upload', 'product', $pid, ['image' => $id]);
        Http::json(['ok' => true, 'products' => Products::all()]);
    }

    public static function deleteImage(array $p): void {
        self::write('stock');
        $img = DB::one('SELECT * FROM product_images WHERE id = ?', [(int) $p['id']]);
        if ($img) { Images::delete($img['path']); Images::delete($img['path_sm']); DB::q('DELETE FROM product_images WHERE id = ?', [$img['id']]); }
        Auth::audit('image.delete', 'product', $img['product_id'] ?? null);
        Http::json(['ok' => true, 'products' => Products::all()]);
    }

    // ---------------------------------------------------------------- settings

    private const GROUPS = [
        'store'    => ['store_name', 'logo_text', 'company', 'site_url'],
        'waitlist' => ['drop_date', 'gift', 'proof_min', 'most_wanted_min', 'marquee', 'countries', 'store_mode', 'waitlist_after_launch'],
        'email'    => ['smtp', 'imap'],
        'telegram' => ['telegram'],
        'legal'    => ['legal.privacy', 'legal.terms', 'legal.returns', 'legal.contact', 'legal_reviewed'],
        'shop'     => ['shop', 'referral'],
        'delivery' => ['delivery'],
        'payments' => ['tikkie_text'],
    ];

    public static function settings(): void {
        Auth::require('settings');
        $out = [];
        foreach (self::GROUPS as $keys) foreach ($keys as $k) $out[$k] = Settings::get($k);
        foreach (array_keys(Legal::PAGES) as $s) if (!$out['legal.' . $s]) $out['legal.' . $s] = Legal::draft($s);
        $out['secrets'] = ['smtp_pass' => Settings::hasSecret('smtp_pass'), 'imap_pass' => Settings::hasSecret('imap_pass'), 'telegram' => Telegram::connected()];
        $out['imap_state'] = Settings::get('imap_state', []);
        $out['zones'] = DB::all('SELECT * FROM delivery_zones ORDER BY sort, id');
        Http::json($out);
    }

    public static function saveSettings(): void {
        self::write('settings');
        $in = Http::body();
        $group = (string) ($in['group'] ?? '');
        if (!isset(self::GROUPS[$group])) Http::error('Unknown settings group', 422);
        $v = (array) ($in['values'] ?? []);
        $str = fn($k, $max = 200) => mb_substr(trim((string) ($v[$k] ?? '')), 0, $max);
        switch ($group) {
            case 'store':
                if ($str('store_name') === '') Http::error('The store needs a name.', 422);
                Settings::set('store_name', $str('store_name', 80));
                Settings::set('logo_text', $str('logo_text', 24) ?: $str('store_name', 24));
                $c = array_map(fn($x) => mb_substr(trim((string) $x), 0, 200), array_intersect_key((array) ($v['company'] ?? []), Settings::defaults()['company']));
                Settings::set('company', array_replace(Settings::defaults()['company'], $c));
                $site = rtrim($str('site_url', 120), '/');
                if ($site !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $site)) Http::error('Website address: only the start, like https://yourdomain.nl', 422);
                Settings::set('site_url', strtolower($site) ?: Http::requestBase());
                break;
            case 'waitlist':
                $d = $str('drop_date', 16);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $d)) Http::error('Pick a drop date and time.', 422);
                Settings::set('drop_date', $d);
                $g = (array) ($v['gift'] ?? []);
                $lim = trim((string) ($g['limit'] ?? ''));
                Settings::set('gift', ['title' => mb_substr(trim((string) ($g['title'] ?? '')), 0, 80) ?: 'A free mystery gift', 'text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 200),
                    'limit' => $lim === '' ? null : max(0, (int) $lim), 'prefix' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($g['prefix'] ?? 'D01'))) ?: 'D01']);
                Settings::set('proof_min', max(0, (int) ($v['proof_min'] ?? 25)));
                Settings::set('most_wanted_min', max(1, (int) ($v['most_wanted_min'] ?? 3)));
                Settings::set('marquee', array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 60), (array) ($v['marquee'] ?? [])))));
                $cs = [];
                foreach ((array) ($v['countries'] ?? []) as $c) if (preg_match('/^[A-Z]{2}$/', strtoupper((string) ($c[0] ?? ''))) && preg_match('/^\+\d{1,4}$/', (string) ($c[1] ?? ''))) $cs[] = [strtoupper($c[0]), $c[1]];
                if ($cs) Settings::set('countries', $cs);
                $mode = (string) ($v['store_mode'] ?? 'waitlist');
                Settings::set('store_mode', in_array($mode, ['waitlist', 'shop'], true) ? $mode : 'waitlist');
                Settings::set('waitlist_after_launch', ($v['waitlist_after_launch'] ?? '') === 'redirect' ? 'redirect' : 'keep');
                break;
            case 'email':
                $s = (array) ($v['smtp'] ?? []);
                Settings::set('smtp', ['host' => mb_substr(trim((string) ($s['host'] ?? '')), 0, 120), 'port' => (int) ($s['port'] ?? 587) ?: 587,
                    'secure' => in_array($s['secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $s['secure'] : 'tls', 'user' => mb_substr(trim((string) ($s['user'] ?? '')), 0, 191),
                    'from' => filter_var(trim((string) ($s['from'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '', 'from_name' => mb_substr(trim((string) ($s['from_name'] ?? '')), 0, 80)]);
                if (isset($v['password']) && $v['password'] !== '') Settings::setSecret('smtp_pass', (string) $v['password']);
                $im = (array) ($v['imap'] ?? []); $was = Settings::get('imap');
                $host = mb_substr(trim((string) ($im['host'] ?? '')), 0, 120);
                if (!empty($im['enabled']) && $host === '') Http::error('Fill in the incoming mail server (IMAP) to receive customer emails.', 422);
                $imap = ['enabled' => !empty($im['enabled']), 'host' => $host, 'port' => (int) ($im['port'] ?? 993) ?: 993,
                    'secure' => in_array($im['secure'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $im['secure'] : 'ssl', 'user' => mb_substr(trim((string) ($im['user'] ?? '')), 0, 191),
                    'who' => ($im['who'] ?? '') === 'customers' ? 'customers' : 'all'];
                Settings::set('imap', $imap);
                if (isset($v['imap_password']) && $v['imap_password'] !== '') Settings::setSecret('imap_pass', (string) $v['imap_password']);
                if ($imap['host'] !== $was['host'] || $imap['user'] !== $was['user']) Settings::set('imap_state', []);   // another mailbox: start from now
                break;
            case 'telegram':
                $t = (array) ($v['telegram'] ?? []);
                Settings::set('telegram', array_replace(Settings::get('telegram'), ['welcome' => mb_substr(trim((string) ($t['welcome'] ?? '')), 0, 1000)]));
                if (trim((string) ($v['token'] ?? '')) !== '' || !empty($v['reconnect'])) {
                    try { Telegram::connect(trim((string) ($v['token'] ?? '')) ?: Settings::secret('telegram_token')); }
                    catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
                }
                break;
            case 'shop':
                $sh = (array) ($v['shop'] ?? []); $cur = Settings::get('shop');
                $ends = trim((string) ($sh['ends_at'] ?? ''));
                if ($ends !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $ends)) Http::error('Pick a valid end date, or leave it empty.', 422);
                Settings::set('shop', array_replace($cur, [
                    'free_from_cents' => Products::cents($sh['free_from'] ?? '35'), 'ends_at' => $ends,
                    'order_start' => max(1, (int) ($sh['order_start'] ?? $cur['order_start'])),
                    'reservation_min' => max(5, min(120, (int) ($sh['reservation_min'] ?? 15))),
                    'unpaid_timeout_min' => max(15, min(1440, (int) ($sh['unpaid_timeout_min'] ?? 60))),
                    'tikkie_timeout_hours' => max(1, min(72, (int) ($sh['tikkie_timeout_hours'] ?? 24))),
                    'max_per_item' => max(0, min(99, (int) ($sh['max_per_item'] ?? $cur['max_per_item']))),
                    'announce' => array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 80), (array) ($sh['announce'] ?? [])))),
                ]));
                $rf = (array) ($v['referral'] ?? []);
                Settings::set('referral', ['friend_cents' => Products::cents($rf['friend'] ?? '5'), 'referrer_cents' => Products::cents($rf['referrer'] ?? '5')]);
                break;
            case 'delivery':
                $d = (array) ($v['delivery'] ?? []);
                $wins = [];
                foreach ((array) ($d['windows'] ?? []) as $w) if (preg_match('/^\d{2}:\d{2}$/', (string) ($w['start'] ?? '')) && preg_match('/^\d{2}:\d{2}$/', (string) ($w['end'] ?? '')) && $w['end'] > $w['start'])
                    $wins[] = ['start' => $w['start'], 'end' => $w['end'], 'capacity' => max(0, (int) ($w['capacity'] ?? 10))];
                if (!$wins) Http::error('Add at least one delivery window, like 18:00–21:00.', 422);
                usort($wins, fn($a, $b) => strcmp($a['start'], $b['start']));
                $days = array_values(array_intersect([1, 2, 3, 4, 5, 6, 7], array_map('intval', (array) ($d['days'] ?? []))));
                if (!$days) Http::error('Pick at least one delivery day.', 422);
                $black = array_values(array_filter((array) ($d['blackout'] ?? []), fn($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x)));
                Settings::set('delivery', ['windows' => $wins, 'days' => $days, 'cutoff_min' => max(0, min(720, (int) ($d['cutoff_min'] ?? 120))),
                    'days_ahead' => max(0, min(14, (int) ($d['days_ahead'] ?? 3))), 'blackout' => $black,
                    'speed_kmh' => max(5, min(80, (int) ($d['speed_kmh'] ?? 25))), 'stop_min' => max(0, min(30, (int) ($d['stop_min'] ?? 4))),
                    'travel' => in_array($d['travel'] ?? '', ['driving', 'bicycling', 'walking'], true) ? $d['travel'] : 'driving',
                    'self_reschedule' => !empty($d['self_reschedule']),
                    'notify' => array_map(fn($k) => !empty(((array) ($d['notify'] ?? []))[$k]), array_combine($nk = array_keys(Settings::defaults()['delivery']['notify']), $nk))]);
                // future slots that nobody booked follow the new template
                $today = Analytics::day();
                DB::q("DELETE FROM delivery_slots WHERE date >= ? AND booked = 0 AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.slot_id = delivery_slots.id)", [$today]);
                foreach (DB::all('SELECT * FROM delivery_slots WHERE date >= ?', [$today]) as $sl) foreach ($wins as $w) if ($w['start'] === $sl['start_time']) DB::update('delivery_slots', ['capacity' => $w['capacity'], 'end_time' => $w['end']], 'id = ?', [$sl['id']]);
                Delivery::ensureSlots();
                if (isset($v['zones'])) {
                    $keep = [];
                    foreach ((array) $v['zones'] as $i => $z) {
                        $name = mb_substr(trim((string) ($z['name'] ?? '')), 0, 80); $a = (int) ($z['from'] ?? 0); $b = (int) ($z['to'] ?? $a);
                        if ($name === '' || $a < 1000 || $a > 9999 || $b < $a || $b > 9999) Http::error("Check zone “" . ($name ?: '?') . "”: postcodes are 4 digits, from ≤ to.", 422);
                        $row = ['name' => $name, 'postcode_from' => $a, 'postcode_to' => $b, 'fee_cents' => Products::cents($z['fee'] ?? '0'),
                            'free_from_cents' => trim((string) ($z['free'] ?? '')) === '' ? null : Products::cents($z['free']), 'sort' => $i, 'active' => 1];
                        $zid = (int) ($z['id'] ?? 0);
                        if ($zid && DB::val('SELECT 1 FROM delivery_zones WHERE id = ?', [$zid])) DB::update('delivery_zones', $row, 'id = ?', [$zid]); else $zid = DB::insert('delivery_zones', $row);
                        $keep[] = $zid;
                    }
                    DB::q('UPDATE delivery_zones SET active = 0 WHERE id NOT IN (' . ($keep ? implode(',', $keep) : '0') . ')');
                }
                break;
            case 'payments':
                Settings::set('tikkie_text', mb_substr(trim((string) ($v['tikkie_text'] ?? '')), 0, 300) ?: Settings::defaults()['tikkie_text']);
                break;
            case 'legal':
                foreach (array_keys(Legal::PAGES) as $s) if (isset($v['legal.' . $s])) Settings::set('legal.' . $s, self::cleanHtml((string) $v['legal.' . $s]));
                Settings::set('legal_reviewed', !empty($v['legal_reviewed']));
                break;
        }
        Auth::audit('settings.' . $group);
        Http::json(['ok' => true]);
    }

    /** Legal pages are owner-written HTML: keep simple formatting tags, drop scripts, styles and event attributes. */
    private static function cleanHtml(string $h): string {
        $h = strip_tags($h, '<p><br><b><strong><i><em><u><h2><h3><ul><ol><li><a><mark>');
        $h = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $h);
        return preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*\1/i', 'href="#"', $h);
    }

    public static function testEmail(): void {
        self::write('settings');
        $to = filter_var(trim((string) (Http::body()['to'] ?? '')), FILTER_VALIDATE_EMAIL);
        if (!$to) Http::error('Enter the address to send the test to.', 422);
        [$subject, $html, $text] = EmailTemplates::render('test', []);
        [$ok, $err] = Mailer::send($to, $subject, $html, $text);
        Auth::audit('email.test', null, null, ['ok' => $ok]);
        if (!$ok) Http::error('Not sent: ' . $err, 422);
        // the test proves SMTP works: retry anything that was waiting
        DB::q("UPDATE messages SET status = 'retry', tries = 0 WHERE status IN ('not_sent','failed') AND channel = 'email'");
        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- team & security

    public static function saveTeam(): void {
        $me = self::write('team');
        Auth::requireRecent();
        $in = Http::body();
        $id = (int) ($in['id'] ?? 0);
        $email = Waitlist::normEmail((string) ($in['email'] ?? ''));
        $role = in_array($in['role'] ?? '', Auth::ROLES, true) ? $in['role'] : 'manager';
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if (!$email || $name === '') Http::error('Name and a valid email are needed.', 422);
        if (DB::val('SELECT 1 FROM admins WHERE email = ? AND id <> ?', [$email, $id])) Http::error('Someone already uses this email.', 422);
        $pass = (string) ($in['password'] ?? '');
        if ((!$id || $pass !== '') && strlen($pass) < 10) Http::error('Passwords need at least 10 characters.', 422);
        $active = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1;
        if ($id && ($role !== 'owner' || !$active) && DB::val("SELECT role FROM admins WHERE id = ?", [$id]) === 'owner'
            && (int) DB::val("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND active = 1") <= 1) Http::error('There must always be one active owner.', 422);
        $row = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active];
        if ($pass !== '') $row['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        if ($id) DB::update('admins', $row, 'id = ?', [$id]); else $id = DB::insert('admins', $row + ['created_at' => DB::now()]);
        if ($pass !== '' && (int) $id === (int) $me['id']) Auth::keepSession($row['pass_hash']);   // a new password logs that person out everywhere else
        Auth::audit('team.save', 'admin', $id, ['role' => $role, 'active' => $active]);
        Http::json(['ok' => true]);
    }

    public static function changePassword(): void {
        $u = self::write('');
        $in = Http::body();
        $a = DB::one('SELECT pass_hash FROM admins WHERE id = ?', [$u['id']]);
        if (!password_verify((string) ($in['current'] ?? ''), $a['pass_hash'])) Http::error('Your current password is not right.', 422);
        if (strlen((string) ($in['new'] ?? '')) < 10) Http::error('Use at least 10 characters.', 422);
        DB::update('admins', ['pass_hash' => $hash = password_hash((string) $in['new'], PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        Auth::keepSession($hash);   // other phones and computers logged in as this person are logged out
        Auth::audit('password.change', 'admin', $u['id']);
        Http::json(['ok' => true]);
    }

    public static function twofaStart(): void {
        $u = self::write('');
        $_SESSION['totp_pending'] = Totp::secret();
        Http::json(['secret' => $_SESSION['totp_pending'], 'uri' => Totp::uri($_SESSION['totp_pending'], $u['email'], Settings::get('store_name'))]);
    }
    public static function twofaConfirm(): void {
        $u = self::write('');
        $s = $_SESSION['totp_pending'] ?? '';
        if (!$s || !Totp::verify($s, (string) (Http::body()['code'] ?? ''))) Http::error('That code is not right. Check the time on your phone and try again.', 422);
        DB::update('admins', ['totp_secret' => Crypto::encrypt($s)], 'id = ?', [$u['id']]);
        unset($_SESSION['totp_pending']);
        Auth::audit('2fa.on', 'admin', $u['id']);
        Http::json(['ok' => true]);
    }
    public static function twofaDisable(): void {
        $u = self::write('');
        $a = DB::one('SELECT pass_hash FROM admins WHERE id = ?', [$u['id']]);
        if (!password_verify((string) (Http::body()['password'] ?? ''), $a['pass_hash'])) Http::error('Your password is not right.', 422);
        DB::update('admins', ['totp_secret' => null], 'id = ?', [$u['id']]);
        Auth::audit('2fa.off', 'admin', $u['id']);
        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- system

    public static function system(): void {
        Auth::require('system');
        $cron = Settings::get('cron_last_run');
        $phpBin = PHP_BINDIR . '/php';
        Http::json([
            'version' => APP_VERSION, 'php' => PHP_VERSION, 'db' => DB::$driver, 'sqlite' => class_exists('SQLite3') ? SQLite3::version()['versionString'] : null,
            'checks' => Requirements::check(),
            'pending' => array_map(fn($f) => basename($f, '.sql'), DB::pending()),
            'migrations' => DB::all('SELECT version, applied_at FROM schema_migrations ORDER BY version'),
            'cron' => ['last' => $cron, 'ok' => $cron && strtotime($cron) > time() - 15 * 60,
                'command' => (is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : $phpBin) . ' ' . ROOT . '/cron.php',
                'url' => Http::baseUrl() . '/cron.php?token=' . config('cron_token')],
            'backups' => Backup::list(),
            'offsite' => ['email' => Settings::get('offsite')['email'], 'pass' => Settings::hasSecret('backup_pass'), 'last' => Settings::get('offsite')['last'], 'mail' => Mailer::configured()],
            'dataDir' => data_dir(), 'dataOutside' => !str_starts_with(realpath(data_dir()) ?: data_dir(), realpath(ROOT) ?: ROOT),
            'demo' => ['products' => (int) DB::val('SELECT COUNT(*) FROM products WHERE is_demo = 1'), 'signups' => (int) DB::val('SELECT COUNT(*) FROM waitlist_signups WHERE is_demo = 1')],
            'messages' => DB::all('SELECT status, COUNT(*) AS n FROM messages GROUP BY status'),
            'uploadMax' => ini_get('upload_max_filesize'),
        ]);
    }

    public static function downloadBackup(): void {
        Auth::require('system');
        Auth::requireRecent();
        $p = Backup::path((string) ($_GET['name'] ?? ''));
        if (!$p) Http::error('Backup not found', 404);
        Auth::audit('backup.download', null, null, ['name' => basename($p)]);
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        readfile($p);
        exit;
    }

    /** Where the nightly encrypted copy goes. Changing it needs a fresh password: it is a way to get all the data out. */
    public static function saveOffsite(): void {
        self::write('system');
        Auth::requireRecent();
        $in = Http::body();
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) Http::error('Enter a valid email address, or leave it empty to switch the copy off.', 422);
        $pass = (string) ($in['password'] ?? '');
        if ($pass !== '' && strlen($pass) < 10) Http::error('Use at least 10 characters for the backup password.', 422);
        if ($email !== '' && $pass === '' && !Settings::hasSecret('backup_pass')) Http::error('Choose a backup password. Without it nobody can open the copies.', 422);
        Settings::set('offsite', array_replace(Settings::get('offsite'), ['email' => strtolower($email)]));
        if ($pass !== '') Settings::setSecret('backup_pass', $pass);
        Auth::audit('backup.offsite_settings', null, null, ['email' => strtolower($email), 'new_password' => $pass !== '']);
        Http::json(['ok' => true]);
    }

    public static function removeDemo(): void {
        self::write('system');
        $ids = array_column(DB::all('SELECT id FROM products WHERE is_demo = 1'), 'id');
        foreach ($ids as $id) Products::delete((int) $id);
        DB::q('DELETE FROM waitlist_signups WHERE is_demo = 1');
        Auth::audit('demo.remove', null, null, ['products' => count($ids)]);
        Http::json(['ok' => true, 'removed' => count($ids)]);
    }
}

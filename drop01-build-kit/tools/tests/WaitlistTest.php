<?php
// Tests for waitlist signups, gift codes and limits, de-duplication, votes, analytics derivation, money parsing and security helpers.
defined('DROP01') || exit;

function test_phone_and_email_normalisation(): void {
    eq(Waitlist::normPhone('06 1234 5678', '+31'), '+31612345678');
    eq(Waitlist::normPhone('6-1234-5678', '+31'), '+31612345678');
    eq(Waitlist::normPhone('+49 151 2345 6789', '+31'), '+4915123456789', 'explicit + wins');
    eq(Waitlist::normPhone('0049 151 23456789', '+31'), '+4915123456789', '00 prefix');
    eq(Waitlist::normPhone('123', '+31'), null, 'too short');
    eq(Waitlist::normEmail(' Anna@Example.COM '), 'anna@example.com');
    eq(Waitlist::normEmail('not-an-email'), null);
    eq(Waitlist::prettyPhone('+31612345678'), '+31 6 1234 5678');
}

function test_signup_issues_unique_codes_and_positions(): void {
    $a = Waitlist::signup(['email' => 'a@x.nl', 'phone' => '0611111111', 'cc' => '+31']);
    $b = Waitlist::signup(['email' => 'b@x.nl', 'phone' => '0622222222', 'cc' => '+31']);
    ok($a['ok'] && $b['ok'], 'both succeed');
    ok((bool) preg_match('/^D01-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}$/', $a['code']), 'code format ' . $a['code']);
    ok($a['code'] !== $b['code'], 'codes differ');
    eq([$a['position'], $b['position']], [1, 2]);
    eq(DB::val('SELECT country FROM waitlist_signups WHERE id = ?', [$a['id']]), 'NL');
}

function test_gift_limit_then_signups_without_gift(): void {
    Settings::set('gift', ['title' => 'Gift', 'text' => 't', 'limit' => 2, 'prefix' => 'GX']);
    $r = [];
    for ($i = 1; $i <= 3; $i++) $r[] = Waitlist::signup(['email' => "p$i@x.nl", 'phone' => "06$i$i$i$i$i$i$i$i", 'cc' => '+31']);
    ok(str_starts_with($r[0]['code'], 'GX-') && str_starts_with($r[1]['code'], 'GX-'), 'first two get a code');
    eq($r[2]['ok'], true, 'third still joins');
    eq($r[2]['code'], null, 'third has no gift');
    eq(Waitlist::giftsLeft(), 0);
}

function test_dedupe_never_leaks_someone_elses_code(): void {
    $a = Waitlist::signup(['email' => 'a@x.nl', 'phone' => '0611111111', 'cc' => '+31']);
    $same = Waitlist::signup(['email' => 'A@x.nl', 'phone' => '+31611111111']);
    eq($same['existing'], true); eq($same['code'], $a['code'], 'same person gets the code back');
    $otherPhone = Waitlist::signup(['email' => 'a@x.nl', 'phone' => '0699999999', 'cc' => '+31']);
    eq($otherPhone['ok'], false); eq($otherPhone['status'], 409); ok(!isset($otherPhone['code']), 'no code on mismatch');
    $otherEmail = Waitlist::signup(['email' => 'z@x.nl', 'phone' => '0611111111', 'cc' => '+31']);
    eq($otherEmail['ok'], false); eq($otherEmail['field'], 'phone');
    eq(Waitlist::total(), 1);
}

function test_public_count_hidden_below_minimum(): void {
    Settings::set('proof_min', 2);
    Waitlist::signup(['email' => 'a@x.nl', 'phone' => '0611111111', 'cc' => '+31']);
    eq(Waitlist::publicCounters()['count'], null);
    Waitlist::signup(['email' => 'b@x.nl', 'phone' => '0622222222', 'cc' => '+31']);
    eq(Waitlist::publicCounters()['count'], 2);
}

function test_votes_are_shared_and_one_per_visitor(): void {
    Products::save(['name' => 'Item 01', 'slug' => 'item-01', 'on_waitlist' => 1]);
    $v1 = '11111111-1111-4111-8111-111111111111'; $v2 = '22222222-2222-4222-8222-222222222222';
    ok(Votes::cast($v1, 'item-01', 1), 'cast');
    Votes::cast($v1, 'item-01', 1);            // same visitor again: still one vote
    Votes::cast($v2, 'item-01', -1);
    eq(Votes::counts()['item-01'], ['up' => 1, 'down' => 1]);
    Votes::cast($v1, 'item-01', 0);            // un-vote
    eq(Votes::counts()['item-01'], ['up' => 0, 'down' => 1]);
    eq(Votes::mine($v2), ['item-01' => -1]);
    eq(Votes::cast($v1, 'missing', 1), false, 'unknown product');
    ok(!Votes::validVisitor("x' OR 1=1"), 'visitor id validated');
}

function test_analytics_source_device_and_bots(): void {
    eq(Analytics::source('ig', null), 'Instagram');
    eq(Analytics::source('newsletter', null), 'Other');
    eq(Analytics::source(null, 'l.instagram.com'), 'Instagram');
    eq(Analytics::source(null, 'www.tiktok.com'), 'TikTok');
    eq(Analytics::source(null, 'wa.me'), 'WhatsApp');
    eq(Analytics::source(null, 'www.google.nl'), 'Google');
    eq(Analytics::source(null, 'shop.test'), 'Direct', 'own site');
    eq(Analytics::source(null, null), 'Direct');
    eq(Analytics::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'), 'iPhone');
    eq(Analytics::device('Mozilla/5.0 (Linux; Android 14)'), 'Android');
    ok(Analytics::isBot('Googlebot/2.1'), 'bot'); ok(Analytics::isBot('curl/8'), 'curl');
}

function test_events_and_dashboard_funnel(): void {
    Analytics::record('sessionAAAA', [['type' => 'pageview', 'ref' => 'https://l.instagram.com/'], ['type' => 'intro_shown'], ['type' => 'intro_opened'], ['type' => 'email_started'], ['type' => 'not_a_type']]);
    Analytics::record('sessionBBBB', [['type' => 'pageview'], ['type' => 'intro_shown']]);
    eq((int) DB::val('SELECT COUNT(*) FROM events'), 6, 'unknown type dropped');
    ok(!DB::val("SELECT 1 FROM events WHERE visitor_hash LIKE '%10.0.0.1%'"), 'no raw IP stored');
    $d = Dashboard::waitlist(7);
    eq($d['kpis']['visitors']['v'], 2);
    eq($d['kpis']['opened']['v'], 50.0, 'opened / shown');
    eq(array_column($d['funnel'], 1), [2, 1, 1, 0, 0]);
    eq($d['sources'][0][0], 'Direct'); eq(count($d['sources']), 2);
    eq((int) DB::val('SELECT COUNT(*) FROM daily_salts'), 1, 'one salt only');
}

function test_money_parsing(): void {
    eq(Products::cents('34,95'), 3495); eq(Products::cents('34.95'), 3495); eq(Products::cents('€ 1.234,50'), 123450); eq(Products::cents('9'), 900); eq(Products::cents(1250), 1250);
    eq(eur(3495), '€34,95'); eq(eur(123450), '€1.234,50');
}

function test_product_save_variants_and_stock_log(): void {
    $id = Products::save(['name' => 'Tee Shirt', 'price' => '34,95', 'cost' => '19,50', 'variants' => [['label' => 'S', 'stock' => 3], ['label' => 'M', 'stock' => 2]]]);
    $p = Products::all()[0];
    eq($p['slug'], 'tee-shirt'); eq($p['stock'], 5); eq($p['price_cents'], 3495);
    $vs = $p['variants'];
    Products::save(['id' => $id, 'name' => 'Tee Shirt', 'variants' => [['id' => $vs[0]['id'], 'label' => 'S', 'stock' => 1]]]);
    $p = Products::all()[0];
    eq($p['stock'], 1, 'M deactivated, S adjusted');
    eq((int) DB::val("SELECT SUM(delta) FROM stock_movements WHERE variant_id = ?", [$vs[0]['id']]), 1, 'movements add up to stock');
    try { Products::save(['name' => 'Other', 'slug' => 'tee-shirt']); throw new Exception('duplicate slug allowed'); } catch (InvalidArgumentException $e) {}
}

function test_rate_limit(): void {
    for ($i = 0; $i < 3; $i++) ok(RateLimit::hit('t', 3, 60, 'x'), "hit $i allowed");
    ok(!RateLimit::hit('t', 3, 60, 'x'), 'fourth blocked');
    ok(RateLimit::hit('t', 3, 60, 'y'), 'other key independent');
}

function test_login_lockout_after_ten_failures(): void {
    DB::insert('admins', ['name' => 'O', 'email' => 'o@x.nl', 'pass_hash' => password_hash('right-password', PASSWORD_DEFAULT), 'role' => 'owner', 'created_at' => DB::now()]);
    for ($i = 0; $i < 10; $i++) eq(Auth::locked('o@x.nl'), false);
    for ($i = 0; $i < 10; $i++) DB::insert('login_attempts', ['email' => 'o@x.nl', 'ip_hash' => 'other', 'ok' => 0, 'created_at' => DB::now()]);
    eq(Auth::locked('o@x.nl'), true, 'locked after 10 fails');
}

function test_crypto_totp_and_legal(): void {
    $e = Crypto::encrypt('s3cret');
    ok($e !== 's3cret' && str_starts_with($e, 'v1:'), 'encrypted');
    eq(Crypto::decrypt($e), 's3cret');
    eq(Crypto::decrypt('v1:' . base64_encode(str_repeat('x', 40))), null, 'tampered');
    $s = Totp::secret();
    ok(Totp::verify($s, Totp::code($s)), 'totp now'); ok(!Totp::verify($s, '000000') || Totp::code($s) === '000000', 'wrong code');
    eq(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59), '287082', 'RFC 6238 vector');
    Settings::set('company', ['legal_name' => 'Drop B.V.', 'kvk' => '', 'btw' => '', 'address' => '', 'depot' => '', 'email' => '', 'whatsapp' => '']);
    $h = Legal::fill('[[COMPANY_NAME]] KvK [[KVK]] <script>');
    ok(str_contains($h, 'Drop B.V.') && str_contains($h, '<mark>[[KVK]]</mark>'), 'filled + marked: ' . $h);
}

function test_migrations_are_idempotent(): void {
    eq(DB::pending(), []);
    eq(DB::migrate(), []);
}

function test_sessions_end_on_new_password_and_risky_actions_want_it_again(): void {
    DB::q('DELETE FROM admins'); DB::q('DELETE FROM login_attempts');
    DB::insert('admins', ['name' => 'O', 'email' => 's@x.nl', 'pass_hash' => password_hash('right-password', PASSWORD_DEFAULT), 'role' => 'owner', 'created_at' => DB::now()]);
    eq(Auth::login('nobody@x.nl', 'right-password'), [false, 'Wrong email or password.'], 'unknown email');
    eq(Auth::login('s@x.nl', 'right-password'), [true, '']);
    ok(Auth::user() !== null, 'logged in');
    ok(!isset(Auth::user()['pass_hash']), 'the hash never leaves Auth');
    $phone = $_SESSION;   // the same person on another device
    // changing the password elsewhere ends this session
    DB::update('admins', ['pass_hash' => password_hash('new-password-1', PASSWORD_DEFAULT)], 'email = ?', ['s@x.nl']);
    eq(auth_fresh($phone), null, 'old session ended');
    // a session older than 7 days ends too
    Auth::login('s@x.nl', 'new-password-1');
    $good = $_SESSION;
    eq(auth_fresh(['born' => time() - 8 * 86400] + $good), null, 'too old');
    ok(auth_fresh($good) !== null, 'a new session works');
    // fresh password: 15 minutes
    $_SESSION['sudo_at'] = time() - 16 * 60;
    ok(!Auth::reauth('wrong'), 'wrong password');
    ok(Auth::reauth('new-password-1'), 'right password');
    ok($_SESSION['sudo_at'] >= time() - 1, 'fresh again');
}
/** Auth::user() for another session (as if from another device). */
function auth_fresh(array $sess): ?array { Auth::forget(); $_SESSION = $sess; return Auth::user(); }

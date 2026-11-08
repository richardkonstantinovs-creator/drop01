<?php
// Admin JSON API for Marketing: the shop funnel, discount codes, referral numbers, email broadcasts, back-in-stock requests and abandoned carts.
defined('DROP01') || exit;

final class MarketingApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/marketing/funnel', function () { Auth::require('marketing'); Http::json(Dashboard::shop(max(0, min(400, (int) ($_GET['days'] ?? 30))))); });
        $r->get('/api/admin/marketing/codes', [self::class, 'codes']);
        $r->post('/api/admin/marketing/codes/save', [self::class, 'saveCode']);
        $r->post('/api/admin/marketing/codes/{id}/delete', [self::class, 'deleteCode']);
        $r->get('/api/admin/marketing/referrals', function () { Auth::require('marketing'); Http::json(Referrals::stats()); });
        $r->get('/api/admin/marketing/broadcasts', [self::class, 'broadcasts']);
        $r->post('/api/admin/marketing/broadcasts/test', [self::class, 'testBroadcast']);
        $r->post('/api/admin/marketing/broadcasts/send', [self::class, 'sendBroadcast']);
        $r->get('/api/admin/marketing/restock', function () { Auth::require('marketing'); Restock::notify(); Http::json(Restock::overview()); });
        $r->post('/api/admin/marketing/wa/{id}', [self::class, 'waSent']);
        $r->get('/api/admin/marketing/carts', function () { Auth::require('marketing'); Http::json(Cart::stats() + ['settings' => Settings::get('marketing')]); });
        $r->post('/api/admin/marketing/settings', [self::class, 'settings']);
    }

    private static function write(): array { $u = Auth::require('marketing'); Auth::checkCsrf(); return $u; }

    public static function codes(): void {
        Auth::require('marketing');
        Http::json(['codes' => Discounts::all(), 'suggest' => Discounts::suggest(), 'types' => Discounts::TYPES]);
    }

    public static function saveCode(): void {
        self::write();
        $in = Http::body();
        try { $id = Discounts::save($in); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('code.save', 'discount_code', $id, ['code' => Discounts::norm((string) ($in['code'] ?? ''))]);
        Http::json(['ok' => true, 'codes' => Discounts::all()]);
    }

    /** Only unused codes can be deleted; used ones are switched off so the order history keeps its meaning. */
    public static function deleteCode(array $p): void {
        self::write();
        $d = DB::one('SELECT * FROM discount_codes WHERE id = ?', [(int) $p['id']]);
        if (!$d) Http::error('Code not found.', 404);
        if ((int) $d['used'] > 0 || DB::val('SELECT 1 FROM orders WHERE discount_code = ?', [$d['code']])) Http::error('This code is on orders, so it stays. Switch it off instead.', 422);
        DB::q('DELETE FROM discount_codes WHERE id = ?', [$d['id']]);
        Auth::audit('code.delete', 'discount_code', $d['id'], ['code' => $d['code']]);
        Http::json(['ok' => true, 'codes' => Discounts::all()]);
    }

    public static function broadcasts(): void {
        Auth::require('marketing');
        Http::json(['segments' => Broadcasts::SEGMENTS, 'counts' => Broadcasts::counts(), 'history' => Broadcasts::history(),
            'email_ready' => Mailer::configured(), 'per_hour' => (int) Settings::get('marketing')['per_hour']]);
    }

    public static function testBroadcast(): void {
        $u = self::write();
        RateLimit::enforce('broadcast-test', 10, 600);
        try { $b = Broadcasts::clean(Http::body()); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        if (!Mailer::configured()) Http::error('Email is not set up yet (Settings → Email).', 422);
        [$ok, $err] = Broadcasts::test($b, $u['email']);
        if (!$ok) Http::error('The test email failed: ' . $err, 502);
        Http::json(['ok' => true, 'to' => $u['email']]);
    }

    /** The browser sends the number it showed ("Send to 42 people"); if the group changed meanwhile, nothing is sent. */
    public static function sendBroadcast(): void {
        $u = self::write();
        $in = Http::body();
        try { $b = Broadcasts::clean($in); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        if (!Mailer::configured()) Http::error('Email is not set up yet (Settings → Email).', 422);
        $n = count(Broadcasts::recipients($b['segment']));
        if ($n !== (int) ($in['expect'] ?? -1)) Http::error("The group now has $n people. Check and send again.", 409);
        try { [$id, $n] = Broadcasts::send($b, (int) $u['id']); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('broadcast.send', 'broadcast', $id, ['segment' => $b['segment'], 'subject' => $b['subject'], 'recipients' => $n]);
        Http::json(['ok' => true, 'id' => $id, 'recipients' => $n, 'history' => Broadcasts::history()]);
    }

    /** The owner opened a prepared WhatsApp message: mark it as sent. */
    public static function waSent(array $p): void {
        self::write();
        DB::q("UPDATE messages SET status = 'sent', sent_at = ? WHERE id = ? AND channel = 'whatsapp' AND status = 'ready'", [DB::now(), (int) $p['id']]);
        Http::json(['ok' => true]);
    }

    public static function settings(): void {
        self::write();
        $in = Http::body();
        $v = Settings::get('marketing');   // each tab saves only its own fields
        if (array_key_exists('abandoned_cart', $in)) $v['abandoned_cart'] = !empty($in['abandoned_cart']);
        if (array_key_exists('per_hour', $in)) $v['per_hour'] = max(10, min(5000, (int) $in['per_hour']));
        Settings::set('marketing', $v);
        Auth::audit('settings.save', 'settings', 'marketing', $v);
        Http::json(['ok' => true, 'settings' => $v]);
    }
}

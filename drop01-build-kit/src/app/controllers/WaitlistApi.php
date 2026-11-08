<?php
// Public JSON API for the waitlist: config, signup with server gift codes, shared votes and the analytics beacon.
defined('DROP01') || exit;

final class WaitlistApi {
    public static function routes(Router $r): void {
        $r->get('/api/public/config', fn() => Http::json(PublicController::waitlistConfig()));
        $r->get('/api/waitlist/counters', function () { $c = Waitlist::publicCounters(); Http::json(['count' => $c['count'], 'giftsLeft' => $c['gifts_left']]); });
        $r->post('/api/waitlist/signup', [self::class, 'signup']);
        $r->get('/api/votes', [self::class, 'votes']);
        $r->post('/api/votes', [self::class, 'vote']);
        $r->post('/api/e', [self::class, 'events']);
    }

    public static function signup(): void {
        $in = Http::body();
        // Bots: a hidden "website" field that people never fill, and a minimum time on the page.
        if (!empty($in['website'])) Http::json(['ok' => true, 'code' => null, 'position' => null]);
        if (isset($in['t']) && (int) $in['t'] < 1500) Http::error(t('err.too_quick'), 422);
        RateLimit::enforce('signup', 6, 600);

        $res = Waitlist::signup($in);
        if (!$res['ok']) Http::error($res['error'], $res['status'], ['field' => $res['field'] ?? null]);

        if (!$res['existing']) {
            if (!empty($in['sid'])) Analytics::record((string) $in['sid'], [['type' => 'signup', 'path' => '/']]);
            $gift = Settings::get('gift');
            $mid = Messages::queueEmail('waitlist_signup', $res['email'], [
                'code' => $res['code'], 'position' => $res['position'], 'gift_title' => $gift['title'], 'gift_text' => $gift['text'],
                'drop' => (new DateTime('@' . Settings::dropTs()))->setTimezone(new DateTimeZone(TZ_LOCAL))->format('j F Y, H:i'),
                'unsubscribe' => Messages::unsubscribeUrl($res['id']),
            ], ['signup_id' => $res['id']]);
            Messages::deliverLater($mid);
        }
        $c = Waitlist::publicCounters();
        Http::json(['ok' => true, 'code' => $res['code'], 'position' => $res['position'], 'existing' => $res['existing'],
            'gift' => $res['code'] !== null, 'count' => $c['count'], 'giftsLeft' => $c['gifts_left']]);
    }

    public static function votes(): void {
        header('Cache-Control: no-store');
        $v = (string) ($_GET['v'] ?? '');
        Http::json(['counts' => Votes::counts(), 'mine' => $v ? Votes::mine($v) : (object) []]);
    }

    public static function vote(): void {
        $in = Http::body();
        $visitor = (string) ($in['visitor'] ?? '');
        if (!Votes::validVisitor($visitor)) Http::error('Missing visitor id.', 422);
        RateLimit::enforce('vote', 60, 60);
        RateLimit::enforce('vote-v', 40, 60, $visitor);
        $value = (int) ($in['value'] ?? 0);
        if (!in_array($value, [1, -1, 0], true)) Http::error('Invalid vote.', 422);
        if (!Votes::cast($visitor, (string) ($in['productId'] ?? ''), $value)) Http::error('Unknown product.', 404);
        Http::json(['ok' => true, 'counts' => Votes::counts()]);
    }

    /** sendBeacon endpoint. Always answers 204 so the page never waits or shows errors. */
    public static function events(): void {
        $in = Http::body();
        if (RateLimit::hit('events', 120, 60) && is_array($in['events'] ?? null)) {
            try { Analytics::record((string) ($in['sid'] ?? ''), $in['events']); } catch (Throwable $e) { Log::error('events: ' . $e->getMessage()); }
        }
        http_response_code(204);
        exit;
    }
}

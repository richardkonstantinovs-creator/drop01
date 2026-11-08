<?php
// Public HTML pages: the waitlist (or shop in shop mode), legal pages, unsubscribe and the admin shell.
defined('DROP01') || exit;

final class PublicController {
    public static function routes(Router $r): void {
        $r->get('/', fn() => Settings::get('store_mode') === 'shop' ? self::shop() : self::waitlist());
        $r->get('/waitlist', function () {
            if (Settings::get('store_mode') === 'shop' && Settings::get('waitlist_after_launch') === 'redirect') { header('Location: /', true, 302); exit; }
            self::waitlist();
        });
        $r->get('/pages/{slug}', fn($p) => self::legal($p['slug']));
        $r->get('/unsubscribe', [self::class, 'unsubscribe']);
        $r->get('/admin', [AdminController::class, 'shell']);
        $r->get('/admin/{any}', [AdminController::class, 'shell']);
    }

    /** Everything the waitlist page needs, rendered into window.DROP_CONFIG (no extra request at boot). */
    public static function waitlistConfig(): array {
        $gift = Settings::get('gift');
        $products = [];
        foreach (Products::forWaitlist() as $p) {
            $products[] = ['id' => $p['slug'], 'name' => $p['name'], 'qty' => (int) $p['run_size'], 'image' => $p['image'] ?? '', 'image_lg' => $p['image_lg'] ?? '',
                'note' => $p['note'] ?? '', 'reveal' => (bool) $p['reveal']];
        }
        $c = Waitlist::publicCounters();
        return [
            'store' => Settings::get('store_name'), 'logo' => Settings::get('logo_text'),
            'dropDate' => date('c', Settings::dropTs()),
            'gift' => ['title' => $gift['title'], 'text' => $gift['text'], 'limit' => $gift['limit'] ? (int) $gift['limit'] : null],
            'count' => $c['count'], 'giftsLeft' => $c['gifts_left'],
            'products' => $products, 'mostWantedMin' => (int) Settings::get('most_wanted_min'),
            'marquee' => Settings::get('marquee'), 'countries' => Settings::get('countries'),
            'votes' => Votes::counts(),
            't' => Texts::forJs(['wl.', 'common.']),
        ];
    }

    public static function waitlist(): void {
        header('Cache-Control: no-cache');
        View::render('waitlist', ['cfg' => self::waitlistConfig()]);
    }

    public static function shop(): void { ShopApi::page([]); }

    public static function legal(string $slug): void {
        $page = Legal::page($slug);
        if (!$page) { http_response_code(404); View::render('page', ['title' => t('page.not_found'), 'body' => '<p>' . th('page.not_found_text') . '</p>']); return; }
        View::render('page', $page);
    }

    public static function unsubscribe(): void {
        $id = (int) ($_GET['s'] ?? 0); $t = (string) ($_GET['t'] ?? '');
        if ($id && Messages::checkUnsubscribe($id, $t)) {
            DB::update('waitlist_signups', ['unsubscribed_at' => DB::now(), 'consent' => 0], 'id = ? AND unsubscribed_at IS NULL', [$id]);
            View::render('page', ['title' => t('page.unsub_title'), 'body' => '<p>' . th('page.unsub_waitlist') . '</p>']);
            return;
        }
        $email = (string) ($_GET['e'] ?? '');
        if ($email !== '' && Messages::checkUnsubscribeEmail($email, $t)) {
            Messages::unsubscribeEmail($email);
            View::render('page', ['title' => t('page.unsub_title'), 'body' => '<p>' . th('page.unsub_email') . '</p>']);
            return;
        }
        http_response_code(400);
        View::render('page', ['title' => t('page.unsub_bad_title'), 'body' => '<p>' . th('page.unsub_bad') . '</p>']);
    }
}

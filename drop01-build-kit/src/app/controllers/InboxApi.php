<?php
// Inbox API (/api/admin/inbox/...): conversations list, thread, reply, retry, start a chat with a customer, photos, mail check;
// Telegram status/disconnect for Settings, and the public Telegram webhook (/api/telegram/hook, secret header).
defined('DROP01') || exit;

final class InboxApi {
    public static function routes(Router $r): void {
        $r->post('/api/telegram/hook', [Telegram::class, 'webhook']);

        $r->get('/api/admin/inbox', [self::class, 'list']);
        $r->get('/api/admin/inbox/unread', function () { Auth::require('inbox'); Inbox::pollLater(); Http::json(['unread' => Inbox::unread()]); });
        $r->post('/api/admin/inbox/start', [self::class, 'start']);
        $r->post('/api/admin/inbox/check', [self::class, 'check']);
        $r->get('/api/admin/inbox/file/{id}/{i}', [self::class, 'file']);
        $r->post('/api/admin/inbox/msg/{id}/retry', function (array $p) { self::write(); self::attempt(fn() => Inbox::retry((int) $p['id'])); Auth::audit('inbox.retry', 'chat_message', $p['id']); Http::json(['ok' => true]); });
        $r->get('/api/admin/inbox/{id}', function (array $p) { Auth::require('inbox'); $t = Inbox::thread((int) $p['id']) ?? Http::error('This chat no longer exists.', 404); Http::json($t + ['unread' => Inbox::unread()]); });
        $r->post('/api/admin/inbox/{id}/reply', [self::class, 'reply']);
        $r->post('/api/admin/inbox/{id}/unread', function (array $p) { self::write(); DB::q('UPDATE conversations SET unread = CASE WHEN unread > 0 THEN unread ELSE 1 END WHERE id = ?', [(int) $p['id']]); Http::json(['ok' => true, 'unread' => Inbox::unread()]); });
        $r->post('/api/admin/inbox/{id}/delete', function (array $p) { self::write(); Inbox::delete((int) $p['id']); Auth::audit('inbox.delete', 'conversation', $p['id']); Http::json(['ok' => true]); });

        $r->get('/api/admin/telegram/status', function () { Auth::require('settings'); Http::json(Telegram::status()); });
        $r->post('/api/admin/telegram/disconnect', function () { Auth::require('settings'); Auth::checkCsrf(); Telegram::disconnect(); Auth::audit('telegram.disconnect'); Http::json(['ok' => true]); });
    }

    private static function write(): array { $u = Auth::require('inbox'); Auth::checkCsrf(); return $u; }
    private static function attempt(callable $fn) { try { return $fn(); } catch (RuntimeException $e) { Http::error($e->getMessage(), 422); } }

    public static function list(): void {
        Auth::require('inbox');
        Inbox::pollLater();
        $st = (array) Settings::get('imap_state', []);
        Http::json(Inbox::list(['channel' => $_GET['channel'] ?? '', 'unread' => !empty($_GET['unread']), 'q' => mb_substr((string) ($_GET['q'] ?? ''), 0, 80), 'offset' => (int) ($_GET['offset'] ?? 0)])
            + ['setup' => ['email_out' => Mailer::configured(), 'email_in' => MailIn::enabled(), 'email_error' => MailIn::enabled() ? ($st['error'] ?? null) : null,
                'telegram' => Telegram::connected(), 'bot' => Telegram::connected() ? Settings::get('telegram')['bot'] : null]]);
    }

    public static function reply(array $p): void {
        $u = self::write();
        $in = Http::body();
        RateLimit::enforce('inbox-reply', 120, 600, (string) $u['id']);
        self::attempt(fn() => Inbox::reply((int) $p['id'], (string) ($in['text'] ?? ''), (int) $u['id'], (string) ($in['subject'] ?? '')));
        Auth::audit('inbox.reply', 'conversation', $p['id']);
        Http::json(Inbox::thread((int) $p['id']) + ['unread' => Inbox::unread()]);
    }

    /** "Message" on a customer or order: opens their chat on the channel asked for, starting an email chat if there is none. */
    public static function start(): void {
        self::write();
        $in = Http::body();
        $c = DB::one('SELECT id, name, email FROM customers WHERE id = ?', [(int) ($in['customer_id'] ?? 0)]);
        if (!$c) Http::error('Customer not found.', 404);
        $ch = (string) ($in['channel'] ?? 'email');
        if ($ch === 'auto') $ch = DB::val('SELECT channel FROM conversations WHERE customer_id = ? ORDER BY last_at DESC LIMIT 1', [$c['id']]) ?: 'email';
        if ($ch === 'telegram') {
            $id = DB::val("SELECT id FROM conversations WHERE channel = 'telegram' AND customer_id = ? ORDER BY last_at DESC LIMIT 1", [$c['id']]);
            if (!$id) Http::error('This customer has not opened a Telegram chat with your bot yet. They can do that from their order page.', 422);
        } else {
            if (!$c['email']) Http::error('This customer has no email address.', 422);
            $id = Inbox::open('email', strtolower($c['email']), $c['name'], null, (int) $c['id'])['id'];
        }
        Http::json(['id' => (int) $id]);
    }

    /** Settings → "Check incoming mail now": reads the mailbox (and Telegram in poll mode) right away and says what happened. */
    public static function check(): void {
        Auth::require('settings'); Auth::checkCsrf();
        try {
            $msg = Settings::get('imap')['host'] !== '' ? MailIn::test() : '';
            $r = Inbox::poll(true);
            if ($r['email']) $msg .= ' ' . $r['email'] . ' new email' . ($r['email'] > 1 ? 's' : '') . '.';
            if ($r['telegram']) $msg .= ' ' . $r['telegram'] . ' new Telegram message' . ($r['telegram'] > 1 ? 's' : '') . '.';
        } catch (Throwable $e) { Http::error($e->getMessage(), 422); }
        Http::json(['ok' => true, 'message' => trim($msg) ?: 'Nothing new.']);
    }

    public static function file(array $p): void {
        Auth::require('inbox');
        $f = Inbox::file((int) $p['id'], (int) $p['i']);
        if (!$f) Http::error('Not found', 404);
        header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($f));
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}

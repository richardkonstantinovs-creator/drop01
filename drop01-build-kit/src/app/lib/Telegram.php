<?php
// Telegram bot for the Inbox: connect a bot token from @BotFather (installer or Settings), receive customer messages by webhook
// (or by polling when the site has no HTTPS), reply from the admin. The order page links to the bot so chats are tied to the customer.
defined('DROP01') || exit;

final class Telegram {
    /** Test hook: fn(string $url, ?array $json): [int $httpCode, string $body]. */
    public static $http = null;
    private const FILE_MAX = 8 * 1024 * 1024;

    public static function connected(): bool { return Settings::get('telegram')['bot'] !== '' && Settings::hasSecret('telegram_token'); }

    /**
     * Checks the token with getMe and points the bot at this site: a webhook on HTTPS, otherwise cron polling.
     * Returns the saved settings. Throws a readable error for a wrong token.
     */
    public static function connect(string $token, ?string $base = null): array {
        $token = trim($token);
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) throw new RuntimeException('That does not look like a bot token. It looks like 123456789:AAH… — copy it from @BotFather.');
        $me = self::call('getMe', [], $token);
        if (empty($me['is_bot'])) throw new RuntimeException('Telegram did not accept this token.');
        $base = $base ?? Http::baseUrl();
        $secret = Crypto::token(24);
        $mode = 'poll';
        if (str_starts_with($base, 'https://')) {
            self::call('setWebhook', ['url' => $base . '/api/telegram/hook', 'secret_token' => $secret, 'allowed_updates' => ['message'], 'max_connections' => 5], $token);
            $mode = 'webhook';
        } else self::call('deleteWebhook', [], $token);
        Settings::setSecret('telegram_token', $token);
        Settings::setSecret('telegram_hook', $secret);
        $t = array_replace(Settings::get('telegram'), ['bot' => (string) $me['username'], 'name' => (string) ($me['first_name'] ?? ''), 'mode' => $mode]);
        Settings::set('telegram', $t);
        Settings::set('telegram_offset', 0);
        return $t;
    }

    public static function disconnect(): void {
        if (self::connected()) { try { self::call('deleteWebhook', []); } catch (Throwable $e) {} }
        Settings::setSecret('telegram_token', '');
        Settings::setSecret('telegram_hook', '');
        Settings::set('telegram', array_replace(Settings::get('telegram'), ['bot' => '', 'name' => '', 'mode' => '']));
    }

    /** Webhook health for Settings → Telegram: pending updates and Telegram's last delivery error. */
    public static function status(): array {
        if (!self::connected()) return ['connected' => false];
        $t = Settings::get('telegram');
        $out = ['connected' => true, 'bot' => $t['bot'], 'mode' => $t['mode']];
        try {
            $i = self::call('getWebhookInfo', []);
            $out += ['pending' => (int) ($i['pending_update_count'] ?? 0), 'error' => $i['last_error_message'] ?? null,
                'error_at' => isset($i['last_error_date']) ? gmdate('Y-m-d\TH:i:s\Z', (int) $i['last_error_date']) : null];
            if ($t['mode'] === 'webhook' && empty($i['url'])) $out['error'] = 'The webhook is not set. Tap “Connect again”.';
        } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
        return $out;
    }

    /** Sends a text to a chat. Returns [true, ''] or [false, reason]. */
    public static function send(string $chatId, string $text): array {
        if (!self::connected()) return [false, 'Telegram is not connected (Settings → Telegram).'];
        try { self::call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4096), 'disable_web_page_preview' => true]); return [true, '']; }
        catch (Throwable $e) {
            $m = $e->getMessage();
            return [false, str_contains($m, 'blocked') ? 'The customer blocked the bot.' : $m];
        }
    }

    /** POST /api/telegram/hook — Telegram calls this with the secret we set. Always answers 200 so Telegram does not resend forever. */
    public static function webhook(): void {
        $sent = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        $secret = Settings::secret('telegram_hook');
        if ($secret === '' || !hash_equals($secret, $sent)) Http::json(['ok' => false], 403);
        $u = json_decode((string) file_get_contents('php://input'), true);
        try { if (is_array($u)) self::handle($u); } catch (Throwable $e) { Log::error('telegram hook: ' . $e->getMessage()); }
        Http::json(['ok' => true]);
    }

    /** Poll mode (no HTTPS): fetches new updates. Returns how many messages came in. */
    public static function poll(): int {
        $off = (int) Settings::get('telegram_offset', 0);
        $n = 0;
        foreach (self::call('getUpdates', ['offset' => $off, 'limit' => 50, 'timeout' => 0, 'allowed_updates' => ['message']]) ?: [] as $u) {
            $off = max($off, (int) $u['update_id'] + 1);
            try { if (self::handle($u)) $n++; } catch (Throwable $e) { Log::error('telegram update: ' . $e->getMessage()); }
        }
        Settings::set('telegram_offset', $off);
        return $n;
    }

    /** One update → the Inbox. /start with an order link ties the chat to that customer and gets the welcome message. */
    public static function handle(array $u): bool {
        $m = $u['message'] ?? null;
        if (!$m || ($m['chat']['type'] ?? '') !== 'private' || !empty($m['from']['is_bot'])) return false;
        $chat = (string) $m['chat']['id'];
        $f = $m['from'] ?? [];
        $name = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')) ?: null;
        $handle = !empty($f['username']) ? '@' . $f['username'] : null;
        $text = (string) ($m['text'] ?? $m['caption'] ?? '');
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?/', $text, $mm)) {
            $cust = isset($mm[1]) ? self::customerFromStart($mm[1]) : null;
            $isNew = !DB::val("SELECT 1 FROM conversations WHERE channel = 'telegram' AND address = ?", [$chat]);
            $c = Inbox::open('telegram', $chat, $name, $handle, $cust['customer_id'] ?? null);
            if ($cust) Inbox::receive('telegram', $chat, ['name' => $name, 'handle' => $handle, 'text' => 'Opened the chat from order #' . $cust['number'], 'ext_id' => "tg:$chat:{$m['message_id']}"]);
            if ($isNew || $cust) {
                $welcome = str_replace('{store}', Settings::get('store_name'), (string) Settings::get('telegram')['welcome']);
                if (trim($welcome) !== '') { try { Inbox::reply((int) $c['id'], $welcome, null); } catch (Throwable $e) { Log::error('telegram welcome: ' . $e->getMessage()); } }
            }
            return (bool) $cust;
        }
        $files = [];
        if (!empty($m['photo'])) { $p = end($m['photo']); $files[] = self::image($p['file_id'], 'photo.jpg', (int) ($p['file_size'] ?? 0)) ?? ['name' => 'Photo (too large)']; }
        elseif (!empty($m['document'])) {
            $d = $m['document'];
            $files[] = str_starts_with((string) ($d['mime_type'] ?? ''), 'image/') ? (self::image($d['file_id'], (string) ($d['file_name'] ?? 'image'), (int) ($d['file_size'] ?? 0)) ?? ['name' => (string) ($d['file_name'] ?? 'image')]) : ['name' => (string) ($d['file_name'] ?? 'File')];
        }
        if ($text === '' && !$files) $text = isset($m['voice']) ? '🎤 Voice message (listen in Telegram)' : (isset($m['sticker']) ? ($m['sticker']['emoji'] ?? '🙂') . ' Sticker' : (isset($m['location']) ? '📍 Location: https://maps.google.com/?q=' . $m['location']['latitude'] . ',' . $m['location']['longitude']
            : (isset($m['contact']) ? '📇 Contact: ' . trim(($m['contact']['first_name'] ?? '') . ' ' . ($m['contact']['phone_number'] ?? '')) : (isset($m['video']) || isset($m['video_note']) ? '🎬 Video (watch in Telegram)' : '[Message type not supported]'))));
        return (bool) Inbox::receive('telegram', $chat, ['name' => $name, 'handle' => $handle, 'text' => $text, 'files' => $files, 'ext_id' => "tg:$chat:{$m['message_id']}"]);
    }

    /** Deep link for the order page: t.me/<bot>?start=o<number>_<signature>, so the chat knows which customer it is. */
    public static function orderLink(int $number): ?string {
        if (!self::connected()) return null;
        return 'https://t.me/' . Settings::get('telegram')['bot'] . '?start=o' . $number . '_' . self::sig($number);
    }

    public static function botLink(): ?string { return self::connected() ? 'https://t.me/' . Settings::get('telegram')['bot'] : null; }

    private static function sig(int $number): string { return substr(hash_hmac('sha256', 'tg:' . $number, (string) config('app_key')), 0, 10); }

    private static function customerFromStart(string $p): ?array {
        if (!preg_match('/^o(\d+)_([a-f0-9]{10})$/', $p, $m) || !hash_equals(self::sig((int) $m[1]), $m[2])) return null;
        $o = DB::one('SELECT number, customer_id FROM orders WHERE number = ?', [(int) $m[1]]);
        return $o && $o['customer_id'] ? ['number' => (int) $o['number'], 'customer_id' => (int) $o['customer_id']] : null;
    }

    private static function image(string $fileId, string $name, int $size): ?array {
        if ($size > self::FILE_MAX) return null;
        try {
            $f = self::call('getFile', ['file_id' => $fileId]);
            if (empty($f['file_path'])) return null;
            [$code, $bytes] = self::request(self::base() . '/file/bot' . Settings::secret('telegram_token') . '/' . $f['file_path'], null);
            return $code === 200 ? Inbox::storeImage($bytes, $name) : null;
        } catch (Throwable $e) { return null; }
    }

    /** Calls a Bot API method and returns its `result`, or throws Telegram's description. */
    private static function call(string $method, array $params, ?string $token = null): mixed {
        $token = $token ?? Settings::secret('telegram_token');
        [$code, $body] = self::request(self::base() . '/bot' . $token . '/' . $method, $params);
        $j = json_decode($body, true);
        if (!is_array($j)) throw new RuntimeException($code ? "Telegram answered $code." : "Can't reach Telegram. Check the server's internet connection.");
        if (empty($j['ok'])) throw new RuntimeException($code === 401 || $code === 404 ? 'Telegram did not accept this token.' : 'Telegram: ' . ($j['description'] ?? "error $code"));
        return $j['result'] ?? null;
    }

    /** The API address; data/config.php may point tests at a stand-in server ('telegram_api'). */
    private static function base(): string { return rtrim((string) config('telegram_api', 'https://api.telegram.org'), '/'); }

    private static function request(string $url, ?array $json): array {
        if (self::$http) return (self::$http)($url, $json);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
        if ($json !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $raw === false ? '' : (string) $raw];
    }
}

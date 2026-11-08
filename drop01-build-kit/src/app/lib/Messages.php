<?php
// Customer messaging: renders templates, logs every message in `messages`, and delivers through a channel.
// Channels: 'email' (SMTP, sent automatically), 'whatsapp' (click-to-chat link the owner taps) and 'telegram' (Inbox replies, sent at once).
// A WhatsApp Cloud API channel can be added in deliver() later without touching callers.
defined('DROP01') || exit;

final class Messages {
    /** Queues an email built from a template. Returns the message id. */
    public static function queueEmail(string $template, string $to, array $vars, array $link = [], array $attach = [], array $headers = []): int {
        [$subject, $html, $text] = EmailTemplates::render($template, $vars);
        return DB::insert('messages', $link + [
            'channel' => 'email', 'template' => $template, 'to_addr' => $to, 'subject' => $subject,
            'body' => json_encode(['html' => $html, 'text' => $text, 'attach' => $attach] + ($headers ? ['headers' => $headers] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'queued', 'created_at' => DB::now(),
        ]);
    }

    /** Logs a WhatsApp click-to-chat message: the owner sends it with one tap from the order sheet (status "ready"). */
    public static function logWhatsapp(?int $orderId, ?int $customerId, string $phone, string $template, string $text): int {
        return DB::insert('messages', ['order_id' => $orderId, 'customer_id' => $customerId, 'channel' => 'whatsapp', 'template' => $template, 'to_addr' => $phone,
            'body' => json_encode(['text' => $text, 'link' => self::waLink($phone, $text)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'ready', 'created_at' => DB::now()]);
    }

    /** Tries to send one queued message. Messages are kept for the order timeline either way. */
    public static function deliver(int $id): bool {
        $m = DB::one('SELECT * FROM messages WHERE id = ?', [$id]);
        if (!$m || !in_array($m['status'], ['queued', 'retry'], true)) return false;
        if ($m['channel'] !== 'email') return false;
        if (!Mailer::configured()) { DB::update('messages', ['status' => 'not_sent', 'error' => 'Email is not set up yet'], 'id = ?', [$id]); return false; }
        $b = json_decode($m['body'], true) ?: ['html' => '', 'text' => ''];
        [$ok, $err] = Mailer::send($m['to_addr'], (string) $m['subject'], $b['html'], $b['text'], array_filter((array) ($b['attach'] ?? []), 'is_file'), (array) ($b['headers'] ?? []));
        $tries = (int) $m['tries'] + 1;
        DB::update('messages', $ok ? ['status' => 'sent', 'sent_at' => DB::now(), 'tries' => $tries, 'error' => null]
            : ['status' => $tries >= 5 ? 'failed' : 'retry', 'tries' => $tries, 'error' => substr($err, 0, 500)], 'id = ?', [$id]);
        return $ok;
    }

    /** Sends a message after the HTTP response has gone out (PHP-FPM / LiteSpeed), so visitors never wait for SMTP. */
    public static function deliverLater(int $id): void {
        register_shutdown_function(function () use ($id) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            try { self::deliver($id); } catch (Throwable $e) { Log::error('mail: ' . $e->getMessage()); }
        });
    }

    /**
     * Cron: send queued and retry messages. Customer messages go first; broadcast emails follow, capped per hour
     * (Settings → Marketing) so the SMTP provider's sending limit is never hit.
     */
    public static function flushQueue(int $max = 30): int {
        $n = 0;
        foreach (DB::all("SELECT id FROM messages WHERE status IN ('queued','retry') AND channel = 'email' AND broadcast_id IS NULL ORDER BY id LIMIT $max") as $r) if (self::deliver((int) $r['id'])) $n++;
        $room = min($max, max(0, (int) Settings::get('marketing')['per_hour'] - (int) DB::val("SELECT COUNT(*) FROM messages WHERE broadcast_id IS NOT NULL AND sent_at > ?", [DB::now(-3600)])));
        if ($room) foreach (DB::all("SELECT id FROM messages WHERE status IN ('queued','retry') AND channel = 'email' AND broadcast_id IS NOT NULL ORDER BY id LIMIT $room") as $r) if (self::deliver((int) $r['id'])) $n++;
        return $n;
    }

    /** Sends a batch of the queue after the response, e.g. after back-in-stock or a broadcast; cron does the rest. */
    public static function flushLater(int $max = 20): void {
        static $armed = false;
        if ($armed) return;
        $armed = true;
        register_shutdown_function(function () use ($max) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            try { self::flushQueue($max); } catch (Throwable $e) { Log::error('mail queue: ' . $e->getMessage()); }
        });
    }

    /** Click-to-chat link with a prefilled text: https://wa.me/<digits>?text=… */
    public static function waLink(string $phone, string $text): string {
        return 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . rawurlencode($text);
    }

    /** Signed unsubscribe link for waitlist emails. */
    public static function unsubscribeUrl(int $signupId): string {
        $sig = substr(hash_hmac('sha256', 'unsub:' . $signupId, (string) config('app_key')), 0, 20);
        return Http::baseUrl() . '/unsubscribe?s=' . $signupId . '&t=' . $sig;
    }
    public static function checkUnsubscribe(int $id, string $t): bool {
        return hash_equals(substr(hash_hmac('sha256', 'unsub:' . $id, (string) config('app_key')), 0, 20), $t);
    }

    /** Signed unsubscribe link for marketing emails to an address (broadcasts, abandoned cart). */
    public static function unsubscribeEmailUrl(string $email): string {
        return Http::baseUrl() . '/unsubscribe?e=' . rawurlencode($email) . '&t=' . substr(hash_hmac('sha256', 'unsub-e:' . strtolower($email), (string) config('app_key')), 0, 20);
    }
    public static function checkUnsubscribeEmail(string $email, string $t): bool {
        return $email !== '' && hash_equals(substr(hash_hmac('sha256', 'unsub-e:' . strtolower($email), (string) config('app_key')), 0, 20), $t);
    }

    /** No more marketing to this address, from any list. Order emails still arrive. */
    public static function unsubscribeEmail(string $email): void {
        DB::q('UPDATE customers SET marketing_consent = 0 WHERE email = ?', [$email]);
        DB::q('UPDATE waitlist_signups SET consent = 0, unsubscribed_at = ? WHERE email = ? AND unsubscribed_at IS NULL', [DB::now(), $email]);
        DB::q('UPDATE carts SET consent = 0, email = NULL, phone = NULL, name = NULL WHERE email = ?', [$email]);
        DB::q('DELETE FROM list_signups WHERE email = ?', [$email]);
        DB::q("UPDATE messages SET status = 'cancelled' WHERE to_addr = ? AND broadcast_id IS NOT NULL AND status IN ('queued','retry')", [$email]);
    }
}

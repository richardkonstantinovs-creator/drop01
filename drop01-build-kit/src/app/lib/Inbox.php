<?php
// The Inbox: every customer chat in one place. Incoming email (MailIn, IMAP) and Telegram (Telegram) land here as conversations;
// replies go out on the same channel and are logged in `messages`. Automatic order emails to the same address show in the thread too.
defined('DROP01') || exit;

final class Inbox {
    public const CHANNELS = ['email' => 'Email', 'telegram' => 'Telegram'];
    private const IMAGE_MAX = 8 * 1024 * 1024;

    /**
     * Stores one incoming message. $m: text, name, handle, subject, ext_id (deduplicates), files (stored image paths and file names),
     * customer_id (a known link, e.g. from the Telegram start link). Returns the conversation id, or null for a duplicate.
     */
    public static function receive(string $channel, string $address, array $m): ?int {
        $address = $channel === 'email' ? strtolower(trim($address)) : trim($address);
        if (!empty($m['ext_id']) && DB::val('SELECT 1 FROM conversation_messages WHERE ext_id = ?', [$m['ext_id']])) return null;
        $c = self::open($channel, $address, $m['name'] ?? null, $m['handle'] ?? null, $m['customer_id'] ?? null);
        if (!empty($m['subject']) && !$c['subject']) DB::update('conversations', ['subject' => mb_substr(self::baseSubject($m['subject']), 0, 200)], 'id = ?', [$c['id']]);
        $text = mb_substr(trim((string) ($m['text'] ?? '')), 0, 20000);
        $files = array_values((array) ($m['files'] ?? []));
        $now = DB::now();
        DB::insert('conversation_messages', ['conversation_id' => $c['id'], 'direction' => 'in', 'body' => $text, 'files' => $files ? json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ext_id' => $m['ext_id'] ?? null, 'auth' => in_array($m['auth'] ?? null, ['pass', 'fail'], true) ? $m['auth'] : null, 'created_at' => $now]);
        DB::q('UPDATE conversations SET unread = unread + 1, last_at = ?, last_text = ?, last_dir = ? WHERE id = ?', [$now, self::preview($text, $files), 'in', $c['id']]);
        return (int) $c['id'];
    }

    /** Finds or starts the conversation with one person on one channel, and links it to a customer when we know who it is. */
    public static function open(string $channel, string $address, ?string $name = null, ?string $handle = null, ?int $customerId = null): array {
        if (!isset(self::CHANNELS[$channel]) || $address === '') throw new RuntimeException('Unknown chat.');
        $c = DB::one('SELECT * FROM conversations WHERE channel = ? AND address = ?', [$channel, $address]);
        $customerId = $customerId ?: ($c['customer_id'] ?? null) ?: ($channel === 'email' ? (DB::val('SELECT id FROM customers WHERE email = ? ORDER BY id DESC LIMIT 1', [$address]) ?: null) : null);
        if (!$c) {
            $id = DB::insert('conversations', ['channel' => $channel, 'address' => $address, 'name' => $name ? mb_substr($name, 0, 160) : null, 'handle' => $handle ? mb_substr($handle, 0, 80) : null,
                'customer_id' => $customerId, 'last_at' => DB::now(), 'created_at' => DB::now()]);
            return DB::one('SELECT * FROM conversations WHERE id = ?', [$id]);
        }
        $set = array_filter(['name' => !$c['name'] && $name ? mb_substr($name, 0, 160) : null, 'handle' => $handle && $handle !== $c['handle'] ? mb_substr($handle, 0, 80) : null,
            'customer_id' => $customerId && (int) $customerId !== (int) $c['customer_id'] ? (int) $customerId : null]);
        if ($set) { DB::update('conversations', $set, 'id = ?', [$c['id']]); $c = array_replace($c, $set); }
        return $c;
    }

    /** The team replies. Email is queued and sent after the response; Telegram goes out at once. Returns the conversation message id. */
    public static function reply(int $convId, string $text, ?int $adminId, string $subject = ''): int {
        $c = DB::one('SELECT * FROM conversations WHERE id = ?', [$convId]);
        if (!$c) throw new RuntimeException('This chat no longer exists.');
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Type a message first.');
        if (mb_strlen($text) > 4000) throw new RuntimeException('That message is too long (4000 characters at most).');
        $link = ['customer_id' => $c['customer_id']];
        if ($c['channel'] === 'email') {
            if (!Mailer::configured()) throw new RuntimeException('Email is not set up yet (Settings → Email).');
            if (!$c['subject'] && trim($subject) !== '') { $c['subject'] = mb_substr(trim($subject), 0, 200); DB::update('conversations', ['subject' => $c['subject']], 'id = ?', [$convId]); }
            $subj = $c['subject'] ? (preg_match('/^re:/i', $c['subject']) ? $c['subject'] : 'Re: ' . $c['subject']) : 'Message from ' . Settings::get('store_name');
            $ref = DB::val("SELECT ext_id FROM conversation_messages WHERE conversation_id = ? AND direction = 'in' AND ext_id LIKE '<%' ORDER BY id DESC LIMIT 1", [$convId]);
            $mid = Messages::queueEmail('chat', $c['address'], ['text' => $text, 'subject' => $subj], $link, [], $ref ? ['In-Reply-To' => $ref, 'References' => $ref] : []);
            Messages::deliverLater($mid);
        } else {
            [$ok, $err] = Telegram::send($c['address'], $text);
            $mid = DB::insert('messages', $link + ['channel' => 'telegram', 'template' => 'chat', 'to_addr' => $c['address'], 'body' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
                'status' => $ok ? 'sent' : 'failed', 'tries' => 1, 'error' => $ok ? null : mb_substr($err, 0, 500), 'created_at' => DB::now(), 'sent_at' => $ok ? DB::now() : null]);
        }
        $id = DB::insert('conversation_messages', ['conversation_id' => $convId, 'direction' => 'out', 'body' => $text, 'admin_id' => $adminId, 'message_id' => $mid, 'created_at' => DB::now()]);
        DB::update('conversations', ['unread' => 0, 'last_at' => DB::now(), 'last_text' => self::preview($text), 'last_dir' => 'out'], 'id = ?', [$convId]);
        return $id;
    }

    /** Sends a failed reply again. */
    public static function retry(int $cmId): void {
        $r = DB::one("SELECT cm.*, m.status, m.id AS mid FROM conversation_messages cm JOIN messages m ON m.id = cm.message_id WHERE cm.id = ? AND cm.direction = 'out'", [$cmId]);
        if (!$r || !in_array($r['status'], ['failed', 'not_sent', 'retry'], true)) throw new RuntimeException('This message does not need sending again.');
        $c = DB::one('SELECT * FROM conversations WHERE id = ?', [$r['conversation_id']]);
        if ($c['channel'] === 'email') {
            if (!Mailer::configured()) throw new RuntimeException('Email is not set up yet (Settings → Email).');
            DB::update('messages', ['status' => 'queued', 'tries' => 0, 'error' => null], 'id = ?', [$r['mid']]);
            Messages::deliverLater((int) $r['mid']);
        } else {
            [$ok, $err] = Telegram::send($c['address'], (string) $r['body']);
            DB::update('messages', $ok ? ['status' => 'sent', 'sent_at' => DB::now(), 'error' => null] : ['error' => mb_substr($err, 0, 500)], 'id = ?', [$r['mid']]);
            if (!$ok) throw new RuntimeException('Telegram did not take it: ' . $err);
        }
    }

    /** The list, newest first. $f: channel (email|telegram), unread (bool), q (search), offset. */
    public static function list(array $f = []): array {
        $w = ['1=1']; $p = [];
        if (isset(self::CHANNELS[$f['channel'] ?? ''])) { $w[] = 'c.channel = ?'; $p[] = $f['channel']; }
        if (!empty($f['unread'])) $w[] = 'c.unread > 0';
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $w[] = '(c.name LIKE ? OR c.address LIKE ? OR c.handle LIKE ? OR c.subject LIKE ? OR cu.name LIKE ? OR EXISTS (SELECT 1 FROM conversation_messages x WHERE x.conversation_id = c.id AND x.body LIKE ?))';
            array_push($p, ...array_fill(0, 6, '%' . $q . '%'));
        }
        $where = implode(' AND ', $w);
        $off = max(0, (int) ($f['offset'] ?? 0));
        $rows = DB::all("SELECT c.*, cu.name AS customer_name FROM conversations c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE $where ORDER BY c.last_at DESC, c.id DESC LIMIT 50 OFFSET $off", $p);
        return ['rows' => array_map([self::class, 'row'], $rows), 'total' => (int) DB::val("SELECT COUNT(*) FROM conversations c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE $where", $p),
            'counts' => ['all' => (int) DB::val('SELECT COUNT(*) FROM conversations'), 'unread' => self::unread(),
                'email' => (int) DB::val("SELECT COUNT(*) FROM conversations WHERE channel = 'email'"), 'telegram' => (int) DB::val("SELECT COUNT(*) FROM conversations WHERE channel = 'telegram'")]];
    }

    /** Conversations with something new: the badge on the Inbox tab. */
    public static function unread(): int { return (int) DB::val('SELECT COUNT(*) FROM conversations WHERE unread > 0'); }

    /** One conversation with its messages (and the automatic emails sent to the same address). Opening it marks it read. */
    public static function thread(int $id, bool $markRead = true): ?array {
        $c = DB::one('SELECT c.*, cu.name AS customer_name, cu.email AS customer_email FROM conversations c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE c.id = ?', [$id]);
        if (!$c) return null;
        if ($markRead && (int) $c['unread']) { DB::update('conversations', ['unread' => 0], 'id = ?', [$id]); $c['unread'] = 0; }
        $items = [];
        foreach (DB::all('SELECT cm.*, a.name AS by_name, m.status, m.error FROM conversation_messages cm LEFT JOIN admins a ON a.id = cm.admin_id LEFT JOIN messages m ON m.id = cm.message_id WHERE cm.conversation_id = ? ORDER BY cm.id', [$id]) as $m) {
            $files = [];
            foreach (json_decode((string) $m['files'], true) ?: [] as $i => $f) $files[] = isset($f['path']) ? ['name' => $f['name'] ?? 'photo', 'url' => '/api/admin/inbox/file/' . $m['id'] . '/' . $i] : ['name' => $f['name'] ?? 'file'];
            $items[] = ['id' => (int) $m['id'], 'kind' => $m['direction'], 'text' => (string) $m['body'], 'at' => $m['created_at'], 'by' => $m['by_name'], 'files' => $files,
                'status' => $m['direction'] === 'out' ? ($m['status'] ?: 'sent') : null, 'error' => $m['error'], 'spoof' => $m['auth'] === 'fail'];
        }
        if ($c['channel'] === 'email') foreach (DB::all("SELECT id, template, subject, status, created_at, order_id FROM messages WHERE to_addr = ? AND channel = 'email' AND template <> 'chat' ORDER BY id DESC LIMIT 30", [$c['address']]) as $m)
            $items[] = ['id' => 'm' . $m['id'], 'kind' => 'auto', 'text' => (string) ($m['subject'] ?: str_replace('_', ' ', $m['template'])), 'at' => $m['created_at'], 'status' => $m['status'], 'order_id' => $m['order_id'] ? (int) $m['order_id'] : null];
        usort($items, fn($a, $b) => strcmp($a['at'], $b['at']));   // stable: same-second messages keep their order
        $cust = $c['customer_id'] ? DB::one("SELECT COUNT(*) AS n, MAX(number) AS last FROM orders WHERE customer_id = ? AND status <> 'cancelled'", [$c['customer_id']]) : null;
        $last = $c['customer_id'] ? DB::one("SELECT id, number, status FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$c['customer_id']]) : null;
        return ['conversation' => self::row($c) + ['orders' => $cust ? (int) $cust['n'] : 0, 'last_order' => $last ? ['id' => (int) $last['id'], 'number' => (int) $last['number'], 'status' => $last['status']] : null,
            'can_send' => $c['channel'] === 'email' ? Mailer::configured() : Telegram::connected()], 'items' => $items];
    }

    /** Deletes a conversation (spam, mistakes) and its saved photos. Replies stay in `messages` like every other message. */
    public static function delete(int $id): void {
        foreach (DB::all('SELECT files FROM conversation_messages WHERE conversation_id = ? AND files IS NOT NULL', [$id]) as $r)
            foreach (json_decode($r['files'], true) ?: [] as $f) if (!empty($f['path']) && !str_contains($f['path'], '..')) @unlink(data_dir() . '/' . $f['path']);
        DB::q('DELETE FROM conversation_messages WHERE conversation_id = ?', [$id]);
        DB::q('DELETE FROM conversations WHERE id = ?', [$id]);
    }

    /** GDPR: a deleted customer's chats go too. */
    public static function forgetCustomer(int $customerId, ?string $email): void {
        foreach (DB::all('SELECT id FROM conversations WHERE customer_id = ? OR (channel = ? AND address = ?)', [$customerId, 'email', strtolower((string) $email)]) as $r) self::delete((int) $r['id']);
    }

    /** An image a customer sent: re-encoded through GD (no EXIF, no tricks) and kept outside the web root. Returns ['name','path'] or null. */
    public static function storeImage(string $bytes, string $name): ?array {
        if ($bytes === '' || strlen($bytes) > self::IMAGE_MAX) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'd01in');
        file_put_contents($tmp, $bytes);
        try {
            $img = Images::store(['error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'tmp_name' => $tmp], 'inbox');
            $dir = data_dir() . '/inbox/' . gmdate('Y-m');
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
            $dest = $dir . '/' . basename($img['path']);
            if (!@rename(ROOT . $img['path'], $dest)) return null;
            @unlink(ROOT . $img['path_sm']);
            return ['name' => mb_substr($name ?: 'photo', 0, 120), 'path' => substr($dest, strlen(data_dir()) + 1)];
        } catch (Throwable $e) {
            return null;
        } finally { @unlink($tmp); }
    }

    public static function file(int $cmId, int $i): ?string {
        $files = json_decode((string) DB::val('SELECT files FROM conversation_messages WHERE id = ?', [$cmId]), true) ?: [];
        $p = $files[$i]['path'] ?? null;
        if (!$p || str_contains($p, '..')) return null;
        $f = data_dir() . '/' . $p;
        return is_file($f) ? $f : null;
    }

    /** Checks email and Telegram (poll mode) at most once a minute: from cron, and after the Inbox loads, so new mail shows up quickly. */
    public static function poll(bool $force = false): array {
        $last = (string) Settings::get('inbox_polled_at', '');
        if (!$force && $last && strtotime($last) > time() - 60) return ['skipped' => true];
        $lock = @fopen(data_dir() . '/inbox.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => true];
        Settings::set('inbox_polled_at', DB::now());
        $out = ['email' => null, 'telegram' => null];
        try {
            if (MailIn::enabled()) $out['email'] = MailIn::fetch();
            if (Telegram::connected() && Settings::get('telegram')['mode'] === 'poll') $out['telegram'] = Telegram::poll();
        } finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
        return $out;
    }

    /** Runs poll() after the response has gone out, so the owner never waits for the mail server. */
    public static function pollLater(): void {
        $last = (string) Settings::get('inbox_polled_at', '');
        if ($last && strtotime($last) > time() - 60) return;
        if (!MailIn::enabled() && !(Telegram::connected() && Settings::get('telegram')['mode'] === 'poll')) return;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            try { self::poll(); } catch (Throwable $e) { Log::error('inbox poll: ' . $e->getMessage()); }
        });
    }

    private static function row(array $c): array {
        return ['id' => (int) $c['id'], 'channel' => $c['channel'], 'address' => $c['address'], 'name' => $c['name'] ?: ($c['customer_name'] ?? null), 'handle' => $c['handle'],
            'customer_id' => $c['customer_id'] ? (int) $c['customer_id'] : null, 'customer_name' => $c['customer_name'] ?? null, 'subject' => $c['subject'], 'unread' => (int) $c['unread'],
            'last_at' => $c['last_at'], 'last_text' => $c['last_text'], 'last_dir' => $c['last_dir']];
    }

    private static function preview(string $text, array $files = []): string {
        $t = trim(preg_replace('/\s+/u', ' ', $text));
        if ($t === '' && $files) $t = isset($files[0]['path']) ? '📷 Photo' : '📎 ' . ($files[0]['name'] ?? 'File');
        return mb_substr($t, 0, 200);
    }

    /** "Re: Fwd: Order 1062" → "Order 1062", so replies keep one subject. */
    public static function baseSubject(string $s): string {
        return trim(preg_replace('/^\s*((re|fw|fwd|aw|antw|sv)\s*(\[\d+\])?\s*:\s*)+/i', '', $s));
    }
}

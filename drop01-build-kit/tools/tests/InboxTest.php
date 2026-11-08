<?php
// Inbox: email parsing and filtering, conversations and replies, Telegram connect / start link / messages / photos, GDPR delete.
defined('DROP01') || exit;

function ib_fixture(): void {
    foreach (['conversations', 'conversation_messages', 'messages', 'customers', 'orders', 'waitlist_signups'] as $t) DB::q("DELETE FROM $t");
    Settings::set('smtp', ['host' => 'smtp.test', 'port' => 587, 'secure' => 'tls', 'user' => 'hello@shop.test', 'from' => 'hello@shop.test', 'from_name' => 'Drop 01']);
    Settings::set('imap', ['enabled' => true, 'host' => 'imap.test', 'port' => 993, 'secure' => 'ssl', 'user' => '', 'who' => 'all']);
    MailIn::$fetcher = null; Telegram::$http = null;
}
function ib_mail(string $from, string $subject, string $body, array $extra = []): string {
    return implode("\r\n", array_merge(["From: $from", 'To: hello@shop.test', "Subject: $subject", 'Message-ID: <' . bin2hex(random_bytes(6)) . '@mail.test>', 'MIME-Version: 1.0'], $extra,
        ['Content-Type: text/plain; charset=utf-8', '', $body]));
}
/** Fake Bot API: answers getMe/setWebhook/sendMessage/getFile/getUpdates and records every call. */
function tg_mock(array $updates = [], bool $failSend = false): void {
    $GLOBALS['tg_calls'] = [];
    Telegram::$http = function (string $url, ?array $json) use ($updates, $failSend) {
        $m = basename(parse_url($url, PHP_URL_PATH));
        $GLOBALS['tg_calls'][] = [$m, $json];
        if (str_contains($url, '/file/bot')) return [200, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')];
        $r = match ($m) {
            'getMe' => ['id' => 42, 'is_bot' => true, 'first_name' => 'Drop 01', 'username' => 'drop01_bot'],
            'getUpdates' => $updates, 'getFile' => ['file_path' => 'photos/p1.png'],
            'getWebhookInfo' => ['url' => 'https://shop.test/api/telegram/hook', 'pending_update_count' => 0],
            default => true,
        };
        if ($m === 'sendMessage' && $failSend) return [403, json_encode(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'])];
        return [200, json_encode(['ok' => true, 'result' => $r])];
    };
}
function tg_msg(int $id, string $text, array $extra = []): array {
    return ['update_id' => 1000 + $id, 'message' => $extra + ['message_id' => $id, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'first_name' => 'Anna', 'last_name' => 'Bakker', 'username' => 'annab'], 'text' => $text]];
}

function test_email_parsing_mime_quotes_and_attachments(): void {
    ib_fixture();
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $raw = "From: =?UTF-8?B?QW5uYSBkZSBWcmllcw==?= <Anna@Example.nl>\r\nSubject: =?UTF-8?Q?Re:_Bestelling_#1062_=E2=9C=93?=\r\nMessage-ID: <m1@x.nl>\r\nContent-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n"
        . "--MIX\r\nContent-Type: multipart/alternative; boundary=ALT\r\n\r\n--ALT\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHoi! Waar blijft mijn pakket? Caf=E9 om de hoek.\r\n\r\nOp di 13 okt. 2026 om 10:00 schreef Drop 01 <hello@shop.test>:\r\n> Your order is on its way\r\n--ALT\r\nContent-Type: text/html\r\n\r\n<p>HTML version</p>\r\n--ALT--\r\n"
        . "--MIX\r\nContent-Type: image/png; name=\"doos.png\"\r\nContent-Disposition: attachment; filename=\"doos.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n$png\r\n"
        . "--MIX\r\nContent-Type: application/pdf; name=\"bon.pdf\"\r\nContent-Disposition: attachment; filename*=UTF-8''b%C3%B3n.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0=\r\n--MIX--\r\n";
    $m = MailIn::parse($raw);
    eq(MailIn::address($m['headers']['from']), ['Anna de Vries', 'anna@example.nl'], 'encoded From');
    eq(MailIn::decodeHeader($m['headers']['subject']), 'Re: Bestelling #1062 ✓', 'encoded subject');
    eq(MailIn::stripQuote($m['text']), 'Hoi! Waar blijft mijn pakket? Café om de hoek.', 'plain part, latin-1, quote cut');
    eq(array_column($m['attachments'], 'name'), ['doos.png', 'bón.pdf'], 'attachment names incl. RFC 2231');
    ok(MailIn::ingest($raw), 'stored');
    $c = DB::one('SELECT * FROM conversations');
    eq([$c['channel'], $c['address'], $c['name'], $c['subject'], (int) $c['unread']], ['email', 'anna@example.nl', 'Anna de Vries', 'Bestelling #1062 ✓', 1]);
    $files = json_decode(DB::val('SELECT files FROM conversation_messages'), true);
    ok(isset($files[0]['path']) && is_file(data_dir() . '/' . $files[0]['path']), 'the photo is kept outside the web root');
    eq($files[1], ['name' => 'bón.pdf'], 'other files are listed by name only');
    ok(!MailIn::ingest($raw), 'the same Message-ID is not stored twice');
    eq(MailIn::stripQuote("Thanks!\n\nOn Tue, 13 Oct 2026 at 10:00, Drop 01 <a@b.c> wrote:\n> hi"), 'Thanks!');
    eq(MailIn::stripQuote("Yes please\n\nFrom: Drop 01 <a@b.c>\nSent: Tuesday\nTo: me"), 'Yes please');
    eq(MailIn::htmlToText('<div>Hi<br>there</div><blockquote>old</blockquote><p>&euro;5 &amp; more</p>'), "Hi\nthere\n\n€5 & more");
}

function test_email_filter_skips_own_automatic_and_strangers(): void {
    ib_fixture();
    ok(!MailIn::ingest(ib_mail('Drop 01 <hello@shop.test>', 'Copy', 'mine')), 'own address');
    ok(!MailIn::ingest(ib_mail('MAILER-DAEMON@mx.test', 'Undelivered', 'bounce')), 'bounce');
    ok(!MailIn::ingest(ib_mail('a@x.nl', 'Out of office', 'away', ['Auto-Submitted: auto-replied'])), 'auto reply');
    ok(!MailIn::ingest(ib_mail('news@brand.nl', 'Sale', 'buy', ['List-Unsubscribe: <https://brand.nl/u>'])), 'newsletter');
    ok(MailIn::ingest(ib_mail('stranger@x.nl', 'Hello', 'Do you ship to Haren?')), 'a new person');
    Settings::set('imap', array_replace(Settings::get('imap'), ['who' => 'customers']));
    ok(!MailIn::ingest(ib_mail('other@x.nl', 'Hello', 'cold pitch')), 'customers only: stranger skipped');
    DB::insert('customers', ['name' => 'Kees', 'email' => 'kees@x.nl', 'created_at' => DB::now()]);
    ok(MailIn::ingest(ib_mail('kees@x.nl', 'Question', 'Can I change my order?')), 'customers only: customer kept');
    ok(MailIn::ingest(ib_mail('stranger@x.nl', 'Re: Hello', 'and Zuidhorn?')), 'customers only: someone already in the Inbox');
    eq((int) DB::val('SELECT COUNT(*) FROM conversations'), 2);
}

function test_mailbox_fetch_starts_from_now_and_remembers_errors(): void {
    ib_fixture();
    $GLOBALS['ib_mails'] = [ib_mail('a@x.nl', 'One', 'first')];
    MailIn::$fetcher = function (array $cfg, array $state) {
        eq($cfg['user'], 'hello@shop.test', 'empty IMAP login falls back to the SMTP login');
        if (!isset($state['last'])) return [['validity' => 1, 'last' => 10], []];
        if (!empty($GLOBALS['ib_fail'])) throw new RuntimeException('The mail server refused the login.');
        return [['validity' => 1, 'last' => 11], $GLOBALS['ib_mails']];
    };
    eq(MailIn::fetch(), 0, 'first run only remembers where the mailbox is');
    eq(MailIn::fetch(), 1);
    $GLOBALS['ib_fail'] = true;
    try { MailIn::fetch(); ok(false, 'should throw'); } catch (RuntimeException $e) {}
    eq(Settings::get('imap_state')['error'], 'The mail server refused the login.');
    eq(Settings::get('imap_state')['last'], 11, 'position kept after an error');
    unset($GLOBALS['ib_fail']); MailIn::$fetcher = null;
}

function test_conversation_reply_by_email_and_thread(): void {
    ib_fixture();
    $cid = DB::insert('customers', ['name' => 'Lotte Mulder', 'email' => 'lotte@x.nl', 'created_at' => DB::now()]);
    DB::insert('messages', ['customer_id' => $cid, 'order_id' => null, 'channel' => 'email', 'template' => 'order_confirmation', 'to_addr' => 'lotte@x.nl', 'subject' => 'Order #1001 confirmed', 'status' => 'sent', 'created_at' => DB::now(-60)]);
    MailIn::ingest(implode("\r\n", ['From: Lotte <lotte@x.nl>', 'Subject: Where is it?', 'Message-ID: <q1@x.nl>', '', 'Hi, when do you come?']));
    $conv = DB::one('SELECT * FROM conversations');
    eq((int) $conv['customer_id'], $cid, 'linked to the customer by email');
    eq(Inbox::unread(), 1);
    Inbox::reply((int) $conv['id'], "Tonight between 18 and 21.\n\nSee you!", 1);
    $m = DB::one("SELECT * FROM messages WHERE template = 'chat'");
    eq([$m['channel'], $m['to_addr'], $m['subject'], $m['status']], ['email', 'lotte@x.nl', 'Re: Where is it?', 'queued']);
    eq(json_decode($m['body'], true)['headers']['In-Reply-To'], '<q1@x.nl>', 'threads in the customer\'s mail app');
    eq(Inbox::unread(), 0, 'answering clears unread');
    $t = Inbox::thread((int) $conv['id']);
    eq(array_column($t['items'], 'kind'), ['auto', 'in', 'out'], 'automatic order email, their message, our reply');
    eq($t['items'][2]['status'], 'queued');
    eq(Inbox::list(['q' => 'tonight'])['total'], 1, 'search finds message text');
    eq(Inbox::list(['channel' => 'telegram'])['total'], 0);
    $st = Inbox::open('email', 'lotte@x.nl');
    eq((int) $st['id'], (int) $conv['id'], 'starting a chat with the customer reuses it');
    Settings::set('smtp', ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'from' => '', 'from_name' => '']);
    try { Inbox::reply((int) $conv['id'], 'x', 1); ok(false, 'should refuse'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'Email is not set up'), $e->getMessage()); }
}

function test_telegram_connect_start_link_messages_and_reply(): void {
    ib_fixture();
    tg_mock();
    try { Telegram::connect('nope'); ok(false, 'bad token accepted'); } catch (RuntimeException $e) {}
    $t = Telegram::connect('123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'https://shop.test');
    eq([$t['bot'], $t['mode']], ['drop01_bot', 'webhook']);
    $hook = array_values(array_filter($GLOBALS['tg_calls'], fn($c) => $c[0] === 'setWebhook'))[0][1];
    eq($hook['url'], 'https://shop.test/api/telegram/hook');
    eq($hook['secret_token'], Settings::secret('telegram_hook'), 'webhook carries our secret');
    eq(Telegram::connect('123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'http://shop.test')['mode'], 'poll', 'no HTTPS → polling');

    $cid = DB::insert('customers', ['name' => 'Anna Bakker', 'email' => 'anna@x.nl', 'created_at' => DB::now()]);
    DB::insert('orders', ['number' => 1062, 'token' => 't', 'customer_id' => $cid, 'created_at' => DB::now()]);
    $link = Telegram::orderLink(1062);
    eq(substr($link, 0, 33), 'https://t.me/drop01_bot?start=o10', 'order deep link');
    ok(!Telegram::handle(tg_msg(1, '/start o1062_0000000000')), 'a forged link links nobody');
    eq(DB::val('SELECT customer_id FROM conversations'), null);
    $GLOBALS['tg_calls'] = [];
    ok(Telegram::handle(tg_msg(2, '/start ' . substr($link, strpos($link, '=') + 1))), 'real link');
    $c = DB::one('SELECT * FROM conversations');
    eq([(int) $c['customer_id'], $c['name'], $c['handle'], $c['address']], [$cid, 'Anna Bakker', '@annab', '777']);
    $sent = array_values(array_filter($GLOBALS['tg_calls'], fn($x) => $x[0] === 'sendMessage'));
    eq(count($sent), 1, 'welcome message');
    ok(str_contains($sent[0][1]['text'], 'Drop 01'), '{store} filled in');

    ok(Telegram::handle(tg_msg(3, 'Is the hoodie back?')), 'text');
    ok(!Telegram::handle(tg_msg(3, 'Is the hoodie back?')), 'same message twice is ignored');
    ok(Telegram::handle(tg_msg(4, '', ['text' => null, 'caption' => 'Arrived like this', 'photo' => [['file_id' => 'small', 'file_size' => 100], ['file_id' => 'big', 'file_size' => 900]]])), 'photo');
    ok(Telegram::handle(tg_msg(5, '', ['text' => null, 'voice' => ['file_id' => 'v']])), 'voice');
    ok(!Telegram::handle(['update_id' => 9, 'message' => ['message_id' => 9, 'chat' => ['id' => -5, 'type' => 'group'], 'from' => ['id' => 1], 'text' => 'hi all']]), 'groups are ignored');
    eq((int) DB::one('SELECT unread FROM conversations')['unread'], 3, 'the start link note is answered by the welcome; three new');
    $t = Inbox::thread((int) $c['id']);
    eq(Inbox::unread(), 0, 'opening the chat marks it read');
    $texts = array_column(array_filter($t['items'], fn($x) => $x['kind'] === 'in'), 'text');
    eq(array_values($texts), ['Opened the chat from order #1062', 'Is the hoodie back?', 'Arrived like this', '🎤 Voice message (listen in Telegram)']);
    $photo = array_values(array_filter($t['items'], fn($x) => $x['files']))[0]['files'][0];
    ok(str_starts_with($photo['url'], '/api/admin/inbox/file/'), 'photo served by the admin API');

    Inbox::reply((int) $c['id'], 'Yes, Friday!', 1);
    $last = end($GLOBALS['tg_calls']);
    eq([$last[0], $last[1]['chat_id'], $last[1]['text']], ['sendMessage', '777', 'Yes, Friday!']);
    eq(DB::val("SELECT status FROM messages WHERE channel = 'telegram' ORDER BY id DESC LIMIT 1"), 'sent');

    tg_mock([], true);
    $mid = Inbox::reply((int) $c['id'], 'Hello?', 1);
    $row = DB::one("SELECT status, error FROM messages WHERE channel = 'telegram' ORDER BY id DESC LIMIT 1");
    eq([$row['status'], $row['error']], ['failed', 'The customer blocked the bot.']);
    tg_mock();
    Inbox::retry($mid);
    eq(DB::val("SELECT status FROM messages WHERE channel = 'telegram' ORDER BY id DESC LIMIT 1"), 'sent', 'sent again');
}

function test_telegram_poll_mode_and_disconnect(): void {
    ib_fixture();
    tg_mock([tg_msg(11, 'hi'), tg_msg(12, 'anyone?')]);
    Telegram::connect('123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'http://shop.test');
    Settings::set('imap', array_replace(Settings::get('imap'), ['enabled' => false]));
    eq(Inbox::poll(true)['telegram'], 2);
    eq((int) Settings::get('telegram_offset'), 1013, 'next poll starts after the last update');
    eq((int) DB::val('SELECT COUNT(*) FROM conversations'), 1);
    eq(Inbox::poll()['skipped'] ?? false, true, 'at most once a minute');
    Telegram::disconnect();
    ok(!Telegram::connected(), 'disconnected');
    eq(Telegram::orderLink(1), null, 'no order page button without a bot');
    [$ok, $err] = Telegram::send('777', 'x');
    ok(!$ok && str_contains($err, 'not connected'), 'sending says why');
}

function test_customer_delete_removes_chats(): void {
    ib_fixture();
    $cid = DB::insert('customers', ['name' => 'Gone', 'email' => 'gone@x.nl', 'created_at' => DB::now()]);
    MailIn::ingest(ib_mail('gone@x.nl', 'Hi', 'delete me later'));
    Inbox::receive('telegram', '555', ['text' => 'hoi', 'customer_id' => $cid]);
    eq(count(Customers::export($cid)['chats']), 2, 'chats are in the GDPR export');
    Customers::delete($cid);
    eq((int) DB::val('SELECT COUNT(*) FROM conversations'), 0);
    eq((int) DB::val('SELECT COUNT(*) FROM conversation_messages'), 0);
}

function test_spoofed_sender_gets_a_warning(): void {
    ib_fixture();
    $c = fn($ar, $from = 'lotte@gmail.com') => MailIn::senderCheck($ar, $from);
    eq($c('mx.secureserver.net; dkim=pass header.i=@gmail.com; spf=pass smtp.mailfrom=lotte@gmail.com; dmarc=pass (p=NONE) header.from=gmail.com'), 'pass');
    eq($c('mx.secureserver.net; spf=softfail smtp.mailfrom=evil.test; dkim=none; dmarc=fail (p=NONE) header.from=gmail.com'), 'fail', 'DMARC fail');
    eq($c('mx.test; dkim=pass header.d=evil.test; spf=pass smtp.mailfrom=bounce@evil.test'), null, 'passes for another domain prove nothing');
    eq($c('mx.test; spf=fail smtp.mailfrom=gmail.com'), 'fail', 'SPF fail without DMARC');
    eq($c('mx.test; dkim=pass header.d=mail.shop.nl', 'kees@shop.nl'), 'pass', 'subdomain of the From domain');
    eq($c(''), null, 'no verdict');
    // the verdict on top (our server's) counts, not one the sender wrote further down
    MailIn::ingest(ib_mail('Lotte <lotte@gmail.com>', 'Change address', 'Please deliver to Kerklaan 1 instead', [
        'Authentication-Results: mx.secureserver.net; spf=softfail smtp.mailfrom=evil.test; dmarc=fail header.from=gmail.com',
        'Authentication-Results: fake; dmarc=pass']));
    MailIn::ingest(ib_mail('Lotte <lotte@gmail.com>', 'Thanks', 'Real one', ['Authentication-Results: mx.secureserver.net; dmarc=pass header.from=gmail.com']));
    $t = Inbox::thread((int) DB::val("SELECT id FROM conversations WHERE address = 'lotte@gmail.com'"));
    eq(array_map(fn($x) => $x['spoof'], array_values(array_filter($t['items'], fn($x) => $x['kind'] === 'in'))), [true, false]);
}

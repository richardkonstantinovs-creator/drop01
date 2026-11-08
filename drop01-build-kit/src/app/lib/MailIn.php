<?php
// Incoming email for the Inbox: a small IMAP client (plain sockets, no php-imap extension needed) that reads new mail without marking it read,
// and a MIME parser that keeps the customer's own words (quoted history cut off), sender, subject and image attachments.
defined('DROP01') || exit;

final class MailIn {
    /** Test hook: fn(array $cfg, array $state): [array $newState, string[] $rawMessages]. */
    public static $fetcher = null;
    private const MAX_PER_RUN = 25, MAX_BYTES = 15 * 1024 * 1024;

    public static function enabled(): bool {
        $c = Settings::get('imap');
        return !empty($c['enabled']) && self::cfg()['host'] !== '' && self::cfg()['user'] !== '';
    }

    /** Connection details; an empty login or password falls back to the SMTP ones (the same mailbox on most hosts). */
    public static function cfg(): array {
        $c = Settings::get('imap'); $s = Settings::get('smtp');
        return ['host' => trim((string) $c['host']), 'port' => (int) $c['port'] ?: 993, 'secure' => in_array($c['secure'], ['ssl', 'tls', 'none'], true) ? $c['secure'] : 'ssl',
            'user' => trim((string) $c['user']) ?: (string) $s['user'], 'pass' => Settings::hasSecret('imap_pass') ? Settings::secret('imap_pass') : Settings::secret('smtp_pass')];
    }

    /** Reads new mail into the Inbox. Returns how many messages became chat messages. Throws with a readable reason when the mailbox can't be read. */
    public static function fetch(): int {
        $state = (array) Settings::get('imap_state', []);
        try { [$new, $raws] = self::$fetcher ? (self::$fetcher)(self::cfg(), $state) : self::imapFetch(self::cfg(), $state); }
        catch (Throwable $e) { Settings::set('imap_state', array_replace($state, ['checked_at' => DB::now(), 'error' => mb_substr($e->getMessage(), 0, 300)])); throw $e; }
        Settings::set('imap_state', array_replace($new, ['checked_at' => DB::now(), 'error' => null]));
        $n = 0;
        foreach ($raws as $raw) {
            try { if (self::ingest($raw)) $n++; } catch (Throwable $e) { Log::error('inbox email: ' . $e->getMessage()); }
        }
        return $n;
    }

    /** One raw email → a chat message, unless it is ours, automatic, a newsletter or (with "customers only") from a stranger. */
    public static function ingest(string $raw): bool {
        $m = self::parse($raw);
        $h = $m['headers'];
        [$name, $from] = self::address($h['from'] ?? '');
        if (!$from || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
        $own = array_filter(array_map('strtolower', [Settings::get('smtp')['from'], self::cfg()['user'], Settings::get('company')['email']]));
        if (in_array($from, $own, true)) return false;
        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounces?)([+@.-]|$)/i', $from)) return false;
        $auto = strtolower(trim($h['auto-submitted'] ?? 'no'));
        if ($auto !== '' && $auto !== 'no') return false;
        if (preg_match('/bulk|junk|list|auto_reply/i', $h['precedence'] ?? '') || isset($h['list-id']) || isset($h['list-unsubscribe']) || isset($h['x-autoreply']) || isset($h['x-autorespond'])) return false;
        if (Settings::get('imap')['who'] === 'customers' && !self::known($from)) return false;
        $files = [];
        foreach ($m['attachments'] as $a) $files[] = str_starts_with($a['type'], 'image/') && ($img = Inbox::storeImage($a['data'], $a['name'])) ? $img : ['name' => $a['name']];
        $text = self::stripQuote($m['text']);
        if ($text === '' && !$files) $text = trim($m['text']);
        return (bool) Inbox::receive('email', $from, ['name' => $name, 'subject' => self::decodeHeader($h['subject'] ?? ''), 'text' => $text, 'files' => $files,
            'auth' => self::senderCheck($h['authentication-results'] ?? '', $from),
            'ext_id' => mb_substr(trim($h['message-id'] ?? '') ?: 'sha:' . sha1($raw), 0, 191)]);
    }

    /**
     * Is the From address real? Reads the Authentication-Results header that our own mail server puts on top of every incoming email.
     * 'pass' (DMARC passed, or DKIM / SPF passed for the From domain), 'fail' (DMARC failed, or SPF / DKIM failed without a pass), null (no verdict).
     */
    public static function senderCheck(string $ar, string $from): ?string {
        $ar = strtolower($ar);
        if ($ar === '') return null;
        if (preg_match('/\bdmarc=(\w+)/', $ar, $m)) {
            if ($m[1] === 'pass') return 'pass';
            if (in_array($m[1], ['fail', 'quarantine', 'reject'], true)) return 'fail';
        }
        $domain = substr(strrchr(strtolower($from), '@') ?: '', 1);
        $aligned = fn(string $d) => $domain !== '' && ($d === $domain || str_ends_with($domain, '.' . $d) || str_ends_with($d, '.' . $domain));
        if (preg_match_all('/\bdkim=pass\b[^;]*?header\.[di]=@?([a-z0-9.-]+)/', $ar, $mm)) foreach ($mm[1] as $d) if ($aligned(ltrim(strrchr('@' . $d, '@'), '@'))) return 'pass';
        if (preg_match('/\bspf=pass\b[^;]*?smtp\.mailfrom=(?:[^@;\s]*@)?([a-z0-9.-]+)/', $ar, $m) && $aligned($m[1])) return 'pass';
        if (preg_match('/\b(spf=(fail|softfail)|dkim=fail)\b/', $ar)) return 'fail';
        return null;
    }

    private static function known(string $email): bool {
        return (bool) (DB::val('SELECT 1 FROM customers WHERE email = ?', [$email]) ?: DB::val('SELECT 1 FROM waitlist_signups WHERE email = ?', [$email])
            ?: DB::val("SELECT 1 FROM conversations WHERE channel = 'email' AND address = ?", [$email]));
    }

    // ------------------------------------------------------------------ MIME

    /** Parses a raw email. Returns ['headers' => lowercased first values, 'text' => plain text, 'attachments' => [[name, type, data]]]. */
    public static function parse(string $raw): array {
        [$headers, $body] = self::split($raw);
        $out = ['headers' => $headers, 'plain' => null, 'html' => null, 'attachments' => []];
        self::walk($headers, $body, $out, 0);
        $text = $out['plain'] ?? ($out['html'] !== null ? self::htmlToText($out['html']) : '');
        return ['headers' => $headers, 'text' => trim(str_replace(["\r\n", "\r"], "\n", $text)), 'attachments' => $out['attachments']];
    }

    private static function split(string $raw): array {
        $raw = ltrim($raw, "\r\n");
        $p = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headers = [];
        foreach (preg_split('/\r?\n(?![ \t])/', $p[0]) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            if (!isset($headers[$k])) $headers[$k] = trim(preg_replace('/\r?\n[ \t]+/', ' ', $v));
        }
        return [$headers, $p[1] ?? ''];
    }

    private static function walk(array $h, string $body, array &$out, int $depth): void {
        [$type, $params] = self::headerParams($h['content-type'] ?? 'text/plain');
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        [$disp, $dparams] = self::headerParams($h['content-disposition'] ?? '');
        if (str_starts_with($type, 'multipart/') && !empty($params['boundary']) && $depth < 8) {
            $parts = explode('--' . $params['boundary'], $body);
            array_shift($parts);
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) break;
                [$ph, $pb] = self::split($part);
                self::walk($ph, preg_replace('/\r?\n$/', '', $pb), $out, $depth + 1);
            }
            return;
        }
        $data = $enc === 'base64' ? (string) base64_decode(preg_replace('/\s+/', '', $body)) : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body);
        $name = $dparams['filename'] ?? $params['name'] ?? '';
        if ($disp === 'attachment' || ($name !== '' && !in_array($type, ['text/plain', 'text/html'], true))) {
            if (count($out['attachments']) < 10) $out['attachments'][] = ['name' => self::decodeHeader($name) ?: 'file', 'type' => $type, 'data' => $data];
            return;
        }
        if ($type === 'text/plain' && $out['plain'] === null) $out['plain'] = self::utf8($data, $params['charset'] ?? '');
        elseif ($type === 'text/html' && $out['html'] === null) $out['html'] = self::utf8($data, $params['charset'] ?? '');
        elseif ($type === 'message/rfc822' && $out['plain'] === null && $out['html'] === null) { [$ih, $ib] = self::split($data); self::walk($ih, $ib, $out, $depth + 1); }
    }

    /** "text/plain; charset=utf-8; name*=UTF-8''f%C3%A9.jpg" → ['text/plain', ['charset' => 'utf-8', 'name' => 'fé.jpg']]. */
    private static function headerParams(string $v): array {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v);
        $main = strtolower(trim(array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            if (!str_contains($p, '=')) continue;
            [$k, $val] = explode('=', $p, 2);
            $k = strtolower(trim($k)); $val = trim(trim($val), '"');
            if (preg_match('/^(\w+)\*(\d+)?\*?$/', $k, $mm)) {   // RFC 2231: name*=utf-8''... and name*0*=...
                $k = $mm[1];
                if (preg_match("/^([\w-]*)'[^']*'(.*)$/", $val, $vv)) $val = self::utf8(rawurldecode($vv[2]), $vv[1]);
                elseif (str_ends_with(trim($p), '*') || str_contains($p, '*=')) $val = rawurldecode($val);
                $params[$k] = isset($mm[2]) && $mm[2] !== '0' ? ($params[$k] ?? '') . $val : $val;
            } else $params[$k] = $val;
        }
        return [$main, $params];
    }

    public static function decodeHeader(string $v): string {
        if (!str_contains($v, '=?')) return trim($v);
        $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        return trim($d !== false ? $d : mb_decode_mimeheader($v));
    }

    /** "Anna de Vries <anna@x.nl>" → ['Anna de Vries', 'anna@x.nl']. */
    public static function address(string $v): array {
        $v = self::decodeHeader($v);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $v, $m)) return [trim($m[1]) ?: null, strtolower(trim($m[2]))];
        if (preg_match('/[^\s<>,;"]+@[^\s<>,;"]+/', $v, $m)) return [null, strtolower($m[0])];
        return [null, null];
    }

    private static function utf8(string $s, string $charset): string {
        $cs = strtoupper(trim($charset)) ?: 'UTF-8';
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') { $c = @mb_convert_encoding($s, 'UTF-8', $cs === 'WINDOWS-1252' ? 'CP1252' : $cs); if (is_string($c)) $s = $c; }
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    public static function htmlToText(string $h): string {
        $h = preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $h);
        $h = preg_replace('#<blockquote\b.*?</blockquote>#is', "\n", $h);   // quoted history in HTML mail
        $h = preg_replace('#<div[^>]+class="?(gmail_quote|gmail_extra|moz-cite-prefix|yahoo_quoted)[\s\S]*$#i', '', $h);
        $h = preg_replace('#<br\s*/?>#i', "\n", $h);
        $h = preg_replace('#</(p|div|tr|h\d|li)>#i', "\n", $h);
        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t\x{00A0}]+/u', ' ', $t)));
    }

    /** Cuts the quoted conversation under a reply ("On … wrote:", "Op … schreef:", "> …", Outlook's "From:" block, signatures after "-- "). */
    public static function stripQuote(string $t): string {
        $lines = explode("\n", str_replace("\r", '', $t));
        $keep = [];
        foreach ($lines as $i => $l) {
            $s = trim($l);
            if ($s === '--' || preg_match('/^-{2,}\s*(original message|oorspronkelijk bericht|исходное сообщение|forwarded message)/iu', $s)) break;
            if (preg_match('/^(on|op|am|le|el)\s.+\b(wrote|schreef|schrieb|a écrit|escribió)\b.*:\s*$/iu', $s) || preg_match('/(wrote|schreef|пишет|написал\(а\)|написал):\s*$/u', $s)) break;
            if (str_ends_with($s, ':') && isset($lines[$i + 1]) && str_starts_with(ltrim($lines[$i + 1]), '>')) break;   // "вт, 13 окт. 2026 г. в 10:00, Drop 01 <…>:" then the quote
            if (preg_match('/^(from|van|von|от):\s/iu', $s) && isset($lines[$i + 1]) && preg_match('/^(sent|date|verzonden|datum|отправлено|дата|to|aan):/iu', trim($lines[$i + 1]))) break;
            if (str_starts_with($s, '>')) continue;
            if (preg_match('/^sent from my (iphone|ipad|android)|^verzonden vanaf mijn/i', $s)) continue;
            $keep[] = rtrim($l);
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $keep)));
    }

    // ------------------------------------------------------------------ IMAP

    /** Reads mail newer than $state['last'] (UID) from INBOX with BODY.PEEK, so the mailbox looks untouched in the owner's mail app. */
    private static function imapFetch(array $cfg, array $state): array {
        $c = new ImapConn($cfg);
        try {
            $sel = $c->cmd('SELECT INBOX');
            $validity = preg_match('/UIDVALIDITY (\d+)/', $sel, $m) ? (int) $m[1] : 0;
            $next = preg_match('/UIDNEXT (\d+)/', $sel, $m) ? (int) $m[1] : 0;
            if (!$next) { preg_match_all('/\* SEARCH([\d ]*)/', $c->cmd('UID SEARCH ALL'), $mm); $all = array_filter(array_map('intval', preg_split('/\s+/', trim(implode(' ', $mm[1] ?? []))))); $next = ($all ? max($all) : 0) + 1; }
            // first run, or the server renumbered the mailbox: start from now instead of importing years of mail
            if (!isset($state['last']) || (int) ($state['validity'] ?? 0) !== $validity) return [['validity' => $validity, 'last' => $next - 1], []];
            $last = (int) $state['last'];
            if ($next <= $last + 1) return [['validity' => $validity, 'last' => $last], []];
            preg_match_all('/UID (\d+)[^)]*RFC822\.SIZE (\d+)|RFC822\.SIZE (\d+)[^)]*UID (\d+)/', $c->cmd('UID FETCH ' . ($last + 1) . ':* (UID RFC822.SIZE)'), $mm, PREG_SET_ORDER);
            $list = [];
            foreach ($mm as $x) { $uid = (int) ($x[1] ?: $x[4]); $size = (int) ($x[2] ?: $x[3]); if ($uid > $last) $list[$uid] = $size; }
            ksort($list);
            $raws = [];
            foreach (array_slice($list, 0, self::MAX_PER_RUN, true) as $uid => $size) {
                if ($size <= self::MAX_BYTES) { $lit = $c->literals('UID FETCH ' . $uid . ' (BODY.PEEK[])'); if ($lit) $raws[] = $lit[0]; }
                $last = $uid;
            }
            return [['validity' => $validity, 'last' => $last], $raws];
        } finally { $c->close(); }
    }

    /** "Check now" in Settings: logs in and reports the mailbox, or the reason it fails. */
    public static function test(): string {
        $cfg = self::cfg();
        if ($cfg['host'] === '' || $cfg['user'] === '') throw new RuntimeException('Fill in the incoming mail server and save first.');
        if (self::$fetcher) { (self::$fetcher)($cfg, []); return 'Connected.'; }
        $c = new ImapConn($cfg);
        try { $sel = $c->cmd('SELECT INBOX'); } finally { $c->close(); }
        return 'Connected. ' . (preg_match('/\* (\d+) EXISTS/', $sel, $m) ? $m[1] . ' emails in the inbox. ' : '') . 'New emails show up in the Inbox from now on.';
    }
}

/** One IMAP session over a socket (ssl:// on 993, STARTTLS or plain on 143). */
final class ImapConn {
    private $s;
    private int $n = 0;

    public function __construct(array $cfg) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $cfg['host']]]);
        $this->s = @stream_socket_client(($cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'], $errno, $err, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) throw new RuntimeException("Can't reach {$cfg['host']}:{$cfg['port']} (" . ($err ?: ($cfg['secure'] === 'ssl' ? 'the secure connection failed — check the port, or try STARTTLS on 143' : 'no answer')) . '). Check the server name and port.');
        stream_set_timeout($this->s, 20);
        $hello = (string) fgets($this->s);
        if (!str_starts_with($hello, '* OK') && !str_starts_with($hello, '* PREAUTH')) throw new RuntimeException('This is not an IMAP server: ' . trim($hello));
        if ($cfg['secure'] === 'tls') {
            $this->cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('Could not switch to TLS. Try port 993 with SSL.');
        }
        try { $this->cmd('LOGIN ' . self::q($cfg['user']) . ' ' . self::q($cfg['pass'])); }
        catch (RuntimeException $e) { throw new RuntimeException('The mail server refused the login. Check the login and password (' . $e->getMessage() . ').'); }
    }

    private static function q(string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; }

    /** Sends a command and returns the untagged response text; throws on NO/BAD. */
    public function cmd(string $c): string { return $this->run($c)[0]; }

    /** Sends a command and returns the literal strings in its response (the message bodies of a FETCH). */
    public function literals(string $c): array { return $this->run($c)[1]; }

    private function run(string $c): array {
        $tag = 'D' . (++$this->n);
        fwrite($this->s, "$tag $c\r\n");
        $text = ''; $lits = [];
        while (true) {
            $line = fgets($this->s);
            if ($line === false) throw new RuntimeException('The mail server stopped answering.');
            while (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {   // a literal: exactly N bytes follow, then the rest of the line
                $lit = ''; $want = (int) $m[1];
                while (strlen($lit) < $want) { $chunk = fread($this->s, min(65536, $want - strlen($lit))); if ($chunk === false || $chunk === '') throw new RuntimeException('The mail server stopped answering.'); $lit .= $chunk; }
                $lits[] = $lit;
                $more = fgets($this->s);
                if ($more === false) break;
                $line = $more;
            }
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) throw new RuntimeException(trim(substr($line, strlen($tag) + 1)));
                return [$text, $lits];
            }
            $text .= $line;
        }
    }

    public function close(): void {
        if (!$this->s) return;
        try { @fwrite($this->s, 'D' . (++$this->n) . " LOGOUT\r\n"); } catch (Throwable $e) {}
        @fclose($this->s);
        $this->s = null;
    }
}

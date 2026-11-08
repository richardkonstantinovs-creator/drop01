<?php
// Email broadcasts to a segment. Only people who said yes to news get them, each email has an unsubscribe link,
// one email per address, and cron sends them in hourly batches (Messages::flushQueue).
defined('DROP01') || exit;

final class Broadcasts {
    public const SEGMENTS = [
        'everyone'  => ['Everyone who said yes', 'Customers with consent, the waitlist and the next-drop list'],
        'customers' => ['All customers', 'People who ordered and ticked “tell me about the next drop”'],
        'regular'   => ['Regulars', 'Customers with 3+ orders'],
        'new'       => ['New customers', 'First order in the last 30 days'],
        'inactive'  => ['Inactive 30d+', 'Ordered before, nothing in the last 30 days'],
        'waitlist'  => ['Waitlist', 'Signed up before the drop and did not unsubscribe'],
        'list'      => ['Next-drop list', 'Left their email in the shop for the next drop'],
    ];

    /** Recipients of a segment: [email => first name or ''], consent only, unsubscribed people never included. */
    public static function recipients(string $segment): array {
        $out = [];
        $add = function (?string $email, ?string $name) use (&$out) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            if (!isset($out[$email]) || $out[$email] === '') $out[$email] = trim(explode(' ', trim((string) $name))[0] ?? '');
        };
        if (in_array($segment, ['everyone', 'customers', 'regular', 'new', 'inactive'], true)) {
            $rows = DB::all("SELECT c.email, c.name, COUNT(o.id) AS orders, MIN(o.created_at) AS first_order, MAX(o.created_at) AS last_order FROM customers c
                LEFT JOIN orders o ON o.customer_id = c.id AND o.payment_status IN ('paid','partially_refunded') WHERE c.marketing_consent = 1 AND c.email IS NOT NULL GROUP BY c.id, c.email, c.name");
            $d30 = DB::now(-30 * 86400);
            foreach ($rows as $r) {
                $n = (int) $r['orders'];
                $ok = match ($segment) {
                    'regular' => $n >= 3, 'new' => $n >= 1 && $n < 3 && $r['first_order'] >= $d30, 'inactive' => $n >= 1 && $r['last_order'] < $d30, default => true,
                };
                if ($ok) $add($r['email'], $r['name']);
            }
        }
        if ($segment === 'everyone' || $segment === 'waitlist')
            foreach (DB::all('SELECT email FROM waitlist_signups WHERE consent = 1 AND unsubscribed_at IS NULL') as $r) $add($r['email'], null);
        if ($segment === 'everyone' || $segment === 'list')
            foreach (DB::all('SELECT email FROM list_signups') as $r) $add($r['email'], null);
        return $out;
    }

    public static function counts(): array {
        $c = [];
        foreach (array_keys(self::SEGMENTS) as $s) $c[$s] = count(self::recipients($s));
        return $c;
    }

    /** Checks and cleans the form. Throws RuntimeException with a message for the owner. */
    public static function clean(array $in): array {
        $b = ['segment' => (string) ($in['segment'] ?? ''), 'subject' => trim((string) ($in['subject'] ?? '')), 'heading' => trim((string) ($in['heading'] ?? '')),
            'body' => trim((string) ($in['body'] ?? '')), 'button_label' => trim((string) ($in['button_label'] ?? '')), 'button_url' => trim((string) ($in['button_url'] ?? ''))];
        if (!isset(self::SEGMENTS[$b['segment']])) throw new RuntimeException('Pick who gets it.');
        if ($b['subject'] === '' || mb_strlen($b['subject']) > 200) throw new RuntimeException('Write a subject (up to 200 characters).');
        if ($b['body'] === '' || mb_strlen($b['body']) > 5000) throw new RuntimeException('Write the message (up to 5000 characters).');
        if (mb_strlen($b['heading']) > 200) throw new RuntimeException('The heading is too long.');
        if ($b['button_url'] !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $b['button_url'])) throw new RuntimeException('The button link must start with https://');
        if (($b['button_url'] === '') !== ($b['button_label'] === '')) throw new RuntimeException('A button needs both a label and a link (or leave both empty).');
        $b['button_label'] = mb_substr($b['button_label'], 0, 60);
        return $b;
    }

    private static function vars(array $b, string $first, string $email): array {
        return $b + ['first_name' => $first, 'unsubscribe' => Messages::unsubscribeEmailUrl($email)];
    }

    /** Sends one copy to the admin straight away (marked [Test], not logged as a broadcast). */
    public static function test(array $b, string $to): array {
        [$subject, $html, $text] = EmailTemplates::render('broadcast', self::vars($b, '', $to));
        return Mailer::send($to, '[Test] ' . $subject, $html, $text);
    }

    /** Queues the broadcast for every recipient. Returns [broadcast id, recipients]. */
    public static function send(array $b, int $adminId): array {
        $to = self::recipients($b['segment']);
        if (!$to) throw new RuntimeException('Nobody in this group has said yes to emails yet.');
        $id = DB::tx(function () use ($b, $to, $adminId) {
            $id = DB::insert('broadcasts', $b + ['recipients' => count($to), 'by_admin_id' => $adminId, 'created_at' => DB::now()]);
            foreach ($to as $email => $first) {
                $cid = DB::val('SELECT id FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]);
                Messages::queueEmail('broadcast', $email, self::vars($b, $first, $email), ['broadcast_id' => $id, 'customer_id' => $cid ? (int) $cid : null]);
            }
            return $id;
        });
        Messages::flushLater();
        return [$id, count($to)];
    }

    /** History with delivery counts. */
    public static function history(): array {
        $rows = DB::all('SELECT b.*, a.name AS by_name FROM broadcasts b LEFT JOIN admins a ON a.id = b.by_admin_id ORDER BY b.id DESC LIMIT 30');
        foreach ($rows as &$r) {
            $st = [];
            foreach (DB::all('SELECT status, COUNT(*) AS n FROM messages WHERE broadcast_id = ? GROUP BY status', [$r['id']]) as $s) $st[$s['status']] = (int) $s['n'];
            $r['sent'] = $st['sent'] ?? 0; $r['failed'] = ($st['failed'] ?? 0) + ($st['not_sent'] ?? 0);
            $r['waiting'] = ($st['queued'] ?? 0) + ($st['retry'] ?? 0); $r['cancelled'] = $st['cancelled'] ?? 0;
        }
        return $rows;
    }
}

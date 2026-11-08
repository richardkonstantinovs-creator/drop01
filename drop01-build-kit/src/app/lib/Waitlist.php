<?php
// Waitlist domain logic: validation, de-duplication, positions, server-issued gift codes with a limit, and public counters.
defined('DROP01') || exit;

final class Waitlist {
    /** Unambiguous characters only (no 0/O, 1/I/L). */
    public const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function normEmail(string $e): ?string {
        $e = strtolower(trim($e));
        return (strlen($e) <= 191 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
    }

    /** Normalises to +<digits>, 9–15 digits in total. Dutch "06…" with +31 becomes +316…. */
    public static function normPhone(string $p, string $cc = '+31'): ?string {
        $p = trim($p);
        if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
        if (str_starts_with($p, '+')) $full = '+' . preg_replace('/\D/', '', $p);
        else $full = '+' . preg_replace('/\D/', '', $cc) . ltrim(preg_replace('/\D/', '', $p), '0');
        $d = strlen($full) - 1;
        return ($d >= 9 && $d <= 15) ? $full : null;
    }

    public static function newCode(string $prefix): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
        return strtoupper($prefix) . '-' . $s;
    }

    public static function total(): int { return (int) DB::val('SELECT COUNT(*) FROM waitlist_signups'); }
    public static function giftsIssued(): int { return (int) DB::val('SELECT COUNT(*) FROM waitlist_signups WHERE gift_code IS NOT NULL'); }

    /** Gift spots left, or null when there is no limit. */
    public static function giftsLeft(): ?int {
        $lim = Settings::get('gift')['limit'] ?? null;
        return $lim ? max(0, (int) $lim - self::giftsIssued()) : null;
    }

    /**
     * Creates a signup. Returns ['ok'=>true, 'code'=>?string, 'position'=>int, 'existing'=>bool, 'id'=>int]
     * or ['ok'=>false, 'error'=>string, 'status'=>int].
     */
    public static function signup(array $in): array {
        $email = self::normEmail((string) ($in['email'] ?? ''));
        $phone = self::normPhone((string) ($in['phone'] ?? ''), (string) ($in['cc'] ?? '+31'));
        if (!$email) return ['ok' => false, 'error' => t('wl.err_email'), 'field' => 'email', 'status' => 422];
        if (!$phone) return ['ok' => false, 'error' => t('wl.err_phone'), 'field' => 'phone', 'status' => 422];

        return DB::tx(function () use ($in, $email, $phone) {
            $hit = DB::one('SELECT * FROM waitlist_signups WHERE email = ? OR phone = ? ORDER BY id LIMIT 1', [$email, $phone]);
            if ($hit) {
                // Only hand back the code when both email and phone match, so nobody can fish for someone else's code.
                if ($hit['email'] === $email && $hit['phone'] === $phone) {
                    return ['ok' => true, 'existing' => true, 'id' => (int) $hit['id'], 'code' => $hit['gift_code'], 'position' => (int) $hit['position'], 'email' => $email];
                }
                return ['ok' => false, 'error' => t($hit['email'] === $email ? 'err.already_email' : 'err.already_phone'), 'field' => $hit['email'] === $email ? 'email' : 'phone', 'status' => 409];
            }
            $gift = Settings::get('gift');
            $limit = (int) ($gift['limit'] ?? 0);
            $code = null;
            if (!$limit || self::giftsIssued() < $limit) {
                for ($i = 0; $i < 8 && !$code; $i++) {
                    $c = self::newCode($gift['prefix'] ?: 'D01');
                    if (!DB::val('SELECT 1 FROM waitlist_signups WHERE gift_code = ?', [$c])) $code = $c;
                }
            }
            $pos = (int) DB::val('SELECT COALESCE(MAX(position), 0) FROM waitlist_signups') + 1;
            $utm = array_filter(array_map(fn($v) => substr((string) $v, 0, 80), array_intersect_key((array) ($in['utm'] ?? []), array_flip(['source', 'medium', 'campaign', 'content']))));
            $ref = substr((string) ($in['ref'] ?? ''), 0, 255) ?: null;
            $likes = array_values(array_filter(array_map('strval', (array) ($in['likes'] ?? [])), fn($s) => preg_match('/^[a-z0-9-]{1,120}$/', $s)));
            $id = DB::insert('waitlist_signups', [
                'email' => $email, 'phone' => $phone, 'country' => self::countryOf((string) ($in['cc'] ?? ''), (string) ($in['region'] ?? '')),
                'gift_code' => $code, 'position' => $pos,
                'source' => Analytics::source($utm['source'] ?? null, $ref ? parse_url($ref, PHP_URL_HOST) : null),
                'device' => Analytics::device(Http::ua()), 'utm' => $utm ? json_encode($utm) : null, 'referrer' => $ref,
                'likes' => $likes ? json_encode(array_slice($likes, 0, 50)) : null,
                'consent' => !empty($in['consent']) ? 1 : 0, 'consent_text' => substr((string) ($in['consent_text'] ?? ''), 0, 255) ?: null,
                'created_at' => DB::now(),
            ]);
            return ['ok' => true, 'existing' => false, 'id' => $id, 'code' => $code, 'position' => $pos, 'email' => $email];
        });
    }

    /** Country of the signup: the chosen calling code's country (e.g. "NL" for +31), using the visitor's region to split shared codes like +1/+7. */
    private static function countryOf(string $cc, string $region): ?string {
        $matches = array_values(array_filter(Settings::get('countries'), fn($c) => $c[1] === $cc));
        if (!$matches) return null;
        foreach ($matches as $m) if ($m[0] === strtoupper($region)) return $m[0];
        return $matches[0][0];
    }

    /** The waitlist signup whose gift a buyer gets: matching email or phone, holding a code that is not yet redeemed. */
    public static function giftFor(?string $email, ?string $phone): ?array {
        if (!$email && !$phone) return null;
        return DB::one('SELECT * FROM waitlist_signups WHERE gift_code IS NOT NULL AND gift_redeemed_order_id IS NULL AND (email = ? OR phone = ?) ORDER BY id LIMIT 1', [(string) $email, (string) $phone]);
    }

    /** Public numbers for the waitlist page. The "already in line" count stays hidden below the configured minimum. */
    public static function publicCounters(): array {
        $total = self::total();
        return ['count' => $total >= (int) Settings::get('proof_min') ? $total : null, 'gifts_left' => self::giftsLeft()];
    }

    /** "+31612345678" → "+31 6 1234 5678" for NL, "+<cc> <rest>" elsewhere (also keeps spreadsheets from reading it as a number). */
    public static function prettyPhone(string $p): string {
        $codes = array_unique(array_column(Settings::get('countries'), 1));
        usort($codes, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($codes as $cc) if (str_starts_with($p, $cc)) {
            $rest = substr($p, strlen($cc));
            if ($cc === '+31' && strlen($rest) === 9) return "+31 {$rest[0]} " . substr($rest, 1, 4) . ' ' . substr($rest, 5);
            return $cc . ' ' . $rest;
        }
        return $p;
    }

    public static function maskEmail(string $e): string {
        [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
        return mb_substr($u, 0, 2) . '•••' . mb_substr($u, -1) . '@' . $d;
    }
}

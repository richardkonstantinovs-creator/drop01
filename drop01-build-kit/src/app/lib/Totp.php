<?php
// RFC 6238 TOTP (6 digits, 30 s) for optional owner 2FA, with base32 helpers.
defined('DROP01') || exit;

final class Totp {
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string {
        $s = '';
        foreach (str_split(random_bytes(20)) as $c) $s .= self::B32[ord($c) & 31];
        return $s;
    }

    private static function b32decode(string $s): string {
        $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
        return $out;
    }

    public static function code(string $secret, ?int $t = null): string {
        $ctr = pack('N*', 0, intdiv($t ?? time(), 30));
        $h = hash_hmac('sha1', $ctr, self::b32decode($secret), true);
        $o = ord($h[19]) & 15;
        $n = ((ord($h[$o]) & 127) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
        return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and one step either side for clock drift. */
    public static function verify(string $secret, string $code): bool {
        $code = preg_replace('/\D/', '', $code);
        if ($secret === '' || strlen($code) !== 6) return false;
        foreach ([-30, 0, 30] as $d) if (hash_equals(self::code($secret, time() + $d), $code)) return true;
        return false;
    }

    public static function uri(string $secret, string $email, string $issuer): string {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }
}

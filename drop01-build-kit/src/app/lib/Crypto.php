<?php
// Symmetric encryption for stored secrets (AES-256-GCM with the app key) and random tokens.
defined('DROP01') || exit;

final class Crypto {
    private static function key(): string { return hash('sha256', (string) config('app_key'), true); }

    public static function encrypt(string $plain): string {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $enc): ?string {
        if (!str_starts_with($enc, 'v1:')) return null;
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }

    public static function token(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }

    /** Privacy-preserving hash of the client IP, used for rate limits and lockouts (never stored raw). */
    public static function ipHash(): string { return hash_hmac('sha256', Http::ip(), (string) config('app_key')); }
}

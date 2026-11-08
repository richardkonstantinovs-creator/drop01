<?php
// HTTP helpers: JSON responses, request body parsing, client info, security headers and the CSP nonce.
defined('DROP01') || exit;

final class Http {
    private static ?string $nonce = null;
    private static ?array $body = null;

    public static function nonce(): string { return self::$nonce ??= base64_encode(random_bytes(16)); }

    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public static function path(): string { return '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/'); }

    /** JSON body (or form fields as a fallback). sendBeacon posts text/plain JSON, so the type is not checked. */
    public static function body(): array {
        if (self::$body !== null) return self::$body;
        $raw = file_get_contents('php://input', false, null, 0, 65536) ?: '';
        $j = json_decode($raw, true);
        return self::$body = is_array($j) ? $j : $_POST;
    }

    public static function json($data, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $msg, int $code = 400, array $extra = []): never { self::json(['error' => $msg] + $extra, $code); }

    public static function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
    public static function ua(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400); }

    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** The site's own address for links in emails and pages: Settings → Store, so cron (no request) and forged Host headers get it right. */
    public static function baseUrl(): string {
        $site = is_installed() ? (string) Settings::get('site_url', '') : '';
        if ($site !== '') return rtrim($site, '/');
        return self::requestBase();
    }

    /** The address this request came in on. Only for filling in site_url the first time. */
    public static function requestBase(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) $host = 'localhost';
        return (self::isHttps() ? 'https' : 'http') . '://' . $host;
    }

    /** Headers sent with every PHP response. Apache's .htaccess adds the same set for static files. */
    public static function securityHeaders(bool $allowMollie = false): void {
        $n = self::nonce();
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(self)');
        header('X-Frame-Options: SAMEORIGIN');
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000');
        $form = $allowMollie ? " https://www.mollie.com" : '';
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$n'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://service.pdok.nl; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'$form; base-uri 'self'; object-src 'none'");
    }

    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

<?php
// Server requirement checks with fix-it instructions written for a non-technical owner (cPanel menu names).
defined('DROP01') || exit;

final class Requirements {
    public const EXTENSIONS = ['pdo_sqlite', 'sqlite3', 'curl', 'gd', 'mbstring', 'openssl', 'json', 'fileinfo', 'zip'];

    /** [['name','ok','fix'], ...] */
    public static function check(): array {
        $out = [];
        $out[] = ['PHP 8.1 or newer (you have ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>='),
            'Open cPanel → Select PHP Version (or MultiPHP Manager) → choose PHP 8.2 → Set as current.'];
        foreach (self::EXTENSIONS as $ext) {
            $out[] = ["PHP extension $ext", extension_loaded($ext),
                "Open cPanel → Select PHP Version → Extensions → tick “{$ext}”. It saves by itself. Then reload this page."];
        }
        $out[] = ['GD can make WebP images', function_exists('imagewebp'), 'Not required: photos will be saved as JPEG instead. To get WebP, ask GoDaddy support to enable WebP in GD.', true];
        $data = is_installed() ? data_dir() : ROOT . '/data';
        $out[] = ['The data folder can be written', self::writable($data), 'Open cPanel → File Manager → public_html → select the “data” folder (create it if missing) → Permissions → set to 755.'];
        $out[] = ['The uploads folder can be written', self::writable(ROOT . '/uploads'), 'Open cPanel → File Manager → public_html → select “uploads” → Permissions → set to 755.'];
        $out[] = ['HTTPS is on', Http::isHttps(), 'Open cPanel → SSL/TLS Status → Run AutoSSL. Wait until your domain shows a green lock, then open this page with https://.', true];
        return array_map(fn($r) => ['name' => $r[0], 'ok' => (bool) $r[1], 'fix' => $r[2], 'optional' => $r[3] ?? false], $out);
    }

    public static function allRequiredOk(): bool {
        foreach (self::check() as $c) if (!$c['ok'] && !$c['optional']) return false;
        return true;
    }

    private static function writable(string $dir): bool {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir)) return false;
        $f = $dir . '/.w' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($f, '1') !== false;
        @unlink($f);
        return $ok;
    }
}

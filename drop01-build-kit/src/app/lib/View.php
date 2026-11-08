<?php
// Renders PHP templates from app/views with escaped data, and builds versioned asset URLs.
defined('DROP01') || exit;

final class View {
    public static function render(string $name, array $vars = []): void {
        header('Content-Type: text/html; charset=utf-8');
        extract($vars, EXTR_SKIP);
        require APP . '/views/' . $name . '.php';
    }

    /** /assets/x.css?v=<mtime> so files can be cached for a year and still refresh after an update. */
    public static function asset(string $path): string {
        $f = ROOT . '/assets/' . ltrim($path, '/');
        return '/assets/' . ltrim($path, '/') . '?v=' . (is_file($f) ? base_convert((string) filemtime($f), 10, 36) : APP_VERSION);
    }

    /** JSON safe to place inside a <script> element. */
    public static function json($v): string {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

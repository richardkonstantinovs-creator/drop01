<?php
// Minimal file logger writing to data/logs/app-YYYY-MM.log.
defined('DROP01') || exit;

final class Log {
    public static function error(string $msg): void { self::write('ERROR', $msg); }
    public static function info(string $msg): void { self::write('INFO', $msg); }

    private static function write(string $lvl, string $msg): void {
        $dir = (function_exists('data_dir') && is_installed()) ? data_dir() . '/logs' : ROOT . '/data/logs';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', gmdate('c') . " [$lvl] $msg\n", FILE_APPEND | LOCK_EX);
    }
}

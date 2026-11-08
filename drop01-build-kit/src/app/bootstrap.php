<?php
// Bootstrap: defines paths, loads config, autoloads app classes, sets timezone and error handling.
if (!defined('DROP01')) define('DROP01', 1);

define('ROOT', dirname(__DIR__));          // = public_html
define('APP', __DIR__);
define('CONFIG_FILE', ROOT . '/data/config.php');

date_default_timezone_set('UTC');           // storage is UTC; display converts to Europe/Amsterdam
const TZ_LOCAL = 'Europe/Amsterdam';

spl_autoload_register(function ($class) {
    $f = APP . '/lib/' . $class . '.php';
    if (is_file($f)) { require $f; return; }
    $f = APP . '/controllers/' . $class . '.php';
    if (is_file($f)) require $f;
});

require APP . '/version.php';

/** Returns the config array, or null when the app has not been installed yet. */
function config(?string $key = null, $default = null) {
    if (!array_key_exists('__drop01_cfg', $GLOBALS)) $GLOBALS['__drop01_cfg'] = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : null;
    $cfg = $GLOBALS['__drop01_cfg'];
    if ($key === null) return $cfg;
    return $cfg[$key] ?? $default;
}

/** Forgets the cached config (the installer calls this right after writing it). */
function config_reload(): void { unset($GLOBALS['__drop01_cfg']); }

/** Directory with the SQLite file, backups, logs and private files (may live outside public_html). */
function data_dir(): string {
    return rtrim(config('data_dir', ROOT . '/data'), '/');
}

function is_installed(): bool { return is_array(config()); }

/** HTML-escape shorthand used by the templates. */
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** A customer-facing text (Admin → Texts) as plain text, {words} filled in. */
function t(string $key, array $vars = []): string { return Texts::plain($key, $vars); }

/** The same text as safe HTML (**bold**, [links](/x)); vars named in $raw are already HTML. */
function th(string $key, array $vars = [], array $raw = [], string $linkAttr = ''): string { return Texts::html($key, $vars, $raw, $linkAttr); }

/** Euro amount in Dutch format: 3495 → "€34,95". */
function eur(int $cents): string { return '€' . number_format($cents / 100, 2, ',', '.'); }

// Log errors to data/logs, never show them to visitors.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) {
    Log::error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'Something went wrong on our side. Please try again.']);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="background:#09090B;color:#F3F1EC;font-family:system-ui;padding:40px">Something went wrong. Please try again in a minute.</body>';
    }
});

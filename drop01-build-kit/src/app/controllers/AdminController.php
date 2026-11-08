<?php
// Serves the admin single-page app shell at /admin. The shell is the same for everyone; the JS shows login or the app.
defined('DROP01') || exit;

final class AdminController {
    public static function shell(): void {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        Auth::start();
        View::render('admin', ['csrf' => Auth::user() ? Auth::csrf() : '']);
    }
}

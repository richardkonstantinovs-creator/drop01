<?php
// Admin authentication: secure PHP sessions (end on a password change, after 12 h idle and after 7 days), password login with lockout,
// roles, a fresh password for risky actions, CSRF tokens and the audit log.
defined('DROP01') || exit;

final class Auth {
    public const ROLES = ['owner', 'manager', 'warehouse', 'courier', 'accountant'];
    /** Which admin areas each role may use. */
    public const ACCESS = [
        'owner'      => ['*'],
        'manager'    => ['today', 'orders', 'customers', 'stock', 'delivery', 'marketing', 'waitlist', 'inbox'],
        'warehouse'  => ['orders', 'stock'],
        'courier'    => ['delivery'],
        'accountant' => ['money', 'exports'],
    ];
    private const MAX_FAILS = 10, LOCK_MIN = 15;
    private static ?array $me = null;
    private const IDLE = 12 * 3600, MAX_AGE = 7 * 86400, RECENT = 15 * 60;

    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('d01admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        $dir = data_dir() . '/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) session_save_path($dir);
        session_start();
        if (!empty($_SESSION['aid']) && ($_SESSION['seen'] ?? 0) < time() - self::IDLE) { self::logout(); return; }
        $_SESSION['seen'] = time();
    }

    public static function user(): ?array {
        self::start();
        if (empty($_SESSION['aid'])) return null;
        if (self::$me) return self::$me;
        $a = DB::one('SELECT id, name, email, role, totp_secret, pass_hash FROM admins WHERE id = ? AND active = 1', [$_SESSION['aid']]);
        // a new password (from the person or the owner) ends every other session of that person
        if (!$a || !hash_equals(self::passPrint($a['pass_hash']), (string) ($_SESSION['pv'] ?? ''))
            || ($_SESSION['born'] ?? 0) < time() - self::MAX_AGE) { self::logout(); return null; }
        unset($a['pass_hash']);
        return self::$me = $a;
    }

    /** Forgets the person cached for this request (after logout; tests switch sessions with it). */
    public static function forget(): void { self::$me = null; }

    /** A short fingerprint of the password hash, kept in the session to notice password changes. */
    private static function passPrint(string $hash): string { return substr(hash_hmac('sha256', $hash, (string) config('app_key')), 0, 32); }

    /** After the person changed their own password: keep this session, all others end. */
    public static function keepSession(string $newHash): void { $_SESSION['pv'] = self::passPrint($newHash); $_SESSION['sudo_at'] = time(); }

    /** Risky actions (full backup, CSV of all contacts, team changes) need the password typed in the last 15 minutes. */
    public static function requireRecent(): void {
        if (($_SESSION['sudo_at'] ?? 0) < time() - self::RECENT) Http::error('Enter your password again to continue.', 403, ['reauth' => true]);
    }

    /** Checks the password again for requireRecent(). Wrong tries count towards the login lockout. */
    public static function reauth(string $pass): bool {
        $u = self::require();
        if (self::locked($u['email'])) Http::error('Too many attempts. Wait 15 minutes and try again.', 429);
        $ok = password_verify($pass, (string) DB::val('SELECT pass_hash FROM admins WHERE id = ?', [$u['id']]));
        DB::insert('login_attempts', ['email' => $u['email'], 'ip_hash' => Crypto::ipHash(), 'ok' => $ok ? 1 : 0, 'created_at' => DB::now()]);
        if ($ok) { $_SESSION['sudo_at'] = time(); self::audit('reauth', 'admin', $u['id']); }
        return $ok;
    }

    public static function can(string $area, ?array $u = null): bool {
        $u = $u ?? self::user();
        if (!$u) return false;
        $a = self::ACCESS[$u['role']] ?? [];
        return in_array('*', $a, true) || in_array($area, $a, true);
    }

    public static function require(string $area = ''): array {
        $u = self::user();
        if (!$u) Http::error('Please log in again.', 401);
        if ($area && !self::can($area, $u)) Http::error('Your role does not have access to this.', 403);
        return $u;
    }

    /** Lockout: 10 failed attempts within 15 minutes, per account and per IP hash. */
    public static function locked(string $email): bool {
        $since = DB::now(-self::LOCK_MIN * 60);
        $byEmail = (int) DB::val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ok = 0 AND created_at > ?', [$email, $since]);
        $byIp = (int) DB::val('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND ok = 0 AND created_at > ?', [Crypto::ipHash(), $since]);
        return $byEmail >= self::MAX_FAILS || $byIp >= self::MAX_FAILS;
    }

    /** Returns [ok, message]. */
    public static function login(string $email, string $pass, string $totp = ''): array {
        $email = strtolower(trim($email));
        if (self::locked($email)) return [false, 'Too many attempts. Wait 15 minutes and try again.'];
        $a = DB::one('SELECT * FROM admins WHERE email = ? AND active = 1', [$email]);
        // an unknown email takes as long as a wrong password, so timing doesn't tell which emails have an account
        $ok = password_verify($pass, $a['pass_hash'] ?? self::dummyHash()) && $a;
        if ($ok && $a['totp_secret'] && !Totp::verify(Crypto::decrypt($a['totp_secret']) ?? '', $totp)) {
            if ($totp === '') return [false, 'totp'];
            $ok = false;
        }
        DB::insert('login_attempts', ['email' => $email, 'ip_hash' => Crypto::ipHash(), 'ok' => $ok ? 1 : 0, 'created_at' => DB::now()]);
        if (!$ok) return [false, 'Wrong email or password.'];
        self::start();
        session_regenerate_id(true);
        $hash = $a['pass_hash'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) DB::update('admins', ['pass_hash' => $hash = password_hash($pass, PASSWORD_DEFAULT)], 'id = ?', [$a['id']]);
        $_SESSION = ['aid' => (int) $a['id'], 'csrf' => Crypto::token(), 'seen' => time(), 'born' => time(), 'sudo_at' => time(), 'pv' => self::passPrint($hash)];
        DB::update('admins', ['last_login_at' => DB::now()], 'id = ?', [$a['id']]);
        self::audit('login', 'admin', $a['id']);
        return [true, ''];
    }

    /** A hash of a random password nobody knows, checked when the email has no account. */
    private static function dummyHash(): string { return '$2y$10$kEP3S/MYZrglry19ehCKHeWo1Lm9Hgd.SSpwVlbFqqG.1YFEILHT2'; }

    public static function logout(): void {
        self::forget();
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    // ---- CSRF: a per-session token sent in the X-CSRF header by the admin app

    public static function csrf(): string {
        self::start();
        return $_SESSION['csrf'] ??= Crypto::token();
    }

    public static function checkCsrf(): void {
        $sent = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($sent) || !hash_equals(self::csrf(), $sent)) Http::error('Your session expired. Reload the page.', 419);
    }

    public static function audit(string $action, ?string $entity = null, $id = null, $data = null): void {
        DB::insert('audit_log', [
            'admin_id' => $_SESSION['aid'] ?? null, 'action' => $action, 'entity' => $entity, 'entity_id' => $id === null ? null : (string) $id,
            'data' => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => DB::now(),
        ]);
    }
}

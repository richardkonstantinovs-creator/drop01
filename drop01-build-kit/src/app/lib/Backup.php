<?php
// Backups: consistent SQLite copies and zip archives (database + uploads), keeping the newest 14 nightly files,
// and the nightly off-site copy: the database in a password-protected (AES-256) zip, emailed to an address outside this server.
defined('DROP01') || exit;

final class Backup {
    public static function dir(): string {
        $d = data_dir() . '/backups';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    /** Copies a live SQLite database safely (VACUUM INTO keeps WAL changes; falls back to the SQLite3 backup API). */
    public static function sqliteCopy(string $from, string $to): bool {
        @unlink($to);
        try {
            $db = new PDO('sqlite:' . $from);
            $db->exec('VACUUM INTO ' . $db->quote($to));
            return is_file($to);
        } catch (Throwable $e) {
            if (class_exists('SQLite3')) {
                $s = new SQLite3($from); $d = new SQLite3($to);
                $ok = $s->backup($d); $s->close(); $d->close();
                return $ok;
            }
            return @copy($from, $to);
        }
    }

    /** Creates a zip with the database (SQLite file or a MySQL SQL dump note) and all uploads. Returns the file path. */
    public static function create(string $label = 'manual'): string {
        $dir = self::dir();
        $name = 'drop01-' . $label . '-' . gmdate('Ymd-His') . '.zip';
        $zipPath = "$dir/$name";
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the backup file.');
        $tmp = null;
        if (DB::$driver === 'sqlite') {
            $tmp = "$dir/.tmp-" . Crypto::token(6) . '.sqlite';
            self::sqliteCopy(config('db')['path'], $tmp);
            $zip->addFile($tmp, 'app.sqlite');
        } else {
            $zip->addFromString('README.txt', "This store uses MySQL. Export the database from cPanel → phpMyAdmin → Export.\n");
        }
        $up = ROOT . '/uploads';
        if (is_dir($up)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if ($f->isFile() && $f->getFilename() !== '.htaccess') $zip->addFile($f->getPathname(), 'uploads/' . substr($f->getPathname(), strlen($up) + 1));
        }
        $zip->close();
        if ($tmp) @unlink($tmp);
        return $zipPath;
    }

    /** Lists backups newest first: [['name','size','time']]. */
    public static function list(): array {
        $out = [];
        foreach (glob(self::dir() . '/*.{zip,sqlite}', GLOB_BRACE) ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => gmdate('c', filemtime($f))];
        usort($out, fn($a, $b) => strcmp($b['time'], $a['time']));
        return $out;
    }

    public const OFFSITE_MAX = 18 * 1024 * 1024;   // most mail servers accept 20–25 MB

    /** Off-site copy on? It needs an address and its own password (never the app key: that is lost together with the server). */
    public static function offsiteReady(): bool { return Settings::get('offsite')['email'] !== '' && Settings::hasSecret('backup_pass'); }

    /** Emails the encrypted database copy. Records and returns ['at','ok','error','size']. */
    public static function offsite(): array {
        $r = ['at' => DB::now(), 'ok' => false, 'error' => null, 'size' => 0];
        $zipPath = self::dir() . '/.offsite-' . Crypto::token(6) . '.zip';
        $tmp = self::dir() . '/.offsite-' . Crypto::token(6) . '.sqlite';
        try {
            if (!self::offsiteReady()) throw new RuntimeException('Add an email address and a backup password first.');
            if (DB::$driver !== 'sqlite') throw new RuntimeException('This store uses MySQL: export it in cPanel → phpMyAdmin instead.');
            if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) throw new RuntimeException('This server cannot make password-protected zips (ask the host for a newer PHP zip extension).');
            if (!self::sqliteCopy(config('db')['path'], $tmp)) throw new RuntimeException('Could not copy the database.');
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the zip.');
            $zip->addFile($tmp, 'app.sqlite');
            $zip->setEncryptionName('app.sqlite', ZipArchive::EM_AES_256, Settings::secret('backup_pass'));
            $zip->close();
            $r['size'] = (int) filesize($zipPath);
            if ($r['size'] > self::OFFSITE_MAX) throw new RuntimeException('The database is too large to email (' . round($r['size'] / 1048576, 1) . ' MB). Download backups from System instead.');
            $date = (new DateTime('now', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d');
            $store = Settings::get('store_name');
            $text = "The nightly copy of the $store database is attached, locked with your backup password.\n\n"
                . "To open it: use 7-Zip (Windows), Keka or The Unarchiver (Mac) and type the backup password. The file inside is app.sqlite.\n"
                . "To restore: see \"Restore a backup\" in DEPLOY-GODADDY.md.\n\nPhotos are not in this email; they are in the backups you download in the admin under System.";
            [$ok, $err] = Mailer::send(Settings::get('offsite')['email'], "$store backup $date", '<p>' . nl2br(e($text)) . '</p>', $text, ["drop01-db-$date.zip" => $zipPath]);
            if (!$ok) throw new RuntimeException('Email not sent: ' . $err);
            $r['ok'] = true;
        } catch (Throwable $e) {
            $r['error'] = mb_substr($e->getMessage(), 0, 300);
        } finally {
            @unlink($zipPath); @unlink($tmp);
        }
        Settings::set('offsite', array_replace(Settings::get('offsite'), ['last' => $r]));
        return $r;
    }

    /** Keeps the newest $keep nightly backups and the newest 10 pre-migration copies. */
    public static function prune(int $keep = 14): void {
        foreach (['drop01-nightly-*.zip' => $keep, 'pre-migrate-*.sqlite' => 10] as $pat => $n) {
            $files = glob(self::dir() . '/' . $pat) ?: [];
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, $n) as $f) @unlink($f);
        }
    }

    public static function path(string $name): ?string {
        if (!preg_match('/^[\w.-]+\.(zip|sqlite)$/', $name)) return null;
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }
}

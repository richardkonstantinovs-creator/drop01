<?php
// Database layer: PDO wrapper for SQLite (default) or MySQL, small query helpers and the migration runner.
defined('DROP01') || exit;

final class DB {
    private static ?PDO $pdo = null;
    public static string $driver = 'sqlite';

    /** Connects using config('db') unless an explicit config is given (installer/tests). */
    public static function pdo(?array $cfg = null): PDO {
        if (self::$pdo && !$cfg) return self::$pdo;
        $cfg = $cfg ?? config('db');
        self::$driver = $cfg['driver'] ?? 'sqlite';
        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? 'localhost', $cfg['port'] ?? 3306, $cfg['name']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec("SET time_zone = '+00:00'");
        } else {
            $pdo = new PDO('sqlite:' . $cfg['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
        }
        return self::$pdo = $pdo;
    }

    public static function reset(): void { self::$pdo = null; }

    public static function q(string $sql, array $p = []): PDOStatement {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }
    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public static function val(string $sql, array $p = []) { $r = self::q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

    public static function insert(string $table, array $data): int {
        $cols = array_keys($data);
        self::q('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $p = []): int {
        $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        return self::q("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$p])->rowCount();
    }

    /** Insert or update on primary/unique key conflict. $keys are the conflict columns. */
    public static function upsert(string $table, array $data, array $keys): void {
        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $upd = array_diff($cols, $keys);
        if (self::$driver === 'mysql') {
            $set = $upd ? implode(',', array_map(fn($c) => "$c = VALUES($c)", $upd)) : $keys[0] . ' = ' . $keys[0];
            $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $set";
        } else {
            $set = $upd ? 'DO UPDATE SET ' . implode(',', array_map(fn($c) => "$c = excluded.$c", $upd)) : 'DO NOTHING';
            $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON CONFLICT(" . implode(',', $keys) . ") $set";
        }
        self::q($sql, array_values($data));
    }

    public static function tx(callable $fn) {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** Current time as ISO-8601 UTC text, the storage format for every timestamp. */
    public static function now(int $offsetSec = 0): string { return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSec); }

    // ---------------------------------------------------------------- migrations

    /** SQL files use {{PK}} for an auto-increment primary key so one file works on SQLite and MySQL. */
    private static function portable(string $sql): string {
        $mysql = self::$driver === 'mysql';
        return strtr($sql, [
            '{{PK}}'   => $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{{TEXT}}' => $mysql ? 'MEDIUMTEXT' : 'TEXT',
            '{{ENGINE}}' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '',
        ]);
    }

    public static function pending(): array {
        $files = glob(APP . '/migrations/*.sql') ?: [];
        sort($files);
        $done = [];
        try { $done = array_column(self::all('SELECT version FROM schema_migrations'), 'version'); } catch (Throwable $e) {}
        return array_values(array_filter($files, fn($f) => !in_array(basename($f, '.sql'), $done, true)));
    }

    /** Applies pending migrations, each in a transaction, after backing up the SQLite file. Returns applied versions. */
    public static function migrate(): array {
        $todo = self::pending();
        if (!$todo) return [];
        $cfg = config('db');
        if (self::$driver === 'sqlite' && $cfg && is_file($cfg['path']) && filesize($cfg['path']) > 0) {
            $dir = data_dir() . '/backups';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            Backup::sqliteCopy($cfg['path'], $dir . '/pre-migrate-' . basename(end($todo), '.sql') . '-' . gmdate('Ymd-His') . '.sqlite');
        }
        $applied = [];
        foreach ($todo as $f) {
            $v = basename($f, '.sql');
            $sql = self::portable(file_get_contents($f));
            $stmts = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', preg_replace('/^\s*--.*$/m', '', $sql))));
            // MySQL commits DDL implicitly, so a transaction only protects SQLite; statements are written to be re-runnable.
            self::tx(function () use ($stmts, $v) {
                foreach ($stmts as $s) self::pdo()->exec($s);
                self::insert('schema_migrations', ['version' => $v, 'applied_at' => self::now()]);
            });
            $applied[] = $v;
        }
        return $applied;
    }
}

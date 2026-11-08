<?php
// Customer-facing texts: the originals live in app/texts.php, the owner's changes in the setting "texts". Fills {words}, turns **bold** and [links](/x) into HTML.
defined('DROP01') || exit;

final class Texts {
    public const FORMAT = 'drop01-texts';
    private const MAX_LINE = 400, MAX_LONG = 3000, MAX_LIST = 20;
    private static ?array $groups = null;
    private static ?array $cat = null;

    /** The catalogue as written in app/texts.php: group id => ['page','title','url','items']. */
    public static function groups(): array {
        return self::$groups ??= require APP . '/texts.php';
    }

    /** key => ['d' => original, 'label', 'group', 'list' => bool, 'long' => bool, 'max' => int, 'vars' => [names]]. */
    public static function catalog(): array {
        if (self::$cat !== null) return self::$cat;
        self::$cat = [];
        foreach (self::groups() as $gid => $g) {
            foreach ($g['items'] as $key => $it) {
                $o = $it[2] ?? [];
                $list = is_array($it[0]);
                $src = $list ? implode(' ', array_merge(...$it[0])) : $it[0];
                self::$cat[$key] = ['d' => $it[0], 'label' => $it[1], 'group' => $gid, 'list' => $list, 'long' => !empty($o['long']) || $list,
                    'max' => (int) ($o['max'] ?? ($list || !empty($o['long']) ? self::MAX_LONG : self::MAX_LINE)),
                    'vars' => array_values(array_unique(array_merge(self::varsIn($src), $o['vars'] ?? [])))];
            }
        }
        return self::$cat;
    }

    /** The owner's changes only (key => text or list). */
    public static function overrides(): array {
        $o = Settings::get('texts', []);
        return is_array($o) ? array_intersect_key($o, self::catalog()) : [];
    }

    /** The text in use: the owner's version, else the original. Unknown keys come back as the key (shows up in testing). */
    public static function get(string $key) {
        $c = self::catalog();
        if (!isset($c[$key])) return $key;
        $o = self::overrides();
        return array_key_exists($key, $o) ? $o[$key] : $c[$key]['d'];
    }

    /** Plain text (emails' text part, WhatsApp, subjects, page titles, error messages): markup removed, {words} filled. */
    public static function plain(string $key, array $vars = []): string {
        return self::fillPlain((string) self::get($key), $vars);
    }

    /** Safe HTML: the text is escaped, **bold**, [links](/x) and line breaks become tags, {words} are filled escaped unless named in $raw. */
    public static function html(string $key, array $vars = [], array $raw = [], string $linkAttr = ''): string {
        return self::format((string) self::get($key), $vars, $raw, $linkAttr);
    }

    /** A list text (questions): [[question, answer], …]. */
    public static function items(string $key): array {
        $v = self::get($key);
        return is_array($v) ? $v : [];
    }

    public static function format(string $s, array $vars = [], array $raw = [], string $linkAttr = ''): string {
        $h = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $h);
        $h = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/u', fn($m) => self::safeUrl($m[2]) ? '<a href="' . $m[2] . '"' . $linkAttr . '>' . $m[1] . '</a>' : $m[0], $h);
        $h = str_replace(["\r\n", "\n"], '<br>', $h);
        return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (in_array($m[1], $raw, true) ? (string) $vars[$m[1]] : e($vars[$m[1]])) : $m[0], $h);
    }

    public static function fillPlain(string $s, array $vars = []): string {
        $s = preg_replace('/\*\*(.+?)\*\*/u', '$1', $s);
        $s = preg_replace('/~~(.+?)~~/u', '$1', $s);
        $s = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/u', fn($m) => preg_match('~^(https?://|mailto:|tel:|\{\w+\})~', $m[2]) ? "$m[1] ($m[2])" : $m[1], $s);
        return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $s);
    }

    private static function safeUrl(string $u): bool { return (bool) preg_match('~^(/|#|https?://|mailto:|tel:|\{\w+\})~', $u) && !str_starts_with($u, '//'); }

    public static function varsIn(string $s): array {
        preg_match_all('/\{(\w+)\}/', $s, $m);
        return array_values(array_unique($m[1]));
    }

    /** Texts for a public page's JavaScript: every key starting with one of the prefixes, in use. */
    public static function forJs(array $prefixes): array {
        $out = [];
        foreach (array_keys(self::catalog()) as $k) {
            foreach ($prefixes as $p) if (str_starts_with($k, $p)) { $out[$k] = self::get($k); break; }
        }
        return $out;
    }

    // ---------------------------------------------------------------- admin

    /** Everything the Texts screen needs. */
    public static function forAdmin(): array {
        $o = self::overrides(); $c = self::catalog(); $groups = [];
        foreach (self::groups() as $gid => $g) {
            $items = [];
            foreach (array_keys($g['items']) as $k) {
                $items[] = ['key' => $k, 'label' => $c[$k]['label'], 'original' => $c[$k]['d'], 'value' => $o[$k] ?? $c[$k]['d'], 'changed' => array_key_exists($k, $o),
                    'list' => $c[$k]['list'], 'long' => $c[$k]['long'], 'max' => $c[$k]['max'], 'vars' => $c[$k]['vars']];
            }
            $groups[] = ['id' => $gid, 'page' => $g['page'], 'title' => $g['title'], 'url' => $g['url'], 'items' => $items];
        }
        return ['groups' => $groups, 'changed' => count($o)];
    }

    /**
     * Checks one text and returns it cleaned up; throws InvalidArgumentException with a message for the owner.
     * Returns null when the text equals the original (or is empty), so the original is used again.
     */
    public static function clean(string $key, $value) {
        $c = self::catalog()[$key] ?? null;
        if (!$c) throw new InvalidArgumentException('Unknown text: ' . $key);
        if ($value === null) return null;
        if ($c['list']) {
            if (!is_array($value)) throw new InvalidArgumentException($c['label'] . ': expected a list of questions.');
            $rows = [];
            foreach ($value as $row) {
                $q = trim((string) (is_array($row) ? ($row[0] ?? $row['q'] ?? '') : ''));
                $a = trim(str_replace("\r\n", "\n", (string) (is_array($row) ? ($row[1] ?? $row['a'] ?? '') : '')));
                if ($q === '' && $a === '') continue;
                if ($q === '') throw new InvalidArgumentException($c['label'] . ': a question is empty.');
                if (mb_strlen($q) > self::MAX_LINE || mb_strlen($a) > self::MAX_LONG) throw new InvalidArgumentException($c['label'] . ': “' . mb_substr($q, 0, 40) . '” is too long.');
                self::checkVars($c, $q . ' ' . $a);
                $rows[] = [$q, $a];
            }
            if (count($rows) > self::MAX_LIST) throw new InvalidArgumentException($c['label'] . ': at most ' . self::MAX_LIST . ' questions.');
            return $rows === $c['d'] ? null : $rows;
        }
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException($c['label'] . ': expected text.');
        $v = str_replace("\r\n", "\n", (string) $value);
        $v = trim($c['long'] ? $v : preg_replace('/\s*\n\s*/', ' ', $v));
        if ($v === '') return null;
        // some texts are glued to others (" in Groningen", "Thanks, {name}. "): keep their outer space like the original
        if (str_starts_with($c['d'], ' ')) $v = ' ' . $v;
        if (str_ends_with($c['d'], ' ')) $v .= ' ';
        if (mb_strlen($v) > $c['max']) throw new InvalidArgumentException($c['label'] . ': at most ' . $c['max'] . ' characters (now ' . mb_strlen($v) . ').');
        self::checkVars($c, $v);
        return $v === $c['d'] ? null : $v;
    }

    private static function checkVars(array $c, string $v): void {
        $bad = array_diff(self::varsIn($v), $c['vars']);
        if ($bad) {
            $can = $c['vars'] ? ' You can use ' . implode(', ', array_map(fn($x) => '{' . $x . '}', $c['vars'])) . '.' : ' This text has no {words}.';
            throw new InvalidArgumentException($c['label'] . ': {' . reset($bad) . '} is not known here.' . $can);
        }
    }

    /** Saves changes (key => text, list, or null for the original). All or nothing. Returns the number of changed texts after saving. */
    public static function save(array $changes): int {
        $o = self::overrides();
        foreach ($changes as $k => $v) {
            $v = self::clean((string) $k, $v);
            if ($v === null) unset($o[$k]); else $o[$k] = $v;
        }
        Settings::set('texts', $o);
        return count($o);
    }

    public static function resetAll(): void { Settings::set('texts', []); }

    /** The file the owner downloads, fills in and uploads again: every text in use, grouped by screen. */
    public static function export(): array {
        $c = self::catalog(); $out = [];
        foreach (self::groups() as $g) {
            $sec = [];
            foreach (array_keys($g['items']) as $k) $sec[$k] = self::get($k);
            $out[$g['page'] . ' · ' . $g['title']] = $sec;
        }
        return ['format' => self::FORMAT, 'version' => 1, 'store' => Settings::get('store_name'), 'exported_at' => DB::now(),
            'how_to' => 'Change the text after the colon and keep the quotes. Keep {words in braces}: the shop fills them in. **word** is bold, [label](/pages/returns) is a link, \\n is a new line. Delete a line or leave it empty to use the original text. Questions are lists of ["question", "answer"].',
            'texts' => $out, 'count' => count($c)];
    }

    /**
     * Applies an uploaded file (export() shape, or a flat {key: text}). Valid texts are saved, the rest is reported.
     * Texts missing from the file are left as they are. Returns ['changed' => n, 'same' => n, 'skipped' => [[key, reason]]].
     */
    public static function import(array $data): array {
        $flat = [];
        $walk = function (array $a) use (&$walk, &$flat) {
            foreach ($a as $k => $v) {
                if (is_string($k) && isset(self::catalog()[$k])) $flat[$k] = $v;
                elseif (is_array($v) && !array_is_list($v)) $walk($v);
            }
        };
        $walk(isset($data['texts']) && is_array($data['texts']) ? $data['texts'] : $data);
        if (!$flat) throw new InvalidArgumentException('This file has no texts in it. Download the texts file first and fill that one in.');
        $o = self::overrides(); $changed = 0; $same = 0; $skipped = [];
        foreach ($flat as $k => $v) {
            try { $v = self::clean($k, is_string($v) ? str_replace('\\n', "\n", $v) : $v); }
            catch (InvalidArgumentException $e) { $skipped[] = [$k, $e->getMessage()]; continue; }
            $before = $o[$k] ?? null;
            if ($v === null) unset($o[$k]); else $o[$k] = $v;
            if ($before === $v) $same++; else $changed++;
        }
        Settings::set('texts', $o);
        return ['changed' => $changed, 'same' => $same, 'skipped' => $skipped];
    }

    /** Tests swap the catalogue cache after changing settings. */
    public static function flush(): void { self::$cat = null; }
}

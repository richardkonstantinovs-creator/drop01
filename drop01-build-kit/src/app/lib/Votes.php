<?php
// Shared product votes keyed by an anonymous visitor id (random UUID kept in the visitor's localStorage).
defined('DROP01') || exit;

final class Votes {
    public static function validVisitor(string $v): bool { return (bool) preg_match('/^[a-f0-9-]{16,64}$/i', $v); }

    /** Counts per product slug: ['item-01' => ['up'=>3,'down'=>1], ...] for products shown on the waitlist. */
    public static function counts(): array {
        $out = [];
        foreach (DB::all('SELECT slug FROM products WHERE active = 1 AND on_waitlist = 1') as $p) $out[$p['slug']] = ['up' => 0, 'down' => 0];
        $rows = DB::all('SELECT p.slug, SUM(CASE WHEN v.value = 1 THEN 1 ELSE 0 END) AS up, SUM(CASE WHEN v.value = -1 THEN 1 ELSE 0 END) AS down
            FROM votes v JOIN products p ON p.id = v.product_id WHERE p.active = 1 AND p.on_waitlist = 1 GROUP BY p.slug');
        foreach ($rows as $r) $out[$r['slug']] = ['up' => (int) $r['up'], 'down' => (int) $r['down']];
        return $out;
    }

    /** This visitor's own votes: ['item-01' => 1, ...]. */
    public static function mine(string $visitor): array {
        if (!self::validVisitor($visitor)) return [];
        $out = [];
        foreach (DB::all('SELECT p.slug, v.value FROM votes v JOIN products p ON p.id = v.product_id WHERE v.visitor_id = ?', [strtolower($visitor)]) as $r) $out[$r['slug']] = (int) $r['value'];
        return $out;
    }

    /** value 1 = like, -1 = dislike, 0 = remove. Returns false for an unknown product. */
    public static function cast(string $visitor, string $slug, int $value): bool {
        $pid = DB::val('SELECT id FROM products WHERE slug = ? AND active = 1 AND on_waitlist = 1', [$slug]);
        if (!$pid) return false;
        $visitor = strtolower($visitor);
        if ($value === 0) DB::q('DELETE FROM votes WHERE visitor_id = ? AND product_id = ?', [$visitor, $pid]);
        else DB::upsert('votes', ['visitor_id' => $visitor, 'product_id' => (int) $pid, 'value' => $value > 0 ? 1 : -1, 'updated_at' => DB::now()], ['visitor_id', 'product_id']);
        return true;
    }
}

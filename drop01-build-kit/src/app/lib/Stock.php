<?php
// Stock: reservations while a customer pays (15 min), release of expired holds, and turning holds into sales.
// variants.stock is always "available to sell"; every change is a row in stock_movements.
defined('DROP01') || exit;

final class Stock {
    /** Holds stock for an order. $lines: [['variant_id','qty','name']]. Throws RuntimeException when something sold out meanwhile. */
    public static function reserve(int $orderId, array $lines, int $minutes): void {
        DB::tx(function () use ($orderId, $lines, $minutes) {
            $exp = DB::now($minutes * 60);
            foreach ($lines as $l) {
                $ok = DB::q('UPDATE variants SET stock = stock - ? WHERE id = ? AND active = 1 AND stock >= ?', [$l['qty'], $l['variant_id'], $l['qty']])->rowCount();
                if (!$ok) {
                    $left = (int) DB::val('SELECT stock FROM variants WHERE id = ?', [$l['variant_id']]);
                    throw new RuntimeException($left ? "Only $left of {$l['name']} left. Update your bag and try again." : "{$l['name']} just sold out. Remove it from your bag to continue.");
                }
                DB::insert('stock_movements', ['variant_id' => $l['variant_id'], 'delta' => -$l['qty'], 'reason' => 'reserve', 'ref' => 'order:' . $orderId, 'expires_at' => $exp, 'created_at' => DB::now()]);
            }
            DB::update('orders', ['reserved' => 1], 'id = ?', [$orderId]);
        });
    }

    /** Gives back holds that ran out. Cheap; called on catalogue reads and by cron. */
    public static function releaseExpired(): int {
        $rows = DB::all("SELECT id, ref FROM stock_movements WHERE reason = 'reserve' AND expires_at IS NOT NULL AND expires_at < ?", [DB::now()]);
        $orders = array_unique(array_map(fn($r) => (int) substr($r['ref'], 6), $rows));
        foreach ($orders as $oid) self::releaseOrder($oid, 'expired');
        return count($orders);
    }

    /** Releases every outstanding hold of an order (cancel, failed payment, timeout). */
    public static function releaseOrder(int $orderId, string $why = 'release'): void {
        DB::tx(function () use ($orderId, $why) {
            foreach (DB::all("SELECT * FROM stock_movements WHERE reason = 'reserve' AND expires_at IS NOT NULL AND ref = ?", ['order:' . $orderId]) as $m) {
                DB::q('UPDATE variants SET stock = stock + ? WHERE id = ?', [-$m['delta'], $m['variant_id']]);
                DB::update('stock_movements', ['expires_at' => null], 'id = ?', [$m['id']]);
                DB::insert('stock_movements', ['variant_id' => $m['variant_id'], 'delta' => -$m['delta'], 'reason' => 'release', 'ref' => 'order:' . $orderId . ':' . $why, 'created_at' => DB::now()]);
            }
            DB::update('orders', ['reserved' => 0], 'id = ?', [$orderId]);
        });
    }

    /** Payment arrived: holds become sales. If a hold had already run out, the stock is taken again (may go below zero → flagged). */
    public static function sellOrder(int $orderId): bool {
        return DB::tx(function () use ($orderId) {
            $held = DB::all("SELECT * FROM stock_movements WHERE reason = 'reserve' AND expires_at IS NOT NULL AND ref = ?", ['order:' . $orderId]);
            $oversold = false;
            if ($held) {
                DB::q("UPDATE stock_movements SET reason = 'sale', expires_at = NULL WHERE reason = 'reserve' AND expires_at IS NOT NULL AND ref = ?", ['order:' . $orderId]);
            } else {
                foreach (DB::all('SELECT variant_id, qty FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL', [$orderId]) as $i) {
                    DB::q('UPDATE variants SET stock = stock - ? WHERE id = ?', [$i['qty'], $i['variant_id']]);
                    DB::insert('stock_movements', ['variant_id' => $i['variant_id'], 'delta' => -$i['qty'], 'reason' => 'sale', 'ref' => 'order:' . $orderId, 'created_at' => DB::now()]);
                    if ((int) DB::val('SELECT stock FROM variants WHERE id = ?', [$i['variant_id']]) < 0) $oversold = true;
                }
            }
            DB::update('orders', ['reserved' => 0], 'id = ?', [$orderId]);
            return !$oversold;
        });
    }

    /** Manual adjustment from the admin with a reason (adjust, damage, receive, return). */
    public static function adjust(int $variantId, int $delta, string $reason, string $note = ''): void {
        if (!in_array($reason, ['adjust', 'damage', 'receive', 'return'], true)) throw new InvalidArgumentException('Unknown reason');
        DB::tx(function () use ($variantId, $delta, $reason, $note) {
            DB::q('UPDATE variants SET stock = stock + ? WHERE id = ?', [$delta, $variantId]);
            DB::insert('stock_movements', ['variant_id' => $variantId, 'delta' => $delta, 'reason' => $reason, 'ref' => mb_substr($note, 0, 80) ?: null, 'by_admin_id' => $_SESSION['aid'] ?? null, 'created_at' => DB::now()]);
        });
        // sold out → back in stock: tell the people who asked
        if ($delta > 0 && !DB::pdo()->inTransaction()) Restock::notify();
    }
}

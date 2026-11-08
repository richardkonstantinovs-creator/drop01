<?php
// Cash from the door: who collected which order, what each courier still holds, and the handovers to the owner (with any difference).
defined('DROP01') || exit;

final class Cash {
    /** Cash collected and not yet handed over, per person (owners keep their own cash, so they are left out). */
    public static function open(?int $courierId = null): array {
        $rows = DB::all("SELECT o.id, o.number, o.total_cents, o.paid_at, o.cash_by, c.name AS customer, a.name AS courier
            FROM orders o JOIN admins a ON a.id = o.cash_by LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.payment_method = 'cash' AND o.payment_status = 'paid' AND o.cash_handover_id IS NULL AND a.role <> 'owner'"
            . ($courierId ? ' AND o.cash_by = ?' : '') . ' ORDER BY o.paid_at', $courierId ? [$courierId] : []);
        $out = [];
        foreach ($rows as $r) {
            $k = (int) $r['cash_by'];
            $out[$k] ??= ['courier_id' => $k, 'courier' => $r['courier'], 'total' => 0, 'since' => $r['paid_at'], 'orders' => []];
            $out[$k]['total'] += (int) $r['total_cents'];
            $out[$k]['orders'][] = ['id' => (int) $r['id'], 'number' => (int) $r['number'], 'cents' => (int) $r['total_cents'], 'customer' => $r['customer'], 'at' => $r['paid_at']];
        }
        return array_values($out);
    }

    /** The owner got the money. $orderIds: the orders it covers (all open ones of that courier if empty). Returns the handover. */
    public static function handover(int $courierId, array $orderIds, int $receivedCents, string $note, int $adminId): array {
        return DB::tx(function () use ($courierId, $orderIds, $receivedCents, $note, $adminId) {
            $open = self::open($courierId)[0]['orders'] ?? [];
            if ($orderIds) $open = array_values(array_filter($open, fn($o) => in_array($o['id'], array_map('intval', $orderIds), true)));
            if (!$open) throw new RuntimeException('There is no open cash for this person.');
            if ($receivedCents < 0) throw new RuntimeException('Enter the amount you received.');
            $expected = array_sum(array_column($open, 'cents'));
            $id = DB::insert('cash_handovers', ['courier_id' => $courierId, 'expected_cents' => $expected, 'received_cents' => $receivedCents, 'orders' => count($open),
                'note' => mb_substr(trim($note), 0, 200) ?: null, 'by_admin_id' => $adminId, 'created_at' => DB::now()]);
            foreach ($open as $o) {
                DB::update('orders', ['cash_handover_id' => $id], 'id = ?', [$o['id']]);
                Orders::event($o['id'], 'cash_handed_over', ['handover' => $id], $adminId);
            }
            return DB::one('SELECT * FROM cash_handovers WHERE id = ?', [$id]);
        });
    }

    public static function history(int $limit = 20): array {
        return DB::all('SELECT h.*, a.name AS courier, b.name AS by_name FROM cash_handovers h LEFT JOIN admins a ON a.id = h.courier_id LEFT JOIN admins b ON b.id = h.by_admin_id
            ORDER BY h.id DESC LIMIT ' . (int) $limit);
    }
}

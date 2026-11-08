<?php
// Courier actions on a route: start the route, delivered (cash collected, photo proof), not home, on my way, reschedule,
// and the customer messages each of them sends (email automatically, WhatsApp as a one-tap link).
defined('DROP01') || exit;

final class Courier {
    /** Orders a courier can still act on. */
    private const OPEN = ['new', 'paid', 'packing', 'out_for_delivery', 'failed_delivery'];

    /** Every paid or cash stop of a window that has not left yet goes out for delivery; customers get their ETA window. Returns [orderId => WhatsApp link]. */
    public static function start(int $slotId, ?int $adminId): array {
        $r = Route::forSlot($slotId);
        if (!$r) throw new RuntimeException('Window not found.');
        $wa = [];
        foreach ($r['stops'] as $s) {
            if ($s['state'] !== 'todo' || !in_array($s['status'], ['paid', 'packing', 'new'], true)) continue;
            Orders::setStatus($s['id'], 'out_for_delivery', $adminId);
            $wa[$s['id']] = self::notify($s['id'], 'out_for_delivery', ['eta' => self::etaWindow($s['eta_ts'], $r['slot'])]);
        }
        return $wa;
    }

    /** Delivered. $collected: the courier took the payment at the door (cash). $photo: optional $_FILES entry as proof. */
    public static function delivered(int $id, ?int $adminId, bool $collected = false, ?array $photo = null): ?string {
        $o = self::open($id);
        if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) DB::update('orders', ['proof_path' => self::storeProof($photo)], 'id = ?', [$id]);
        if ($collected && $o['payment_status'] !== 'paid') Orders::markPaid($id, 'cash', 'cash', $adminId);
        Orders::setStatus($id, 'delivered', $adminId);
        return self::notify($id, 'delivered');
    }

    /** Nobody home (or another reason): the order waits for a new window. */
    public static function failed(int $id, string $why, ?int $adminId): ?string {
        self::open($id);
        Orders::setStatus($id, 'failed_delivery', $adminId);
        DB::q('UPDATE orders SET failed_count = failed_count + 1, route_pos = NULL WHERE id = ?', [$id]);
        Orders::event($id, 'not_home', ['why' => $why], $adminId);
        return self::notify($id, 'failed_delivery');
    }

    /** "On my way, ~10 min" heads-up for the next stop. */
    public static function onMyWay(int $id, ?int $adminId): ?string {
        self::open($id);
        Orders::event($id, 'on_my_way', [], $adminId);
        return self::notify($id, 'on_my_way');
    }

    /** A failed (or any open) delivery gets a new window; the goods are packed already, so it goes back to packing. */
    public static function reschedule(int $id, int $slotId, ?int $adminId): ?string {
        $o = self::open($id);
        Orders::changeSlot($id, $slotId, $adminId);
        DB::update('orders', ['route_pos' => null], 'id = ?', [$id]);
        if (in_array($o['status'], ['failed_delivery', 'out_for_delivery'], true)) Orders::setStatus($id, $o['payment_status'] === 'paid' ? 'packing' : 'new', $adminId);
        return self::notify($id, 'rescheduled');
    }

    /** Sends the customer message for a delivery event: email when switched on in Settings → Delivery, WhatsApp as a ready link. */
    public static function notify(int $id, string $what, array $vars = []): ?string {
        $o = Orders::get($id);
        if (!$o) return null;
        $vars += ['eta' => $o['slot_label']];
        if ($o['email'] && !empty(Settings::get('delivery')['notify'][$what] ?? true))
            Messages::deliverLater(Messages::queueEmail($what, $o['email'], ['order' => $o, 'url' => Orders::trackUrl($o)] + $vars, ['order_id' => $id, 'customer_id' => $o['customer_id']]));
        if (!$o['phone']) return null;
        $text = Orders::waText($o, $what, $vars);
        Messages::logWhatsapp($id, (int) $o['customer_id'], $o['phone'], $what, $text);
        return Messages::waLink($o['phone'], $text);
    }

    /** "18:30–19:00": the stop's ETA ± 15 minutes, rounded to quarters, never starting before the window does. */
    public static function etaWindow(?int $eta, array $slot): string {
        if (!$eta) return $slot['start'] . '–' . $slot['end'];
        $tz = new DateTimeZone(TZ_LOCAL);
        $q = (int) (round($eta / 900) * 900);
        $a = (new DateTime('@' . ($q - 900)))->setTimezone($tz)->format('H:i');
        $b = (new DateTime('@' . ($q + 900)))->setTimezone($tz)->format('H:i');
        return max($a, $slot['start']) . '–' . $b;
    }

    /** Photo proof: re-encoded like product photos (EXIF stripped), then moved out of the web root. */
    private static function storeProof(array $file): string {
        $img = Images::store($file, 'proof');
        $dir = data_dir() . '/proof/' . gmdate('Y-m');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('The data folder is not writable.');
        $dest = $dir . '/' . basename($img['path']);
        if (!@rename(ROOT . $img['path'], $dest)) throw new RuntimeException('Saving the photo failed.');
        @unlink(ROOT . $img['path_sm']);
        return substr($dest, strlen(data_dir()) + 1);
    }

    public static function proofFile(array $o): ?string {
        if (empty($o['proof_path']) || str_contains($o['proof_path'], '..')) return null;
        $f = data_dir() . '/' . $o['proof_path'];
        return is_file($f) ? $f : null;
    }

    private static function open(int $id): array {
        $o = DB::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) throw new RuntimeException('Order not found.');
        if (!in_array($o['status'], self::OPEN, true)) throw new RuntimeException('Order #' . $o['number'] . ' is ' . strtolower(Orders::STATUS[$o['status']]) . ' already.');
        return $o;
    }
}

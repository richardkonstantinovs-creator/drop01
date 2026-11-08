<?php
// Invoices: sequential numbers per year (2026-0001), PDF with seller/buyer details, lines and the BTW split per rate.
defined('DROP01') || exit;

final class Invoice {
    /** Creates the invoice for a paid order once (idempotent). Returns the invoice row. */
    public static function create(int $orderId): array {
        if ($inv = DB::one('SELECT * FROM invoices WHERE order_id = ?', [$orderId])) return $inv;
        $year = (new DateTime('now', new DateTimeZone(TZ_LOCAL)))->format('Y');
        $inv = DB::tx(function () use ($orderId, $year) {
            $last = DB::val('SELECT number FROM invoices WHERE number LIKE ? ORDER BY number DESC LIMIT 1', [$year . '-%']);
            $num = $year . '-' . str_pad((string) ($last ? (int) substr($last, 5) + 1 : 1), 4, '0', STR_PAD_LEFT);
            $id = DB::insert('invoices', ['order_id' => $orderId, 'number' => $num, 'created_at' => DB::now()]);
            return DB::one('SELECT * FROM invoices WHERE id = ?', [$id]);
        });
        $path = self::write($inv);
        DB::update('invoices', ['pdf_path' => $path], 'id = ?', [$inv['id']]);
        $inv['pdf_path'] = $path;
        return $inv;
    }

    public static function file(array $inv): string {
        $p = data_dir() . '/private/invoices/' . $inv['pdf_path'];
        if (!$inv['pdf_path'] || !is_file($p)) { $inv['pdf_path'] = self::write($inv); DB::update('invoices', ['pdf_path' => $inv['pdf_path']], 'id = ?', [$inv['id']]); $p = data_dir() . '/private/invoices/' . $inv['pdf_path']; }
        return $p;
    }

    /** Renders the PDF into data/private/invoices/<year>/<number>.pdf and returns the relative path. */
    public static function write(array $inv): string {
        $o = Orders::get((int) $inv['order_id']);
        $c = Settings::get('company');
        $tz = new DateTimeZone(TZ_LOCAL);
        $pdf = new Pdf(); $pdf->addPage();
        $ink = [0.07, 0.07, 0.09]; $mut = [0.42, 0.42, 0.47];
        $pdf->rect(0, 0, Pdf::W, 6, [0.66, 0.86, 1]);
        $pdf->text(50, 46, Settings::get('logo_text'), 22, true);
        $pdf->text(545, 48, 'INVOICE', 11, true, 'R', $mut);
        $pdf->text(545, 64, $inv['number'], 14, true, 'R');
        $seller = array_filter([$c['legal_name'] ?: Settings::get('store_name'), $c['address'], $c['kvk'] ? 'KvK ' . $c['kvk'] : '', $c['btw'] ? 'BTW ' . $c['btw'] : '', $c['email']]);
        $y = 96; foreach ($seller as $l) { $pdf->text(50, $y, $l, 9, false, 'L', $mut); $y += 13; }
        $date = (new DateTime($o['paid_at'] ?: $inv['created_at']))->setTimezone($tz);
        $meta = [['Invoice date', $date->format('j F Y')], ['Order', '#' . $o['number']], ['Payment', $o['payment_method_label']]];
        $y = 96; foreach ($meta as [$k, $v]) { $pdf->text(400, $y, $k, 9, false, 'L', $mut); $pdf->text(545, $y, $v, 9, false, 'R'); $y += 13; }

        $y = 178;
        $pdf->text(50, $y, 'Bill to', 9, true, 'L', $mut); $y += 14;
        foreach (array_filter([$o['customer_name'], $o['street'] ? trim($o['street'] . ' ' . $o['house_no'] . ' ' . $o['addition']) : '', trim(($o['postcode'] ?? '') . ' ' . ($o['city'] ?? '')), $o['email']]) as $l) { $pdf->text(50, $y, $l, 10); $y += 14; }

        $y = max($y + 18, 270);
        $pdf->text(50, $y, 'Item', 9, true, 'L', $mut); $pdf->text(330, $y, 'Qty', 9, true, 'R', $mut); $pdf->text(400, $y, 'BTW', 9, true, 'R', $mut);
        $pdf->text(470, $y, 'Price', 9, true, 'R', $mut); $pdf->text(545, $y, 'Amount', 9, true, 'R', $mut);
        $y += 16; $pdf->line(50, $y, 545, $y);
        foreach ($o['items'] as $it) {
            $y += 9;
            $pdf->text(50, $y, mb_strimwidth($it['name_snapshot'], 0, 52, '…'), 10);
            $pdf->text(330, $y, (string) $it['qty'], 10, false, 'R');
            $pdf->text(400, $y, $it['vat_rate'] . '%', 10, false, 'R');
            $pdf->text(470, $y, eur((int) $it['unit_price_cents']), 10, false, 'R');
            $pdf->text(545, $y, eur($it['unit_price_cents'] * $it['qty']), 10, false, 'R');
            $y += 16;
            if ($y > 700) { $pdf->addPage(); $y = 60; }
        }
        if ($o['gift_code']) { $y += 9; $pdf->text(50, $y, 'Waitlist gift (' . $o['gift_code'] . ')', 10); $pdf->text(330, $y, '1', 10, false, 'R'); $pdf->text(545, $y, eur(0), 10, false, 'R'); $y += 16; }
        $pdf->line(50, $y + 4, 545, $y + 4); $y += 16;
        $rows = [['Subtotal', eur((int) $o['subtotal_cents'])]];
        if ((int) $o['discount_cents']) $rows[] = ['Discount', '−' . eur((int) $o['discount_cents'])];
        $rows[] = ['Delivery', (int) $o['delivery_cents'] ? eur((int) $o['delivery_cents']) : 'Free'];
        foreach ($rows as [$k, $v]) { $pdf->text(400, $y, $k, 10, false, 'L', $mut); $pdf->text(545, $y, $v, 10, false, 'R'); $y += 15; }
        $pdf->text(400, $y + 2, 'Total', 12, true); $pdf->text(545, $y + 2, eur((int) $o['total_cents']), 12, true, 'R'); $y += 26;

        // BTW summary per rate, from the stored lines so it always matches the order
        $split = self::split($o);
        $pdf->text(50, $y, 'BTW summary', 9, true, 'L', $mut); $y += 14;
        $pdf->text(50, $y, 'Rate', 9, false, 'L', $mut); $pdf->text(250, $y, 'Excl. BTW', 9, false, 'R', $mut); $pdf->text(340, $y, 'BTW', 9, false, 'R', $mut); $pdf->text(430, $y, 'Incl. BTW', 9, false, 'R', $mut); $y += 14;
        foreach ($split as $rate => $r) {
            $pdf->text(50, $y, $rate . '%', 10); $pdf->text(250, $y, eur($r['gross'] - $r['vat']), 10, false, 'R'); $pdf->text(340, $y, eur($r['vat']), 10, false, 'R'); $pdf->text(430, $y, eur($r['gross']), 10, false, 'R'); $y += 14;
        }
        $y += 20;
        foreach (['Prices include BTW. You have the right to cancel within 14 days after delivery without giving a reason;',
                  'see ' . Http::baseUrl() . '/pages/returns. Thank you for your order!'] as $l) { $pdf->text(50, $y, $l, 8.5, false, 'L', $mut); $y += 12; }

        $rel = substr($inv['number'], 0, 4) . '/' . $inv['number'] . '.pdf';
        $dir = data_dir() . '/private/invoices/' . substr($inv['number'], 0, 4);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        file_put_contents($dir . '/' . $inv['number'] . '.pdf', $pdf->output());
        return $rel;
    }

    /** BTW per rate for an order: discount spread over the lines by value, delivery on the main rate, rounding absorbed so totals match. */
    public static function split(array $o): array {
        $lines = array_map(fn($i) => ['product_id' => $i['product_id'], 'unit_cents' => (int) $i['unit_price_cents'], 'qty' => (int) $i['qty'], 'vat_rate' => (int) $i['vat_rate']], $o['items']);
        $sub = array_sum(array_map(fn($l) => $l['unit_cents'] * $l['qty'], $lines));
        $disc = (int) $o['discount_cents']; $byRate = [];
        foreach ($lines as $l) { $g = $l['unit_cents'] * $l['qty']; $byRate[$l['vat_rate']] = ($byRate[$l['vat_rate']] ?? 0) + $g - ($sub ? (int) round($disc * $g / $sub) : 0); }
        if ($byRate) { arsort($byRate); $main = array_key_first($byRate); $byRate[$main] += ($sub - $disc) - array_sum($byRate) + (int) $o['delivery_cents']; }
        $out = [];
        foreach ($byRate as $r => $g) $out[$r] = ['gross' => $g, 'vat' => $r ? (int) round($g * $r / (100 + $r)) : 0];
        krsort($out);
        return $out;
    }
}

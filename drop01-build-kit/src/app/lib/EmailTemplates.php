<?php
// Email templates in the dark holographic look (table layout + inline CSS for mail clients), with plain-text versions.
defined('DROP01') || exit;

final class EmailTemplates {
    private const LINK = ' style="color:#A8DCFF"';

    /** Returns [subject, html, text]. Every word comes from Admin → Texts (group Emails); the text part is built from the same rows. */
    public static function render(string $tpl, array $v): array {
        $store = Settings::get('store_name');
        $v += ['store' => $store];
        $P = fn(string $key, array $x = []) => ['p' => th($key, $x + $v, [], self::LINK), 'pt' => t($key, $x + $v)];
        $first = fn(?string $name) => trim(explode(' ', trim((string) $name))[0] ?? '') ?: t('email.there');
        switch ($tpl) {
            case 'waitlist_signup':
                $subject = t($v['code'] ? 'email.wl.subject_code' : 'email.wl.subject', $v);
                $rows = [['h' => t('email.wl.heading', $v)], $P('email.wl.intro')];
                if ($v['code']) {
                    $rows[] = ['code' => $v['code'], 'label' => t('email.wl.code_label', $v)];
                    $rows[] = $P('email.wl.gift');
                } else {
                    $rows[] = $P('email.wl.no_gift');
                }
                $rows[] = $P('email.wl.outro');
                return self::build($subject, $store, $rows, $v['unsubscribe'] ?? null);
            case 'order_confirmation':
                $o = $v['order'];
                $x = ['first' => $first($o['customer_name']), 'number' => $o['number'], 'slot' => $o['slot_label'],
                    'address' => trim($o['street'] . ' ' . $o['house_no'] . ' ' . $o['addition']) . ', ' . $o['postcode'],
                    'vat' => eur((int) $o['vat_cents']), 'time_url' => $v['url'] . '#time', 'returns_url' => Http::baseUrl() . '/pages/returns'];
                $lines = array_map(fn($i) => [$i['qty'] . '× ' . $i['name_snapshot'], eur($i['unit_price_cents'] * $i['qty'])], $o['items']);
                if ($o['gift_code']) $lines[] = [t('email.order.gift'), t('email.order.free')];
                if ((int) $o['discount_cents']) $lines[] = [t('email.order.discount'), '−' . eur((int) $o['discount_cents'])];
                $lines[] = [t('email.order.delivery'), (int) $o['delivery_cents'] ? eur((int) $o['delivery_cents']) : t('email.order.free')];
                $rows = [['h' => t('email.order.heading', $x + $v)], $P('email.order.intro', $x),
                    ['table' => $lines, 'total' => [t('email.order.total', $x), eur((int) $o['total_cents'])]], ['button' => [t('email.track'), $v['url']]],
                    ...(!empty(Settings::get('delivery')['self_reschedule']) ? [$P('email.order.reschedule', $x)] : []),
                    $P('email.order.withdrawal', $x)];
                return self::build(t('email.order.subject', $x + $v), $store, $rows);
            case 'payment_link':
                $o = $v['order'];
                $x = ['number' => $o['number'], 'total' => eur((int) $o['total_cents']), 'slot' => $o['slot_label'], 'first' => $first($o['customer_name'])];
                $rows = [['h' => t('email.pay.heading', $x + $v)], $P('email.pay.text', $x), ['button' => [t('email.pay.button'), $v['url']]]];
                return self::build(t('email.pay.subject', $x + $v), $store, $rows);
            case 'out_for_delivery':
            case 'on_my_way':
            case 'delivered':
            case 'failed_delivery':
            case 'rescheduled':
                $o = $v['order'];
                $self = !empty(Settings::get('delivery')['self_reschedule']);
                $x = ['first' => $first($o['customer_name']), 'number' => $o['number'], 'eta' => $v['eta'] ?? $o['slot_label'], 'slot' => $o['slot_label'], 'total' => eur((int) $o['total_cents'])];
                $k = ['out_for_delivery' => 'out', 'on_my_way' => 'way', 'delivered' => 'delivered', 'failed_delivery' => 'missed', 'rescheduled' => 'moved'][$tpl];
                $p = $P($tpl === 'failed_delivery' && $self ? 'email.missed.text_self' : "email.$k.text", $x);
                // not paid yet: ask to have the money ready
                if ($tpl === 'out_for_delivery' && (int) $o['total_cents'] && $o['payment_status'] !== 'paid') {
                    $c = $P('email.out.cash', $x); $p = ['p' => $p['p'] . ' ' . $c['p'], 'pt' => $p['pt'] . ' ' . $c['pt']];
                }
                // delivered → "Order again" puts the same things back in the bag; not home → pick a new window yourself
                $btn = match (true) {
                    $tpl === 'delivered' => [t('email.delivered.button'), Http::baseUrl() . '/?reorder=' . $o['number'] . '&t=' . $o['token'] . '&utm_source=email&utm_campaign=reorder'],
                    $tpl === 'failed_delivery' && $self => [t('email.missed.button'), $v['url'] . '#time'],
                    default => [t('email.track'), $v['url']],
                };
                $rows = [['h' => t("email.$k.heading", $x + $v)], $p, ['button' => $btn]];
                return self::build(t("email.$k.subject", $x + $v), $store, $rows);
            case 'abandoned_cart':
                $x = ['first' => $first($v['name'] ?? '')];
                $lines = array_map(fn($l) => [$l['qty'] . '× ' . $l['name'], eur($l['unit_cents'] * $l['qty'])], $v['lines']);
                $sum = array_sum(array_map(fn($l) => $l['unit_cents'] * $l['qty'], $v['lines']));
                $rows = [['h' => t('email.cart.heading', $x + $v)], $P('email.cart.text', $x),
                    ['table' => $lines, 'total' => [t('email.cart.total'), eur($sum)]], ['button' => [t('email.cart.button'), $v['url']]],
                    $P('email.cart.outro', $x)];
                return self::build(t('email.cart.subject', $x + $v), $store, $rows, $v['unsubscribe']);
            case 'back_in_stock':
                $rows = [['h' => t('email.restock.heading', $v)], $P('email.restock.text'), ['button' => [t('email.restock.button'), $v['url']]], $P('email.restock.outro')];
                return self::build(t('email.restock.subject', $v), $store, $rows);
            case 'referral_reward':
                $x = ['first' => $first($v['name'] ?? ''), 'amount' => eur((int) $v['amount'])];
                $rows = [['h' => t('email.reward.heading', $x + $v)], $P('email.reward.text', $x),
                    ['code' => $v['code'], 'label' => t('email.reward.code_label')], ['button' => [t('email.reward.button'), $v['url']]], $P('email.reward.outro', $x)];
                return self::build(t('email.reward.subject', $x + $v), $store, $rows);
            case 'broadcast':
                $name = $v['first_name'] ?: t('email.there');
                $fill = fn(string $s) => str_replace('{first_name}', $name, $s);
                $rows = [];
                if ($v['heading'] !== '') $rows[] = ['h' => $fill($v['heading'])];
                foreach (preg_split('/\R{2,}/', $fill($v['body'])) as $para) $rows[] = ['p' => nl2br(e(trim($para))), 'pt' => trim($para)];
                if ($v['button_url'] !== '') $rows[] = ['button' => [$v['button_label'], $v['button_url']]];
                return self::build($fill($v['subject']), $store, $rows, $v['unsubscribe']);
            case 'chat':   // an Inbox reply: the team's own words, no marketing footer
                $rows = array_map(fn($para) => ['p' => nl2br(e(trim($para)))], preg_split('/\R{2,}/', trim($v['text'])));
                return [$v['subject'], self::layout($store, $rows), trim($v['text']) . "\n\n— {$store}\n"];
            case 'test':
                return ["Test email from {$store}", self::layout($store, [['h' => 'It works.'], ['p' => 'Your email settings are correct. Customers will get their messages.']]), "It works. Your email settings are correct."];
        }
        throw new InvalidArgumentException("Unknown email template $tpl");
    }

    /** [subject, html, text]: the plain-text part is made from the same rows as the HTML. */
    private static function build(string $subject, string $store, array $rows, ?string $unsub = null): array {
        $txt = [];
        foreach ($rows as $r) {
            if (isset($r['h'])) $txt[] = $r['h'];
            if (isset($r['p'])) $txt[] = $r['pt'] ?? html_entity_decode(strip_tags(str_replace('<br>', "\n", $r['p'])), ENT_QUOTES, 'UTF-8');
            if (isset($r['table'])) $txt[] = implode("\n", array_map(fn($l) => $l[0] . ': ' . $l[1], [...$r['table'], $r['total']]));
            if (isset($r['button'])) $txt[] = $r['button'][0] . ': ' . $r['button'][1];
            if (isset($r['code'])) $txt[] = $r['label'] . ': ' . $r['code'];
        }
        if ($unsub) $txt[] = t('email.unsub_line', ['url' => $unsub]);
        return [$subject, self::layout($store, $rows, $unsub), implode("\n\n", $txt) . "\n"];
    }

    private static function layout(string $store, array $rows, ?string $unsub = null): string {
        $holo = 'linear-gradient(115deg,#FFB3D9 0%,#FFE7A3 22%,#B8FFD9 44%,#A8DCFF 66%,#D4B8FF 88%)';
        $c = Settings::get('company');
        $body = '';
        foreach ($rows as $r) {
            if (isset($r['h'])) $body .= '<tr><td style="padding:0 28px 12px;font:800 30px/1.05 Arial Narrow,Arial,sans-serif;letter-spacing:-.5px;color:#F3F1EC">' . e($r['h']) . '</td></tr>';
            if (isset($r['p'])) $body .= '<tr><td style="padding:0 28px 16px;font:15px/1.5 Arial,sans-serif;color:#C9C7CF">' . $r['p'] . '</td></tr>';
            if (isset($r['table'])) {
                $t = '';
                foreach ($r['table'] as [$k, $val]) $t .= '<tr><td style="padding:6px 0;font:14px Arial,sans-serif;color:#C9C7CF">' . e($k) . '</td><td align="right" style="padding:6px 0;font:14px Arial,sans-serif;color:#F3F1EC">' . e($val) . '</td></tr>';
                $t .= '<tr><td style="padding:10px 0 0;border-top:1px solid #2A2A31;font:bold 15px Arial,sans-serif;color:#F3F1EC">' . e($r['total'][0]) . '</td><td align="right" style="padding:10px 0 0;border-top:1px solid #2A2A31;font:bold 15px Arial,sans-serif;color:#F3F1EC">' . e($r['total'][1]) . '</td></tr>';
                $body .= '<tr><td style="padding:0 28px 18px"><table role="presentation" width="100%">' . $t . '</table></td></tr>';
            }
            if (isset($r['button'])) $body .= '<tr><td style="padding:0 28px 18px"><a href="' . e($r['button'][1]) . '" style="display:inline-block;padding:13px 20px;border-radius:12px;background:#A8DCFF;background-image:' . $holo . ';color:#121216;font:bold 15px Arial,sans-serif;text-decoration:none">' . e($r['button'][0]) . '</a></td></tr>';
            if (isset($r['code'])) $body .= '<tr><td style="padding:4px 28px 18px"><table role="presentation" width="100%" style="border-collapse:separate;border:1px dashed #3A3A44;border-radius:12px;background:#1C1C22"><tr><td style="padding:14px 16px">'
                . '<div style="font:10px/1.4 Courier New,monospace;letter-spacing:2px;text-transform:uppercase;color:#8D8C96">' . e($r['label']) . '</div>'
                . '<div style="font:bold 26px/1.3 Courier New,monospace;letter-spacing:3px;color:#FFE7A3">' . e($r['code']) . '</div></td></tr></table></td></tr>';
        }
        $foot = $unsub ? '<a href="' . e($unsub) . '" style="color:#8D8C96">' . th('email.unsubscribe') . '</a> · ' : '';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="dark"></head>'
            . '<body style="margin:0;background:#09090B"><table role="presentation" width="100%" style="background:#09090B"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" style="max-width:480px;background:#141418;border:1px solid #2A2A31;border-radius:22px;overflow:hidden">'
            . '<tr><td style="height:6px;background:#A8DCFF;background-image:' . $holo . '"></td></tr>'
            . '<tr><td style="padding:22px 28px 18px;font:800 22px Arial Narrow,Arial,sans-serif;letter-spacing:-.5px;color:#F3F1EC">' . e(Settings::get('logo_text')) . '</td></tr>'
            . $body
            . '<tr><td style="padding:14px 28px 24px;border-top:1px solid #2A2A31;font:12px/1.5 Arial,sans-serif;color:#8D8C96">' . $foot . e(implode(' · ', array_filter([$c['legal_name'] ?: $store, $c['address'], $c['kvk'] ? 'KvK ' . $c['kvk'] : '', $c['btw'] ? 'BTW ' . $c['btw'] : '']))) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

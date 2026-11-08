<?php
// Legal pages (Privacy, Terms, Returns, Contact): English drafts with [[PLACEHOLDER]] markers, editable in Settings.
defined('DROP01') || exit;

final class Legal {
    public const PAGES = ['privacy' => 'Privacy policy', 'terms' => 'Terms and conditions', 'returns' => 'Returns and withdrawal', 'contact' => 'Contact'];

    /** Returns ['title','body'] with placeholders filled from Settings → Store where known. */
    public static function page(string $slug): ?array {
        if (!isset(self::PAGES[$slug])) return null;
        $html = Settings::get('legal.' . $slug) ?: self::draft($slug);
        return ['title' => t('page.' . $slug), 'body' => self::fill($html), 'draft' => !Settings::get('legal_reviewed')];
    }

    public static function fill(string $html): string {
        $c = Settings::get('company');
        $map = ['[[COMPANY_NAME]]' => $c['legal_name'], '[[KVK]]' => $c['kvk'], '[[BTW]]' => $c['btw'], '[[ADDRESS]]' => $c['address'],
            '[[EMAIL]]' => $c['email'], '[[WHATSAPP]]' => $c['whatsapp'], '[[STORE]]' => Settings::get('store_name')];
        foreach ($map as $k => $v) if ($v !== '' && $v !== null) $html = str_replace($k, e($v), $html);
        return preg_replace('/\[\[([A-Z_]+)\]\]/', '<mark>[[$1]]</mark>', $html);
    }

    public static function draft(string $slug): string {
        switch ($slug) {
            case 'privacy': return <<<HTML
<p><b>Draft. Have this checked before launch.</b></p>
<p>[[STORE]] is run by [[COMPANY_NAME]], [[ADDRESS]], KvK [[KVK]]. We are responsible for your personal data. Questions: [[EMAIL]].</p>
<h2>What we store and why</h2>
<ul>
<li><b>Waitlist:</b> your email address and phone number, the country code you picked, your gift code, the time you joined and how you found us (for example Instagram). We use this to send you one message when the drop opens and to match your gift at checkout. Legal basis: your consent when you join.</li>
<li><b>Orders:</b> name, address, phone, email, what you bought and payment status. We need this to deliver your order and keep our books. Legal basis: the contract with you, and Dutch tax law (we keep invoices for 7 years).</li>
<li><b>Votes on products:</b> a random id stored in your browser, linked to your likes. It is not linked to your name.</li>
<li><b>Visit statistics:</b> we count visits without cookies. We use a code made from your IP address and browser that changes every day and cannot be traced back to you. We do not store your IP address.</li>
</ul>
<h2>Who else sees your data</h2>
<p>Our web host and email provider process data for us. Payments are handled by Mollie B.V. We do not sell your data and we do not use advertising trackers.</p>
<h2>How long we keep it</h2>
<p>Waitlist data until you unsubscribe or until 12 months after the drop. Order data for 7 years (tax law). Visit statistics for 13 months, after that only daily totals.</p>
<h2>Your rights</h2>
<p>You can ask to see, correct, export or delete your data, and you can withdraw consent at any time. Every email has an unsubscribe link. Email [[EMAIL]] and we answer within 30 days. You can also complain to the Autoriteit Persoonsgegevens.</p>
HTML;
            case 'terms': return <<<HTML
<p><b>Draft. Have this checked before launch.</b></p>
<p>These terms apply to every order at [[STORE]], a trade name of [[COMPANY_NAME]], [[ADDRESS]], KvK [[KVK]], BTW [[BTW]].</p>
<h2>Prices and payment</h2>
<p>All prices are in euros and include 21% or 9% BTW. Delivery costs are shown before you pay. You pay online through Mollie (iDEAL, Apple Pay, card) or with a payment link.</p>
<h2>Delivery</h2>
<p>We deliver ourselves in Groningen and nearby villages, in the time window you choose. If nobody is home we contact you to plan a new time.</p>
<h2>Withdrawal</h2>
<p>You have 14 days after delivery to cancel your purchase without giving a reason. See the <a href="/pages/returns">returns page</a>.</p>
<h2>Waitlist gifts</h2>
<p>A waitlist gift is free, one per person, while stocks last, and is packed with your first order. It cannot be exchanged for money.</p>
<h2>Complaints</h2>
<p>Contact us at [[EMAIL]]. We reply within 14 days. You can also use the EU dispute platform: ec.europa.eu/consumers/odr.</p>
HTML;
            case 'returns': return <<<HTML
<p><b>Draft. Have this checked before launch.</b></p>
<h2>14-day right of withdrawal</h2>
<p>You may cancel your order within 14 days after you receive it, without giving a reason. Tell us by email at [[EMAIL]] or via WhatsApp [[WHATSAPP]]. You then have another 14 days to return the items.</p>
<p>We refund the full amount, including the original delivery cost, within 14 days after we receive your cancellation, using the same payment method. If you return items by post, you pay the return cost. In Groningen we can pick them up.</p>
<p>Items must be unused and complete, in their original packaging where possible. You can unpack an item to look at it the way you would in a shop.</p>
<h2>Damaged or wrong item</h2>
<p>Message us within 48 hours with a photo and we will replace or refund it.</p>
<h2>Model withdrawal form</h2>
<p>To [[COMPANY_NAME]], [[ADDRESS]], [[EMAIL]]: I hereby give notice that I withdraw from my contract for the following goods: … Ordered on / received on: … Name: … Address: … Date: …</p>
HTML;
            case 'contact': return <<<HTML
<p><b>[[STORE]]</b> is a trade name of [[COMPANY_NAME]].</p>
<p>Address: [[ADDRESS]]<br>Email: [[EMAIL]]<br>WhatsApp: [[WHATSAPP]]<br>KvK: [[KVK]]<br>BTW: [[BTW]]</p>
<p>We usually answer within a day.</p>
HTML;
        }
        return '';
    }
}

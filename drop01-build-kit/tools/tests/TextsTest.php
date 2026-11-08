<?php
// Texts tests: the catalogue, {words}, **bold** and links, saving and checking the owner's changes, the texts file, and that emails, errors and WhatsApp texts use them.
defined('DROP01') || exit;

function tx_fails(callable $fn, string $needle): void {
    try { $fn(); } catch (InvalidArgumentException $e) { ok(str_contains($e->getMessage(), $needle), "expected “{$needle}”, got “{$e->getMessage()}”"); return; }
    throw new Exception("expected an error with “{$needle}”");
}

function test_texts_catalogue_is_complete(): void {
    $keys = []; $n = 0;
    foreach (Texts::groups() as $g) { $n += count($g['items']); $keys = array_merge($keys, array_keys($g['items'])); }
    eq(count(array_unique($keys)), $n, 'no key is in two groups');
    // every literal key the code asks for exists (catches typos in t("…") / th('…'))
    $missing = [];
    $files = array_merge(glob(ROOT . '/app/{lib,controllers,views}/*.php', GLOB_BRACE), [ROOT . '/assets/shop/shop.js', ROOT . '/assets/waitlist/waitlist.js']);
    foreach ($files as $f) {
        preg_match_all('/\b(?:t|th|Texts::(?:plain|html|items))\(\s*["\']([a-z_]+(?:\.[a-z0-9_]+)+)["\']\s*[,)]/', file_get_contents($f), $m);
        foreach ($m[1] as $k) if (!isset(Texts::catalog()[$k])) $missing[] = basename($f) . ": $k";
    }
    eq($missing, [], 'texts used in code but missing from app/texts.php');
    // the keys built from parts
    foreach (['days', 'hrs', 'min', 'sec'] as $u) ok(isset(Texts::catalog()["shop.timer.$u"]), "shop.timer.$u");
    foreach (array_keys(Legal::PAGES) as $p) ok(isset(Texts::catalog()["page.$p"]), "page.$p");
    foreach (['out', 'way', 'delivered', 'missed', 'moved'] as $k) foreach (['subject', 'heading', 'text'] as $part) ok(isset(Texts::catalog()["email.$k.$part"]), "email.$k.$part");
}

function test_texts_format_words_bold_links(): void {
    eq(t('shop.hero.cta'), 'Shop the drop ↓');
    eq(t('shop.hero.live', ['store' => 'Drop 01', 'left' => 12]), 'Drop 01 is live · 12 pieces left');
    eq(th('shop.promise.where_zone', ['zone' => 'A<b>']), ' in <b>A&lt;b&gt;</b>', 'vars are escaped, **x** is bold');
    eq(th('wl.back', ['email' => '<b id="e"></b>'], ['email']), '← <b id="e"></b> · change', 'raw vars stay HTML');
    eq(th('shop.pc.no', [], [], ' class="x"'), 'Not in our area yet. <a href="#list" class="x">Tell me when you do</a>');
    eq(t('shop.pc.no'), 'Not in our area yet. Tell me when you do', 'plain text drops a relative link');
    eq(Texts::fillPlain('See [returns]({u}) or [x](/a)', ['u' => 'https://s.nl/r']), 'See returns (https://s.nl/r) or x', 'plain text keeps a full link');
    eq(Texts::format('[bad](javascript:alert(1)) [ok](https://a.nl/?a=1&b=2)'), '[bad](javascript:alert(1)) <a href="https://a.nl/?a=1&amp;b=2">ok</a>', 'only safe links');
    eq(Texts::format("a\n<script>"), 'a<br>&lt;script&gt;');
    eq(t('no.such.key'), 'no.such.key');
    eq(Delivery::dayName(gmdate('Y-m-d', time() + 3600)) !== '', true);
}

function test_texts_save_checks_and_original(): void {
    eq(Texts::save(['shop.hero.cta' => '  Shop now ↓ ']), 1);
    eq(t('shop.hero.cta'), 'Shop now ↓', 'saved and trimmed');
    eq(Texts::forAdmin()['changed'], 1);
    Texts::save(['shop.hero.cta' => '']);
    eq(t('shop.hero.cta'), 'Shop the drop ↓', 'empty brings the original back');
    Texts::save(['shop.hero.cta' => 'X']); Texts::save(['shop.hero.cta' => 'Shop the drop ↓']);
    eq(Texts::overrides(), [], 'the original is not stored as a change');
    // words the shop does not know, too long, glued spaces
    tx_fails(fn() => Texts::save(['shop.hero.live' => '{store} has {stock} left']), '{stock} is not known');
    tx_fails(fn() => Texts::save(['shop.hero.cta' => 'Go {store}']), 'has no {words}');
    tx_fails(fn() => Texts::save(['wl.h1.1' => 'Supercalifragilistic']), 'at most 14');
    tx_fails(fn() => Texts::save(['nope.key' => 'x']), 'Unknown text');
    Texts::save(['shop.promise.where_city' => 'in Haren']);
    eq(t('shop.promise.where_city'), ' in Haren', 'a glued text keeps its leading space');
    Texts::save(['shop.hero.live' => "{store}\nis open"]);
    eq(t('shop.hero.live', ['store' => 'S']), 'S is open', 'one-line texts lose their line breaks');
    // all or nothing
    tx_fails(fn() => Texts::save(['shop.hero.cta' => 'Fine', 'shop.hero.title' => '{nope}']), 'not known');
    eq(t('shop.hero.cta'), 'Shop the drop ↓', 'a failed save changes nothing');
    // questions: empty rows dropped, order kept, {words} checked, back to original
    Texts::save(['shop.faq' => [['Open on Sunday?', 'Yes, from {cutoff}.'], ['', ''], ['Gift wrap?', '']]]);
    eq(Texts::items('shop.faq'), [['Open on Sunday?', 'Yes, from {cutoff}.'], ['Gift wrap?', '']]);
    tx_fails(fn() => Texts::save(['shop.faq' => [['Q', '{price}']]]), '{price} is not known');
    tx_fails(fn() => Texts::save(['shop.faq' => [['', 'An answer without a question']]]), 'question is empty');
    Texts::save(['shop.faq' => []]);
    eq(Texts::items('shop.faq'), [], 'all questions removed hides the block');
    Texts::save(['shop.faq' => null]);
    eq(count(Texts::items('shop.faq')), 4, 'null brings the original questions back');
}

function test_texts_reach_emails_errors_and_whatsapp(): void {
    Texts::save(['email.restock.subject' => '{product} is terug · {store}', 'email.restock.heading' => 'Hij is er weer.', 'err.code_empty' => 'Vul een code in.',
        'wa.delivered' => 'Hoi {first}! Order #{number} is bezorgd.', 'common.today' => 'Vandaag']);
    [$subject, $html, $text] = EmailTemplates::render('back_in_stock', ['product' => 'Book', 'url' => 'https://shop.test/p/book']);
    eq($subject, 'Book is terug · Drop 01');
    ok(str_contains($html, 'Hij is er weer.') && str_contains($text, 'Hij is er weer.'), 'heading in html and text');
    ok(str_contains($html, '<b>Book</b>') && str_contains($text, 'Book is back in stock'), 'untouched paragraph still original, bold in HTML only');
    try { Discounts::resolve('', null, null, 1000); throw new Exception('no error'); } catch (RuntimeException $e) { eq($e->getMessage(), 'Vul een code in.'); }
    eq(Orders::waText(['customer_name' => 'Anna de Vries', 'number' => 1062, 'slot_label' => 'x', 'total_cents' => 0], 'delivered'), 'Hoi Anna! Order #1062 is bezorgd.');
    eq(Delivery::dayName((new DateTime('now', new DateTimeZone(TZ_LOCAL)))->format('Y-m-d')), 'Vandaag');
    // the order confirmation keeps its links in the HTML and spells them out in the text part
    Texts::resetAll();
    [, $html, $text] = EmailTemplates::render('waitlist_signup', ['code' => 'D01-AB12', 'position' => 7, 'drop' => 'Fri 27 Nov', 'gift_title' => 'A gift', 'gift_text' => 'On us.', 'unsubscribe' => 'https://shop.test/u']);
    ok(str_contains($html, '<b>A gift</b><br>On us.'), 'gift lines in HTML');
    ok(str_contains($text, "Your gift code: D01-AB12") && str_contains($text, 'Unsubscribe: https://shop.test/u'), 'text part has the code and unsubscribe line');
}

function test_texts_file_round_trip(): void {
    $x = Texts::export();
    eq($x['format'], Texts::FORMAT);
    ok($x['count'] > 300, 'the file holds every text');
    $flatCount = array_sum(array_map('count', $x['texts']));
    eq($flatCount, $x['count']);
    // the owner changes two texts in the file, one with a mistake, and uploads it
    $sec = array_key_first(array_filter($x['texts'], fn($s) => isset($s['shop.hero.cta'])));
    $x['texts'][$sec]['shop.hero.cta'] = 'Bekijk de drop ↓';
    $x['texts'][$sec]['shop.hero.live'] = '{store} heeft nog {stock} stuks';
    $r = Texts::import(json_decode(json_encode($x), true));
    eq([$r['changed'], count($r['skipped'])], [1, 1]);
    eq($r['skipped'][0][0], 'shop.hero.live');
    eq(t('shop.hero.cta'), 'Bekijk de drop ↓');
    // a flat file with only a few lines works too, other texts stay as they are
    $r = Texts::import(['shop.list.button' => 'Doe mee', 'unknown.key' => 'x']);
    eq($r['changed'], 1);
    eq(t('shop.hero.cta'), 'Bekijk de drop ↓', 'texts missing from the file are kept');
    tx_fails(fn() => Texts::import(['hello' => 'world']), 'no texts');
    Texts::resetAll();
    eq(Texts::overrides(), []);
}

<?php
// Shop page: the approved prototype (prototypes/shop.html). Products, stock, zones, slots and company details come from the database.
defined('DROP01') || exit;
$store = Settings::get('store_name');
$logo = Settings::get('logo_text');
[$l1, $l2] = array_pad(explode('/', $logo, 2), 2, null);
$d = Settings::get('delivery');
$wins = $d['windows'];
$evening = end($wins) ?: ['start' => '18:00', 'end' => '21:00'];
$cut = (new DateTime('today ' . $evening['start']))->modify('-' . (int) $d['cutoff_min'] . ' minutes')->format('H:i');
$free = eur((int) Settings::get('shop')['free_from_cents']);
// delivery fees in words, e.g. "€3,95 below that. Haren: €5,95"
$byFee = [];
foreach ($cfg['zones'] as $z) $byFee[$z['fee']][] = $z['name'];
arsort($byFee);
$feeText = '';
$i = 0;
foreach ($byFee as $fee => $names) { $feeText .= $i++ === 0 ? t('shop.faq.fee_below', ['fee' => eur((int) $fee)]) : ' ' . implode(', ', array_slice($names, 0, 3)) . ': ' . eur((int) $fee) . '.'; }
$c = $cfg['company'];
$wa = $c['whatsapp'] ? 'https://wa.me/' . preg_replace('/\D/', '', $c['whatsapp']) : null;
$ann = array_map('e', $cfg['announce']);
$faqVars = ['store' => $store, 'cutoff' => $cut, 'evening' => $evening['start'] . '–' . $evening['end'], 'days_ahead' => (int) $d['days_ahead'], 'free_from' => $free, 'fees' => $feeText];
$link = ' style="color:var(--ink)"';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B0B0F">
<title><?= e(t('shop.title', ['store' => $store])) ?></title>
<meta name="description" content="<?= e(t('shop.meta', ['store' => $store, 'cutoff' => $cut])) ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/icon-180.png">
<link rel="stylesheet" href="<?= View::asset('fonts/fonts.css') ?>">
<link rel="stylesheet" href="<?= View::asset('shop/shop.css') ?>">
<link rel="stylesheet" href="<?= View::asset('shop/shop-live.css') ?>">
</head>
<body>
<div class="app">
  <div class="ann"><div><?php for ($k = 0; $k < 2; $k++) foreach ($ann as $a) echo "<span>$a</span>"; ?></div></div>
  <header class="top">
    <button class="mark" data-act="home"><?= e($l1) ?><?php if ($l2 !== null): ?>/<span><?= e($l2) ?></span><?php endif ?></button>
    <span class="where" id="where"></span>
    <button class="ib first" aria-label="Search" data-act="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg></button>
    <button class="ib" id="bagBtn" aria-label="Bag" data-act="cart"><svg viewBox="0 0 24 24"><path d="M6 7h12l-1 13H7zM9 7a3 3 0 0 1 6 0"/></svg><b id="cartN">0</b></button>
  </header>

  <!-- ================= HOME ================= -->
  <main class="view on" id="v-home">
    <div class="hero">
      <span class="mono live"><i></i><?= th('shop.hero.live', ['store' => $store, 'left' => '<span id="leftAll"></span>'], ['left']) ?></span>
      <h1 class="display"><?= th('shop.hero.title', ['store' => $store]) ?><br><span class="holo-t"><?= th('shop.hero.title_foil', ['store' => $store]) ?></span></h1>
      <div class="stage" id="stage"><div class="fan" id="fan"></div><div class="dots" id="dots"></div></div>
      <div class="promise"><svg class="ic" viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg>
        <div id="promise"></div></div>
      <button class="cta" data-act="toShop"><?= th('shop.hero.cta') ?></button>
      <div class="timer" id="timer" hidden></div>
      <div class="trust">
        <div><svg class="ic" viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg><?= th('shop.trust.1') ?></div>
        <div><svg class="ic" viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.6M4 4v4h4"/></svg><?= th('shop.trust.2') ?></div>
        <div><svg class="ic" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><?= th('shop.trust.3') ?></div>
      </div>
    </div>

    <div class="card pc">
      <h3><?= th('shop.pc.title') ?></h3>
      <div class="sub"><?= th('shop.pc.sub') ?></div>
      <div class="pcrow"><input class="field" id="pcIn" placeholder="<?= e(t('shop.pc.placeholder')) ?>" maxlength="7" autocomplete="postal-code" inputmode="text" aria-label="Postcode"><button class="btn" data-act="checkPc"><?= th('shop.pc.button') ?></button></div>
      <div class="pcres" id="pcRes" hidden></div>
    </div>

    <div class="sec" id="shop"><h2><?= th('shop.grid.title') ?></h2><span class="sub" id="countLbl"></span></div>
    <div class="pcrow" id="searchRow" hidden style="margin:0 16px 10px"><input class="field" id="q" type="search" placeholder="<?= e(t('shop.grid.search')) ?>" aria-label="Search products"></div>
    <div class="chips" id="cats"></div>
    <div class="grid" id="grid"></div>

    <div class="bundle" id="bundle" hidden>
      <span class="mono"><?= th('shop.bundle.kicker') ?></span>
      <h3 id="bName">The full drop</h3>
      <div class="arts" id="bArts"></div>
      <div class="pr"><b class="num" id="bPrice"></b><s class="num" id="bWas"></s><span class="save" id="bSave"></span></div>
      <p class="sub" style="margin:0 0 14px" id="bText"></p>
      <button class="cta" id="bAdd"><?= th('shop.bundle.button') ?></button>
    </div>

    <div class="sec"><h2><?= th('shop.how.title') ?></h2></div>
    <div class="how">
      <div class="card"><div class="k">1</div><div><b><?= th('shop.how.1', ['cutoff' => $cut]) ?></b><span class="sub"><?= th('shop.how.1_sub') ?></span></div></div>
      <div class="card"><div class="k">2</div><div><?php if ($cfg['payOnline']): ?><b><?= th('shop.how.2_online') ?></b><span class="sub"><?= th('shop.how.2_online_sub') ?></span><?php else: ?><b><?= th('shop.how.2') ?></b><span class="sub"><?= th('shop.how.2_sub') ?></span><?php endif ?></div></div>
      <div class="card"><div class="k">3</div><div><b><?= th('shop.how.3') ?></b><span class="sub"><?= th('shop.how.3_sub') ?></span></div></div>
    </div>

    <?php if ($faq = Texts::items('shop.faq')): ?><div class="sec"><h2><?= th('shop.faq.title') ?></h2></div>
    <?php foreach ($faq as [$q, $a]): ?><details class="faq"><summary><?= e(Texts::fillPlain($q, $faqVars)) ?></summary><p><?= Texts::format($a, $faqVars, [], $link) ?></p></details>
    <?php endforeach; endif ?>

    <div class="card wl"><span class="mono"><?= th('shop.list.kicker') ?></span><h3><?= th('shop.list.title') ?></h3><p class="sub" style="margin:0 0 12px"><?= th('shop.list.sub') ?></p>
      <form class="pcrow" style="margin:0" id="listForm"><input class="field" type="email" id="listEmail" placeholder="<?= e(t('shop.list.placeholder')) ?>" aria-label="Email" required><button class="btn" type="submit"><?= th('shop.list.button') ?></button></form></div>

    <footer>
      <div class="display holo-t"><?= e($logo) ?></div>
      <div class="paylogos"><?php if ($cfg['payOnline']): ?><span>iDEAL</span><span>Apple Pay</span><span>VISA</span><span>Mastercard</span><?php endif ?><span>Tikkie</span><span>iDEAL via Tikkie</span></div>
      <p class="sub" style="line-height:1.6"><?= e(implode(' · ', array_filter([t('shop.footer.city'), $c['name'], $c['kvk'] ? 'KvK ' . $c['kvk'] : '', $c['btw'] ? 'BTW ' . $c['btw'] : '']))) ?><br>
        <a href="/pages/terms"><?= th('shop.footer.terms') ?></a> · <a href="/pages/privacy"><?= th('shop.footer.privacy') ?></a> · <a href="/pages/returns"><?= th('shop.footer.returns') ?></a> · <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= th('shop.footer.whatsapp') ?></a><?php else: ?><a href="/pages/contact"><?= th('shop.footer.contact') ?></a><?php endif ?></p>
      <?php if (!empty($cfg['testMode']) && $cfg['payOnline']): ?><p class="mono" style="margin-top:16px"><?= th('shop.footer.test') ?></p><?php endif ?>
    </footer>
  </main>

  <main class="view pp" id="v-product"></main>
  <main class="view" id="v-checkout"></main>
  <main class="view" id="v-ok"></main>
</div>

<div class="bagbar" id="bagbar"><div class="in"><div class="row"><div class="t" id="bbText"></div><button class="btn" data-act="cart"><?= th('shop.bagbar.button') ?></button></div><div class="track" id="bbTrack"><i></i></div></div></div>
<div class="proof" id="proof" aria-live="polite"></div>
<div class="scrim" id="scrim"></div>
<aside class="drawer" id="drawer" aria-label="Bag">
  <div class="hd"><h3 id="drawerTitle"><?= th('shop.bag.title') ?></h3><button class="ib" data-act="closeCart" aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
  <div class="bd" id="cartBody"></div>
  <div class="ft" id="cartFoot"></div>
</aside>
<canvas id="fx"></canvas>
<div class="toast" id="toast" role="status"></div>

<script nonce="<?= Http::nonce() ?>">window.SHOP = <?= View::json($cfg) ?>; window.SHOP_START = <?= View::json((object) $start) ?>;</script>
<script src="<?= View::asset('shop/shop.js') ?>"></script>
</body>
</html>

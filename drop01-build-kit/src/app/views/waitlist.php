<?php
// Waitlist page: the approved prototype (prototypes/waitlist.html) with settings, products and counters rendered from the database.
defined('DROP01') || exit;
$store = Settings::get('store_name');
$logo = Settings::get('logo_text');
[$l1, $l2] = array_pad(explode('/', $logo, 2), 2, null);
$logoHtml = e($l1) . ($l2 !== null ? '<i>/' . e($l2) . '</i>' : '');
$logoSpaced = str_replace('/', ' ', $logo);
$first = $cfg['products'][0]['image'] ?? '';
$giftLow = mb_strtolower($cfg['gift']['title']);
$tv = ['store' => $store, 'gift' => $giftLow];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#09090B">
<title><?= e(t('wl.title', $tv)) ?></title>
<meta name="description" content="<?= e(t('wl.meta', $tv)) ?>">
<meta property="og:title" content="<?= e(t('wl.og_title', $tv)) ?>">
<meta property="og:description" content="<?= e(t('wl.og_text', $tv)) ?>">
<meta property="og:type" content="website">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/icon-180.png">
<link rel="preload" href="<?= View::asset('fonts/fonts.css') ?>" as="style">
<link rel="stylesheet" href="<?= View::asset('fonts/fonts.css') ?>">
<link rel="stylesheet" href="<?= View::asset('waitlist/waitlist.css') ?>">
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
  <linearGradient id="holoGrad" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#FFB3D9"/><stop offset=".25" stop-color="#FFE7A3"/><stop offset=".5" stop-color="#B8FFD9"/><stop offset=".75" stop-color="#A8DCFF"/><stop offset="1" stop-color="#D4B8FF"/>
  </linearGradient>
  <linearGradient id="heartGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFB3D9"/><stop offset=".5" stop-color="#FFE7A3"/><stop offset="1" stop-color="#A8DCFF"/></linearGradient>
  <symbol id="i-gift" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"><rect x="3.5" y="9" width="17" height="11.5" rx="1.5"/><path d="M2.5 9h19M12 9v11.5M12 9c-1.5-3.5-6-4-6-1.5S10 9 12 9Zm0 0c1.5-3.5 6-4 6-1.5S14 9 12 9Z"/></g></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></g></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></g></symbol>
  <symbol id="i-chat" viewBox="0 0 24 24"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.3L4 20Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
  <symbol id="i-share" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3.5M7.5 8 12 3.5 16.5 8"/><path d="M5 12.5v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></g></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
  <symbol id="i-hand" viewBox="0 0 24 24"><path d="M9 11V5.5a1.5 1.5 0 0 1 3 0V10m0-1.5a1.5 1.5 0 0 1 3 0V11m0-1a1.5 1.5 0 0 1 3 0v4.5a6 6 0 0 1-6 6h-1.2a5 5 0 0 1-3.9-1.9L4.4 15a1.6 1.6 0 0 1 2.4-2.1L9 15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-up" viewBox="0 0 24 24"><path d="M7 10.5V20H4.5a1 1 0 0 1-1-1v-7.5a1 1 0 0 1 1-1H7Zm0 0 3.6-6.3a1.9 1.9 0 0 1 3.5 1.3l-.6 3.5h5.2a2 2 0 0 1 2 2.4l-1.3 6.8A2.2 2.2 0 0 1 17.2 20H7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
  <symbol id="i-down" viewBox="0 0 24 24"><path d="M17 13.5V4h2.5a1 1 0 0 1 1 1v7.5a1 1 0 0 1-1 1H17Zm0 0-3.6 6.3a1.9 1.9 0 0 1-3.5-1.3l.6-3.5H5.3a2 2 0 0 1-2-2.4l1.3-6.8A2.2 2.2 0 0 1 6.8 4H17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
  <symbol id="i-heart" viewBox="0 0 24 24"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2.1 0 3.6 1.2 5 3 1.4-1.8 2.9-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2Z"/></symbol>
  <symbol id="i-cal" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/></g></symbol>
</defs></svg>

<canvas id="ambient" aria-hidden="true"></canvas>

<div class="tones" aria-hidden="true"><i class="tn warm"></i><i class="tn cool"></i><i class="tn violet"></i></div>
<div class="wrap" id="wrap">
  <header>
    <div class="logo display"><?= $logoHtml ?></div>
    <div class="hdr-r">
      <div class="status"><span class="dot"></span><span id="status-text"><?= th('wl.status') ?></span></div>
      <button type="button" class="snd" id="snd" aria-label="Sound on" aria-pressed="true"><svg viewBox="0 0 24 24"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor"/><path class="w1" d="M15.5 9a4 4 0 0 1 0 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path class="w2" d="M18 6.5a7.5 7.5 0 0 1 0 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path class="x" d="M16 9.5l5 5m0-5-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    </div>
  </header>

  <section class="hero">
    <div class="hero-copy">
      <div class="kicker"><span><?= th('wl.kicker', $tv) ?></span><span class="proof" id="proof"></span></div>
      <h1 class="display" id="h1"><span class="line"><span><?= e(t('wl.h1.1', $tv)) ?></span></span><span class="line"><span><?= e(t('wl.h1.2', $tv)) ?></span></span><span class="line drop-line"><span class="drops" id="drops"><?= e(t('wl.h1.3', $tv)) ?></span></span></h1>
      <p class="lead" id="lead"></p>
    </div>

    <div class="stage" id="stage">
      <div class="aura" aria-hidden="true"></div>
      <div class="spill" aria-hidden="true"></div>
      <div class="stage-col">
        <div class="ticket" id="ticket">
          <div class="badge" aria-hidden="true">
            <svg class="badge-ring" viewBox="0 0 120 120">
              <circle cx="60" cy="60" r="58" fill="url(#holoGrad)"/>
              <circle cx="60" cy="60" r="58" fill="none" stroke="rgba(18,18,22,.25)"/>
              <path id="ring" d="M60,60 m-44,0 a44,44 0 1,1 88,0 a44,44 0 1,1 -88,0" fill="none"/>
              <text><textPath href="#ring" textLength="272" lengthAdjust="spacing"><?= e(t('wl.badge', $tv)) ?></textPath></text>
            </svg>
            <div class="badge-core"><svg><use href="#i-gift"/></svg></div>
          </div>
          <div class="foil"><canvas class="foil-gl" id="foil-gl" aria-hidden="true"></canvas>
            <div class="foil-top"><small><?= th('wl.admit', $tv) ?></small><small class="foil-cd" id="foil-cd"></small></div>
            <div class="admit display"><?= e($logoSpaced) ?></div>
            <div class="emboss" id="emboss" aria-hidden="true">•••• •••• •••• ••••</div>
            <div class="gift-line"><span id="gift-for"><?= th('wl.gift_for', $tv) ?></span><strong id="gift-title"></strong></div>
            <div class="stamp" id="stamp" aria-hidden="true"><?= th('wl.stamp') ?></div>
            <span class="punch" id="punch" aria-hidden="true"></span>
          </div>
          <div class="perf"><i></i><i></i></div>
          <div class="body" id="ticket-body"><span class="beam" aria-hidden="true"></span>
            <form id="form" novalidate>
              <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Website <input name="website" id="website" tabindex="-1" autocomplete="off"></label></div>
              <div class="steps" aria-hidden="true"><i class="on" id="dot1"></i><i id="dot2"></i><span id="step-txt"><?= th('wl.step1') ?></span></div>

              <div class="step" id="step1">
                <div class="field">
                  <label for="email"><?= th('wl.email_label', $tv) ?></label>
                  <input class="ctl" id="email" name="email" type="email" inputmode="email" placeholder="<?= e(t('wl.email_ph')) ?>" autocomplete="email" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="next">
                  <div class="chips" id="chips" hidden></div>
                  <button type="button" class="suggest" id="suggest" hidden></button>
                  <span class="hint" id="email-hint" role="alert"></span>
                </div>
                <button class="btn" type="button" id="next"><span><?= th('wl.continue') ?></span><svg><use href="#i-arrow"/></svg></button>
              </div>

              <div class="step" id="step2" hidden>
                <button type="button" class="back" id="back"><?= th('wl.back', ['email' => '<b id="email-echo"></b>'], ['email']) ?></button>
                <div class="field">
                  <label for="phone"><?= th('wl.phone_label', $tv) ?></label>
                  <div class="phone-row">
                    <select class="ctl" id="cc" aria-label="Country code"></select>
                    <input class="ctl" id="phone" name="tel" type="tel" inputmode="tel" placeholder="<?= e(t('wl.phone_ph')) ?>" autocomplete="tel-national" enterkeyhint="done">
                  </div>
                  <span class="hint" id="phone-hint" role="alert"></span>
                </div>
                <button class="btn" type="submit" id="submit"><span id="submit-label"><?= th('wl.submit', $tv) ?></span><span id="submit-icon"><svg><use href="#i-gift"/></svg></span></button>
              </div>

              <p class="nudge-hint" id="nudge-hint" hidden></p>
              <div class="scarce" id="scarce"></div>
              <p class="fine" id="consent-text"><?= th('wl.consent', $tv, [], ' style="color:inherit"') ?></p>
            </form>

            <div class="done" id="done" hidden>
              <button type="button" class="gift" id="gift-btn" aria-label="Open your gift">
                <div class="rays"></div><div class="glow"></div><div class="pillar"></div>
                <div class="boxwrap">
                  <div class="mcard"><span class="display">?</span><small><?= th('wl.done.mystery') ?></small></div>
                  <svg class="boxsvg" viewBox="0 0 140 130" aria-hidden="true">
                    <defs>
                      <linearGradient id="bxG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFB3D9"/><stop offset=".3" stop-color="#FFE7A3"/><stop offset=".55" stop-color="#B8FFD9"/><stop offset=".8" stop-color="#A8DCFF"/><stop offset="1" stop-color="#D4B8FF"/></linearGradient>
                      <linearGradient id="bxS" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".28"/></linearGradient>
                      <linearGradient id="bxL" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".5" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient>
                      <linearGradient id="rbG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFFFFF"/><stop offset=".55" stop-color="#F1EEF6"/><stop offset="1" stop-color="#D9D4E4"/></linearGradient>
                      <filter id="bxB" x="-50%" y="-200%" width="200%" height="500%"><feGaussianBlur stdDeviation="4"/></filter>
                    </defs>
                    <g class="bodyg">
                      <rect x="20" y="62" width="100" height="62" rx="6" fill="url(#bxG)"/>
                      <rect x="20" y="62" width="100" height="62" rx="6" fill="url(#bxS)"/>
                      <rect x="20" y="62" width="100" height="5" fill="#000" opacity=".18"/>
                      <rect x="63" y="62" width="14" height="62" fill="url(#rbG)"/>
                      <rect x="63" y="62" width="1.2" height="62" fill="#000" opacity=".12"/><rect x="75.8" y="62" width="1.2" height="62" fill="#000" opacity=".12"/>
                    </g>
                    <ellipse class="inner" cx="70" cy="63" rx="44" ry="7" fill="#FFF8E0" filter="url(#bxB)"/>
                    <g class="lidg">
                      <rect x="12" y="45" width="116" height="20" rx="5" fill="url(#bxG)"/>
                      <rect x="12" y="45" width="116" height="20" rx="5" fill="url(#bxL)"/>
                      <rect x="12" y="62" width="116" height="3" fill="#000" opacity=".12"/>
                      <rect x="63" y="45" width="14" height="20" fill="url(#rbG)"/>
                      <path d="M70 45C57 21 35 23 41 37c4 9 18 10 29 8Z" fill="url(#rbG)" stroke="rgba(0,0,0,.14)" stroke-width="1"/>
                      <path d="M70 45c13-24 35-22 29-8-4 9-18 10-29 8Z" fill="url(#rbG)" stroke="rgba(0,0,0,.14)" stroke-width="1"/>
                      <path d="M52 33c5 2 10 6 14 10M88 33c-5 2-10 6-14 10" stroke="rgba(0,0,0,.12)" stroke-width="1.4" fill="none" stroke-linecap="round"/>
                      <path d="M66 46l-7 13M74 46l7 13" stroke="url(#rbG)" stroke-width="5" stroke-linecap="round"/>
                      <circle cx="70" cy="44" r="6" fill="url(#rbG)" stroke="rgba(0,0,0,.14)" stroke-width="1"/>
                    </g>
                  </svg>
                  <div class="leak"></div>
                </div>
                <i class="spark" style="--dx:-38px;animation-delay:.6s"></i><i class="spark" style="--dx:30px;animation-delay:.7s"></i>
                <i class="spark" style="--dx:-12px;animation-delay:.85s"></i><i class="spark" style="--dx:44px;animation-delay:.95s"></i><i class="spark" style="--dx:6px;animation-delay:1.05s"></i>
              </button>
              <span class="open-hint"><?= th('wl.done.open') ?></span>
              <h2 class="display rv" style="animation-delay:.6s"><?= th('wl.done.title', $tv) ?></h2>
              <p class="gift-name rv" id="gift-name" style="animation-delay:.72s"></p>
              <p class="rv" id="gift-text" style="animation-delay:.8s"></p>
              <div class="code-box rv" style="animation-delay:.9s">
                <div class="code-wrap"><small><?= th('wl.done.code') ?></small><div class="code" id="code">GIFT-·····</div>
                  <canvas class="scratch" id="scratch" aria-label="Scratch to reveal your gift code"></canvas>
                  <span class="scratch-hint" id="scratch-hint"><svg><use href="#i-hand"/></svg><?= th('wl.done.scratch') ?></span>
                </div>
                <button type="button" class="ghost" id="copy-code"><svg><use href="#i-copy"/></svg><span id="copy-label"><?= th('wl.done.copy') ?></span></button>
              </div>
              <p class="rv" id="done-text" style="animation-delay:1s"></p>
              <a class="ghost wide rv" id="cal" target="_blank" rel="noopener" style="animation-delay:1.1s"><svg><use href="#i-cal"/></svg><?= th('wl.done.calendar', $tv) ?></a>
              <div class="share rv" style="animation-delay:1.2s">
                <b><?= th('wl.done.share_title', $tv) ?></b>
                <button type="button" class="btn share-main" id="share-native"><svg><use href="#i-share"/></svg><span><?= th('wl.done.share', $tv) ?></span></button>
                <div class="share-row">
                  <a class="ghost" id="share-wa" target="_blank" rel="noopener"><svg><use href="#i-chat"/></svg><?= th('wl.done.share_wa') ?></a>
                  <button type="button" class="ghost" id="share-copy"><svg><use href="#i-copy"/></svg><?= th('wl.done.share_copy') ?></button>
                </div>
              </div>
              <button type="button" class="reset rv" id="reset" style="animation-delay:1.3s"><?= th('wl.done.reset') ?></button>
            </div>
            <div class="barcode" aria-hidden="true"></div>
          </div>
        </div>
        <div class="refl" aria-hidden="true"></div>
        <div class="trust">
          <span><svg><use href="#i-lock"/></svg><?= th('wl.trust.1', $tv) ?></span>
          <span><svg><use href="#i-x"/></svg><?= th('wl.trust.2', $tv) ?></span>
        </div>
      </div>
    </div>
      <div class="count-wrap">
        <div class="count-label"><span><?= th('wl.count.opens', $tv) ?></span><b id="drop-date"></b></div>
        <div class="count" id="count">
          <div class="unit"><span class="n display flip" id="cd-d"></span><small><?= th('wl.count.days') ?></small></div>
          <div class="unit"><span class="n display flip" id="cd-h"></span><small><?= th('wl.count.hours') ?></small></div>
          <div class="unit"><span class="n display flip" id="cd-m"></span><small><?= th('wl.count.min') ?></small></div>
          <div class="unit"><span class="n display flip" id="cd-s"></span><small><?= th('wl.count.sec') ?></small></div>
        </div>
      </div>

  </section>

  <div class="marquee-clip" aria-hidden="true">
    <div class="marquee"><div class="marquee-track display" id="marquee"></div></div>
    <div class="marquee alt"><div class="marquee-track display" id="marquee2"></div></div>
  </div>

  <section class="manifesto" aria-label="About the drop">
    <p class="display" id="manifesto"><?= preg_replace('/~~(.+?)~~/u', '<span class="vanish">$1</span>', str_replace(['<b>', '</b>'], ['<em>', '</em>'], th('wl.manifesto', $tv))) ?></p>
  </section>

  <section>
    <div class="drop-head rise">
      <h2 class="display"><?= th('wl.drop.title', $tv) ?></h2>
      <p><?= th('wl.drop.text', $tv) ?></p>
    </div>
    <div class="grid" id="grid"></div>
  </section>

  <section>
    <h2 class="how-head display rise"><?= th('wl.road.title', $tv) ?></h2>
    <ol class="tl" id="tl">
      <li class="tl-i"><span class="tl-dot"></span><div class="tl-c"><small id="tl-d0"><?= th('wl.road.1_when') ?></small><b><?= th('wl.road.1', $tv) ?></b><p><?= th('wl.road.1_text', $tv) ?></p></div></li>
      <li class="tl-i"><span class="tl-dot"></span><div class="tl-c"><small id="tl-d1"></small><b><?= th('wl.road.2', $tv) ?></b><p><?= th('wl.road.2_text', $tv) ?></p></div></li>
      <li class="tl-i"><span class="tl-dot"></span><div class="tl-c"><small id="tl-d2"></small><b><?= th('wl.road.3', $tv) ?></b><p><?= th('wl.road.3_text', $tv) ?></p></div></li>
      <li class="tl-i"><span class="tl-dot"></span><div class="tl-c"><small><?= th('wl.road.4_when') ?></small><b><?= th('wl.road.4', $tv) ?></b><p><?= th('wl.road.4_text', $tv) ?></p></div></li>
    </ol>
  </section>

  <?php if ($faq = Texts::items('wl.faq')): ?><section>
    <h2 class="how-head display rise"><?= th('wl.faq.title') ?></h2>
    <div class="faq" id="faq">
      <?php foreach ($faq as [$q, $a]): ?><details class="rise"><summary><?= e(Texts::fillPlain($q, $tv)) ?></summary><p><?= Texts::format($a, $tv, [], ' style="color:inherit"') ?></p></details>
      <?php endforeach ?>
    </div>
  </section><?php endif ?>

  <section class="final rise" id="final">
    <small class="admit-s"><?= th('wl.admit', $tv) ?></small>
    <h2 class="display"><?= th('wl.final.title', $tv) ?></h2>
    <p id="final-text"></p>
    <div class="mini"><?= th('wl.final.opens', $tv) ?> <b id="final-cd"></b></div>
    <button class="btn" type="button" id="final-btn"><svg><use href="#i-gift"/></svg><span><?= th('wl.final.button', $tv) ?></span></button>
    <div class="final-stub" id="final-stub" aria-hidden="true">
      <span class="final-perf"><i></i><i></i></span>
      <span class="barcode final-bc"></span>
    </div>
  </section>

  <footer>
    <div class="wordmark display" id="wordmark" aria-hidden="true"><span class="wm-o"><?= e($logo) ?></span><span class="wm-f"><?= e($logo) ?></span></div>
    <div class="foot-row">
      <span id="foot-name"></span>
      <button type="button" class="totop" id="totop"><?= th('wl.foot.top') ?> <svg><use href="#i-arrow"/></svg></button>
    </div>
    <p class="foot-note"><?= th('wl.foot.note', $tv) ?></p>
    <p class="foot-note"><?php foreach (array_keys(Legal::PAGES) as $slug): ?><a href="/pages/<?= $slug ?>" style="color:inherit;margin-right:14px"><?= th('page.' . $slug) ?></a><?php endforeach ?></p>
  </footer>
</div>

<div class="scene" id="scene" aria-hidden="true"></div>
<div class="hcard-wrap" id="hcard-wrap" hidden>
  <div class="hcard" id="hcard">
    <div class="hc-face hc-back"><span class="display"><?= e($logo) ?></span></div>
    <div class="hc-face hc-front"><canvas class="hc-gl" id="hc-gl" aria-hidden="true"></canvas>
      <small><?= th('wl.card.small', $tv) ?></small>
      <span class="hc-q display">?</span>
      <b class="hc-name" id="hc-name"><?= th('wl.card.name', $tv) ?></b>
      <i><?= th('wl.card.ships', $tv) ?></i>
    </div>
  </div>
  <span class="hc-tap"><?= th('wl.card.tap', $tv) ?></span>
</div>
<div class="sticky off" id="sticky" role="region" aria-label="Free gift">
  <div class="sticky-gift"><svg><use href="#i-gift"/></svg></div>
  <div class="sticky-t"><b><?= th('wl.sticky.title', $tv) ?></b><span id="sticky-cd"><?= th('wl.sticky.soon', $tv) ?></span></div>
  <button class="btn" type="button" id="sticky-btn"><?= th('wl.sticky.button', $tv) ?></button>
</div>
<div class="sprog" id="sprog" aria-hidden="true"></div>
<div class="cur" id="cur" aria-hidden="true"></div>
<canvas id="confetti" aria-hidden="true"></canvas>
<div class="toast" id="toast" role="status"></div>
<div class="vnudge" id="vnudge" role="dialog" aria-label="Get first dibs" hidden>
  <button type="button" class="vn-x" id="vn-x" aria-label="Close"><svg><use href="#i-x"/></svg></button>
  <div class="vn-ic"><svg><use href="#i-heart"/></svg></div>
  <div class="vn-t"><b id="vn-title"><?= th('wl.vnudge.title', $tv) ?></b><span><?= th('wl.vnudge.text', $tv) ?></span></div>
  <button type="button" class="btn vn-go" id="vn-go"><?= th('wl.vnudge.button', $tv) ?></button>
</div>


<script nonce="<?= Http::nonce() ?>">window.DROP_CONFIG = <?= View::json($cfg) ?>;</script>
<script src="<?= View::asset('waitlist/waitlist.js') ?>"></script>
</body>
</html>

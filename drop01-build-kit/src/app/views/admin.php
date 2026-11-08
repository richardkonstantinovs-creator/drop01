<?php
// Admin shell: the frame from prototypes/crm.html (header, screens, bottom nav, bottom sheet, toast); on a computer the nav becomes a sidebar. admin.js fills it with real data.
defined('DROP01') || exit;
$logo = Settings::get('logo_text');
[$l1, $l2] = array_pad(explode('/', $logo, 2), 2, null);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0E0E12">
<meta name="robots" content="noindex">
<title><?= e(Settings::get('store_name')) ?> Back Office</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="stylesheet" href="<?= View::asset('fonts/fonts.css') ?>">
<link rel="stylesheet" href="<?= View::asset('vendor/leaflet/leaflet.css') ?>">
<link rel="stylesheet" href="<?= View::asset('admin/admin.css') ?>">
</head>
<body>
<div id="login" class="login" hidden>
  <form class="card" id="loginForm">
    <div class="mark"><?= e($l1) ?><?php if ($l2 !== null): ?>/<span><?= e($l2) ?></span><?php endif ?></div>
    <h1>Back office</h1>
    <p class="err" id="loginErr" role="alert"></p>
    <label class="field"><span>Email</span><input class="in" id="lEmail" type="email" autocomplete="username" required></label>
    <label class="field"><span>Password</span><input class="in" id="lPass" type="password" autocomplete="current-password" required></label>
    <label class="field" id="lTotpRow" hidden><span>6-digit code from your authenticator app</span><input class="in" id="lTotp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
    <button class="btn holo block" type="submit" id="lBtn">Log in</button>
  </form>
</div>

<div class="app" id="app" hidden>
  <header class="top"><div class="bar">
    <div class="mark"><?= e($l1) ?><?php if ($l2 !== null): ?>/<span><?= e($l2) ?></span><?php endif ?></div><span class="title" id="scrTitle">Today</span>
    <button class="icon-btn" aria-label="Needs your attention" data-go="home"><svg viewBox="0 0 24 24"><path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9M10 20a2.2 2.2 0 0 0 4 0"/></svg><b id="attCount" hidden>0</b></button>
  </div></header>

  <div class="screen" id="s-home"></div>
  <div class="screen" id="s-orders"></div>
  <div class="screen" id="s-inbox"></div>
  <div class="screen" id="s-delivery"></div>
  <div class="screen" id="s-stock"></div>
  <div class="screen" id="s-customers"></div>
  <div class="screen" id="s-waitlist"></div>
  <div class="screen" id="s-packing"></div>
  <div class="screen" id="s-marketing"></div>
  <div class="screen" id="s-money"></div>
  <div class="screen" id="s-settings"></div>
  <div class="screen" id="s-texts"></div>
  <div class="screen" id="s-team"></div>
  <div class="screen" id="s-system"></div>

  <p class="foot mono" id="foot"></p>
</div>

<button class="fab" id="fab" hidden>+ New order</button>

<nav class="nav" id="nav" hidden aria-label="Main"><div>
  <a class="nv-brand" href="/admin/home" data-go="home" title="Today"><span class="mark"><?= e($l1) ?><?php if ($l2 !== null): ?>/<span><?= e($l2) ?></span><?php endif ?></span><small>Back office</small></a>
  <span class="nv-h">Daily</span>
  <button data-s="home"><svg viewBox="0 0 24 24"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg><span class="nl">Today</span><em id="navAtt" hidden>0</em></button>
  <button data-s="orders"><svg viewBox="0 0 24 24"><path d="M6 3h12l1 18H5zM9 7a3 3 0 0 0 6 0"/></svg><span class="nl">Orders</span></button>
  <button data-s="inbox"><svg viewBox="0 0 24 24"><path d="M4 5.5h16v10.5H10l-4.5 3.5V16H4z"/><path d="M8 9.5h8M8 12.5h5"/></svg><span class="nl">Inbox</span><em id="ibCount" hidden>0</em></button>
  <button data-s="delivery"><svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg><span class="nl">Delivery</span></button>
  <button data-s="stock" class="dk"><svg viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9zM4 7.5l8 4.5 8-4.5M12 12v9"/></svg><span class="nl">Stock</span></button>
  <button data-s="customers"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/></svg><span class="nl">Customers</span></button>
  <span class="nv-h">Grow</span>
  <button data-s="waitlist" class="dk"><svg viewBox="0 0 24 24"><rect x="3.5" y="9" width="17" height="11.5" rx="1.5"/><path d="M2.5 9h19M12 9v11.5M12 9c-1.5-3.5-6-4-6-1.5S10 9 12 9Zm0 0c1.5-3.5 6-4 6-1.5S14 9 12 9Z"/></svg><span class="nl">Waitlist</span></button>
  <button data-s="marketing" class="dk"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><span class="nl">Marketing</span></button>
  <button data-s="money" class="dk"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15 9.2c-.6-.9-1.7-1.4-3-1.4-1.7 0-3 .9-3 2.1s1.3 1.7 3 2.1 3 .9 3 2.1-1.3 2.1-3 2.1c-1.3 0-2.4-.5-3-1.4M12 6v12"/></svg><span class="nl">Money</span></button>
  <span class="nv-h">Setup</span>
  <button data-s="settings" class="dk"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h5"/></svg><span class="nl">Settings</span></button>
  <button data-s="texts" class="dk"><svg viewBox="0 0 24 24"><path d="M4 7V5h11v2M9.5 5v14M7 19h5M14 12v-1.5h7V12M17.5 10.5V19M16 19h3"/></svg><span class="nl">Texts</span></button>
  <button data-s="team" class="dk"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/></svg><span class="nl">Team</span></button>
  <button data-s="system" class="dk"><svg viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9zM4 7.5l8 4.5 8-4.5"/></svg><span class="nl">System</span></button>
  <div class="nv-me"><span class="av" id="nvAv"></span><span class="nv-who"><b id="nvName"></b><small id="nvRole"></small></span>
    <button data-act="logout" class="nv-out" title="Log out" aria-label="Log out"><svg viewBox="0 0 24 24"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg></button></div>
  <button class="nv-fold" id="nvFold" type="button" title="Collapse the menu" aria-label="Collapse the menu"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>
  <button data-s="more"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span class="nl">More</span></button>
</div></nav>

<div class="scrim" id="scrim"></div>
<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-label="Details"><div class="grab"></div><div id="sheetBody"></div></div>
<div class="toast" id="toast" role="status"></div>
<div class="errs" id="errs" role="alert"></div>
<div class="ask" id="ask" role="alertdialog" aria-modal="true" aria-labelledby="askT" aria-describedby="askM"><div class="ask-c"><h2 id="askT"></h2><p id="askM"></p><input id="askIn" hidden aria-labelledby="askM" autocomplete="off"><div class="ask-b"><button type="button" class="btn ghost" id="askNo">Cancel</button><button type="button" class="btn" id="askOk">OK</button></div></div></div>

<script nonce="<?= Http::nonce() ?>">window.ADMIN = <?= View::json(['csrf' => $csrf, 'version' => APP_VERSION, 'store' => Settings::get('store_name'), 'leaflet' => View::asset('vendor/leaflet/leaflet.js')]) ?>;</script>
<script src="<?= View::asset('admin/admin.js') ?>"></script>
<script nonce="<?= Http::nonce() ?>">if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js").catch(() => {});</script>
</body>
</html>

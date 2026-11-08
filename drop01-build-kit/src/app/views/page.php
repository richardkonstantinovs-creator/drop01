<?php
// Simple content page (legal pages, messages) in the waitlist's dark look.
defined('DROP01') || exit;
$store = is_installed() ? Settings::get('store_name') : 'Drop 01';
$logo = is_installed() ? Settings::get('logo_text') : 'DROP/01';
[$l1, $l2] = array_pad(explode('/', $logo, 2), 2, null);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#09090B">
<title><?= e($title) ?> · <?= e($store) ?></title>
<link rel="stylesheet" href="<?= View::asset('fonts/fonts.css') ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
:root{--bg:#09090B;--panel:#141418;--ink:#F3F1EC;--muted:#8D8C96;--line:#2A2A31;--holo-lin:linear-gradient(115deg,#FFB3D9 0%,#FFE7A3 22%,#B8FFD9 44%,#A8DCFF 66%,#D4B8FF 88%);color-scheme:dark}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg)}
body{color:var(--ink);font:16px/1.6 Manrope,system-ui,sans-serif;padding:0 16px 64px}
.wrap{max-width:720px;margin:0 auto}
header{display:flex;justify-content:space-between;align-items:center;padding:16px 0 28px}
.logo{font-family:"Bricolage Grotesque","Arial Narrow",sans-serif;font-variation-settings:"wdth" 78,"opsz" 96;font-weight:800;font-size:22px;letter-spacing:-.02em;color:var(--ink);text-decoration:none}
.logo i{font-style:normal;background:var(--holo-lin);-webkit-background-clip:text;background-clip:text;color:transparent}
h1{font-family:"Bricolage Grotesque","Arial Narrow",sans-serif;font-variation-settings:"wdth" 78,"opsz" 96;font-weight:800;font-size:clamp(36px,9vw,56px);line-height:.95;letter-spacing:-.03em;margin:0 0 24px}
h2{font-size:19px;margin:28px 0 8px}
p,li{color:#C9C7CF}a{color:#A8DCFF}
mark{background:rgba(255,231,163,.15);color:#FFE7A3;padding:0 4px;border-radius:4px;font-family:"JetBrains Mono",monospace;font-size:.85em}
footer{margin-top:48px;padding-top:20px;border-top:1px solid var(--line);font-size:13px;color:var(--muted);display:flex;flex-wrap:wrap;gap:8px 16px}
footer a{color:var(--muted)}
a:focus-visible{outline:2px solid #A8DCFF;outline-offset:2px}
</style>
</head>
<body>
<div class="wrap">
  <header><a class="logo" href="/"><?= e($l1) ?><?php if ($l2 !== null): ?><i>/<?= e($l2) ?></i><?php endif ?></a></header>
  <h1><?= e($title) ?></h1>
  <?= $body /* trusted HTML: built in code or by the owner in the admin */ ?>
  <footer>
    <span>© <?= date('Y') ?> <?= e($store) ?></span>
    <?php foreach (Legal::PAGES as $slug => $t): ?><a href="/pages/<?= $slug ?>"><?= e(is_installed() ? t('page.' . $slug) : $t) ?></a><?php endforeach ?>
  </footer>
</div>
</body>
</html>

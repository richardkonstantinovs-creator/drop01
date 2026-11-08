/* Shop (the real-data version of prototypes/shop.html).
   Catalogue, stock, zones and slots come from window.SHOP; checkout and payment go through the API (Mollie hosted checkout). */
"use strict";
const S = window.SHOP || {}, START = window.SHOP_START || {};
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const eur = c => "€" + (c / 100).toFixed(2).replace(".", ",");
/* customer-facing texts (Admin → Texts): t() is plain text, th() safe HTML with **bold** and [links](/x); a var given as { h: "<b>…</b>" } is already HTML */
const TX = S.t || {};
const tvar = (v, k, html) => { const x = v[k]; return x && typeof x === "object" ? (html ? x.h : String(x.h).replace(/<[^>]*>/g, "")) : html ? esc(x) : String(x); };
const fmtT = (s, v = {}, attr = "") => esc(s).replace(/\*\*(.+?)\*\*/g, "<b>$1</b>")
  .replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (m, l, u) => /^(\/(?!\/)|#|https?:\/\/|mailto:|tel:|\{\w+\})/.test(u) ? `<a href="${u}"${attr}>${l}</a>` : m)
  .replace(/\n/g, "<br>").replace(/\{(\w+)\}/g, (m, k) => k in v ? tvar(v, k, 1) : m);
function t(k, v = {}){ return String(TX[k] ?? k).replace(/\*\*(.+?)\*\*/g, "$1").replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, "$1").replace(/\{(\w+)\}/g, (m, n) => n in v ? tvar(v, n) : m); }
function th(k, v, attr){ return fmtT(String(TX[k] ?? k), v, attr); }
const vib = p => { try { if (navigator.vibrate && (!navigator.userActivation || navigator.userActivation.hasBeenActive)) navigator.vibrate(p); } catch {} };   // Chrome blocks (and logs) vibration before the first tap
const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
const ls = { get(k){ try { return JSON.parse(localStorage.getItem(k)); } catch { return null; } }, set(k, v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch {} } };
function toast(t){ const el = $("toast"); el.textContent = t; el.classList.add("on"); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove("on"), 1900); }
async function api(path, body){
  const r = await fetch(path, body === undefined ? { cache: "no-store" } : { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  let j = null; try { j = await r.json(); } catch {}
  if (!r.ok) throw new Error((j && j.error) || t("common.error"));
  return j;
}

/* ---------- analytics: same cookieless beacon as the waitlist ---------- */
const Track = (function(){
  let sid = null; try { sid = sessionStorage.getItem("drop01-sid"); } catch {}
  if (!sid) { sid = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()).replace(/-/g, "").slice(0, 24); try { sessionStorage.setItem("drop01-sid", sid); } catch {} }
  const q = []; let t = null; const once = {};
  const flush = () => { t = null; if (!q.length) return; const body = JSON.stringify({ sid, events: q.splice(0, 25) });
    try { if (!(navigator.sendBeacon && navigator.sendBeacon("/api/e", new Blob([body], { type: "text/plain" })))) fetch("/api/e", { method: "POST", body, keepalive: true }); } catch {} };
  const send = (type, extra) => { q.push(Object.assign({ type, path: location.pathname }, extra || {})); if (!t) t = setTimeout(flush, 400); };
  addEventListener("pagehide", () => { send("leave"); flush(); });
  const sp = new URLSearchParams(location.search);
  const utm = { source: sp.get("utm_source") || "", medium: sp.get("utm_medium") || "", campaign: sp.get("utm_campaign") || "" };
  send("pageview", { ref: document.referrer && !document.referrer.startsWith(location.origin) ? document.referrer : "", utm });
  return { send, sid, once(type, extra){ if (once[type]) return; once[type] = 1; send(type, extra); } };
})();

/* ---------- catalogue ---------- */
const ARTS = [
  "linear-gradient(135deg,#FFB3D9,#FFE7A3 55%,#B8FFD9)", "linear-gradient(160deg,#A8DCFF,#D4B8FF 60%,#FFB3D9)", "linear-gradient(120deg,#B8FFD9,#A8DCFF 50%,#FFE7A3)",
  "linear-gradient(200deg,#FFE7A3,#FFB3D9 50%,#D4B8FF)", "linear-gradient(140deg,#D4B8FF,#B8FFD9 60%,#FFE7A3)", "linear-gradient(110deg,#FFB3D9,#A8DCFF)",
];
const SHAPES = [["50%", 0], ["18px", 12], ["50% 50% 12px 12px", 0], ["8px", 45], ["50%", 0], ["30% 70% 70% 30% / 30% 30% 70% 70%", 20]];
const P = S.products || [];
const byId = id => P.find(p => p.id === id);
const VAR = {}; P.forEach(p => p.variants.forEach(v => { VAR[v.id] = { ...v, p }; }));
const MAXP = S.maxPerItem > 0 ? S.maxPerItem : Infinity;   // most of one product (all sizes) per order; the server checks it too
P.forEach((p, i) => { p.no = String(i + 1).padStart(2, "0"); p.a = ARTS[p.art % 6]; [p.sh, p.rot] = SHAPES[p.art % 6]; });
const art = (p, big) => p.images.length
  ? `<div class="art photo" style="--a:${p.a}"><img src="${esc(p.images[0].sm)}" ${big ? `srcset="${esc(p.images[0].sm)} 600w, ${esc(p.images[0].lg)} 1600w" sizes="(min-width:520px) 480px, 100vw"` : ""} alt="${esc(p.name)}" loading="lazy" decoding="async">${p.stock === 0 && !big ? `<div class="sold">${th("shop.card.sold")}</div>` : ""}</div>`
  : `<div class="art" style="--a:${p.a}"><div class="shape" style="--sh:${p.sh};--rot:${p.rot}deg"></div><span class="no">${p.no}</span>${p.stock === 0 && !big ? `<div class="sold">${th("shop.card.sold")}</div>` : ""}</div>`;
const stockBadge = p => p.stock === 0 ? "" : p.stock <= Math.max(p.low, 1) ? `<span class="badge low">${th("shop.card.only_left", { n: p.stock })}</span>` : p.cat ? `<span class="badge">${esc(p.cat)}</span>` : "";
const claimed = p => p.run > 0 ? Math.max(0, Math.min(100, Math.round((p.run - p.stock) / p.run * 100))) : null;
const firstVariant = p => p.variants.find(v => v.stock > 0) || null;
const showSizes = p => p.sizes.length > 1 || (p.sizes[0] && !["Default", "One size"].includes(p.sizes[0]));

/* ---------- views ---------- */
let view = "home";
function go(v, keep){
  view = v; document.querySelectorAll(".view").forEach(x => x.classList.toggle("on", x.id === "v-" + v));
  if (!keep) scrollTo(0, 0); updBag();
  const url = v === "home" ? "/" : v === "checkout" ? "/checkout" : null;
  if (url && location.pathname !== url && !location.pathname.startsWith("/order/")) history.pushState({ v }, "", url);
}
addEventListener("popstate", () => { const m = location.pathname.match(/^\/p\/([\w-]+)/); if (m) { const p = P.find(x => x.slug === m[1]); if (p) return openProduct(p.id, true); } go(location.pathname === "/checkout" && cart.length ? "checkout" : "home", 1); });

/* ---------- delivery promise: real cut-off and real free places ---------- */
let myZone = null;
const ZONES = S.zones || [];
function zoneFor(pc){ const d = String(pc).replace(/\s/g, "").slice(0, 4); if (!/^[1-9]\d{3}$/.test(d)) return null; const n = +d; return ZONES.find(z => n >= z.from && n <= z.to) || false; }
const short = s => s.start.replace(":00", "") + "–" + s.end.replace(":00", "");
const nextDay = d => { const x = new Date((d || "") + "T12:00:00Z"); if (isNaN(x)) return ""; x.setUTCDate(x.getUTCDate() + 1); return x.toISOString().slice(0, 10); };
function promiseText(){
  const now = Date.now(), slots = (S.slots || []).filter(s => s.available && new Date(s.cutoff) > now);
  const where = { h: myZone ? th("shop.promise.where_zone", { zone: myZone.name }) : th("shop.promise.where_city") };
  if (!slots.length) return th("shop.promise.full", { where });
  const s = slots[0], left = (new Date(s.cutoff) - now) / 1000;
  if (s.date === S.today) return th("shop.promise.today", { time: { h: `<b class="num">${left / 3600 | 0}h ${String(left % 3600 / 60 | 0).padStart(2, "0")}m</b>` }, when: t(+s.start.slice(0, 2) >= 17 ? "shop.word.tonight" : "shop.word.today"), window: short(s), where });
  return th("shop.promise.later", { day: s.date === nextDay(S.today) ? t("shop.word.tomorrow") : s.label.replace(/ \d\d:\d\d.*/, ""), window: short(s), where });
}
function tickPromise(){ $("promise").innerHTML = promiseText(); const e = $("eta"); if (e) e.innerHTML = promiseText(); }

/* ---------- hero: holographic card fan (featured products) ---------- */
let FAN = P.filter(p => p.featured);
if (FAN.length < 3) FAN = FAN.concat(P.filter(p => !p.featured)).slice(0, 5);
let fanI = Math.min(1, FAN.length - 1), fanTimer;
function renderFan(){
  if (!FAN.length) { $("stage").hidden = true; return; }
  $("fan").innerHTML = FAN.map((p, i) => `<div class="fcard" data-i="${i}">${art(p, true)}<div class="tag"><span>${esc(p.name)}</span><b class="num">${eur(p.price)}</b></div></div>`).join("");
  $("dots").innerHTML = FAN.map(() => "<i></i>").join("");
  $("fan").querySelectorAll(".fcard").forEach(c => c.onclick = () => { const i = +c.dataset.i; if (i === fanI) openProduct(FAN[i].id); else setFan(i); });
  setFan(fanI);
}
function setFan(i){
  fanI = (i + FAN.length) % FAN.length;
  $("fan").querySelectorAll(".fcard").forEach((c, k) => {
    let d = k - fanI; if (d > 2) d -= FAN.length; if (d < -2) d += FAN.length;
    const a = Math.abs(d);
    c.style.transform = `translateX(${d * 92}px) translateZ(${-a * 120}px) rotateY(${-d * 24}deg) rotateZ(${d * 3}deg)`;
    c.style.zIndex = 10 - a; c.style.filter = a ? `brightness(${1 - a * .25})` : "none"; c.style.opacity = a > 2 ? 0 : 1;
  });
  $("dots").querySelectorAll("i").forEach((d, k) => d.classList.toggle("on", k === fanI));
  clearTimeout(fanTimer); if (!reduce && view === "home") fanTimer = setTimeout(() => setFan(fanI + 1), 3600);
}
(() => { let x0 = null; const st = $("stage");
  st.addEventListener("touchstart", e => { x0 = e.touches[0].clientX; }, { passive: true });
  st.addEventListener("touchend", e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) setFan(fanI + (dx < 0 ? 1 : -1)); x0 = null; }); })();

/* ---------- home grid ---------- */
let cat = "all", query = "";
function renderGrid(){
  const cats = [["all", t("shop.grid.all")], ...[...new Set(P.map(p => p.cat).filter(Boolean))].map(c => [c, c])];
  $("cats").innerHTML = cats.length > 1 ? cats.map(([k, l]) => `<button class="chip" aria-pressed="${k === cat}" data-c="${esc(k)}">${esc(l)}</button>`).join("") : "";
  $("cats").querySelectorAll(".chip").forEach(b => b.onclick = () => { cat = b.dataset.c; renderGrid(); });
  const list = P.filter(p => (cat === "all" || p.cat === cat) && (!query || p.name.toLowerCase().includes(query)));
  $("countLbl").textContent = t(list.length === 1 ? "shop.grid.count_one" : "shop.grid.count_many", { n: list.length });
  $("grid").innerHTML = list.map(p => { const cl = claimed(p);
    return `<div class="pcard" data-id="${p.id}">${art(p)}${stockBadge(p)}${p.likes ? `<span class="heart">♥ ${p.likes}</span>` : ""}
    <div class="n">${esc(p.name)}</div>
    <div class="meta sub">${p.stock ? (cl === null ? (p.stock <= p.low ? `<span class="warn">${th("shop.card.left", { n: p.stock })}</span>` : th("shop.card.in_stock")) : p.stock <= p.low ? `<span class="warn">${th("shop.card.claimed", { pct: cl })}</span>` : th("shop.card.claimed", { pct: cl })) : th("shop.card.gone")}</div>
    ${cl === null ? "" : `<div class="mini-track"><i style="width:${cl}%"></i></div>`}
    <div class="p"><b class="num">${eur(p.price)}</b>${p.stock ? `<button class="add" data-add="${p.id}" aria-label="Add ${esc(p.name)}">${th("shop.card.add")}</button>` : `<button class="add notify" data-notify="${p.id}">${th("shop.card.notify")}</button>`}</div></div>`; }).join("")
    || `<p class="sub" style="grid-column:1/-1;padding:10px 4px">${th(query ? "shop.grid.no_match" : "shop.grid.empty")}</p>`;
  $("grid").querySelectorAll(".pcard .art").forEach(c => c.onclick = () => openProduct(+c.closest(".pcard").dataset.id));
  $("grid").querySelectorAll("[data-add]").forEach(b => b.onclick = () => { const p = byId(+b.dataset.add);
    if (p.variants.filter(v => v.stock > 0).length > 1) return openProduct(p.id);   // pick a size first
    const v = firstVariant(p); if (!v) return;
    addToCart(v.id, 1, b.closest(".pcard").querySelector(".art"));
    b.classList.add("done"); b.textContent = t("shop.card.added"); setTimeout(() => { b.classList.remove("done"); b.textContent = t("shop.card.add"); }, 1400); });
  $("grid").querySelectorAll("[data-notify]").forEach(b => b.onclick = () => notifyMe(byId(+b.dataset.notify), b));
  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } }), { rootMargin: "0px 0px -8% 0px" });
    $("grid").querySelectorAll(".pcard").forEach((c, i) => { c.style.transitionDelay = (i % 2) * 70 + "ms"; io.observe(c); });
  } else $("grid").querySelectorAll(".pcard").forEach(c => c.classList.add("in"));
}
function renderBundle(){
  const B = S.bundle; if (!B) return;
  const ps = B.ids.map(byId).filter(Boolean);
  if (ps.length < 2 || ps.some(p => !p.stock)) return;
  const was = ps.reduce((s, p) => s + p.price, 0), price = Math.round(was * (100 - B.pct) / 100);
  $("bundle").hidden = false; $("bName").textContent = B.name;
  $("bArts").innerHTML = ps.map((p, i) => art(p, true).replace('class="art', `style="--r:${(i - 1) * 7}deg" class="art`)).join("");
  $("bPrice").textContent = eur(price); $("bWas").textContent = eur(was); $("bSave").textContent = t("shop.bundle.save", { pct: B.pct });
  $("bText").textContent = ps.map(p => p.name).join(" + ") + (price >= S.freeFrom ? " · " + t("shop.bundle.free_delivery") : "") + (giftKnown() ? " · " + t("shop.bundle.gift") : "");
  $("bAdd").onclick = () => { ps.forEach((p, i) => setTimeout(() => { const v = firstVariant(p); if (v) addToCart(v.id, 1, $("bArts").children[i], true); }, i * 160));
    setTimeout(() => { toast(t("shop.bundle.added", { name: B.name, pct: B.pct })); openCart(); }, 900); };
}
function tick(){
  if (!S.endsAt) return;
  let s = Math.max(0, (new Date(S.endsAt) - Date.now()) / 1000) | 0;
  $("timer").hidden = false;
  const v = [[s / 86400 | 0, "days"], [s % 86400 / 3600 | 0, "hrs"], [s % 3600 / 60 | 0, "min"], [s % 60, "sec"]];
  $("timer").innerHTML = v.map(([n, l]) => `<div><b class="num">${String(n).padStart(2, "0")}</b><span>${th("shop.timer." + l)}</span></div>`).join("");
}
function checkPc(quiet){
  const pc = $("pcIn").value, z = zoneFor(pc), r = $("pcRes"); r.hidden = false;
  if (z) { myZone = z; ls.set("d01-pc", pc); $("where").innerHTML = `✓ ${esc(z.name)}`; $("where").classList.add("on"); tickPromise(); if (!quiet) vib(10); }
  if (!quiet) Track.send("postcode_check", { value: z ? z.name : z === false ? "none:" + pc.replace(/\s/g, "").slice(0, 4) : "invalid" });
  const fee = z ? (z.free ? th("shop.pc.fee_free", { fee: eur(z.fee), free: eur(z.free) }) : th("shop.pc.fee", { fee: eur(z.fee) })) : "";
  r.innerHTML = z === null ? `<span class="no">✕</span><span>${th("shop.pc.bad")}</span>`
    : z ? `<span class="ok">✓</span><span>${th("shop.pc.yes", { zone: z.name, promise: { h: promiseText() }, fee: { h: fee } })}</span>`
    : `<span class="no">✕</span><span>${th("shop.pc.no", {}, ' style="color:var(--ink)"')}</span>`;
}
$("pcIn").addEventListener("keydown", e => { if (e.key === "Enter") checkPc(); });

/* ---------- notify me (sold out) ---------- */
function notifyMe(p, btn){
  $("drawerTitle").textContent = t("shop.notify.title");
  $("cartBody").innerHTML = `<form class="nf" id="nfForm"><p class="sub" style="margin:0">${th("shop.notify.text", { product: p.name })}</p>
    <input class="field" id="nfContact" placeholder="${esc(t("shop.notify.placeholder"))}" aria-label="Email or phone" required autocomplete="email"><p class="err" id="nfErr"></p>
    <button class="cta" type="submit">${th("shop.notify.button")}</button></form>`;
  $("cartFoot").innerHTML = "";
  $("scrim").classList.add("on"); $("drawer").classList.add("on"); setTimeout(() => $("nfContact").focus(), 200);
  $("nfForm").onsubmit = async e => { e.preventDefault();
    try { await api("/api/restock", { product: p.slug, contact: $("nfContact").value, sid: Track.sid }); closeCart(); if (btn) btn.textContent = t("shop.notify.done"); toast(t("shop.notify.toast")); }
    catch (err) { $("nfErr").textContent = err.message; } };
}

/* ---------- product page ---------- */
function openProduct(id, fromHistory){
  const p = byId(id); if (!p) return;
  let v = firstVariant(p) || p.variants[0], qty = 1, img = 0;
  const sizes = showSizes(p), colors = p.colors.length > 1;
  const sizeOk = s => p.variants.some(x => x.size === s && x.stock > 0 && (!colors || x.color === v.color));
  const pairs = P.filter(x => x.id !== id && x.stock).sort((a, b) => a.price - b.price).slice(0, 4);
  const cl = claimed(p);
  $("v-product").innerHTML = `<button class="back" data-act="home1"><svg class="ic" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>${th("shop.product.back")}</button>
    <div class="hero-art" id="tilt">${art(p, true)}${stockBadge(p)}${p.likes ? `<span class="heart">${th("shop.product.want", { n: p.likes })}</span>` : ""}</div>
    ${p.images.length > 1 ? `<div class="thumbs">${p.images.map((im, i) => `<button aria-pressed="${!i}" data-img="${i}" aria-label="Photo ${i + 1}"><img src="${esc(im.sm)}" alt=""></button>`).join("")}</div>` : ""}
    <h1 class="display">${esc(p.name)}</h1>
    <div class="price"><b class="num">${eur(p.price)}</b><span class="sub">${th("shop.product.price_note", { free: eur(S.freeFrom) })}</span></div>
    <div class="stockbar"><div class="row"><span>${p.stock ? (p.stock <= p.low ? "⚡ " : "") + (p.run ? th("shop.product.left_of", { n: p.stock, run: p.run }) : th("shop.product.left", { n: p.stock })) : th("shop.product.sold")}</span><span>${cl === null ? "" : th("shop.product.claimed", { pct: cl })}</span></div><div class="track"${cl === null ? " hidden" : ""}><i style="width:0" data-w="${cl || 0}"></i></div></div>
    ${sizes ? `<div class="opt"><div class="l"><span>${th("shop.product.size")}</span></div><div class="sizes" id="sizes">${p.sizes.map(s => `<button ${sizeOk(s) ? "" : "disabled"} aria-pressed="${s === v.size}">${esc(s)}</button>`).join("")}</div></div>` : ""}
    ${colors ? `<div class="opt"><div class="l"><span>${th("shop.product.colour")}</span></div><div class="swatches" id="sw">${p.colors.map((c, i) => `<button style="--c:${esc(c)}" aria-pressed="${c === v.color}" aria-label="Colour ${i + 1}" data-c="${esc(c)}"></button>`).join("")}</div></div>` : ""}
    <div class="card eta"><svg class="ic" viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg><div id="eta">${promiseText()}</div></div>
    <div class="trust"><div>${th("shop.product.trust.1")}</div><div>${th("shop.product.trust.2")}</div><div>${th("shop.product.trust.3")}</div></div>
    <div style="margin-top:22px">
      ${p.desc ? `<details class="faq" open><summary>${th("shop.product.details")}</summary><p>${esc(p.desc).replace(/\n/g, "<br>")}</p></details>` : ""}
      <details class="faq"${p.desc ? "" : " open"}><summary>${th("shop.product.delivery_title")}</summary><p>${th("shop.product.delivery_text")}</p></details>
    </div>
    ${pairs.length ? `<div class="sec" style="margin-top:26px"><h2 style="font-size:26px">${th("shop.product.pairs")}</h2></div>
    <div class="pairs">${pairs.map(x => `<div class="pair" data-id="${x.id}">${art(x)}<div class="r"><span>${esc(x.name)}<br><b class="num">${eur(x.price)}</b></span><button class="add" data-add="${x.id}">${th("shop.card.add")}</button></div></div>`).join("")}</div>` : ""}
    <div class="buybar"><div class="qty"><button id="qm" aria-label="Less">−</button><span id="qv">1</span><button id="qp" aria-label="More">+</button></div>
      <button class="cta" id="buy"></button></div>`;
  view = "product"; document.querySelectorAll(".view").forEach(x => x.classList.toggle("on", x.id === "v-product")); scrollTo(0, 0); updBag();
  if (!fromHistory) history.pushState({ p: id }, "", "/p/" + p.slug);
  Track.send("product_view", { product: p.slug });
  requestAnimationFrame(() => requestAnimationFrame(() => { const t = document.querySelector("#v-product .stockbar .track i"); if (t) t.style.width = t.dataset.w + "%"; }));
  const upd = () => { $("qv").textContent = qty; const ok = v && v.stock > 0; $("buy").disabled = !ok; $("buy").textContent = ok ? t("shop.product.buy", { price: eur(p.price * qty) }) : t("shop.product.sold"); };
  const pick = (size, color) => { v = p.variants.find(x => (!sizes || x.size === size) && (!colors || x.color === color) && x.stock > 0) || p.variants.find(x => (!sizes || x.size === size) && (!colors || x.color === color)) || v; qty = Math.min(qty, Math.max(1, v.stock));
    document.querySelectorAll("#sizes button").forEach(b => { b.setAttribute("aria-pressed", b.textContent === v.size); b.disabled = !sizeOk(b.textContent); });
    document.querySelectorAll("#sw button").forEach(b => b.setAttribute("aria-pressed", b.dataset.c === v.color)); upd(); };
  upd();
  $("qm").onclick = () => { qty = Math.max(1, qty - 1); upd(); };
  $("qp").onclick = () => { if (qty >= v.stock) return toast(t("shop.product.only_left", { n: v.stock })); if (qty >= MAXP - inBag(p)) return toast(t("shop.product.max", { n: MAXP })); qty++; upd(); };
  document.querySelectorAll("#sizes button").forEach(b => b.onclick = () => pick(b.textContent, v.color));
  document.querySelectorAll("#sw button").forEach(b => b.onclick = () => pick(v.size, b.dataset.c));
  document.querySelectorAll("#v-product .thumbs button").forEach(b => b.onclick = () => { img = +b.dataset.img; const im = p.images[img], el = document.querySelector("#tilt .art img");
    el.src = im.sm; el.srcset = `${im.sm} 600w, ${im.lg} 1600w`; document.querySelectorAll("#v-product .thumbs button").forEach(x => x.setAttribute("aria-pressed", x === b)); });
  $("buy").onclick = () => { if (!v || !v.stock) return; addToCart(v.id, qty, document.querySelector("#tilt .art")); setTimeout(openCart, 650); };
  document.querySelectorAll("#v-product .pair .art").forEach(a => a.onclick = () => openProduct(+a.closest(".pair").dataset.id));
  document.querySelectorAll("#v-product .pair [data-add]").forEach(b => b.onclick = () => { const x = byId(+b.dataset.add);
    if (x.variants.filter(y => y.stock > 0).length > 1) return openProduct(x.id); const fv = firstVariant(x); if (!fv) return;
    addToCart(fv.id, 1, b.closest(".pair").querySelector(".art")); b.textContent = "✓"; });
  // the product art leans with your finger
  const tilt = $("tilt"), a = tilt.querySelector(".art");
  tilt.onpointermove = e => { if (reduce) return; const r = a.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
    a.style.transform = `rotateY(${x * 14}deg) rotateX(${-y * 14}deg)`; a.style.setProperty("--sx", 50 + x * 80); };
  tilt.onpointerleave = () => { a.style.transform = ""; a.style.removeProperty("--sx"); };
}

/* ---------- bag: localStorage for speed, mirrored to the server ---------- */
const BAG = ls.get("d01-bag") || {};
if (!BAG.id || !/^[a-f0-9-]{16,40}$/i.test(BAG.id)) BAG.id = crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, "0")).join("");
let cart = (BAG.items || []).filter(l => VAR[l.vid] && VAR[l.vid].stock > 0).map(l => ({ vid: l.vid, qty: Math.min(l.qty, VAR[l.vid].stock) }));
let syncT;
function saveBag(){ ls.set("d01-bag", { id: BAG.id, items: cart }); clearTimeout(syncT); syncT = setTimeout(() => api("/api/cart", { id: BAG.id, items: cart.map(l => ({ variant_id: l.vid, qty: l.qty })) }).catch(() => {}), 800); }
const giftKnown = () => { const s = ls.get("drop01-signup"); return !!(s && s.code) || co.gift === true; };
const lineP = l => VAR[l.vid].p;
function totals(zone){
  const sub = cart.reduce((s, l) => s + lineP(l).price * l.qty, 0);
  let disc = 0;
  const B = S.bundle;
  if (B) { let sets = Infinity, setPrice = 0;
    for (const pid of B.ids) { const have = cart.filter(l => lineP(l).id === pid).reduce((s, l) => s + l.qty, 0); if (!have) { sets = 0; break; } sets = Math.min(sets, have); setPrice += byId(pid).price; }
    if (sets > 0 && sets !== Infinity) disc = Math.round(setPrice * sets * B.pct / 100); }
  const goods = sub - disc, z = zone || null, free = z ? z.free : S.freeFrom;
  let fee = z ? (free && goods >= free ? 0 : z.fee) : 0, codeOff = 0;
  // discount or friend's code (checked by the server): after the bundle; the free-delivery threshold ignores it, like the server
  const C = co.code;
  if (C) { if (C.type === "pct") codeOff = Math.round(goods * C.value / 100); else if (C.type === "fixed") codeOff = Math.min(C.value, goods); else if (C.type === "free_delivery") fee = 0; }
  return { sub, disc, codeOff, total: goods - codeOff, fee, grand: goods - codeOff + fee, free };
}
const minFee = () => ZONES.length ? Math.min(...ZONES.map(z => z.fee)) : 0;
function updBag(){
  const n = cart.reduce((s, l) => s + l.qty, 0), b = $("cartN"); b.textContent = n; b.hidden = !n;
  const { total } = totals(myZone), FREE = myZone ? myZone.free : S.freeFrom, left = Math.max(0, FREE - total);
  $("bagbar").classList.toggle("on", n > 0 && view === "home");
  $("bbText").innerHTML = `<b class="num">${th(n > 1 ? "shop.bagbar.items_many" : "shop.bagbar.items_one", { n, total: eur(total) })}</b><br><span class="sub">${left ? th("shop.bagbar.to_free", { amount: eur(left) }) : th("shop.bagbar.unlocked")}</span>`;
  $("bbTrack").firstChild.style.width = Math.min(100, FREE ? total / FREE * 100 : 100) + "%";
}
const inBag = p => cart.filter(l => VAR[l.vid] && VAR[l.vid].p === p).reduce((s, l) => s + l.qty, 0);
/* How many more of variant v fit in the bag: its stock, and the per-order limit for its product. Says why when none fit. */
function room(v, say){
  const inV = cart.filter(l => l.vid === v.id).reduce((s, l) => s + l.qty, 0), inP = inBag(v.p);
  const n = Math.max(0, Math.min(v.stock - inV, MAXP - inP));
  if (!n && say) toast(inP >= MAXP ? t("shop.product.max", { n: MAXP }) : t("shop.product.only_left", { n: v.stock }));
  return n;
}
function addToCart(vid, qty, fromEl, quiet){
  const v = VAR[vid]; if (!v) return;
  const ex = cart.find(l => l.vid === vid), q = Math.min(qty, room(v, true));
  if (!q) return;
  if (ex) ex.qty += q; else cart.push({ vid, qty: q });
  saveBag(); vib(12); Track.send("add_to_cart", { product: v.p.slug });
  if (fromEl) flyToBag(fromEl, v.p); else { updBag(); bumpBag(); }
  if (!quiet && !fromEl) toast(t("shop.bag.added", { name: v.p.name }));
  if ($("drawer").classList.contains("on")) renderCart();
}
function bumpBag(){ const b = $("bagBtn"); b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
function flyToBag(el, p){
  if (reduce) { updBag(); bumpBag(); return; }
  const r = el.getBoundingClientRect(), t = $("bagBtn").getBoundingClientRect(), f = document.createElement("div");
  f.className = "flyer" + (p.images.length ? " photo" : ""); f.style.setProperty("--a", p.a);
  if (p.images.length) f.style.backgroundImage = `url("${p.images[0].sm}")`;
  Object.assign(f.style, { left: r.left + "px", top: r.top + "px", width: r.width + "px", height: r.height + "px" });
  document.body.appendChild(f);
  const dx = t.left + t.width / 2 - (r.left + r.width / 2), dy = t.top + t.height / 2 - (r.top + r.height / 2), s = 26 / r.width;
  f.animate([{ transform: "none", opacity: 1, borderRadius: "16px" }, { transform: `translate(${dx * .55}px,${dy * .35 - 60}px) scale(${Math.max(s * 3, .35)}) rotate(-12deg)`, opacity: 1, offset: .55 },
    { transform: `translate(${dx}px,${dy}px) scale(${s}) rotate(-20deg)`, opacity: .6, borderRadius: "50%" }], { duration: 650, easing: "cubic-bezier(.5,0,.3,1)" })
    .finished.then(() => { f.remove(); updBag(); bumpBag(); sparkle(t.left + t.width / 2, t.top + t.height / 2, 14); });
}
function renderCart(){
  $("drawerTitle").textContent = t("shop.bag.title");
  const { sub, disc, total, free } = totals(myZone), left = Math.max(0, free - total);
  const ups = P.filter(x => x.stock && x.variants.filter(v => v.stock > 0).length === 1 && !cart.some(l => lineP(l) === x)).sort((a, b) => a.price - b.price).slice(0, 3);
  const vlabel = v => [showSizes(v.p) ? v.size : "", v.color ? `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${esc(v.color)};vertical-align:-1px;border:1px solid var(--line2)"></span>` : ""].filter(Boolean).join(" · ");
  $("cartBody").innerHTML = cart.length ? `<div class="free">${left ? th("shop.bag.free_more", { amount: eur(left) }) : th("shop.bag.free_done")}<div class="track"><i style="width:${Math.min(100, free ? total / free * 100 : 100)}%"></i></div></div>` +
    cart.map((l, i) => { const v = VAR[l.vid], p = v.p; return `<div class="ci ${i ? "" : "first"}">${art(p, true)}<div><div class="n">${esc(p.name)}</div><div class="sub">${vlabel(v)}${v.stock <= p.low ? `${vlabel(v) ? " · " : ""}<span style="color:var(--warn)">${th("shop.bag.only_left", { n: v.stock })}</span>` : ""}</div>
      <div class="qty"><button data-m="${i}" aria-label="Less">−</button><span>${l.qty}</span><button data-p="${i}" aria-label="More">+</button></div></div><b class="num">${eur(p.price * l.qty)}</b></div>`; }).join("") +
    (giftKnown() ? `<div class="ci gift"><div class="art"><span style="font-size:26px">🎁</span></div><div><div class="n">${esc(S.gift.title)}</div><div class="sub">${th("shop.bag.gift_sub")}</div></div><span class="free-t">${th("shop.bag.free")}</span></div>` : "") +
    (ups.length ? `<div class="upsell"><div class="mono">${th(left ? "shop.bag.upsell_more" : "shop.bag.upsell")}</div><div class="ups">${ups.map(x => `<div class="up">${art(x, true)}<div>${esc(x.name)}<br><b class="num">${eur(x.price)}</b>${left && x.price >= left ? `<br><span class="hint">${th("shop.bag.unlocks")}</span>` : ""}</div><button class="add" data-up="${x.id}" aria-label="Add ${esc(x.name)}">+</button></div>`).join("")}</div></div>` : "")
    : `<div class="empty"><div class="display" style="font-size:46px;color:var(--ink)">${th("shop.bag.empty_title")}</div><p>${th("shop.bag.empty_text")}</p></div>`;
  const z = totals(myZone);
  $("cartFoot").innerHTML = cart.length ? `<div class="tot"><span>${th("shop.bag.subtotal")}</span><span class="num">${eur(sub)}</span></div>
    ${disc ? `<div class="tot" style="color:var(--good)"><span>${esc(S.bundle.name)} −${S.bundle.pct}%</span><span class="num">−${eur(disc)}</span></div>` : ""}
    <div class="tot"><span>${th("shop.bag.delivery")}</span><span>${myZone ? (z.fee ? eur(z.fee) : th("shop.bag.free")) : left ? th("shop.bag.delivery_from", { fee: eur(minFee()) }) : th("shop.bag.free")}</span></div>
    <div class="tot big"><span>${th("shop.bag.total")}</span><span class="num">${eur(myZone ? z.grand : total)}</span></div>
    <button class="cta" data-co="">${th("shop.bag.checkout")}</button>
    ${S.payOnline ? `<div class="express"><button class="apple" data-co="applepay">Apple Pay</button><button class="ideal" data-co="ideal">iDEAL</button></div>` : ""}`
    : `<button class="cta dark" data-act="closeHome">${th("shop.bag.shop")}</button>`;
  $("cartBody").querySelectorAll("[data-m]").forEach(b => b.onclick = () => { const l = cart[b.dataset.m]; l.qty--; if (!l.qty) cart.splice(b.dataset.m, 1); saveBag(); updBag(); renderCart(); });
  $("cartBody").querySelectorAll("[data-p]").forEach(b => b.onclick = () => { const l = cart[b.dataset.p], v = VAR[l.vid]; if (!room(v, true)) return; l.qty++; saveBag(); updBag(); renderCart(); });
  $("cartBody").querySelectorAll("[data-up]").forEach(b => b.onclick = () => { const x = byId(+b.dataset.up), v = firstVariant(x); if (v) { addToCart(v.id, 1, null, true); toast(t("shop.bag.added", { name: x.name })); } });
  $("cartFoot").querySelectorAll("[data-co]").forEach(b => b.onclick = () => { closeCart(); openCheckout(b.dataset.co || null); });
}
function openCart(){ renderCart(); $("scrim").classList.add("on"); $("drawer").classList.add("on"); }
function closeCart(){ $("scrim").classList.remove("on"); $("drawer").classList.remove("on"); }
$("scrim").onclick = closeCart;
document.addEventListener("keydown", e => { if (e.key === "Escape") closeCart(); });

/* ---------- checkout ---------- */
const co = { slot: null, pay: S.payOnline ? "ideal" : "tikkie", zone: null, addr: null, gift: null, busy: false, code: null, codeOpen: false, codeErr: "" };
function openCheckout(express){
  if (!cart.length) return go("home");
  if (express && S.payOnline) co.pay = express === "applepay" ? "card" : express;
  const saved = ls.get("d01-co") || {};
  const savedPc = saved.pc || (() => { try { return JSON.parse(localStorage.getItem("d01-pc")); } catch { return null; } })() || "";
  const n = cart.reduce((s, l) => s + l.qty, 0);
  $("v-checkout").innerHTML = `<button class="back" data-act="home"><svg class="ic" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>${th("shop.co.back")}</button>
  <div class="co"><div class="mono">${th("shop.co.kicker")}</div><h1 class="display">${th("shop.co.title")}</h1>
    <button class="sumtoggle" id="sumT"><span>${th(n > 1 ? "shop.co.show_many" : "shop.co.show_one", { n })}${giftKnown() ? th("shop.co.plus_gift") : ""}</span><span class="num" id="sumTop"></span></button>
    <div class="card summary" id="sum" hidden style="margin-top:8px"></div>
    ${S.payOnline ? `<div class="exp-h">${th("shop.co.express")}</div>
    <div class="express" style="margin-top:0"><button class="apple" data-exp="card">Apple Pay</button><button class="ideal" data-exp="ideal">iDEAL</button></div>
    <div class="exp-h">${th("shop.co.or_fill")}</div>` : ""}
    <form id="coForm" novalidate>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" id="hp" tabindex="-1" autocomplete="off"></div>
    <div class="box card" id="b1"><h3><em>1</em>${th("shop.co.step1")}<span class="chk">✓</span></h3><div class="fgrid">
      <div><label class="f" for="nm">${th("shop.co.name")}</label><input class="field" id="nm" placeholder="${esc(t("shop.co.name_ph"))}" autocomplete="name" value="${esc(saved.name || "")}"></div>
      <div><label class="f" for="em">${th("shop.co.email")}</label><input class="field" type="email" id="em" placeholder="${esc(t("shop.co.email_ph"))}" autocomplete="email" value="${esc(saved.email || "")}"></div>
      <div><label class="f" for="ph">${th("shop.co.phone")}</label><input class="field" type="tel" id="ph" placeholder="${esc(t("shop.co.phone_ph"))}" autocomplete="tel" value="${esc(saved.phone || "")}"></div></div></div>
    <div class="box card" id="b2"><h3><em>2</em>${th("shop.co.step2")}<span class="chk">✓</span></h3><div class="fgrid two">
      <div><label class="f" for="coPc">${th("shop.co.postcode")}</label><input class="field" id="coPc" value="${esc(savedPc)}" autocomplete="postal-code" placeholder="9711 LD"></div><div><label class="f" for="coNo">${th("shop.co.house")}</label><input class="field" id="coNo" placeholder="22" autocomplete="address-line2" value="${esc(saved.no || "")}"></div></div>
      <div class="addr" id="coAddr"></div></div>
    <div class="box card" id="b3"><h3><em>3</em>${th("shop.co.step3")}<span class="chk">✓</span></h3><div id="slots"></div></div>
    <div class="box card valid" id="b4"><h3><em>4</em>${th("shop.co.step4")}<span class="chk">✓</span></h3><div class="pays" id="pays">
      ${[["ideal", "shop.co.pay_ideal", "iDEAL", "shop.co.pay_ideal_sub"], ["card", "shop.co.pay_card", "VISA", "shop.co.pay_card_sub"], ["tikkie", "shop.co.pay_tikkie", "TIKKIE", S.payOnline ? "shop.co.pay_tikkie_online" : "shop.co.pay_tikkie_sub"]]
        .filter(([k]) => S.payOnline || k === "tikkie")
        .map(([k, nm, lg, s]) => `<button type="button" class="pay" data-k="${k}" aria-pressed="${k === co.pay}"><span class="lg">${lg}</span><span><b style="display:block;font-size:14px">${th(nm)}</b><span class="sub" style="font-size:12px">${th(s)}</span></span><span class="rd"></span></button>`).join("")}</div>
      ${S.payOnline ? "" : `<p class="sub" style="margin:10px 0 0;font-size:12px">${esc(S.tikkieText)}</p>`}</div>
    <div id="promo"></div>
    <button class="cta" style="margin-top:16px" id="payBtn" type="submit"></button>
    <p class="err" id="coErr" role="alert"></p>
    <div class="secure"><span>${th("shop.co.secure.1")}</span><span>${th("shop.co.secure.2")}</span><span>${th("shop.co.secure.3")}</span></div>
    <label class="legal" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="mk" ${saved.mk ? "checked" : ""}> ${th("shop.co.consent")}</label>
    <p class="legal">${th(S.payOnline ? "shop.co.legal_pay" : "shop.co.legal", {}, ' style="color:inherit"')}</p>
    </form>
  </div>`;
  go("checkout");
  Track.send("checkout_started");
  $("sumT").onclick = () => { $("sum").hidden = !$("sum").hidden; };
  const emailOk = () => /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test($("em").value.trim()), phoneOk = () => $("ph").value.replace(/\D/g, "").length >= 9;
  const validate = () => {
    $("b1").classList.toggle("valid", $("nm").value.trim().length > 1 && emailOk() && phoneOk());
    $("b2").classList.toggle("valid", !!co.zone && !!co.addr && (co.addr.street || "").length > 1);
    $("b3").classList.toggle("valid", !!co.slot);
    ls.set("d01-co", { name: $("nm").value, email: $("em").value, phone: $("ph").value, pc: $("coPc").value, no: $("coNo").value, mk: $("mk").checked });
  };
  // with consent, the bag remembers how to reach you (one reminder email if you leave); without it, nothing is kept
  let contactT;
  const syncContact = () => { clearTimeout(contactT); contactT = setTimeout(() => { const yes = $("mk").checked && emailOk();
    api("/api/cart", { id: BAG.id, items: cart.map(l => ({ variant_id: l.vid, qty: l.qty })), contact: yes ? { email: $("em").value, phone: $("ph").value, name: $("nm").value, consent: true } : { consent: false } }).catch(() => {}); }, 600); };
  ["nm", "em", "ph", "mk"].forEach(id => $(id).addEventListener("change", syncContact));
  $("mk").addEventListener("change", validate);
  // discount or friend's code
  const renderPromo = () => {
    const C = co.code;
    $("promo").innerHTML = C ? `<div class="pr-on"><span>✓ <b class="mono">${esc(C.code)}</b> · ${esc(C.label)}</span><button type="button" class="pr-x" id="prX">${th("shop.co.code_remove")}</button></div>`
      : co.codeOpen ? `<div class="pcrow"><input class="field" id="prIn" placeholder="${esc(t("shop.co.code_ph"))}" aria-label="Discount or friend’s code" autocomplete="off" autocapitalize="characters" spellcheck="false" value="${esc(ls.get("d01-code") || "")}"><button type="button" class="btn" id="prBtn">${th("shop.co.code_apply")}</button></div><p class="err" id="prErr" role="alert">${esc(co.codeErr)}</p>`
      : `<button type="button" class="pr-open" id="prOpen">${th("shop.co.code_open")}</button>`;
    if ($("prX")) $("prX").onclick = () => { co.code = null; co.codeOpen = true; co.codeErr = ""; ls.set("d01-code", null); renderPromo(); renderSum(); };
    if ($("prOpen")) $("prOpen").onclick = () => { co.codeOpen = true; renderPromo(); $("prIn").focus(); };
    if ($("prBtn")) { $("prBtn").onclick = () => applyCode($("prIn").value); $("prIn").onkeydown = e => { if (e.key === "Enter") { e.preventDefault(); applyCode($("prIn").value); } }; }
  };
  const applyCode = async (code, quiet) => {
    code = String(code || "").trim(); if (!code) return;
    try {
      const r = await api("/api/code-check", { code, email: $("em").value, phone: $("ph").value, items: cart.map(l => ({ variant_id: l.vid, qty: l.qty })) });
      co.code = r; co.codeErr = ""; ls.set("d01-code", r.code); if (!quiet) toast("✓ " + r.label);
    } catch (err) { co.code = null; co.codeOpen = true; co.codeErr = err.message; ls.set("d01-code", code); }
    renderPromo(); renderSum();
  };
  // the waitlist gift: matched on email or phone by the server
  let giftT;
  const giftCheck = () => { clearTimeout(giftT); giftT = setTimeout(async () => { if (!emailOk() && !phoneOk()) return;
    try { const r = await api("/api/gift-check", { email: $("em").value, phone: $("ph").value }); if (r.gift !== co.gift) { co.gift = r.gift; renderSum(); if (r.gift) toast(t("shop.co.gift_added")); } } catch {} }, 500); };
  ["nm", "em", "ph"].forEach(id => $(id).addEventListener("input", validate));
  ["em", "ph"].forEach(id => $(id).addEventListener("change", giftCheck));
  ["em", "ph"].forEach(id => $(id).addEventListener("change", () => { if (co.code) applyCode(co.code.code, true); }));
  // address: zone from the postcode at once, street and map position from PDOK
  let addrT, addrSeq = 0;
  const parseNo = () => { const m = $("coNo").value.trim().match(/^(\d+)\s*[-\s]?\s*([A-Za-z0-9]{0,6})/); return m ? { no: m[1], add: m[2] || "" } : { no: "", add: "" }; };
  const addr = () => {
    const z = zoneFor($("coPc").value); co.zone = z || null; co.addr = null;
    const pc = $("coPc").value.replace(/\s/g, "").toUpperCase(), { no, add } = parseNo();
    if (z === null) $("coAddr").innerHTML = pc.length >= 6 ? `<span style="color:var(--bad)">✕</span> ${th("shop.co.addr_bad")}` : "";
    else if (!z) $("coAddr").innerHTML = `<span style="color:var(--bad)">✕</span> ${th("shop.co.addr_no")}`;
    else if (!no || pc.length < 6) $("coAddr").innerHTML = `<span style="color:var(--good)">✓</span> ${esc(pc)} · <b>${esc(z.name)}</b>`;
    else {
      $("coAddr").innerHTML = `<span class="confirming"><i></i>${th("shop.co.addr_looking")}</span>`;
      clearTimeout(addrT); const seq = ++addrSeq;
      addrT = setTimeout(async () => {
        let a = null; try { a = (await api(`/api/address?pc=${encodeURIComponent(pc)}&no=${encodeURIComponent(no)}&add=${encodeURIComponent(add)}`)).address; } catch {}
        if (seq !== addrSeq) return;
        if (a) { co.addr = { ...a, addition: add || a.addition }; $("coAddr").innerHTML = `<span style="color:var(--good)">✓</span> ${esc(a.street)} ${esc(no)}${add ? esc(add) : ""}, ${esc(pc)} · <b>${esc(z.name)}</b>`; }
        else { co.addr = { postcode: pc, house_no: no, addition: add, street: "", city: "Groningen" };
          $("coAddr").innerHTML = `<span style="color:var(--warn)">!</span> ${th("shop.co.addr_missing")}<input class="field" id="coStreet" placeholder="${esc(t("shop.co.street_ph"))}" autocomplete="address-line1">`;
          $("coStreet").oninput = () => { co.addr.street = $("coStreet").value.trim(); validate(); }; }
        renderSum(); validate();
      }, 350);
    }
    renderSum(); validate();
  };
  $("coPc").oninput = $("coNo").oninput = addr;
  const slots = (S.slots || []).filter(s => s.open);
  if (!co.slot || !slots.find(s => s.id === co.slot && s.available)) co.slot = (slots.find(s => s.available) || {}).id || null;
  const renderSlots = () => {
    const days = [...new Set(slots.map(s => s.date))];
    $("slots").innerHTML = days.length ? days.map((d, di) => { const ss = slots.filter(s => s.date === d); return `<div class="mono" style="margin:${di ? 14 : 0}px 0 8px">${esc(ss[0].label.replace(/ \d\d:\d\d.*$/, ""))}</div><div class="slots">${ss.map(s =>
      `<button type="button" class="slot" data-i="${s.id}" ${s.available ? "" : "disabled"} aria-pressed="${s.id === co.slot}"><b>${s.start}–${s.end}</b><span class="${s.available && s.left <= 2 ? "left" : ""}">${s.available ? (s.left <= 2 ? th(s.left > 1 ? "shop.co.slot_only_many" : "shop.co.slot_only_one", { n: s.left }) : th("shop.co.slot_spots", { n: s.left })) : th("shop.co.slot_full")}</span></button>`).join("")}</div>`; }).join("")
      : `<p class="sub" style="margin:0">${th("shop.co.slots_none")}</p>`;
    $("slots").querySelectorAll(".slot").forEach(b => b.onclick = () => { co.slot = +b.dataset.i; renderSlots(); renderSum(); validate(); }); };
  $("pays").querySelectorAll(".pay").forEach(b => b.onclick = () => { if (b.disabled) return; co.pay = b.dataset.k; $("pays").querySelectorAll(".pay").forEach(x => x.setAttribute("aria-pressed", x === b)); renderSum(); });
  const renderSum = () => {
    const tt = totals(co.zone), s = slots.find(x => x.id === co.slot), vat = Math.round(tt.grand - tt.grand / 1.21);
    $("sum").innerHTML = `${cart.map(l => { const v = VAR[l.vid]; return `<div class="row"><span>${l.qty}× ${esc(v.p.name)}${showSizes(v.p) ? " · " + esc(v.size) : ""}</span><span class="num">${eur(v.p.price * l.qty)}</span></div>`; }).join("")}
      ${giftKnown() ? `<div class="row"><span>🎁 ${esc(S.gift.title)}</span><span style="color:var(--good)">${th("shop.co.free")}</span></div>` : ""}
      ${tt.disc ? `<div class="row" style="color:var(--good)"><span>${esc(S.bundle.name)} −${S.bundle.pct}%</span><span class="num">−${eur(tt.disc)}</span></div>` : ""}
      ${co.code ? `<div class="row" style="color:var(--good)"><span>${esc(co.code.code)} · ${esc(co.code.label)}</span><span class="num">${tt.codeOff ? "−" + eur(tt.codeOff) : "✓"}</span></div>` : ""}
      <div class="row"><span>${th("shop.co.delivery")}${s ? " · " + esc(s.label) : ""}</span><span>${co.zone ? (tt.fee ? eur(tt.fee) : th("shop.co.free")) : "—"}</span></div>
      <div class="row t"><span>${th("shop.co.total")} <span class="sub" style="font-weight:500">${th("shop.co.incl_btw", { vat: eur(vat) })}</span></span><span class="num">${eur(tt.grand)}</span></div>`;
    $("sumTop").textContent = eur(tt.grand);
    $("payBtn").textContent = t(co.pay === "tikkie" ? (S.payOnline ? "shop.co.btn_tikkie" : "shop.co.btn_order") : co.pay === "card" ? "shop.co.btn_card" : "shop.co.btn_ideal", { total: eur(tt.grand) });
    $("payBtn").disabled = co.busy || !co.zone || !co.slot;
  };
  const pay = async () => {
    validate();
    const bad = ["b1", "b2", "b3"].map($).find(b => !b.classList.contains("valid"));
    if (bad) { bad.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" });
      bad.animate([{ transform: "translateX(0)" }, { transform: "translateX(-6px)" }, { transform: "translateX(6px)" }, { transform: "translateX(0)" }], { duration: 300 }); toast(t("shop.co.one_more")); return; }
    co.busy = true; $("coErr").textContent = ""; renderSum();
    $("payBtn").textContent = t(co.pay === "tikkie" ? "shop.co.placing" : "shop.co.opening");
    try {
      const a = co.addr || {};
      const r = await api("/api/checkout", { name: $("nm").value, email: $("em").value, phone: $("ph").value, postcode: $("coPc").value, house_no: a.house_no || parseNo().no, addition: a.addition || parseNo().add,
        street: a.street || "", city: a.city || "", lat: a.lat, lng: a.lng, slot_id: co.slot, method: co.pay, marketing: $("mk").checked, code: co.code ? co.code.code : "",
        items: cart.map(l => ({ variant_id: l.vid, qty: l.qty })), sid: Track.sid, cart_id: BAG.id, website: $("hp").value });
      ls.set("d01-pending", { at: Date.now() }); ls.set("d01-code", null);
      location.href = r.redirect;
    } catch (err) { co.busy = false; $("coErr").textContent = err.message; if (co.code && /code/i.test(err.message)) { co.code = null; co.codeOpen = true; co.codeErr = err.message; renderPromo(); } renderSum(); }
  };
  $("coForm").onsubmit = e => { e.preventDefault(); pay(); };
  document.querySelectorAll("#v-checkout [data-exp]").forEach(b => b.onclick = () => { co.pay = b.dataset.exp; $("pays").querySelectorAll(".pay").forEach(x => x.setAttribute("aria-pressed", x.dataset.k === co.pay)); renderSum(); pay(); });
  renderSlots(); addr(); validate(); giftCheck();
  // a code from a friend's link (?ref=) or typed earlier is applied by itself
  const pending = ls.get("d01-code");
  if (co.code) applyCode(co.code.code, true); else if (pending) applyCode(pending, true); else renderPromo();
}

/* ---------- order page: "It's yours." with the real status ---------- */
const CHECK = `<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>`;
function showOrder(o){
  const tikkie = o.method === "tikkie", paid = o.paid, cancelled = o.cancelled;
  const FLOW = ["paid", "packing", "out_for_delivery", "delivered"], st = o.status, after = s => FLOW.indexOf(st) >= FLOW.indexOf(s);
  const step = (on, cur, title, sub) => `<div class="tst ${on ? "on" : ""} ${cur ? "cur" : ""}"><div class="d">${on ? CHECK : ""}</div><div><b>${title}${cur ? `<em>${th("shop.order.now")}</em>` : ""}</b><span>${sub}</span></div></div>`;
  const wa = S.company.whatsapp ? "https://wa.me/" + S.company.whatsapp.replace(/\D/g, "") : null;
  const lead = `font-size:15px;line-height:1.5;max-width:32ch;margin:0 auto`;
  const hi = o.first ? th("shop.order.paid_hi", { name: o.first }) : "", num = { number: o.number };
  const head = cancelled ? `<div class="mono">${th("shop.order.cancelled_kicker", num)}</div><h1 class="display">${th("shop.order.cancelled_title")}</h1>
      <p class="sub" style="${lead}">${th("shop.order.cancelled_text")}</p>`
    : paid ? `<div class="tick">${CHECK}</div><div class="mono">${th("shop.order.paid_kicker", num)}</div><h1 class="display holo-t">${th("shop.order.paid_title")}</h1>
      <p class="sub" style="${lead}">${hi}${th("shop.order.paid_text")}</p>`
    : tikkie ? `<div class="mono">${th("shop.order.reserved_kicker", num)}</div><h1 class="display holo-t">${th("shop.order.reserved_title")}</h1>
      <p class="sub" style="${lead}">${esc(S.tikkieText)}</p>${o.pay_url ? `<p style="margin-top:16px"><a class="cta" style="text-decoration:none;max-width:320px;margin:0 auto" href="${esc(o.pay_url)}" rel="noopener">${th("shop.order.pay_tikkie")}</a></p>` : ""}`
    : `<div class="mono">${th("shop.order.confirming_kicker", num)}</div><h1 class="display">${th("shop.order.confirming_title")}</h1>
      <p class="sub confirming" style="font-size:15px;justify-content:center;width:100%" id="confirmTxt"><i></i>${th("shop.order.confirming_text")}</p>`;
  const first = o.items.map(i => (i.qty > 1 ? i.qty + "× " : "") + i.name).join(", ");
  const n = o.items.reduce((s, i) => s + i.qty, 0), extra = o.total - o.items.reduce((s, i) => s + i.cents, 0);
  const line = i => { const p = byId(i.product_id);
    return `<div class="oi">${p ? art(p) : `<div class="art"></div>`}<div><b>${esc(i.name)}</b><span class="sub">${i.qty > 1 ? `${i.qty} × ${eur(i.cents / i.qty)}` : th("shop.order.piece")}</span></div><span class="num">${i.cents ? eur(i.cents) : th("shop.order.free")}</span></div>`; };
  // the window filled up while paying, or nobody was home: the customer picks a new one right here
  const mustMove = o.can_move && (o.needs_new_slot || st === "failed_delivery");
  const when = o.slot ? `<div class="card when" id="time">${mustMove ? `<div class="mv-alert">${th(st === "failed_delivery" ? "shop.order.missed" : "shop.order.filled")}</div>` : ""}
      <div class="mono">${th(after("delivered") ? "shop.order.delivered_label" : "shop.order.your_delivery")}</div>
      <div class="display wh">${esc(o.slot)}</div>
      <p class="sub">${th("shop.order.door", { address: o.address || o.zone || t("shop.order.city") })}</p>
      ${o.slot_date && !after("delivered") ? `<a class="btn ghost cal" target="_blank" rel="noopener" href="${calUrl(o)}"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="3"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>${th("shop.order.calendar")}</a>` : ""}
      ${o.can_move ? `<div id="mv">${mustMove ? "" : `<button class="btn ghost cal" id="mvBtn"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>${th("shop.order.change")}</button>`}</div>` : ""}
    </div>` : "";
  $("v-ok").innerHTML = `<div class="ok">${head}</div>
    ${cancelled ? `<div class="okb"><button class="cta" data-act="home">${th("shop.order.back_shop")}</button></div>` : `
    ${when}
    <div class="card track-st">
      ${step(true, false, th("shop.order.placed"), esc(first) + (o.gift ? " + 🎁" : ""))}
      ${step(paid, !paid, th("shop.order.paid"), paid ? esc(o.method_label) : th(tikkie ? "shop.order.waiting_tikkie" : "shop.order.confirming_bank"))}
      ${step(after("packing"), paid && st === "paid", th("shop.order.packing"), th("shop.order.packing_sub"))}
      ${step(after("out_for_delivery"), st === "packing", th("shop.order.on_way"), esc(o.slot))}
      ${step(after("delivered"), st === "out_for_delivery", th("shop.order.delivered"), esc(o.zone || t("shop.order.city")))}
    </div>
    <div id="retry"></div>
    <div class="card osum">
      <div class="mono">${th(n > 1 ? "shop.order.items_many" : "shop.order.items_one", { n })}${o.gift ? th("shop.order.plus_gift") : ""}</div>
      ${o.items.map(line).join("")}
      ${extra + (o.discount || 0) ? `<div class="ex"><span>${th("shop.order.delivery")}</span><span class="num">${eur(extra + (o.discount || 0))}</span></div>` : ""}
      ${o.discount ? `<div class="ex"><span>${o.code ? th("shop.order.code", { code: o.code }) : th("shop.order.discount")}</span><span class="num">−${eur(o.discount)}</span></div>` : ""}
      <div class="tot"><span>${th(paid ? "shop.order.total_paid" : "shop.order.total")}</span><b class="num">${eur(o.total)}</b></div>
    </div>
    ${o.referral ? `<div class="card refer"><div class="mono">${th("shop.order.ref_kicker")}</div><h3>${th("shop.order.ref_title", { friend: eur(S.referral.friend_cents), referrer: eur(S.referral.referrer_cents) })}</h3><p class="sub" style="margin:0">${th("shop.order.ref_text", { friend: eur(S.referral.friend_cents) })}</p>
      <button class="code" id="refCode" aria-label="Copy code ${esc(o.referral)}">${esc(o.referral)}<small>${th("shop.order.ref_copy")}</small></button>
      <a class="cta" style="text-decoration:none" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(t("shop.order.ref_msg", { code: o.referral, friend: eur(S.referral.friend_cents), store: S.store, url: `${location.origin}/?ref=${encodeURIComponent(o.referral)}` }))}">${th("shop.order.ref_share")}</a></div>` : ""}
    <div class="okb">
      ${paid ? `<button class="${o.delivered ? "cta" : "btn ghost"}" id="againBtn">${th("shop.order.again")}</button>` : ""}
      ${o.telegram ? `<a class="btn ghost" target="_blank" rel="noopener" href="${esc(o.telegram)}">${th("shop.order.telegram")}</a>` : ""}
      ${wa ? `<a class="btn ghost" target="_blank" rel="noopener" href="${wa}?text=${encodeURIComponent(t("shop.order.wa_msg", num))}">${th("shop.order.whatsapp")}</a>` : ""}
      <button class="btn ghost" data-act="home">${th("shop.order.back")}</button></div>`}`;
  if ($("againBtn")) $("againBtn").onclick = () => orderAgain(o.items);
  if ($("mv")) { if (mustMove || location.hash === "#time") openMove(o); else $("mvBtn").onclick = () => openMove(o); }
  if (location.hash === "#time" && $("time")) setTimeout(() => $("time").scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "start" }), 300);
  const rc = $("refCode"); if (rc) rc.onclick = async () => { try { await navigator.clipboard.writeText(o.referral); toast(t("shop.order.ref_copied")); } catch { toast(o.referral); } };
  view = "ok"; document.querySelectorAll(".view").forEach(x => x.classList.toggle("on", x.id === "v-ok")); updBag();
  if (paid) {
    if (cart.length) { cart = []; saveBag(); updBag(); }
    const seen = ls.get("d01-bought") || [];
    if (!seen.includes(o.number)) { seen.push(o.number); ls.set("d01-bought", seen.slice(-20)); Track.send("purchase", { value: String(o.total) }); vib([20, 40, 60]); if (!reduce) confetti(); }
  }
}
/* ---------- change the delivery window from the order page ---------- */
async function openMove(o){
  const box = $("mv"); if (!box) return;
  box.innerHTML = `<p class="sub confirming" style="margin-top:14px"><i></i>${th("shop.move.loading")}</p>`;
  let slots = S.slots || []; try { slots = (await api("/api/slots")).slots; } catch {}
  const keepCurrent = o.needs_new_slot || o.status === "failed_delivery";
  slots = slots.filter(s => s.open && s.available && (keepCurrent || s.id !== o.slot_id));
  let pick = null;
  const draw = () => {
    const days = [...new Set(slots.map(s => s.date))];
    box.innerHTML = `<div class="mv-pick"><div class="mono" style="margin:16px 0 8px">${th("shop.move.pick")}</div>${days.length ? days.map(d => { const ss = slots.filter(s => s.date === d);
      return `<div class="mono mv-day">${esc(ss[0].label.replace(/ \d\d:\d\d.*$/, ""))}</div><div class="slots">${ss.map(s => `<button type="button" class="slot" data-mv="${s.id}" aria-pressed="${s.id === pick}"><b>${s.start}–${s.end}</b><span class="${s.left <= 2 ? "left" : ""}">${s.left <= 2 ? th("shop.move.only_left", { n: s.left }) : th("shop.move.spots", { n: s.left })}</span></button>`).join("")}</div>`; }).join("")
      : `<p class="sub">${th("shop.move.none")}</p>`}
      ${days.length ? `<button class="cta" id="mvGo" style="margin-top:14px" ${pick ? "" : "disabled"}>${pick ? th("shop.move.to", { slot: slots.find(s => s.id === pick).label }) : th("shop.move.pick_btn")}</button>` : ""}
      <p class="err" id="mvErr" role="alert"></p></div>`;
    box.querySelectorAll("[data-mv]").forEach(b => b.onclick = () => { pick = +b.dataset.mv; draw(); });
    if ($("mvGo")) $("mvGo").onclick = async () => { $("mvGo").disabled = true; $("mvGo").textContent = t("shop.move.moving");
      try { const n = await api(`/api/orders/${o.number}/reschedule`, { t: o.t, slot_id: pick }); try { history.replaceState(history.state, "", location.pathname + location.search); } catch {} showOrder(n); toast(t("shop.move.done", { slot: n.slot })); }
      catch (e) { $("mvErr").textContent = e.message; $("mvGo").disabled = false; $("mvGo").textContent = t("shop.move.try_again"); } };
  };
  draw();
}

/* ---------- order again: the same things back in the bag (what is still in stock) ---------- */
function orderAgain(items){
  const gone = [];
  let added = 0;
  (items || []).forEach(i => {
    const v = VAR[i.variant_id];
    if (!v || v.stock <= 0) { gone.push(i.name); return; }
    const l = cart.find(x => x.vid === v.id), q = Math.min(i.qty, room(v));
    if (q <= 0) return;
    if (l) l.qty += q; else cart.push({ vid: v.id, qty: q });
    added += q;
  });
  saveBag(); updBag();
  if (added) { go("home"); openCart(); }
  toast(added ? (gone.length ? t("shop.again.added_some", { names: gone.join(", ") }) : t("shop.again.added")) : t("shop.again.none"));
}
function calUrl(o){
  const d = (t) => o.slot_date.replace(/-/g, "") + "T" + t.replace(":", "") + "00";
  return "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" + encodeURIComponent(t("shop.order.calendar_title", { store: S.store, number: o.number })) + "&dates=" + d(o.start) + "/" + d(o.end) + "&ctz=Europe/Amsterdam";
}
async function pollOrder(o){
  showOrder(o);
  if (o.paid || o.cancelled || o.method === "tikkie") return;
  const t0 = Date.now();
  while (Date.now() - t0 < 20000) {
    await new Promise(r => setTimeout(r, 2000));
    try { o = await api(`/api/orders/${o.number}/status?t=${encodeURIComponent(o.t)}`); } catch { continue; }
    if (o.paid || o.cancelled) return showOrder(o);
  }
  // still not confirmed: never a blank page — explain and offer to try again
  const c = $("confirmTxt"); if (c) c.innerHTML = th("shop.order.not_received");
  $("retry").innerHTML = `<div class="okb"><button class="cta" id="retryBtn">${th("shop.order.retry")}</button><button class="btn ghost" id="reloadBtn">${th("shop.order.check_again")}</button></div>`;
  $("retryBtn").onclick = async () => { try { location.href = (await api(`/api/orders/${o.number}/pay`, { t: o.t })).redirect; } catch (e) { toast(e.message); } };
  $("reloadBtn").onclick = () => pollOrder(o);
}

/* ---------- particles ---------- */
const fx = $("fx"), cx = fx.getContext("2d"); let parts = [], running = false;
const COLS = ["#FFB3D9", "#FFE7A3", "#B8FFD9", "#A8DCFF", "#D4B8FF", "#F3F1EC"];
function sizeFx(){ const k = Math.min(devicePixelRatio || 1, 2); fx.width = innerWidth * k; fx.height = innerHeight * k; cx.setTransform(k, 0, 0, k, 0, 0); }
sizeFx(); addEventListener("resize", sizeFx);
function loopFx(){ cx.clearRect(0, 0, innerWidth, innerHeight);
  parts = parts.filter(p => p.l < p.m);
  for (const p of parts) { p.l++; p.vy += p.g; p.vx *= .985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
    cx.save(); cx.globalAlpha = 1 - p.l / p.m; cx.translate(p.x, p.y); cx.rotate(p.r); cx.fillStyle = p.c;
    if (p.star) { cx.beginPath(); cx.arc(0, 0, p.s, 0, 6.283); cx.fill(); } else cx.fillRect(-p.s, -p.s * .45, p.s * 2, p.s * .9); cx.restore(); }
  if (parts.length) requestAnimationFrame(loopFx); else running = false; }
function kick(){ if (!running) { running = true; requestAnimationFrame(loopFx); } }
function sparkle(x, y, n){ if (reduce) return; for (let i = 0; i < n; i++) { const a = Math.random() * 6.283, v = 1.5 + Math.random() * 3; parts.push({ x, y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, g: .05, s: 1.5 + Math.random() * 1.8, c: COLS[i % 6], l: 0, m: 30 + Math.random() * 20, r: 0, vr: 0, star: 1 }); } kick(); }
function confetti(){ for (let i = 0; i < 150; i++) { const side = i % 2, a = -1.35 + Math.random() * .75, v = 9 + Math.random() * 9;
  parts.push({ x: side ? innerWidth + 10 : -10, y: innerHeight * .78, vx: Math.cos(a) * v * (side ? -1 : 1), vy: Math.sin(a) * v, g: .22, s: 4 + Math.random() * 4, c: COLS[i % 6], l: 0, m: 110 + Math.random() * 50, r: Math.random() * 6, vr: (Math.random() - .5) * .3 }); }
  kick(); }

/* ---------- live activity: real paid orders of the last 24 h only; hidden when there are none ---------- */
let ACT = [], actI = 0;
async function loadActivity(){ try { ACT = (await api("/api/activity")).items || []; } catch { ACT = []; } }
function proof(){
  if (!ACT.length || view !== "home" || $("drawer").classList.contains("on") || document.hidden) return;
  const a = ACT[actI++ % ACT.length], p = P.find(x => x.slug === a.product);
  const m = a.min < 60 ? t("shop.ago.min", { n: a.min }) : t("shop.ago.h", { n: Math.round(a.min / 60) });
  $("proof").innerHTML = `<i style="--a:${p ? (p.images.length ? `url('${esc(p.images[0].sm)}') center/cover` : p.a) : ARTS[0]}"></i><span>${th("shop.proof", { zone: a.zone, product: a.name, ago: m })}</span>`;
  $("proof").classList.add("on"); setTimeout(() => $("proof").classList.remove("on"), 3800);
}

/* ---------- foil follows the scroll ---------- */
let sxPending = false;
addEventListener("scroll", () => { if (sxPending) return; sxPending = true; requestAnimationFrame(() => { document.documentElement.style.setProperty("--sx", (50 + Math.sin(scrollY / 260) * 45).toFixed(1)); sxPending = false; }); }, { passive: true });

/* ---------- buttons with data-act ---------- */
document.addEventListener("click", e => {
  if (e.target.closest('a[href="#list"]')) { e.preventDefault(); $("listEmail").scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" }); setTimeout(() => $("listEmail").focus(), 400); return; }
  const b = e.target.closest("[data-act]"); if (!b) return;
  const a = b.dataset.act;
  if (a !== "checkPc") e.preventDefault();
  if (a === "home") go("home");
  if (a === "home1") go("home", 1);
  if (a === "cart") openCart();
  if (a === "closeCart") closeCart();
  if (a === "closeHome") { closeCart(); go("home"); }
  if (a === "checkPc") checkPc();
  if (a === "toShop") $("shop").scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
  if (a === "toList") $("listEmail").scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" }), setTimeout(() => $("listEmail").focus(), 400);
  if (a === "search") { if (view !== "home") go("home"); $("searchRow").hidden = false; $("shop").scrollIntoView({ behavior: reduce ? "auto" : "smooth" }); setTimeout(() => $("q").focus(), 300); }
});
$("q").addEventListener("input", () => { query = $("q").value.trim().toLowerCase(); renderGrid(); });
$("listForm").onsubmit = async e => { e.preventDefault(); try { await api("/api/list", { email: $("listEmail").value }); $("listEmail").value = ""; toast(t("shop.list.done")); } catch (err) { toast(err.message); } };

/* ---------- boot ---------- */
$("leftAll").textContent = P.reduce((s, p) => s + p.stock, 0);
try { const pc = JSON.parse(localStorage.getItem("d01-pc") || "null"); if (pc) { $("pcIn").value = pc; checkPc(true); $("pcRes").hidden = true; } } catch {}
renderFan(); renderGrid(); renderBundle(); tick(); tickPromise(); updBag();
if (S.endsAt) setInterval(tick, 1000);
setInterval(tickPromise, 30000);
loadActivity().then(() => { setTimeout(proof, 6000); setInterval(proof, 15000); });
// links from messages: ?ref=NAME-D01 or ?code=… (applied at checkout), ?bag=… (the reminder email puts the bag back)
(function(){
  const sp = new URLSearchParams(location.search), code = sp.get("ref") || sp.get("code"), bag = sp.get("bag"), again = sp.get("reorder"), tok = sp.get("t");
  if (again && /^\d+$/.test(again) && tok) api(`/api/orders/${again}/status?t=${encodeURIComponent(tok)}`).then(o => orderAgain(o.items)).catch(() => toast(t("shop.again.expired")));
  if (code && /^[A-Za-z0-9-]{3,40}$/.test(code)) { ls.set("d01-code", code.toUpperCase()); toast(t("shop.code.saved", { code: code.toUpperCase() })); }
  if (bag && /^[a-f0-9-]{16,40}$/i.test(bag)) api("/api/cart/" + bag).then(r => {
    const items = (r.items || []).filter(i => VAR[i.variant_id]);
    if (!items.length) return;
    if (cart.length && bag !== BAG.id) return openCart();   // a newer bag on this phone wins
    cart = items.map(i => ({ vid: i.variant_id, qty: Math.min(i.qty, VAR[i.variant_id].stock) })).filter(l => l.qty > 0);
    BAG.id = bag; saveBag(); updBag(); openCart();
  }).catch(() => {});
  if (code || bag || again) { sp.delete("ref"); sp.delete("code"); sp.delete("bag"); sp.delete("reorder"); sp.delete("t"); try { history.replaceState(history.state, "", location.pathname + (sp.toString() ? "?" + sp : "")); } catch {} }
})();
if (START.view === "ok" && START.order) pollOrder(START.order);
else if (START.view === "checkout" && cart.length) openCheckout();
else if (START.product) { const p = P.find(x => x.slug === START.product); if (p) openProduct(p.id, true); }

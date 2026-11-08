/* Admin app (the real-data version of prototypes/crm.html + prototypes/waitlist-dashboard.html).
   Today, Orders, Inbox (email + Telegram chats), Delivery + courier mode, Stock, Customers, Waitlist dashboard, Money (margins per product), Settings, Team, System. No fake numbers anywhere. */
"use strict";
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const fmt = n => Number(n || 0).toLocaleString("nl-NL");
const eur = c => "€" + (c / 100).toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const euroIn = c => (c / 100).toFixed(2).replace(".", ",");
const pct = (a, b) => b ? Math.round(a / b * 100) : 0;
let CSRF = (window.ADMIN || {}).csrf || "", ME = null;

const ICON = {
  warn: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 4l9 16H3zM12 10v4M12 17h.01"/></svg>',
  card: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/></svg>',
  box: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9zM4 7.5l8 4.5 8-4.5"/></svg>',
  mail: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
  clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  gift: '<svg class="ic" viewBox="0 0 24 24"><rect x="3.5" y="9" width="17" height="11.5" rx="1.5"/><path d="M2.5 9h19M12 9v11.5M12 9c-1.5-3.5-6-4-6-1.5S10 9 12 9Zm0 0c1.5-3.5 6-4 6-1.5S14 9 12 9Z"/></svg>',
  doc: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h5"/></svg>',
  chev: '<svg class="ic chev" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>',
  bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12l1 18H5zM9 7a3 3 0 0 0 6 0"/></svg>',
  ok: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  nav: '<svg class="ic" viewBox="0 0 24 24"><path d="M3.5 10.5L20.5 3.5l-7 17-2.2-7.8z"/></svg>',
  phone: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 3.5h3.5l2 5-2.5 1.5a11 11 0 0 0 6 6l1.5-2.5 5 2V19a2 2 0 0 1-2 2A17 17 0 0 1 3 5.5a2 2 0 0 1 2-2z"/></svg>',
  x: '<svg class="ic" viewBox="0 0 24 24"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>',
  truck: '<svg class="ic" viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg>',
  chat: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 5.5h16v10.5H10l-4.5 3.5V16H4z"/><path d="M8 9.5h8M8 12.5h5"/></svg>',
  tg: '<svg class="ic" viewBox="0 0 24 24"><path d="M21 4 2.8 11.1l6 2.1L18 7.2l-7.4 7.4.3 5.4 3.1-3.6 4.6 3.4z"/></svg>',
  people: '<svg class="ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/></svg>',
};

/* ---------- API ---------- */
async function api(path, body, isForm, again){
  const o = { headers: { "X-CSRF": CSRF }, cache: "no-store", credentials: "same-origin" };
  if (body !== undefined) { o.method = "POST"; if (isForm) o.body = body; else { o.headers["Content-Type"] = "application/json"; o.body = JSON.stringify(body); } }
  let r; try { r = await fetch(path, o); } catch { throw new Error("No connection. Check your internet and try again."); }
  let j = null; try { j = await r.json(); } catch {}
  if (r.status === 401 && !path.endsWith("/login")) { showLogin(); throw new Error("Please log in again."); }
  if (r.status === 403 && j && j.reauth && !again) { if (await reauth()) return api(path, body, isForm, true); throw new Error("Not done: the password is needed for this."); }
  if (!r.ok) throw new Error((j && j.error) || "Something went wrong (" + r.status + ").");
  return j;
}
/* Risky actions (backups, the contacts CSV, team changes) want the password typed in the last 15 minutes. Resolves true once it is. */
async function reauth(){
  for (let msg = "For your safety, type your password again. You won’t be asked again for 15 minutes."; ;) {
    const pass = await ask(msg, { title: "Confirm it’s you", ok: "Continue", input: "", type: "password", placeholder: "Your password" });
    if (pass == null) return false;
    try { await api("/api/admin/reauth", { password: pass }, false, true); return true; } catch (e) { msg = e.message; }
  }
}
/* Download links that need a fresh password: check first, then follow the link. */
document.addEventListener("click", async e => {
  const a = e.target.closest("a[data-sudo]"); if (!a) return;
  if (a.dataset.ok) { delete a.dataset.ok; return; }
  e.preventDefault();
  try { await api("/api/admin/reauth"); } catch (err) { return toastErr(err.message); }
  a.dataset.ok = 1; a.click();
});

/* toast(t) for good news; toast(t, { act: "Undo", fn }) adds a button and stays a little longer */
function toast(t, a){
  const el = $("toast"); el.textContent = t; el.classList.toggle("has-act", !!a);
  if (a) { const b = document.createElement("button"); b.type = "button"; b.textContent = a.act; b.onclick = () => { el.classList.remove("on"); a.fn(); }; el.appendChild(b); }
  el.classList.add("on"); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove("on"), a ? 6000 : 2200);
}
/* errors stay on screen, in red, until the owner closes them; the same error twice shows once with a count */
function toastErr(t){
  const box = $("errs"), same = [...box.children].find(x => x.dataset.t === t);
  if (same) { same.dataset.n = +same.dataset.n + 1; same.querySelector(".n").textContent = "×" + same.dataset.n; return; }
  const d = document.createElement("div"); d.className = "err"; d.dataset.t = t; d.dataset.n = 1;
  d.innerHTML = `${ICON.warn}<span>${esc(t)} <b class="n"></b></span><button type="button" aria-label="Close">${ICON.x}</button>`;
  d.querySelector("button").onclick = () => d.remove();
  box.appendChild(d); while (box.children.length > 3) box.firstChild.remove();
}
/* ask(): the app's own confirm. Resolves true/false, or with { input } the typed text / null. danger paints the button red. */
function ask(msg, o = {}){
  return new Promise(res => {
    const el = $("ask"), ok = $("askOk"), no = $("askNo"), inp = $("askIn"), prev = document.activeElement;
    $("askT").textContent = o.title || "Are you sure?"; $("askM").textContent = msg;
    ok.textContent = o.ok || "OK"; ok.className = "btn" + (o.danger ? " bad" : ""); no.textContent = o.no || "Cancel";
    inp.hidden = o.input == null; inp.type = o.type || "text"; inp.value = o.input || ""; inp.placeholder = o.placeholder || "";
    el.classList.add("on");
    const end = v => { el.classList.remove("on"); ok.onclick = no.onclick = el.onclick = inp.onkeydown = ASK.esc = null; if (prev && prev.focus) prev.focus(); res(v); };
    ok.onclick = () => end(o.input == null ? true : inp.value);
    no.onclick = () => end(o.input == null ? false : null);
    el.onclick = e => { if (e.target === el) no.onclick(); };
    inp.onkeydown = e => { if (e.key === "Enter") { e.preventDefault(); ok.onclick(); } };
    ASK.esc = () => no.onclick();
    setTimeout(() => (o.input != null ? inp : o.danger ? no : ok).focus(), 30);
  });
}
const ASK = { esc: null };
function sheet(html){ delete $("sheetBody").dataset.order; $("sheetBody").innerHTML = html; $("scrim").classList.add("on"); $("sheet").classList.add("on"); $("sheet").scrollTop = 0; }
function closeSheet(){ $("scrim").classList.remove("on"); $("sheet").classList.remove("on"); }
$("scrim").onclick = closeSheet;
document.addEventListener("keydown", e => { if (e.key !== "Escape") return; if (ASK.esc) ASK.esc(); else closeSheet(); });
const busy = async (btn, fn) => { if (btn) btn.disabled = true; try { return await fn(); } catch (e) { toastErr(e.message); } finally { if (btn) btn.disabled = false; } };
const ago = iso => { if (!iso) return "never"; const m = Math.max(0, Math.round((Date.now() - new Date(iso)) / 6e4)); return m < 1 ? "just now" : m < 60 ? m + "m ago" : m < 1440 ? Math.round(m / 60) + "h ago" : Math.round(m / 1440) + "d ago"; };
const flag = cc => cc && /^[A-Z]{2}$/.test(cc) ? String.fromCodePoint(...[...cc].map(c => 127397 + c.charCodeAt(0))) : "";
const COUNTRY = { NL: "Netherlands", DE: "Germany", BE: "Belgium", GB: "UK", FR: "France", ES: "Spain", IT: "Italy", PL: "Poland", US: "USA", CA: "Canada", UA: "Ukraine", KZ: "Kazakhstan", RU: "Russia", TR: "Turkey", AE: "UAE" };
const toggle = (name, label, on, hint) => `<label class="tog"><span>${label}${hint ? `<small>${hint}</small>` : ""}</span><input type="checkbox" name="${name}" ${on ? "checked" : ""}><i></i></label>`;

/* ---------- login ---------- */
function showLogin(){ document.documentElement.classList.remove("cv"); $("app").hidden = true; $("nav").hidden = true; $("login").hidden = false; closeSheet(); setTimeout(() => $("lEmail").focus(), 50); }
$("loginForm").addEventListener("submit", async e => {
  e.preventDefault();
  $("loginErr").textContent = "";
  await busy($("lBtn"), async () => {
    try {
      const r = await api("/api/admin/login", { email: $("lEmail").value, password: $("lPass").value, totp: $("lTotp").value });
      if (r.needTotp) { $("lTotpRow").hidden = false; $("lTotp").focus(); $("loginErr").textContent = "Enter the code from your authenticator app."; return; }
      CSRF = r.csrf; ME = r.user; $("lPass").value = ""; $("lTotp").value = "";
      if (r.migrated && r.migrated.length) toast("Updates applied: " + r.migrated.join(", "));
      startApp();
    } catch (err) { $("loginErr").textContent = err.message; }
  });
});

/* ---------- navigation ---------- */
const TITLES = { home: "Today", orders: "Orders", inbox: "Inbox", delivery: "Delivery", stock: "Stock", customers: "Customers", waitlist: "Waitlist", marketing: "Marketing", money: "Money", packing: "Packing list", settings: "Settings", texts: "Texts", team: "Team", system: "System" };
const AREA = { home: "today", orders: "orders", inbox: "inbox", delivery: "delivery", stock: "stock", customers: "customers", waitlist: "waitlist", marketing: "marketing", money: "money", packing: "orders", settings: "settings", texts: "texts", team: "team", system: "system" };
const can = area => ME && (ME.access.includes("*") || ME.access.includes(area));
let current = null;
function go(s, sub){
  if (s === "more") { openMore(); return; }
  if (current === "settings" && s !== "settings" && settingsDirty()) {
    ask("Your changes in Settings are not saved yet.", { title: "Leave without saving?", ok: "Leave", no: "Stay", danger: true }).then(ok => {
      if (!ok) return;
      document.querySelectorAll("#s-settings form[data-dirty]").forEach(f => delete f.dataset.dirty);
      go(s, sub);
    });
    return;
  }
  if (current === "texts" && s !== "texts" && txDirty()) {
    ask("Your text changes are not saved yet.", { title: "Leave without saving?", ok: "Leave", no: "Stay", danger: true }).then(ok => { if (ok) { TXS.edits = {}; go(s, sub); } });
    return;
  }
  if (!can(AREA[s])) s = ["home", "delivery", "stock", "orders"].find(x => can(AREA[x])) || "home";
  current = s; closeSheet(); pmClose(); document.body.classList.remove("ib-thread");
  document.querySelectorAll(".screen").forEach(x => x.classList.toggle("on", x.id === "s-" + s));
  document.querySelectorAll(".nav button[data-s]").forEach(b => b.classList.toggle("on", b.dataset.s === s || (b.dataset.s === "more" && ["stock", "waitlist", "marketing", "money", "settings", "texts", "team", "system"].includes(s)) || (b.dataset.s === "orders" && s === "packing")));
  $("scrTitle").textContent = TITLES[s];
  $("fab").hidden = s !== "orders" || !can("orders");
  scrollTo(0, 0);
  try { history.replaceState(null, "", "/admin/" + s + (sub ? "#" + sub : "")); } catch {}
  (RENDER[s] || (() => {}))(sub);
}
document.querySelectorAll(".nav button[data-s]").forEach(b => b.onclick = () => go(b.dataset.s));
document.addEventListener("click", e => { const g = e.target.closest("[data-go]"); if (g) { e.preventDefault(); const [s, sub] = g.dataset.go.split(":"); go(s, sub); } });
function openMore(){
  const items = [["stock", "Stock", ICON.box], ["waitlist", "Waitlist", ICON.gift], ["marketing", "Marketing", ICON.mail], ["money", "Money", ICON.card],
    ["settings", "Settings", ICON.doc], ["texts", "Texts", ICON.chat], ["team", "Team", ICON.people], ["system", "System", ICON.box]].filter(([k, , , soon]) => soon || can(AREA[k]));
  sheet(`<h2 class="t">More</h2><div class="menu">${items.map(([k, l, ic, soon]) => `<button ${soon ? "disabled" : `data-go="${k}"`}>${ic}${l}${soon ? `<small>${soon}</small>` : ""}</button>`).join("")}
    <button data-act="logout">${ICON.chev}Log out <small>${esc(ME.email)}</small></button></div>`);
}
document.addEventListener("click", async e => {
  if (e.target.closest("[data-act=logout]")) { try { await api("/api/admin/logout", {}); } catch {} ME = null; showLogin(); }
});

/* Computer sidebar: who is logged in, group titles only over tabs this role has, tooltips, fold to icons (remembered per device). */
const ROLE_NAME = { owner: "Owner", manager: "Manager", warehouse: "Warehouse", courier: "Courier", accountant: "Accountant" };
function drawSidebar(){
  $("nvAv").textContent = initials(ME.name || ME.email);
  $("nvName").textContent = ME.name || ME.email;
  $("nvRole").textContent = ROLE_NAME[ME.role] || ME.role;
  $("nvName").closest(".nv-me").title = ME.email;
  document.querySelectorAll(".nav .nv-h").forEach(h => {
    let n = h.nextElementSibling, any = false;
    while (n && n.matches("button[data-s]")) { if (!n.hidden) any = true; n = n.nextElementSibling; }
    h.hidden = !any;
  });
  document.querySelectorAll(".nav button[data-s]").forEach(b => { const l = b.querySelector(".nl"); if (l) b.title = l.textContent; });
}
function setMini(on){
  document.documentElement.classList.toggle("nav-mini", on);
  const f = $("nvFold"), t = on ? "Expand the menu" : "Collapse the menu"; f.title = t; f.setAttribute("aria-label", t); f.setAttribute("aria-expanded", String(!on));
  try { localStorage.setItem("d01navmini", on ? "1" : ""); } catch {}
}
try { if (localStorage.getItem("d01navmini")) setMini(true); } catch {}
$("nvFold").onclick = () => setMini(!document.documentElement.classList.contains("nav-mini"));
// Ctrl/⌘ + \ folds the sidebar too
document.addEventListener("keydown", e => { if ((e.metaKey || e.ctrlKey) && e.key === "\\" && wide()) { e.preventDefault(); setMini(!document.documentElement.classList.contains("nav-mini")); } });

async function boot(){
  try { const r = await api("/api/admin/me"); CSRF = r.csrf; ME = r.user; startApp(); }
  catch { showLogin(); }
}
function startApp(){
  $("login").hidden = true; $("app").hidden = false; $("nav").hidden = false;
  document.documentElement.classList.toggle("cv", ME.role === "courier"); // the courier's own iOS-style look
  document.querySelectorAll(".nav button[data-s]").forEach(b => { const s = b.dataset.s; b.hidden = s !== "more" && !can(AREA[s]); });
  drawSidebar();
  $("foot").textContent = (window.ADMIN.store || "") + " · v" + (window.ADMIN.version || "");
  const m = location.pathname.match(/^\/admin\/(\w+)/), sub = location.hash.slice(1);
  go(m && TITLES[m[1]] ? m[1] : "home", sub || undefined);
  if (can("inbox") && !startApp.ib) { startApp.ib = setInterval(() => { if (!document.hidden && ME) refreshUnread(); }, 60000); }
  if (can("inbox")) refreshUnread();
}

/* ================= TODAY ================= */
const RENDER = {};
RENDER.home = async () => {
  const el = $("s-home");
  const hello = `<div class="hello"><div class="mono">${esc(new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" }))}</div><h1>${greeting()},<br>here’s your day.</h1></div>`;
  el.innerHTML = hello + `<p class="sub" style="margin:14px 2px">Loading…</p>`;
  let w, d;
  try { [w, d] = await Promise.all([api("/api/admin/today"), can("orders") || can("today") ? api("/api/admin/today/shop") : Promise.resolve(null)]); }
  catch (e) { el.querySelector("p.sub").textContent = e.message; return; }
  const att = (d ? d.attention : []).concat(w.attention);
  $("attCount").hidden = !att.length; $("attCount").textContent = att.length;
  $("navAtt").hidden = !att.length; $("navAtt").textContent = att.length;
  const lw = d ? d.lastWeekday : "";
  const delta = (a, b, money) => b == null ? "" : a === b ? `<span class="sub">same as last ${lw}</span>` : !b ? `<span class="sub">none last ${lw}</span>`
    : `<span class="delta ${a > b ? "up" : "down"}">${a > b ? "▲" : "▼"} ${money ? Math.abs(Math.round((a - b) / b * 100)) + "%" : Math.abs(a - b)}</span> <span class="sub">vs. last ${lw}</span>`;
  const ic = { w: ICON.warn, b: ICON.warn, n: ICON.box, m: ICON.chat };
  const k = d && d.kpi;
  const mx = d ? Math.max(1, ...d.days.map(x => x.v)) : 1;
  el.innerHTML = hello + (d ? `
    <section class="grid2">
      <div class="card kpi"><div class="mono">Sales today</div><div class="v num">${eurShort(k.sales)}</div>${delta(k.sales, k.salesLw, true)}</div>
      <div class="card kpi"><div class="mono">Orders today</div><div class="v num">${k.orders}</div>${delta(k.orders, k.ordersLw)}</div>
      <div class="card kpi"><div class="mono">To deliver</div><div class="v num">${k.done + k.left}</div><span class="sub">${k.done} done · ${k.left} left</span></div>
      <div class="card kpi"><div class="mono">Profit today</div><div class="v num">${eurShort(k.profit)}</div><span class="sub">${k.margin == null ? "no sales yet" : k.margin + "% margin"}</span></div>
    </section>` : "") + `
    <section class="card"><div class="head"><h2>Needs your attention</h2><span class="sub">${att.length ? att.length + " item" + (att.length > 1 ? "s" : "") : "all clear"}</span></div>
      <div class="list">${att.map(([kd, t, s, link]) => `<div class="att" role="button" tabindex="0" data-go="${esc(link)}"><div class="ico ${kd}">${ic[kd]}</div><div class="t">${esc(t)}<small>${esc(s)}</small></div>${ICON.chev}</div>`).join("") || `<p class="sub" style="margin:0">Nothing needs you right now.</p>`}</div></section>
    ${d ? `<section class="card"><div class="head"><h2>Sales, last 7 days</h2><span class="sub num">${eurShort(d.days.reduce((s, x) => s + x.v, 0))} total</span></div>
      <div class="bars">${d.days.map((x, i) => `<div class="${i === 6 ? "today" : ""}"><em style="bottom:calc(${x.v / mx * 100}% - 2px + 20px)">${eurShort(x.v)}</em><i style="height:${Math.max(x.v ? 2 : 0, x.v / mx * 100)}%"></i><span>${new Date(x.d + "T12:00").toLocaleDateString("en-GB", { weekday: "short" })}</span></div>`).join("")}</div></section>
    <section class="card"><div class="head"><h2>Delivery windows today</h2>${can("orders") ? `<button class="btn sm ghost" data-go="packing">Packing list</button>` : ""}${can("delivery") ? `<button class="btn sm ghost" data-go="delivery">Open route</button>` : `<button class="btn sm ghost" data-go="orders">Open orders</button>`}</div>
      <div class="list">${d.windows.map(s => `<div class="win"><div class="row"><span><b>${s.start}–${s.end}</b> <span class="sub">· ${s.open ? (s.left ? "open" : "full") : "closed for orders"}</span></span><span class="num">${s.capacity - s.left} / ${s.capacity}</span></div><div class="track"><i style="width:${s.capacity ? (s.capacity - s.left) / s.capacity * 100 : 0}%"></i></div></div>`).join("") || `<p class="sub" style="margin:0">No deliveries today (see Settings → Delivery).</p>`}</div></section>
    <section class="card"><div class="head"><h2>${esc(d.month.label)} so far</h2><span class="sub">${esc(d.month.range)}</span></div>
      <div class="money"><div>Revenue<b class="num">${eurShort(d.month.revenue)}</b></div><div>Costs (stock, expenses)<b class="num">${eurShort(d.month.costs)}</b></div>
        <div class="profit">Profit<b class="num">${eurShort(d.month.profit)}</b></div><div>BTW to set aside<b class="num">${eurShort(d.month.btw)}</b></div></div></section>
    <section class="card"><div class="head"><h2>Best sellers this week</h2></div>
      ${d.best.length ? d.best.map(p => `<div class="row" style="padding:7px 0;font-size:13px"><span style="width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(p.name)}</span><div style="flex:1"><div class="hb" style="width:${p.sold / d.best[0].sold * 100}%"></div></div><span class="num" style="width:56px;text-align:right;color:var(--ink2)">${p.sold} sold</span></div>`).join("") : `<p class="sub" style="margin:0">No sales this week yet.</p>`}</section>` : ""}
    <section class="card"><div class="head"><h2>Waitlist</h2><button class="btn sm ghost" data-go="waitlist">Open</button></div>
      <div class="money"><div>Signups today<b class="num">${fmt(w.signupsToday)}</b></div><div>On the list<b class="num">${fmt(w.total)}</b></div>
        <div>Visitors today<b class="num">${fmt(w.visitorsToday)}</b></div><div>On site now<b class="num">${fmt(w.live)}</b></div></div></section>`;
};
const eurShort = c => (c < 0 ? "−" : "") + "€" + Math.abs(c / 100).toLocaleString("nl-NL", { minimumFractionDigits: Math.abs(c) % 100 ? 2 : 0, maximumFractionDigits: 2 });
function greeting(){ const h = new Date().getHours(); return h < 12 ? "Good morning" : h < 18 ? "Good afternoon" : "Good evening"; }

/* ================= ORDERS ================= */
const ST_CLASS = { new: "new", paid: "paid", packing: "packing", out_for_delivery: "out", delivered: "done", failed_delivery: "fail", cancelled: "fail", returned: "fail" };
const OL = { filter: "all", q: "", rows: [], total: 0, labels: {} };
RENDER.orders = async sub => {
  const el = $("s-orders");
  if (!el.dataset.ready) {
    el.dataset.ready = 1;
    el.innerHTML = `<div class="row" style="gap:8px;margin-top:14px"><div class="search" style="flex:1;margin:0"><input id="oq" type="search" placeholder="Search order, name, street" aria-label="Search orders"></div><button class="btn sm ghost pk-go" data-go="packing">${ICON.box}Packing list</button></div>
      <div class="chips" id="ofilters"></div><div id="orders"></div><button class="btn more block" id="oMore" hidden style="margin-top:10px;background:var(--card2);color:var(--ink2)">Load more</button>`;
    let t; $("oq").oninput = () => { clearTimeout(t); t = setTimeout(() => { OL.q = $("oq").value.trim(); loadOrders(); }, 250); };
    $("oMore").onclick = () => loadOrders(true);
    // infinite scroll
    if ("IntersectionObserver" in window) new IntersectionObserver(([e]) => { if (e.isIntersecting && !$("oMore").hidden && current === "orders") loadOrders(true); }, { rootMargin: "300px" }).observe($("oMore"));
  }
  if (sub && /^\w+$/.test(sub)) OL.filter = sub;
  $("fab").hidden = !can("orders");
  await loadOrders();
};
async function loadOrders(more){
  if (loadOrders.busy) return; loadOrders.busy = true;
  try {
    const r = await api(`/api/admin/orders?status=${encodeURIComponent(OL.filter)}&q=${encodeURIComponent(OL.q)}&offset=${more ? OL.rows.length : 0}`);
    OL.rows = more ? OL.rows.concat(r.rows) : r.rows; OL.total = r.total; OL.labels = r.labels;
    $("ofilters").innerHTML = [["all", "All"], ...Object.entries(r.labels)].filter(([k]) => r.counts[k] || k === OL.filter).map(([k, l]) => `<button class="chip" data-f="${k}" aria-pressed="${k === OL.filter}">${esc(l)}<i>${r.counts[k] || 0}</i></button>`).join("");
    $("ofilters").querySelectorAll(".chip").forEach(b => b.onclick = () => { OL.filter = b.dataset.f; loadOrders(); });
    $("orders").innerHTML = OL.rows.map(o => `<button class="order" data-oid="${o.id}">
      <div class="row"><span class="id">#${o.number} · ${esc(new Date(o.created_at).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }))}${o.channel !== "web" ? " · " + esc(o.channel) : ""}</span><span class="st ${ST_CLASS[o.status]}">${esc(r.labels[o.status])}</span></div>
      <div class="row"><div><div class="who">${esc(o.name || "—")}</div><div class="meta">${esc(o.zone || "")}${o.slot ? ' · <span class="nw">' + esc(o.slot) + "</span>" : ""}${o.needs_new_slot == 1 ? ' · <span style="color:var(--bad)">needs new window</span>' : ""}</div></div>
        <div style="text-align:right"><div class="sum num">${eur(o.total_cents)}</div><div class="pay ${o.payment_status === "paid" ? "" : "unpaid"}">${o.payment_status === "paid" ? (/^paid/i.test(o.method) ? "Paid" : "Paid · " + esc(o.method.replace(/ on delivery$/i, ""))) : o.status === "cancelled" ? "Not paid" : "Awaiting " + esc(o.method)}</div></div></div>
      <div class="items">${esc(o.items.join(" · "))}</div></button>`).join("") || `<p class="sub" style="padding:20px 4px">${OL.q ? "No order matches." : "No orders here yet."}</p>`;
    $("orders").querySelectorAll(".order").forEach(b => b.onclick = () => openOrder(+b.dataset.oid));
    $("oMore").hidden = OL.rows.length >= OL.total;
  } catch (e) { toastErr(e.message); }
  loadOrders.busy = false;
}
const NEXT = { new: null, paid: ["packing", "Start packing"], packing: ["out_for_delivery", "Hand to courier"], out_for_delivery: ["delivered", "Mark delivered"], failed_delivery: null, delivered: null };
const EV = { created: "Order placed", created_by_admin: "Created in the back office", payment_started: "Payment started", paid: "Paid", status: "Status changed", cancelled: "Cancelled", note: "Note",
  tikkie_link: "Tikkie link added", slot_changed: "Delivery window changed", needs_new_slot: "Window was full when paid", gift_already_used: "Gift already used on another order",
  awaiting_tikkie: "Waiting for Tikkie", whatsapp_opened: "WhatsApp opened", payment_failed: "Payment failed", payment_expired: "Payment expired", payment_canceled: "Payment cancelled", oversold: "⚠ Sold more than in stock", paid_after_cancel: "Paid after it was cancelled",
  not_home: "Not home", on_my_way: "“On my way” sent", referral_reward: "Referral reward sent", rescheduled_by_customer: "Customer picked a new window", cash_handed_over: "Cash handed to the owner" };
// steps that tell the customer nothing, so an Undo can really take them back
const SILENT_STEP = { paid: "packing" };
async function openOrder(id){
  let r; try { r = await api("/api/admin/orders/" + id); } catch (e) { return toastErr(e.message); }
  drawOrder(r.order, r.labels, r.flow);
}
function drawOrder(o, labels, flow){
  labels = labels || OL.labels; flow = flow || ["new", "paid", "packing", "out_for_delivery", "delivered"];
  const step = o.status === "failed_delivery" ? 3 : flow.indexOf(o.status);
  const paid = o.payment_status === "paid", live = o.status !== "cancelled";
  const next = NEXT[o.status];
  const addr = [o.street ? `${o.street} ${o.house_no}${o.addition ? " " + o.addition : ""}` : `House ${o.house_no}`, `${o.postcode} ${o.city || ""}`].join(", ");
  const map = o.lat ? `https://www.google.com/maps/search/?api=1&query=${o.lat},${o.lng}` : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(addr)}`;
  const first = (o.customer_name || "").split(" ")[0];
  const evText = e => { const d = e.data ? JSON.parse(e.data) : {};
    return (EV[e.type] || e.type) + (e.type === "status" ? `: ${labels[d.from] || d.from} → ${labels[d.to] || d.to}` : e.type === "note" ? `: ${d.text}` : e.type === "paid" && d.method ? ` · ${d.method}` : e.type === "cancelled" && d.why ? ` · ${d.why}` : e.type === "slot_changed" || e.type === "rescheduled_by_customer" ? ` → ${d.to}` : e.type === "not_home" && d.why ? ` · ${d.why}` : e.type === "created" && d.code ? ` · code ${d.code}` : e.type === "referral_reward" ? ` · ${d.code} to ${d.to}` : ""); };
  const timeline = [...o.events.map(e => ({ t: e.created_at, h: `<b>${esc(evText(e))}</b>${e.by_name ? " · " + esc(e.by_name) : ""}` })),
    ...o.messages.map(m => ({ t: m.created_at, h: `<b>${m.channel === "email" ? "Email" : "WhatsApp"}</b> · ${esc(m.subject || m.template.replace(/_/g, " "))} · ${m.status === "sent" ? "✓ sent" : m.status === "ready" ? "ready to send" : esc(m.status.replace("_", " "))}${m.error ? ` <span style="color:var(--bad)">(${esc(m.error.slice(0, 60))})</span>` : ""}` }))]
    .sort((a, b) => a.t < b.t ? -1 : 1);
  const time = iso => new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
  sheet(`<div class="row" style="justify-content:space-between"><span class="mono">Order #${o.number} · ${esc(time(o.created_at))}</span><span class="st ${ST_CLASS[o.status]}">${esc(labels[o.status])}</span></div>
    ${live ? `<div class="steps">${flow.map((_, i) => `<i class="${i <= step ? "on" : ""}"></i>`).join("")}</div><div class="steps-l"><span>New</span><span>Paid</span><span>Packed</span><span>Out</span><span>Delivered</span></div>` : ""}
    <h2 style="margin:18px 0 2px;font-size:20px">${esc(o.customer_name || "—")}</h2><div class="sub"><a href="${esc(map)}" target="_blank" rel="noopener" style="color:inherit">${esc(addr)}</a>${o.zone ? " · " + esc(o.zone) : ""}</div>
    ${o.needs_new_slot == 1 ? `<p class="note bad" style="margin-top:10px">The window filled up while they paid. Pick a new one below and let them know.</p>` : ""}
    <dl class="kv"><dt>Delivery</dt><dd>${esc(o.slot_label || "—")} <a href="#" data-oact="slot" style="color:var(--ink3)">change</a></dd>
      <dt>Payment</dt><dd>${paid ? (/^paid/i.test(o.payment_method_label) ? "✓ " : "✓ Paid with ") + esc(o.payment_method_label) : live ? "⚠ Waiting · " + esc(o.payment_method_label) : "✕ Not paid"}</dd>
      <dt>Contact</dt><dd>${o.phone ? `<a href="tel:${esc(o.phone)}" style="color:inherit">${esc(o.phone)}</a>` : ""}${o.email ? `<br><span class="sub">${esc(o.email)}</span>` : ""}</dd>
      <dt>Customer</dt><dd>${o.customer_orders} paid order${o.customer_orders === 1 ? "" : "s"} · ${eur(o.customer_spent)} spent</dd>
      ${o.gift_code ? `<dt>Waitlist gift</dt><dd>🎁 ${esc(o.gift_code)} — pack it in</dd>` : ""}</dl>
    <div class="card" style="padding:12px 14px"><div class="lines">${o.items.map(i => `<div class="row"><span>${i.qty}× ${esc(i.name_snapshot)} <span class="sub">${i.vat_rate}% BTW</span></span><span class="num">${eur(i.unit_price_cents * i.qty)}</span></div>`).join("")}
      ${+o.discount_cents ? `<div class="row"><span class="sub">Discount${o.discount_code || o.referral_code ? ` · ${o.referral_code ? "friend’s code " : "code "}${esc(o.discount_code || o.referral_code)}` : ""}</span><span class="num">−${eur(+o.discount_cents)}</span></div>` : ""}
      <div class="row"><span class="sub">Delivery</span><span class="num">${+o.delivery_cents ? eur(+o.delivery_cents) : "Free"}</span></div>
      <div class="row"><b>Total <span class="sub" style="font-weight:500">incl. ${Object.entries(o.vat_split).map(([r, v]) => `${eur(v.vat)} BTW ${r}%`).join(" + ")}</span></b><b class="num">${eur(+o.total_cents)}</b></div></div></div>
    ${o.notes ? `<p class="note" style="margin-top:12px">${esc(o.notes)}</p>` : ""}
    <div class="acts2">
      ${live && next && paid ? `<button class="btn wide" data-oact="status" data-to="${next[0]}">${next[1]}</button>` : ""}
      ${o.status === "failed_delivery" && can("delivery") ? `<button class="btn wide" data-oact="resched">Reschedule delivery</button>` : ""}
      ${live && !paid && o.payment_method !== "cash" ? `<div style="grid-column:1/3"><p class="sub" style="margin:4px 0 6px">Tikkie: make it in the Tikkie app for ${eur(+o.total_cents)}, then paste the link</p><div class="search" style="margin:0"><input id="tkUrl" placeholder="https://tikkie.me/pay/…" value="${esc(o.tikkie_url || "")}" aria-label="Tikkie link"><button class="btn" data-oact="tikkie">Send via WhatsApp</button></div></div>` : ""}
      ${live && !paid ? `<button class="btn ghost" data-oact="paid" data-m="${o.payment_method === "cash" ? "cash" : o.payment_method === "tikkie" ? "tikkie" : "manual"}">Mark as paid</button>` : ""}
      ${o.phone ? `<a class="btn ghost" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="${esc(waFor(o))}" data-wa="${esc(waTpl(o))}">WhatsApp</a>` : ""}
      ${o.phone ? `<a class="btn ghost" style="text-align:center;text-decoration:none" href="tel:${esc(o.phone)}">Call ${esc(first)}</a>` : ""}
      ${o.customer_id && can("inbox") && (o.email || o.has_chat) ? `<button class="btn ghost ib-btn" data-oact="chat">${ICON.chat}Message</button>` : ""}
      ${paid ? `<a class="btn ghost" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="/api/admin/orders/${o.id}/invoice">Invoice ${esc(o.invoice ? o.invoice.number : "")}</a>` : ""}
      <a class="btn ghost" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="${esc(o.track_url)}">Customer’s page</a>
      ${o.proof_path ? `<a class="btn ghost" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="/api/admin/stops/${o.id}/proof">Delivery photo</a>` : ""}
      ${live ? `<button class="btn bad" data-oact="cancel">Cancel order</button>` : ""}
    </div>
    <div class="mono" style="margin:18px 0 8px">Timeline</div>
    ${timeline.map(x => `<div class="msg">${x.h} <span class="sub">· ${esc(time(x.t))}</span></div>`).join("")}
    <form id="noteForm" class="search"><input id="noteIn" placeholder="Internal note (only you see this)" aria-label="Internal note"><button class="btn ghost">Add</button></form>`);
  const sb = $("sheetBody"); sb.dataset.order = o.id;
  sb.onclick = async e => {
    const b = e.target.closest("[data-oact]");
    const w = e.target.closest("[data-wa]"); if (w) api(`/api/admin/orders/${o.id}/wa`, { template: w.dataset.wa }).catch(() => {});
    if (!b) return; e.preventDefault();
    const act = b.dataset.oact, done = r => { drawOrder(r.order, labels, flow); loadOrders(); };
    if (act === "status") await busy(b, async () => { const from = o.status, to = b.dataset.to, r = await api(`/api/admin/orders/${o.id}/status`, { status: to }); done(r);
      // a step that messaged the customer can't be taken back, so it offers WhatsApp instead of Undo
      if (r.wa) toast(labels[to] + " ✓ · customer emailed", { act: "WhatsApp", fn: () => { window.open(r.wa, "_blank", "noopener"); api(`/api/admin/orders/${o.id}/wa`, { template: to }).catch(() => {}); } });
      else if (SILENT_STEP[from] === to) toast(labels[to] + " ✓", { act: "Undo", fn: () => api(`/api/admin/orders/${o.id}/status`, { status: from }).then(x => { toast("Back to " + labels[from]); loadOrders(); if ($("sheet").classList.contains("on") && $("sheetBody").dataset.order == o.id) drawOrder(x.order, labels, flow); }).catch(err => toastErr(err.message)) });
      else toast(labels[to] + " ✓"); });
    if (act === "paid" && await ask(`The invoice and the order confirmation go to the customer straight away.`, { title: `Mark #${o.number} as paid (${b.dataset.m})?`, ok: "Mark as paid" })) await busy(b, async () => { done(await api(`/api/admin/orders/${o.id}/paid`, { method: b.dataset.m })); toast("Paid ✓ Invoice and confirmation sent"); });
    if (act === "cancel") { const why = await ask("Why is it cancelled? Only you see this. The stock goes back.", { title: `Cancel #${o.number}?`, ok: "Cancel order", no: "Keep it", danger: true, input: "Customer asked" }); if (why !== null) await busy(b, async () => { done(await api(`/api/admin/orders/${o.id}/cancel`, { why })); toast("Cancelled · stock is back"); }); }
    if (act === "tikkie") await busy(b, async () => { const r = await api(`/api/admin/orders/${o.id}/tikkie`, { url: $("tkUrl").value }); done(r); if (r.wa) window.open(r.wa, "_blank", "noopener"); });
    if (act === "slot") slotPicker(o, labels, flow);
    if (act === "chat") startChat(+o.customer_id, "auto");
    if (act === "resched") reschedule(o.id, o.number, () => { openOrder(o.id); loadOrders(); });
  };
  $("noteForm").onsubmit = async e => { e.preventDefault(); await busy(e.target.querySelector("button"), async () => { drawOrder((await api(`/api/admin/orders/${o.id}/note`, { text: $("noteIn").value })).order, labels, flow); }); };
}
const waTpl = o => o.payment_status !== "paid" && o.status === "new" ? "payment_link" : o.status === "out_for_delivery" ? "out_for_delivery" : o.status === "delivered" ? "delivered" : o.status === "failed_delivery" ? "failed_delivery" : o.status === "paid" ? "order_confirmation" : "other";
const waFor = o => o.wa[waTpl(o)] || o.wa.other;
async function slotPicker(o, labels, flow){
  let r; try { r = await api("/api/admin/slots"); } catch (e) { return toastErr(e.message); }
  sheet(`<h2 class="t">New window for #${o.number}</h2><div class="menu">${r.slots.map(s => `<button data-sl="${s.id}" ${s.id === +o.slot_id ? "disabled" : ""}>${esc(s.label)}<small>${s.left} left${s.open ? "" : " · closed"}</small></button>`).join("") || `<p class="sub">No windows. Check Settings → Delivery.</p>`}</div>
    <button class="btn ghost block" style="margin-top:12px" data-back>Back</button>`);
  $("sheetBody").onclick = async e => {
    if (e.target.closest("[data-back]")) return drawOrder(o, labels, flow);
    const b = e.target.closest("[data-sl]"); if (!b) return;
    await busy(b, async () => { const res = await api(`/api/admin/orders/${o.id}/slot`, { slot_id: +b.dataset.sl }); toast("Window changed ✓"); drawOrder(res.order, labels, flow); loadOrders(); });
  };
}

/* ---------- + New order (WhatsApp, Instagram, phone) ---------- */
document.addEventListener("click", e => { if (e.target.closest("#fab")) newOrder(); });
async function newOrder(){
  let slots, prods;
  try { [slots, prods] = await Promise.all([api("/api/admin/slots"), api("/api/admin/products")]); } catch (e) { return toastErr(e.message); }
  const P2 = prods.products.filter(p => p.active == 1);
  const NO = { items: {}, addr: null };
  sheet(`<h2 class="t">New order</h2><form id="noForm">
    <div class="cols"><label class="field"><span>Came in via</span><select class="in" name="channel"><option value="whatsapp">WhatsApp</option><option value="instagram">Instagram</option><option value="phone">Phone</option><option value="other">Other</option></select></label>
      <label class="field"><span>Name</span><input class="in" name="name" required></label></div>
    <div class="cols"><label class="field"><span>Phone</span><input class="in" name="phone" type="tel" placeholder="06 …"></label><label class="field"><span>Email (optional)</span><input class="in" name="email" type="email"></label></div>
    <div class="cols"><label class="field"><span>Postcode</span><input class="in" name="postcode" placeholder="9711 LD"></label><label class="field"><span>House no.</span><input class="in" name="house_no" placeholder="22a"></label></div>
    <p class="sub" id="noAddr" style="margin:-4px 0 12px"></p>
    <div class="sec-h">Products</div>
    <div class="list" id="noItems">${P2.flatMap(p => p.variants.filter(v => v.active == 1).map(v => `<div class="row" style="justify-content:space-between;padding:8px 0;font-size:13px">
      <span style="min-width:0">${esc(p.name)}${v.label !== "Default" && v.label !== "One size" ? " · " + esc(v.label) : ""} <span class="sub">${eur(p.price_cents)} · ${v.stock} left</span></span>
      <span class="row" style="gap:6px"><button type="button" class="btn sm ghost" data-q="${v.id}" data-d="-1" aria-label="Less">−</button><b class="num" id="nq${v.id}" style="min-width:16px;text-align:center">0</b><button type="button" class="btn sm ghost" data-q="${v.id}" data-d="1" data-max="${v.stock}" aria-label="More">+</button></span></div>`)).join("")}</div>
    <div class="sec-h">Delivery window</div>
    <select class="in" name="slot_id">${slots.slots.map(s => `<option value="${s.id}">${esc(s.label)} · ${s.left} left${s.open ? "" : " (closed for the shop)"}</option>`).join("")}</select>
    <div class="sec-h">Payment</div>
    <div class="card" style="padding:4px 14px;background:var(--card2)">
      ${[["tikkie", "Tikkie", "You paste the Tikkie link on the order next."], ["cash", "Cash on delivery", "The courier collects the money."], ["manual", "Already paid", "Marked paid now: invoice and confirmation go out."]]
        .map(([k, l, s], i) => `<label class="tog"><span>${l}<small>${s}</small></span><input type="radio" name="payment" value="${k}" ${!i ? "checked" : ""}><i></i></label>`).join("")}
    </div>
    <label class="field" style="margin-top:12px"><span>Note (only you see this)</span><input class="in" name="notes"></label>
    <p class="err" id="noErr"></p>
    <button class="btn holo block" type="submit" id="noBtn">Create order</button></form>`);
  const f = $("noForm");
  const upd = () => { $("noBtn").textContent = "Create order · " + Object.values(NO.items).reduce((s, x) => s + x, 0) + " items"; };
  f.onclick = e => { const b = e.target.closest("[data-q]"); if (!b) return; const id = b.dataset.q, n = Math.max(0, (NO.items[id] || 0) + +b.dataset.d);
    if (b.dataset.max && n > +b.dataset.max) return toastErr("Not enough stock"); NO.items[id] = n; $("nq" + id).textContent = n; upd(); };
  let at;
  const lookup = () => { clearTimeout(at); at = setTimeout(async () => { const pc = f.postcode.value, no = f.house_no.value.match(/^\d+/); if (pc.replace(/\s/g, "").length < 6 || !no) return;
    try { const r = await api(`/api/admin/address?pc=${encodeURIComponent(pc)}&no=${no[0]}&add=${encodeURIComponent(f.house_no.value.replace(/^\d+\s*-?/, ""))}`);
      NO.addr = r.address; $("noAddr").innerHTML = r.address ? `✓ ${esc(r.address.street)}, ${esc(r.address.city)}${r.zone ? " · " + esc(r.zone) : ' · <span style="color:var(--bad)">outside delivery zones</span>'}` : (r.zone ? "Address not found, it will be saved as typed · " + esc(r.zone) : '<span style="color:var(--bad)">Not in a delivery zone</span>'); } catch {} }, 400); };
  f.postcode.oninput = f.house_no.oninput = lookup;
  f.onsubmit = async e => { e.preventDefault(); $("noErr").textContent = "";
    const items = Object.entries(NO.items).filter(([, q]) => q > 0).map(([v, q]) => ({ variant_id: +v, qty: q }));
    if (!items.length) { $("noErr").textContent = "Add at least one product."; return; }
    const m = f.house_no.value.trim().match(/^(\d+)\s*-?\s*(.*)$/) || [];
    await busy($("noBtn"), async () => {
      try {
        const r = await api("/api/admin/orders/new", { channel: f.channel.value, name: f.name.value, phone: f.phone.value, email: f.email.value, postcode: f.postcode.value, house_no: m[1] || "", addition: m[2] || "",
          street: NO.addr ? NO.addr.street : "", city: NO.addr ? NO.addr.city : "", lat: NO.addr ? NO.addr.lat : null, lng: NO.addr ? NO.addr.lng : null,
          slot_id: +f.slot_id.value, items, payment: f.payment.value, notes: f.notes.value });
        toast(`Order #${r.order.number} created ✓`); OL.filter = "all"; loadOrders();
        drawOrder(r.order);
      } catch (err) { $("noErr").textContent = err.message; }
    });
  };
  upd();
}

/* ================= DELIVERY + COURIER MODE ================= */
// One route per window: stops in driving order (computed on the server), ETAs, Google Maps links, drag to reorder.
// Courier mode (role courier, or the owner's toggle) shows the same stops as a big list with only the buttons needed at the door.
const DV = { date: null, data: null };
const lsGet = k => { try { return localStorage.getItem(k); } catch { return null; } };
const lsSet = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
const courierMode = () => DV.data && (DV.data.courier_view || lsGet("d01courier") === "1");
RENDER.delivery = async sub => {
  if (sub && /^\d{4}-\d{2}-\d{2}$/.test(sub)) DV.date = sub;
  try { DV.data = await api("/api/admin/delivery" + (DV.date ? "?date=" + DV.date : "")); } catch (e) { return toastErr(e.message); }
  DV.date = DV.data.date; DV.at = Date.now();
  drawDelivery();
  if (sub === "cash" && $("cashCard")) setTimeout(() => $("cashCard").scrollIntoView({ block: "start" }), 60);
};
// Back from Google Maps or a phone call: show what changed meanwhile (other couriers, new orders).
document.addEventListener("visibilitychange", () => {
  if (!document.hidden && current === "delivery" && DV.data && !$("sheet").classList.contains("on") && Date.now() - DV.at > 20000) RENDER.delivery();
});
const isToday = d => d.days.length && d.days[0].date === d.date;
const winColor = (d, slotId) => WIN_COLOR[Math.max(0, d.routes.findIndex(r => r.slot.id === slotId)) % WIN_COLOR.length];
const mapOn = () => lsGet("d01map") !== "0";
const pcs = n => n ? n + " item" + (n > 1 ? "s" : "") : "";
function drawDelivery(){
  const d = DV.data, cm = courierMode(), el = $("s-delivery");
  el.classList.toggle("courier", cm);
  if (d.courier_view) return drawCourier(d, el);
  const withStops = d.routes.filter(r => r.total), all = withStops.flatMap(r => r.stops);
  const left = all.filter(x => x.state === "todo").length, done = all.filter(x => x.state === "done").length, failed = all.length - left - done;
  const cash = all.filter(x => x.state === "todo" && x.collect).reduce((a, x) => a + x.collect, 0);
  el.innerHTML = `<div class="dv-bar"><div class="chips">${d.days.map(x => `<button class="chip" data-day="${x.date}" aria-pressed="${x.date === d.date}">${esc(x.label)}${x.stops ? `<i>${x.stops}</i>` : ""}</button>`).join("")}
      ${d.courier_view ? "" : `<button class="chip" data-cm aria-pressed="${cm}">${ICON.truck.replace('class="ic"', 'class="ic" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"')}Courier mode</button>`}</div>
      ${all.length ? `<div class="dv-sum"><span><b class="num">${done}</b> of ${all.length} delivered</span>${left ? `<span>${left} to go</span>` : ""}${failed ? `<span class="badline">${failed} not home</span>` : ""}${cash ? `<span class="warnline">collect ${eur(cash)}</span>` : ""}</div>` : ""}</div>
    ${!cm && d.earlier_failed.length ? `<section class="card"><div class="head"><h2>Waiting for a new window</h2><span class="sub">not home earlier</span></div><div class="list">${d.earlier_failed.map(f => `<div class="row" style="justify-content:space-between;padding:10px 0"><span><b>#${f.number}</b> · ${esc(f.name || "")} <span class="sub">${esc((f.street || "") + " " + (f.house_no || ""))}</span></span><button class="btn sm" data-resched="${f.id}" data-num="${f.number}">New window</button></div>`).join("")}</div></section>` : ""}
    ${cashCard(d, cm)}
    ${isToday(d) ? upNext(d) : ""}
    ${withStops.length ? mapCard(d) : ""}
    ${(cm ? withStops : d.routes).map(r => routeCard(r, cm)).join("") || `<section class="card"><div class="empty">${ICON.truck}<h2>${cm ? "No stops for you" : "No delivery windows"}</h2><p>${cm ? "Nothing to deliver on this day yet. Tap the day again to refresh." : "This is not a delivery day. Change days and windows in Settings → Delivery."}</p></div></section>`}
    ${cm ? "" : zonesCard(d)}`;
  el.querySelectorAll(".stops").forEach(l => dragStops(l, +l.dataset.slot));
  el.querySelectorAll("[data-cashin]").forEach(b => b.onclick = () => cashSheet(d.cash.find(c => c.courier_id == b.dataset.cashin)));
  const ch = el.querySelector("[data-cashhist]"); if (ch) ch.onclick = cashHistory;
  drawMap(d);
}
/** Today only: the one stop to drive to now, with thumb-sized buttons, so nobody has to scroll while on the road. */
function upNext(d){
  const r = d.routes.find(r => r.stops.some(x => x.state === "todo" && x.out)) || d.routes.find(r => r.stops.some(x => x.state === "todo"));
  if (!r) return "";
  const i = r.stops.findIndex(x => x.state === "todo"), x = r.stops[i], started = r.stops.some(s => s.out);
  return `<section class="card upnext" data-id="${x.id}" style="--c:${winColor(d, r.slot.id)}">
    <div class="un-top"><span class="mono"><i class="dot"></i>Up next · ${r.slot.start}–${r.slot.end}</span>${x.eta ? `<b class="un-eta num">ETA ${esc(x.eta)}</b>` : ""}</div>
    <div class="un-addr">${esc(x.address)}</div>
    <div class="un-meta">${[`stop ${i + 1} of ${r.total}`, esc(x.postcode), esc(x.name || "—"), pcs(x.pieces), "#" + x.number].filter(Boolean).join(" · ")}</div>
    ${x.collect ? `<span class="collect">Collect ${eur(x.collect)} · ${esc(x.method)}</span>` : ""}
    ${x.notes ? `<p class="note" style="margin:10px 0 0">${esc(x.notes)}</p>` : ""}
    <div class="un-acts">
      ${r.startable && !started ? `<button class="btn wide" data-start="${r.slot.id}">Start route · tell ${r.startable} customer${r.startable > 1 ? "s" : ""}</button>` : ""}
      <a class="btn holo" target="_blank" rel="noopener" href="${esc(x.nav)}">${ICON.nav}Navigate</a>
      <button class="btn" data-sact="done">${ICON.ok}Delivered</button>
      ${x.phone ? `<a class="btn ghost" href="tel:${esc(x.phone)}">${ICON.phone}Call</a>` : ""}
      <button class="btn ghost${x.phone ? "" : " wide"}" data-sact="fail">${ICON.x}Not home</button>
      ${x.out ? `<button class="btn ghost wide" data-sact="onway">${ICON.clock}On my way · ~10 min heads-up</button>` : ""}
    </div></section>`;
}
function routeCard(r, cm){
  const s = r.slot, c = winColor(DV.data, s.id), title = `<h2><i class="dot" style="background:${c}"></i>Route ${s.start}–${s.end}</h2>`;
  if (!r.total) return `<section class="card route"><div class="head">${title}<span class="sub num">${s.booked} / ${s.capacity} booked</span></div><p class="sub" style="margin:0">No stops yet.</p></section>`;
  const firstTodo = r.stops.findIndex(x => x.state === "todo");
  const maps = r.maps.map(m => `<a class="btn ghost${r.maps.length === 1 && !s.manual ? " wide" : ""}" target="_blank" rel="noopener" href="${esc(m.url)}">${ICON.nav}${r.maps.length > 1 ? `Maps ${m.from}–${m.to}` : "Whole route in Maps"}</a>`).join("");
  const h = Math.floor(r.minutes / 60), mins = r.minutes % 60;
  return `<section class="card route" data-slot="${s.id}">
      <div class="head">${title}<span class="sub num">${r.done} of ${r.total} delivered</span></div>
      <div class="track"><i style="width:${r.done / r.total * 100}%;background:${c}"></i></div>
      <p class="rt-meta sub">≈ ${String(r.km).replace(".", ",")} km · ${h ? h + " h " : ""}${mins} min${s.courier ? " · " + esc(s.courier) : ""}${r.depot.set || DV.data.courier_view ? "" : ' · <a href="#" class="warnline" data-go="settings:store">set your depot</a>'}</p>
      <div class="rt-acts">
        ${r.startable ? `<button class="btn${cm ? " holo" : ""} wide" data-start="${s.id}">Start route · tell ${r.startable} customer${r.startable > 1 ? "s" : ""}</button>` : ""}
        ${maps}
        ${s.manual ? `<button class="btn ghost" data-reset="${s.id}">Auto order</button>` : ""}
      </div>
      ${!cm && DV.data.couriers.length ? `<label class="rt-cour"><span class="sub">Courier</span><select class="in" data-courier="${s.id}" aria-label="Courier for ${s.start}–${s.end}"><option value="0">No courier</option>${DV.data.couriers.map(c => `<option value="${c.id}" ${c.id == s.courier_id ? "selected" : ""}>${esc(c.name)}</option>`).join("")}</select></label>` : ""}
      <div class="sec-h rt-h"><span>Stops</span><span>${s.manual ? "your order" : "in driving order"}${r.stops.some(x => x.state === "todo" && x.lat != null) ? " · drag ⋮⋮ to move" : ""}</span></div>
      <div class="list stops" data-slot="${s.id}">${r.stops.map((x, i) => stopRow(x, i, i === firstTodo, cm)).join("")}</div></section>`;
}
function stopRow(x, i, next, cm){
  const todo = x.state === "todo", owner = !cm && !DV.data.courier_view;
  const num = owner ? `<a href="#" data-sact="order">#${x.number}</a>` : "#" + x.number;
  const when = x.state === "done" ? "✓ " + (x.eta || "") : x.state === "failed" ? "not home" : x.eta || "—";
  const meta = [esc(x.name || "—"), esc(x.zone), pcs(x.pieces), x.failed && todo ? `<span class="warnline">try ${x.failed + 1}</span>` : "", num];
  const acts = todo ? `<div class="acts">
      <button class="act ok" data-sact="done">${ICON.ok}<span>Delivered</span></button>
      <a class="act" target="_blank" rel="noopener" href="${esc(x.nav)}">${ICON.nav}<span>Navigate</span></a>
      ${x.phone ? `<a class="act" href="tel:${esc(x.phone)}">${ICON.phone}<span>Call</span></a>` : `<span class="act off" aria-hidden="true">${ICON.phone}<span>No phone</span></span>`}
      <button class="act no" data-sact="fail">${ICON.x}<span>Not home</span></button>
      ${x.out && next ? `<button class="act wide" data-sact="onway">${ICON.clock}<span>On my way · ~10 min heads-up</span></button>` : ""}</div>`
    : x.state === "failed" && !DV.data.courier_view ? `<div class="acts1"><button class="btn sm" data-resched="${x.id}" data-num="${x.number}">New window</button></div>`
    : x.photo ? `<div class="acts1"><a class="btn sm ghost" target="_blank" rel="noopener" href="/api/admin/stops/${x.id}/proof">Photo</a></div>` : "";
  return `<div class="stop ${todo ? "todo" : x.state === "done" ? "done" : "fail"}${next ? " next" : ""}" data-id="${x.id}">
    <div class="n">${x.state === "done" ? "✓" : x.state === "failed" ? "!" : i + 1}</div>
    <div class="sb"><div class="sh"><div class="addr">${esc(x.address)} <span class="pc">${esc(x.postcode)}</span></div><b class="eta num">${esc(when)}</b></div>
      <div class="meta">${meta.filter(Boolean).join(" · ")}</div>
      ${x.collect && x.state !== "done" ? `<span class="collect">Collect ${eur(x.collect)} · ${esc(x.method)}</span>` : ""}
      ${x.notes && todo ? `<p class="note" style="margin:8px 0 0">${esc(x.notes)}</p>` : ""}</div>
    ${todo && x.lat != null ? `<button class="grip" aria-label="Move stop ${i + 1} (drag, or use the arrow keys)">⋮⋮</button>` : ""}
    ${acts}</div>`;
}
/* ---------- the courier's own view (role courier only; html.cv): iOS look that follows the phone's light/dark setting ---------- */
// Big title, a week strip, a progress ring, the next stop as a hero (today), then each window as an inset list where one stop is open at a time.
const CV = { open: {}, edit: 0, drawn: false };
const cvDate = (iso, o) => new Date(iso + "T12:00:00").toLocaleDateString("en-GB", o);
function drawCourier(d, el){
  const routes = d.routes.filter(r => r.total), all = routes.flatMap(r => r.stops), today = isToday(d);
  const done = all.filter(x => x.state === "done").length, failed = all.filter(x => x.state === "failed").length, left = all.length - done - failed;
  const cash = all.filter(x => x.state === "todo" && x.collect).reduce((a, x) => a + x.collect, 0);
  const last = all.filter(x => x.state === "todo" && x.eta).map(x => x.eta).sort().pop();
  const day = d.days.find(x => x.date === d.date), parts = day ? day.label.split(" · ") : [];
  const stats = [[left ? left : "✓", left ? "to go" : "all done", ""], last ? [esc(last), "last stop", ""] : null,
    failed ? [failed, "not home", "red"] : null, cash ? [eur(cash), "to collect", "orange"] : null].filter(Boolean);
  el.innerHTML = `<div class="dv-bar cv-bar"><div class="cv-week" role="tablist" aria-label="Day">${d.days.map((x, k) => {
      const p = x.label.split(" · "), [wd, dn] = p[p.length - 1].split(" ");
      return `<button role="tab" class="${k ? "" : "now"}" data-day="${x.date}" aria-selected="${x.date === d.date}" aria-label="${esc(x.label)}, ${x.stops} stop${x.stops === 1 ? "" : "s"}"><small>${esc(wd)}</small><b class="num">${esc(dn || "")}</b><i>${x.stops || ""}</i></button>`; }).join("")}</div></div>
    <header class="cv-head"><div><p>${esc(cvDate(d.date, { weekday: "long", day: "numeric", month: "long" }))}</p><h1>${esc(parts.length > 1 ? parts[0] : cvDate(d.date, { weekday: "long" }))}</h1></div>
      <button class="cv-me" data-cvme aria-label="Account and log out">${esc(initials(ME.name || ME.email))}</button></header>
    ${all.length ? `<section class="cv-card cv-sum${CV.drawn ? "" : " anim"}"><div class="cv-ringbox">${cvRing(done, failed, all.length)}<b class="num">${done}</b><small>of ${all.length}</small></div>
      <div class="cv-stats">${stats.map(([v, l, c]) => `<div class="${c}"><b class="num">${v}</b><span>${l}</span></div>`).join("")}</div></section>` : ""}
    ${cashCard(d, true)}
    ${today ? cvNext(d) : ""}
    ${routes.map(cvRoute).join("") || `<section class="cv-card cv-empty">${ICON.truck}<h2>No stops ${today ? "today" : "on this day"}</h2><p>Nothing to deliver yet. New orders appear here by themselves; tap the day again to refresh.</p></section>`}
    ${routes.length ? mapCard(d) : ""}`;
  CV.drawn = true;
  el.querySelectorAll(".stops").forEach(l => dragStops(l, +l.dataset.slot));
  drawMap(d);
}
/** Activity-style ring: green for delivered, red for not home, on a grey track. */
function cvRing(done, failed, total){
  const C = 2 * Math.PI * 30, a = done / total * C, b = failed / total * C;
  return `<svg class="cv-ring" viewBox="0 0 76 76" aria-hidden="true"><circle cx="38" cy="38" r="30" class="tr"/>
    ${failed ? `<circle cx="38" cy="38" r="30" class="fl" stroke-dasharray="${b} ${C}" stroke-dashoffset="${-a}"/>` : ""}${done ? `<circle cx="38" cy="38" r="30" class="dn" stroke-dasharray="${a} ${C}"/>` : ""}</svg>`;
}
/** The buttons at the door: round Navigate / Call / On my way / Not home, then one big Delivered under the thumb. */
const cvActs = (x, onway) => `${x.notes ? `<p class="cv-note">${esc(x.notes)}</p>` : ""}
  <div class="cv-circ">
    <a target="_blank" rel="noopener" href="${esc(x.nav)}">${ICON.nav}<span>Navigate</span></a>
    ${x.phone ? `<a href="tel:${esc(x.phone)}">${ICON.phone}<span>Call</span></a>` : `<span class="off" aria-hidden="true">${ICON.phone}<span>No phone</span></span>`}
    ${onway ? `<button data-sact="onway" aria-label="On my way: send a 10 minute heads-up">${ICON.clock}<span>On my way</span></button>` : ""}
    <button class="red" data-sact="fail">${ICON.x}<span>Not home</span></button>
  </div>
  <button class="cv-btn go" data-sact="done">${ICON.ok}Delivered</button>`;
function cvNext(d){
  const r = d.routes.find(r => r.stops.some(x => x.state === "todo" && x.out)) || d.routes.find(r => r.stops.some(x => x.state === "todo"));
  if (!r) return "";
  const i = r.stops.findIndex(x => x.state === "todo"), x = r.stops[i], started = r.stops.some(s => s.out);
  return `<section class="cv-card cv-next upnext" data-id="${x.id}">
    <div class="cv-nt"><span class="cv-lbl"><i style="background:${winColor(d, r.slot.id)}"></i>Up next · stop ${i + 1} of ${r.total}</span>${x.eta ? `<span class="cv-eta num">${esc(x.eta)}</span>` : ""}</div>
    <h2 class="cv-addr">${esc(x.address)}</h2>
    <p class="cv-sub">${[esc(x.postcode), esc(x.zone), esc(x.name || ""), pcs(x.pieces), "#" + x.number].filter(Boolean).join(" · ")}</p>
    ${x.collect ? `<p class="cv-collect">${ICON.card}Collect ${eur(x.collect)} · ${esc(x.method)}</p>` : ""}
    ${r.startable && !started ? `<button class="cv-btn" data-start="${r.slot.id}" style="margin-top:16px">${ICON.truck}Start route · tell ${r.startable} customer${r.startable > 1 ? "s" : ""}</button>` : ""}
    ${cvActs(x, x.out)}</section>`;
}
function cvRoute(r){
  const s = r.slot, edit = CV.edit === s.id, h = Math.floor(r.minutes / 60), m = r.minutes % 60;
  const firstTodo = r.stops.findIndex(x => x.state === "todo");
  let open = CV.open[s.id];
  if (open !== 0 && !r.stops.some(x => x.id === open && x.state === "todo")) open = isToday(DV.data) || firstTodo < 0 ? 0 : r.stops[firstTodo].id;
  const movable = r.stops.filter(x => x.state === "todo" && x.lat != null).length > 1;
  return `<section class="route cv-route" data-slot="${s.id}">
    <div class="head cv-sh"><h2><i style="background:${winColor(DV.data, s.id)}"></i>${s.start}–${s.end}</h2><span class="num">${r.done} of ${r.total} delivered</span></div>
    <div class="cv-card cv-rt"><div class="cv-prog"><i style="width:${r.done / r.total * 100}%"></i></div>
      <p class="cv-rmeta">≈ ${String(r.km).replace(".", ",")} km · ${h ? h + " h " : ""}${m} min driving</p>
      ${r.startable ? `<button class="cv-btn" data-start="${s.id}">${ICON.truck}Start route · tell ${r.startable} customer${r.startable > 1 ? "s" : ""}</button>` : ""}
      ${r.maps.map(mp => `<a class="cv-link" target="_blank" rel="noopener" href="${esc(mp.url)}">${ICON.nav}<span>${r.maps.length > 1 ? `Stops ${mp.from}–${mp.to} in Google Maps` : "Whole route in Google Maps"}</span>${ICON.chev}</a>`).join("")}
      ${s.manual ? `<button class="cv-link" data-reset="${s.id}">${ICON.clock}<span>Back to the fastest order</span>${ICON.chev}</button>` : ""}</div>
    <div class="cv-sh2"><span>Stops</span>${movable ? `<button data-edit="${s.id}">${edit ? "Done" : "Reorder"}</button>` : ""}</div>
    <div class="cv-card list stops" data-slot="${s.id}">${r.stops.map((x, i) => cvStop(x, i, i === firstTodo, x.id === open, edit)).join("")}</div>
    ${edit ? `<p class="cv-foot">Drag ≡ to change the order. It is saved at once.</p>` : ""}</section>`;
}
function cvStop(x, i, next, open, edit){
  const st = x.state === "todo" ? "todo" : x.state === "done" ? "done" : "fail", todo = st === "todo";
  const more = edit ? "" : todo ? cvActs(x, x.out && next)
    : x.photo ? `<a class="cv-link" target="_blank" rel="noopener" href="/api/admin/stops/${x.id}/proof">${ICON.doc}<span>Photo at the door</span>${ICON.chev}</a>` : "";
  open = open && !!more;
  const when = st === "fail" ? "Not home" : x.eta || "";
  const tags = todo ? (x.collect ? `<em class="cv-tag orange">Collect ${eur(x.collect)}</em>` : "") + (x.failed ? `<em class="cv-tag red">Try ${x.failed + 1}</em>` : "") + (x.notes ? `<em class="cv-tag">Note</em>` : "") : "";
  const body = `<span class="cv-n">${st === "done" ? ICON.ok : st === "fail" ? "!" : i + 1}</span>
    <span class="cv-tx"><b>${esc(x.address)}</b><small>${[esc(x.postcode), esc(x.name || ""), pcs(x.pieces), "#" + x.number].filter(Boolean).join(" · ")}</small>${tags ? `<span class="cv-tags">${tags}</span>` : ""}</span>
    <span class="cv-tr"><b class="num">${esc(when)}</b>${more ? ICON.chev : ""}</span>`;
  return `<div class="stop ${st}${next ? " next" : ""}${open ? " open" : ""}" data-id="${x.id}">
    ${more ? `<button class="cv-row" data-open aria-expanded="${open}">${body}</button>`
      : `<div class="cv-row">${body}${edit && todo && x.lat != null ? `<button class="grip" aria-label="Move stop ${i + 1} (drag, or use the arrow keys)">≡</button>` : ""}</div>`}
    ${more ? `<div class="cv-more"${open ? "" : " inert"}><div><div class="cv-in">${more}</div></div></div>` : ""}</div>`;
}
/** Tap a stop: it opens (and the open one in that window closes), without a redraw so it can slide. */
function cvToggle(row){
  const list = row.parentElement, on = !row.classList.contains("open");
  const set = (r, v) => { r.classList.toggle("open", v); r.querySelector("[data-open]").setAttribute("aria-expanded", v); r.querySelector(".cv-more").inert = !v; };
  list.querySelectorAll(".stop.open").forEach(r => set(r, false));
  if (on) { set(row, true); setTimeout(() => { if (row.getBoundingClientRect().bottom > innerHeight - 12) row.scrollIntoView({ block: "end", behavior: "smooth" }); }, 320); }
  CV.open[+list.dataset.slot] = on ? +row.dataset.id : 0;
}
function cvAccount(){
  sheet(`<div class="cv-acc"><span class="cv-me">${esc(initials(ME.name || ME.email))}</span><h2>${esc(ME.name || "")}</h2><p>${esc(ME.email)}</p></div>
    <div class="cv-card"><button class="cv-link" data-cvreload>${ICON.clock}<span>Refresh my stops</span>${ICON.chev}</button><button class="cv-link red" data-act="logout">${ICON.x}<span>Log out</span></button></div>`);
  $("sheetBody").querySelector("[data-cvreload]").onclick = () => { closeSheet(); RENDER.delivery(); };
}
/* ---------- the real map: every stop of the day on PDOK tiles (Kadaster), one colour per window, the path in driving order ---------- */
const WIN_COLOR = ["#4A93F0", "#D66E30", "#D4B8FF", "#5BC98C", "#FFE7A3", "#FFB3D9"];
let LMAP = null;
function loadLeaflet(){
  return loadLeaflet.p || (loadLeaflet.p = new Promise((ok, no) => {
    const s = document.createElement("script"); s.src = window.ADMIN.leaflet; s.onload = ok;
    s.onerror = () => { loadLeaflet.p = null; no(new Error("The map could not load.")); }; document.head.append(s);
  }));
}
function mapCard(d){
  const rs = d.routes.filter(r => r.total), n = rs.reduce((a, r) => a + r.stops.length, 0), nogeo = rs.reduce((a, r) => a + r.stops.filter(x => x.lat == null).length, 0);
  if (!mapOn()) return `<section class="card map-card off"><div class="head" style="margin:0"><h2>Map</h2><span class="row" style="gap:10px"><span class="sub num">${n} stop${n === 1 ? "" : "s"}</span><button class="btn sm ghost" data-maptog>Show map</button></span></div></section>`;
  return `<section class="card map-card"><div class="head"><h2>Map</h2><span class="row" style="gap:10px"><span class="sub num">${n} stop${n === 1 ? "" : "s"}</span><button class="btn sm ghost" data-maptog>Hide</button></span></div>
    <div class="legend" style="flex-wrap:wrap;margin:-4px 0 10px">${d.routes.map((r, i) => r.total ? `<span><i style="background:${WIN_COLOR[i % WIN_COLOR.length]}"></i>${r.slot.start}–${r.slot.end} · ${r.total}</span>` : "").join("")}
      <span><i style="background:var(--good)"></i>delivered</span><span><i style="background:var(--bad)"></i>not home</span></div>
    <div class="lmap" id="dmap" role="region" aria-label="Map of today's stops"></div>
    ${nogeo ? `<p class="sub" style="margin:8px 0 0;color:var(--warn)">${nogeo} stop${nogeo === 1 ? " has" : "s have"} no map position (address not found) and ${nogeo === 1 ? "is" : "are"} not shown.</p>` : ""}</section>`;
}
async function drawMap(d){
  if (LMAP) { LMAP.remove(); LMAP = null; }
  const el = $("dmap"); if (!el) return;
  try { await loadLeaflet(); } catch (e) { el.innerHTML = `<p class="sub" style="padding:16px">${esc(e.message)}</p>`; return; }
  if (!el.isConnected || LMAP) return; // redrawn while loading
  const map = LMAP = L.map(el, { scrollWheelZoom: false, zoomSnap: .5 });
  map.on("click focus", () => map.scrollWheelZoom.enable());
  L.tileLayer("https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/grijs/EPSG:3857/{z}/{x}/{y}.png",
    { minZoom: 7, maxZoom: 19, attribution: 'Map: <a href="https://www.kadaster.nl" target="_blank" rel="noopener">Kadaster</a> / PDOK' }).addTo(map);
  const pts = [], depot = d.routes.find(r => r.total).depot;
  const pin = (html, cls) => L.divIcon({ className: "pin " + cls, html, iconSize: [26, 26], iconAnchor: [13, 13], popupAnchor: [0, -12] });
  L.marker([depot.lat, depot.lng], { icon: pin("<b>D</b>", "depot"), keyboard: false, zIndexOffset: -100 }).bindPopup(`<b>Depot</b><br>${esc(depot.label || "")}`).addTo(map);
  pts.push([depot.lat, depot.lng]);
  d.routes.forEach((r, i) => {
    if (!r.total) return;
    const c = WIN_COLOR[i % WIN_COLOR.length], geo = r.stops.map((x, n) => ({ x, n })).filter(p => p.x.lat != null);
    const firstTodo = r.stops.findIndex(x => x.state === "todo");
    L.polyline([[depot.lat, depot.lng], ...geo.map(p => [p.x.lat, p.x.lng])], { color: c, weight: 3, opacity: .75 }).addTo(map);
    geo.forEach(({ x, n }) => {
      const st = x.state === "done" ? "done" : x.state === "failed" ? "fail" : n === firstTodo ? "next" : "todo";
      const label = st === "done" ? "✓" : st === "fail" ? "!" : n + 1;
      const state = st === "done" ? "Delivered " + (x.eta || "") : st === "fail" ? "Not home " + (x.eta || "") : "ETA " + (x.eta || "—");
      L.marker([x.lat, x.lng], { icon: pin(`<b style="--c:${c}" data-slot="${r.slot.id}">${label}</b>`, st), title: `#${x.number} ${x.address}`, riseOnHover: true })
        .bindPopup(`<b>#${x.number} · ${esc(x.name || "—")}</b><br>${esc(x.address)}, ${esc(x.postcode)}<br><span class="sub">${r.slot.start}–${r.slot.end} · stop ${n + 1} · ${esc(state)}</span>
          <div class="row" style="gap:6px;margin-top:8px">${DV.data.courier_view ? "" : `<button class="btn sm" data-mapord="${x.id}">Open order</button>`}<a class="btn sm ghost" target="_blank" rel="noopener" href="${esc(x.nav)}">Navigate</a></div>`).addTo(map);
      pts.push([x.lat, x.lng]);
    });
  });
  map.on("popupopen", e => { const b = e.popup.getElement().querySelector("[data-mapord]"); if (b) b.onclick = () => openOrder(+b.dataset.mapord); });
  if (pts.length > 1) map.fitBounds(pts, { padding: [28, 28], maxZoom: 16 }); else map.setView(pts[0], 14);
}
function zonesCard(d){
  const byFee = {};
  d.fees.filter(z => +z.active).forEach(z => { (byFee[z.fee_cents] = byFee[z.fee_cents] || []).push(z.name); });
  return `<section class="card"><div class="head"><h2>Zones this week</h2><span class="sub">orders</span></div>
    <div class="zones">${d.zones.map(z => `<span class="zone">${esc(z.name)}<b class="num">${z.n}</b></span>`).join("") || '<span class="sub">No orders this week yet.</span>'}</div>
    <p class="sub" style="margin:12px 0 0">Free delivery from ${eur(d.free_from)} · ${Object.entries(byFee).map(([f, n]) => `${eur(+f)}: ${esc(n.join(", "))}`).join(" · ")}</p></section>`;
}
const findStop = id => { for (const r of DV.data.routes) { const s = r.stops.find(x => x.id === id); if (s) return [s, r]; } return [null, null]; };
function putRoute(route){ const i = DV.data.routes.findIndex(r => r.slot.id === route.slot.id); if (i >= 0) DV.data.routes[i] = route; drawDelivery(); }
/** After an action: customers got their email; offer the WhatsApp message with one tap (a real link, so phones never block it). */
function waOffer(title, text, wa, id, tpl){
  if (!wa) { closeSheet(); return; }
  sheet(`<h2 class="t">${esc(title)}</h2><p class="sub">${esc(text)}</p><div class="acts2"><a class="btn wide" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="${esc(wa)}" data-wa-sent>Send WhatsApp</a><button class="btn ghost" style="grid-column:1/3" data-close>Done</button></div>`);
  $("sheetBody").onclick = e => { if (e.target.closest("[data-wa-sent]")) { api(`/api/admin/orders/${id}/wa`, { template: tpl }).catch(() => {}); setTimeout(closeSheet, 300); } if (e.target.closest("[data-close]")) closeSheet(); };
}
$("s-delivery").addEventListener("click", async e => {
  const day = e.target.closest("[data-day]"); if (day) { DV.date = day.dataset.day; return RENDER.delivery(); }
  if (e.target.closest("[data-cm]")) { lsSet("d01courier", courierMode() ? "0" : "1"); return drawDelivery(); }
  if (e.target.closest("[data-maptog]")) { lsSet("d01map", mapOn() ? "0" : "1"); return drawDelivery(); }
  const st = e.target.closest("[data-start]");
  if (st) { const r = DV.data.routes.find(x => x.slot.id === +st.dataset.start);
    if (await ask(`${r.startable} customer${r.startable > 1 ? "s get" : " gets"} their ETA by email.`, { title: `Start the ${r.slot.start}–${r.slot.end} route?`, ok: "Start route" }))
      await busy(st, async () => { const res = await api(`/api/admin/delivery/${r.slot.id}/start`, {}); toast(`Route started · ${res.started} customer${res.started === 1 ? "" : "s"} told`); putRoute(res.route); });
    return; }
  const rs = e.target.closest("[data-reset]"); if (rs) return busy(rs, async () => { putRoute((await api(`/api/admin/delivery/${rs.dataset.reset}/reset`, {})).route); toast("Back to the shortest order"); });
  const rc = e.target.closest("[data-resched]"); if (rc) return reschedule(+rc.dataset.resched, rc.dataset.num);
  const op = e.target.closest("[data-open]"); if (op) return cvToggle(op.closest(".stop"));
  const ed = e.target.closest("[data-edit]"); if (ed) { CV.edit = CV.edit === +ed.dataset.edit ? 0 : +ed.dataset.edit; return drawDelivery(); }
  if (e.target.closest("[data-cvme]")) return cvAccount();
  const b = e.target.closest("[data-sact]"); if (!b) return;
  e.preventDefault();
  const id = +b.closest(".stop,.upnext").dataset.id, [s] = findStop(id);
  if (b.dataset.sact === "order") return openOrder(id);
  if (b.dataset.sact === "done") return deliveredSheet(s);
  if (b.dataset.sact === "fail") return notHomeSheet(s);
  if (b.dataset.sact === "onway") await busy(b, async () => { const r = await api(`/api/admin/stops/${id}/onway`, {}); putRoute(r.route); toast("Heads-up emailed"); waOffer(`On my way to ${s.name || "#" + s.number}`, "They got an email. Send the WhatsApp heads-up too:", r.wa, id, "on_my_way"); });
});
$("s-delivery").addEventListener("change", async e => {
  const c = e.target.closest("[data-courier]"); if (!c) return;
  try { putRoute((await api(`/api/admin/delivery/${c.dataset.courier}/courier`, { courier_id: +c.value })).route); toast("Courier set ✓"); } catch (err) { toastErr(err.message); }
});
function deliveredSheet(s){
  const first = (s.name || "").split(" ")[0] || "the customer";
  sheet(`<h2 class="t">Delivered #${s.number}?</h2><p class="sub" style="margin-top:-6px">${esc(s.address)} · ${esc(s.name || "")}</p>
    <form id="dvForm">${s.collect ? `<div class="card" style="padding:4px 14px;background:var(--card2);margin:12px 0">${toggle("collected", `I collected ${eur(s.collect)}`, true, "Marks it paid and emails the invoice.")}</div>` : ""}
    <label class="field" style="margin-top:12px"><span>Photo at the door (optional)</span><input class="in" type="file" name="photo" accept="image/*" capture="environment"></label>
    <button class="btn block holo" type="submit" style="margin-top:6px;padding:14px">Delivered · tell ${esc(first)}</button></form>`);
  $("dvForm").onsubmit = async e => { e.preventDefault(); const f = e.target, fd = new FormData();
    if (f.collected && f.collected.checked) fd.append("collected", "1");
    if (f.photo.files[0]) fd.append("photo", f.photo.files[0]);
    await busy(f.querySelector("button"), async () => { const r = await api(`/api/admin/stops/${s.id}/delivered`, fd, true); putRoute(r.route); closeSheet(); toast("Delivered ✓ · customer notified"); }); };
}
function notHomeSheet(s){
  const why = ["Nobody home", "Couldn't get in", "Wrong address", "Customer asked for later"];
  sheet(`<h2 class="t">Not home · #${s.number}</h2><p class="sub" style="margin-top:-6px">${esc(s.address)} · ${esc(s.name || "")}</p>
    <div class="menu">${why.map(w => `<button data-why="${esc(w)}">${esc(w)}</button>`).join("")}</div>`);
  $("sheetBody").onclick = async e => { const b = e.target.closest("[data-why]"); if (!b) return;
    await busy(b, async () => { const r = await api(`/api/admin/stops/${s.id}/failed`, { why: b.dataset.why }); putRoute(r.route); toast("Marked not home · customer emailed");
      waOffer("We missed them", "They got an email. Ask on WhatsApp when to come back:", r.wa, s.id, "failed_delivery"); }); };
}
async function reschedule(id, num, after){
  let r; try { r = await api("/api/admin/slots"); } catch (e) { return toastErr(e.message); }
  sheet(`<h2 class="t">New window for #${esc(num)}</h2><div class="menu">${r.slots.filter(s => s.left > 0 || !s.open).map(s => `<button data-sl="${s.id}">${esc(s.label)}<small>${s.left} left${s.open ? "" : " · closed for the shop"}</small></button>`).join("") || `<p class="sub">No windows. Check Settings → Delivery.</p>`}</div>`);
  $("sheetBody").onclick = async e => { const b = e.target.closest("[data-sl]"); if (!b) return;
    await busy(b, async () => { const res = await api(`/api/admin/stops/${id}/reschedule`, { slot_id: +b.dataset.sl }); toast("New window ✓ · customer emailed");
      if (after) after(); else if (current === "delivery") RENDER.delivery();
      waOffer("New window set", "They got an email with the new time. Send it on WhatsApp too:", res.wa, id, "rescheduled"); }); };
}
/** Drag a stop by its handle (touch or mouse), or move it with the arrow keys; the new order is saved for that window. */
function dragStops(list, slotId){
  const save = async () => {
    const ids = [...list.querySelectorAll(".stop.todo")].map(x => +x.dataset.id);
    const r = DV.data.routes.find(x => x.slot.id === slotId), was = r.stops.filter(x => x.state === "todo").map(x => x.id);
    if (ids.join() === was.join()) return;
    try { putRoute((await api(`/api/admin/delivery/${slotId}/reorder`, { ids })).route); toast("Route order saved"); } catch (e) { toastErr(e.message); drawDelivery(); }
  };
  list.querySelectorAll(".grip").forEach(g => {
    g.onpointerdown = e => {
      e.preventDefault();
      const item = g.closest(".stop"); g.setPointerCapture(e.pointerId); item.classList.add("drag");
      g.onpointermove = ev => {
        const sibs = [...list.querySelectorAll(".stop.todo:not(.drag)")];
        const before = sibs.find(x => { const b = x.getBoundingClientRect(); return ev.clientY < b.top + b.height / 2; });
        if (before) { if (item.nextElementSibling !== before) list.insertBefore(item, before); }
        else if (sibs.length && sibs[sibs.length - 1].nextElementSibling !== item) sibs[sibs.length - 1].after(item);
      };
      g.onpointerup = g.onpointercancel = () => { g.onpointermove = g.onpointerup = g.onpointercancel = null; item.classList.remove("drag"); save(); };
    };
    g.onkeydown = e => {
      if (e.key !== "ArrowUp" && e.key !== "ArrowDown") return;
      e.preventDefault();
      const item = g.closest(".stop"), sib = e.key === "ArrowUp" ? item.previousElementSibling : item.nextElementSibling;
      if (!sib || !sib.classList.contains("todo")) return;
      if (e.key === "ArrowUp") sib.before(item); else sib.after(item);
      g.focus(); clearTimeout(g._t); g._t = setTimeout(save, 600);
    };
  });
}

/* ================= CUSTOMERS ================= */
const CL = { seg: "all", q: "", rows: [], total: 0 };
RENDER.customers = async () => {
  const el = $("s-customers");
  if (!el.dataset.ready) {
    el.dataset.ready = 1;
    el.innerHTML = `<div class="search"><input id="cq" type="search" placeholder="Search name, phone, email" aria-label="Search customers"></div><div class="chips" id="cfilters"></div>
      <section class="card" style="padding-top:4px"><div id="custs"></div></section><button class="btn more block" id="cMore" hidden style="margin-top:10px;background:var(--card2);color:var(--ink2)">Load more</button>`;
    let t; $("cq").oninput = () => { clearTimeout(t); t = setTimeout(() => { CL.q = $("cq").value.trim(); loadCustomers(); }, 250); };
    $("cMore").onclick = () => loadCustomers(true);
  }
  await loadCustomers();
};
const initials = n => (n || "?").split(" ").filter(Boolean).map(w => w[0].toUpperCase()).filter((_, k, a) => k === 0 || k === a.length - 1).join("").slice(0, 2);
async function loadCustomers(more){
  let r; try { r = await api(`/api/admin/customers?segment=${CL.seg}&q=${encodeURIComponent(CL.q)}&offset=${more ? CL.rows.length : 0}`); } catch (e) { return toastErr(e.message); }
  CL.rows = more ? CL.rows.concat(r.rows) : r.rows; CL.total = r.total;
  $("cfilters").innerHTML = Object.entries(r.labels).map(([k, l]) => `<button class="chip" data-f="${k}" aria-pressed="${k === CL.seg}">${esc(l)}<i>${r.counts[k] || 0}</i></button>`).join("");
  $("cfilters").querySelectorAll(".chip").forEach(b => b.onclick = () => { CL.seg = b.dataset.f; loadCustomers(); });
  const tag = s => s === "regular" ? '<span class="tag">Regular</span>' : s === "new" ? '<span class="tag" style="background:rgba(74,147,240,.12);color:#8DB9F5">New</span>' : s === "waitlist" ? '<span class="tag" style="background:rgba(212,184,255,.12);color:#D4B8FF">Waitlist</span>' : s === "inactive" ? '<span class="tag" style="background:var(--card2);color:var(--ink3)">Inactive</span>' : "";
  $("custs").innerHTML = CL.rows.map(c => `<button class="cust" ${c.id ? `data-cid="${c.id}"` : "disabled"}><div class="av">${esc(initials(c.name || c.email))}</div>
    <div style="min-width:0"><div style="font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis">${esc(c.name || c.email || c.phone)}${tag(c.segment)}</div>
    <div class="sub" style="margin-top:2px">${c.last_order ? "last order " + esc(ago(c.last_order)) : "joined " + esc(ago(c.created_at))}${c.source ? " · " + esc(c.source) : ""}</div></div>
    <div style="text-align:right"><div class="num" style="font-weight:700">${eur(+c.spent)}</div><div class="sub">${c.orders} order${+c.orders === 1 ? "" : "s"}</div></div></button>`).join("")
    || `<p class="sub" style="padding:14px 0;margin:0">${CL.q ? "No one matches." : "No customers here yet."}</p>`;
  $("custs").querySelectorAll("[data-cid]").forEach(b => b.onclick = () => openCustomer(+b.dataset.cid));
  $("cMore").hidden = CL.rows.length >= CL.total;
}
async function openCustomer(id){
  let c; try { c = (await api("/api/admin/customers/" + id)).customer; } catch (e) { return toastErr(e.message); }
  const a = c.addresses[0];
  sheet(`<div class="row"><div class="av" style="width:52px;height:52px;font-size:18px">${esc(initials(c.name))}</div><div><h2 style="margin:0;font-size:20px">${esc(c.name || "—")}</h2><div class="sub">${a ? esc(`${a.street || ""} ${a.house_no}, ${a.postcode}`) : "No address yet"}</div></div></div>
    <div class="grid2" style="margin-top:16px"><div class="card kpi"><div class="mono">Spent</div><div class="v num">${eur(c.spent)}</div></div><div class="card kpi"><div class="mono">Orders</div><div class="v num">${c.order_count}</div><span class="sub">avg ${c.order_count ? eur(Math.round(c.spent / c.order_count)) : "—"}</span></div></div>
    <dl class="kv"><dt>Phone</dt><dd>${c.phone ? `<a href="tel:${esc(c.phone)}" style="color:inherit">${esc(c.phone)}</a>` : "—"}</dd><dt>Email</dt><dd>${esc(c.email || "—")}</dd>
      <dt>Came from</dt><dd>${esc(c.source || "—")}</dd><dt>Marketing consent</dt><dd>${+c.marketing_consent ? "✓ Yes · " + esc(new Date(c.consent_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })) : "✕ No"}</dd>
      ${c.waitlist ? `<dt>Waitlist gift</dt><dd>${esc(c.waitlist.gift_code || "—")}${c.waitlist.gift_redeemed_order_id ? " · used" : c.waitlist.gift_code ? " · not used yet" : ""}</dd>` : ""}
      ${c.referral ? `<dt>Referral code</dt><dd>${esc(c.referral)}${c.referral_uses ? ` · ${c.referral_uses} friend${c.referral_uses > 1 ? "s" : ""}` : ""}</dd>` : ""}
      ${c.codes && c.codes.length ? `<dt>Personal codes</dt><dd>${c.codes.map(x => `${esc(x.code)} · ${eur(+x.value)}${+x.used ? " · used" : +x.active ? "" : " · off"}`).join("<br>")}</dd>` : ""}</dl>
    <div class="mono" style="margin:6px 0 8px">Orders</div>
    ${c.orders.map(o => `<div class="row" role="button" tabindex="0" data-open="${o.id}" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line);font-size:13px;cursor:pointer"><span>#${o.number} · ${esc(new Date(o.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short" }))}</span><span class="st ${ST_CLASS[o.status]}">${esc((OL.labels || {})[o.status] || o.status)}</span><span class="num">${eur(+o.total_cents)}</span></div>`).join("") || `<p class="sub">No orders yet.</p>`}
    <label class="field" style="margin-top:14px"><span>Notes</span><textarea class="in" id="cNotes" style="font-family:var(--f-b);font-size:14px;min-height:70px">${esc(c.notes || "")}</textarea></label>
    <div class="acts2"><button class="btn wide" data-cact="new">+ New order</button>
      ${can("inbox") && c.email ? `<button class="btn ghost ib-btn" data-cact="chat" data-ch="email">${ICON.mail}Email</button>` : ""}
      ${can("inbox") && (c.chats || []).some(x => x.channel === "telegram") ? `<button class="btn ghost ib-btn" data-cact="chat" data-ch="telegram">${ICON.tg}Telegram</button>` : ""}
      ${c.wa ? `<a class="btn ghost" style="text-align:center;text-decoration:none" target="_blank" rel="noopener" href="${esc(c.wa)}">WhatsApp</a>` : ""}
      <a class="btn ghost" style="text-align:center;text-decoration:none${[can("inbox") && c.email, can("inbox") && (c.chats || []).some(x => x.channel === "telegram"), c.wa].filter(Boolean).length % 2 ? "" : ";grid-column:1/3"}" href="/api/admin/customers/${c.id}/export" download>Export data</a>
      ${ME.role === "owner" ? `<button class="btn bad" data-cact="delete" style="grid-column:1/3">Delete customer (GDPR)</button>` : ""}</div>`);
  const sb = $("sheetBody");
  $("cNotes").onchange = () => api(`/api/admin/customers/${c.id}/note`, { notes: $("cNotes").value }).then(() => toast("Note saved")).catch(err => toastErr(err.message));
  sb.onclick = async e => {
    const o = e.target.closest("[data-open]"); if (o) return openOrder(+o.dataset.open);
    const b = e.target.closest("[data-cact]"); if (!b) return;
    if (b.dataset.cact === "chat") return startChat(c.id, b.dataset.ch);
    if (b.dataset.cact === "new") { await newOrder(); const f = $("noForm"); if (f) { f.name.value = c.name || ""; f.phone.value = c.phone || ""; f.email.value = c.email || ""; if (a) { f.postcode.value = a.postcode; f.house_no.value = a.house_no + (a.addition || ""); f.postcode.dispatchEvent(new Event("input")); } } }
    if (b.dataset.cact === "delete" && await ask("Their orders stay for the tax office, without name or address. This cannot be undone.", { title: `Delete all personal data of ${c.name || "this customer"}?`, ok: "Delete for good", danger: true }))
      await busy(b, async () => { await api(`/api/admin/customers/${c.id}/delete`, {}); closeSheet(); toast("Customer deleted"); loadCustomers(); });
  };
}

/* ================= INBOX: email + Telegram chats in one place ================= */
const IB = { ch: "", unread: false, q: "", rows: [], total: 0, cur: null, data: null, setup: null, timer: null, sending: false };
const CH = { email: { label: "Email", icon: ICON.mail }, telegram: { label: "Telegram", icon: ICON.tg } };
const chBadge = ch => `<i class="chb ${ch}" title="${CH[ch].label}" aria-label="${CH[ch].label}">${CH[ch].icon}</i>`;
const ibName = c => c.name || c.customer_name || c.handle || c.address;
const ibTime = iso => { const d = new Date(iso), now = new Date(); return d.toDateString() === now.toDateString() ? d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }) : now - d < 6 * 864e5 ? d.toLocaleDateString("en-GB", { weekday: "short" }) : d.toLocaleDateString("en-GB", { day: "numeric", month: "short" }); };
const linkify = t => esc(t).replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)"']/g, u => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`).replace(/\n/g, "<br>");
function setUnread(n){ const b = $("ibCount"); if (!b) return; b.hidden = !n; b.textContent = n > 99 ? "99+" : n; }
async function refreshUnread(){ try { setUnread((await api("/api/admin/inbox/unread")).unread); } catch {} }

RENDER.inbox = async sub => {
  const el = $("s-inbox");
  if (!el.dataset.ready) {
    el.dataset.ready = 1;
    el.innerHTML = `<div class="ib" id="ib"><div class="ib-side">
        <div class="search"><input id="ibq" type="search" placeholder="Search name, email, message" aria-label="Search chats"></div>
        <div class="chips" id="ibf"></div><div id="ibSetup"></div>
        <div class="card ib-list" id="ibList"><p class="sub" style="margin:0;padding:14px 0">Loading…</p></div>
        <button class="btn more block" id="ibMore" hidden style="margin-top:10px;background:var(--card2);color:var(--ink2)">Load more</button></div>
      <div class="ib-main" id="ibMain"><div class="ib-empty">${ICON.chat}<p>Pick a chat on the left.</p></div></div></div>`;
    let t; $("ibq").oninput = () => { clearTimeout(t); t = setTimeout(() => { IB.q = $("ibq").value.trim(); loadInbox(); }, 250); };
    $("ibMore").onclick = () => loadInbox(true);
    $("ibList").onclick = e => { const r = e.target.closest("[data-conv]"); if (r) openChat(+r.dataset.conv); };
  }
  const m = /^c(\d+)$/.exec(sub || "");
  if (!m) { IB.cur = null; $("ib").classList.remove("open"); if (wide()) drawEmptyChat(); }
  await loadInbox();
  if (m) openChat(+m[1]);
  clearInterval(IB.timer);
  IB.timer = setInterval(() => { if (current !== "inbox") { clearInterval(IB.timer); return; } if (document.hidden) return; loadInbox(false, true); if (IB.cur) openChat(IB.cur, true); }, 15000);
};
function drawEmptyChat(){ $("ibMain").innerHTML = `<div class="ib-empty">${ICON.chat}<p>Pick a chat on the left.</p></div>`; }
async function loadInbox(more, quiet){
  let r; try { r = await api(`/api/admin/inbox?channel=${IB.ch}&unread=${IB.unread ? 1 : ""}&q=${encodeURIComponent(IB.q)}&offset=${more ? IB.rows.length : 0}`); } catch (e) { if (!quiet) toastErr(e.message); return; }
  IB.rows = more ? IB.rows.concat(r.rows) : r.rows; IB.total = r.total; IB.setup = r.setup;
  setUnread(r.counts.unread);
  const chips = [["", false, "All", r.counts.all], ["", true, "Unread", r.counts.unread], ["email", false, "Email", r.counts.email], ["telegram", false, "Telegram", r.counts.telegram]];
  $("ibf").innerHTML = chips.map(([ch, un, l, n]) => `<button class="chip" data-ch="${ch}" data-un="${un ? 1 : ""}" aria-pressed="${IB.ch === ch && IB.unread === un}">${ch ? CH[ch].icon : ""}${l}<i>${n}</i></button>`).join("");
  $("ibf").querySelectorAll(".chip").forEach(b => b.onclick = () => { IB.ch = b.dataset.ch; IB.unread = !!b.dataset.un; loadInbox(); });
  const s = r.setup, tips = [];
  if (!s.email_in) tips.push(`<b>Customer emails don’t come in yet.</b> Add your incoming mail server in <a href="#" data-go="settings:email">Settings → Email</a>.`);
  else if (s.email_error) tips.push(`<b>Can’t read your mailbox:</b> ${esc(s.email_error)} <a href="#" data-go="settings:email">Check the settings</a>.`);
  if (!s.telegram) tips.push(`<b>Telegram is not connected.</b> Make a bot with @BotFather and paste its token in <a href="#" data-go="settings:telegram">Settings → Telegram</a>.`);
  $("ibSetup").innerHTML = tips.length && can("settings") ? `<div class="note ib-tip">${tips.join("<br>")}</div>` : "";
  $("ibList").innerHTML = IB.rows.map(c => `<button class="ib-row${c.unread ? " un" : ""}${c.id === IB.cur ? " cur" : ""}" data-conv="${c.id}">
      <div class="av">${esc(initials(ibName(c)))}${chBadge(c.channel)}</div>
      <div class="ib-t"><div class="ib-n"><b>${esc(ibName(c))}</b><span class="sub">${esc(ibTime(c.last_at))}</span></div>
        <div class="ib-p">${c.subject && c.channel === "email" ? `<span class="ib-s">${esc(c.subject)}</span> · ` : ""}${c.last_dir === "out" ? "You: " : ""}${esc(c.last_text || "")}</div></div>
      ${c.unread ? `<em class="ib-un" aria-label="${c.unread} new">${c.unread}</em>` : ""}</button>`).join("")
    || `<p class="sub" style="margin:0;padding:14px 0">${IB.q ? "No chat matches." : IB.unread ? "All read. Nice." : "No chats yet. When customers email you or write to your Telegram bot, they show up here."}</p>`;
  $("ibMore").hidden = IB.rows.length >= IB.total;
}
async function openChat(id, quiet){
  let r; try { r = await api("/api/admin/inbox/" + id); } catch (e) { if (!quiet) { toastErr(e.message); IB.cur = null; } return; }
  const same = IB.cur === id && IB.data;
  if (quiet && same && IB.data.items.length === r.items.length && JSON.stringify(IB.data.items.map(x => x.status)) === JSON.stringify(r.items.map(x => x.status))) return;
  IB.cur = id; IB.data = r; setUnread(r.unread);
  document.querySelectorAll("#ibList .ib-row").forEach(b => { b.classList.toggle("cur", +b.dataset.conv === id); if (+b.dataset.conv === id) { b.classList.remove("un"); const u = b.querySelector(".ib-un"); if (u) u.remove(); } });
  try { history.replaceState(null, "", "/admin/inbox#c" + id); } catch {}
  drawChat(same);
}
function drawChat(keep){
  const { conversation: c, items } = IB.data, main = $("ibMain");
  const box = $("ibMsgs"), atBottom = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 80;
  const draft = keep && $("ibText") ? [$("ibText").value, $("ibSubj") ? $("ibSubj").value : ""] : null;
  $("ib").classList.add("open"); if (!wide()) document.body.classList.add("ib-thread");
  let day = "";
  const time = iso => new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
  const st = x => x.status === "sent" ? "✓ sent" : x.status === "queued" || x.status === "retry" ? "sending…" : x.status === "failed" || x.status === "not_sent" ? `<span class="bad">✕ not sent</span>` : esc(x.status || "");
  const msgs = items.map(x => {
    const d = new Date(x.at).toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" });
    const sep = d !== day ? `<div class="ib-day"><span>${esc(d)}</span></div>` : ""; day = d;
    if (x.kind === "auto") return sep + `<div class="ib-auto">${ICON.mail}<span>Automatic email · ${x.order_id ? `<a href="#" data-ord="${x.order_id}">${esc(x.text)}</a>` : esc(x.text)} · ${st(x)} · ${time(x.at)}</span></div>`;
    const files = (x.files || []).map(f => f.url ? `<a class="ib-img" href="${esc(f.url)}" target="_blank" rel="noopener"><img src="${esc(f.url)}" alt="${esc(f.name)}" loading="lazy"></a>` : `<span class="ib-file">📎 ${esc(f.name)} <small>open it in your ${c.channel === "email" ? "mailbox" : "Telegram app"}</small></span>`).join("");
    const failed = x.kind === "out" && (x.status === "failed" || x.status === "not_sent");
    const spoof = x.spoof ? `<div class="ib-spoof">${ICON.warn}<span><b>Sender not confirmed.</b> The mail server could not prove this email really comes from ${esc(c.address)}. Don’t change an order, address or payment because of it. Ask on another channel first.</span></div>` : "";
    return sep + `<div class="bub ${x.kind}${failed ? " failed" : ""}">${spoof}${x.text ? `<div class="tx">${linkify(x.text)}</div>` : ""}${files}
      <div class="meta">${time(x.at)}${x.kind === "out" ? ` · ${esc(x.by || "Auto reply")} · ${st(x)}` : ""}</div>
      ${failed ? `<div class="meta bad">${esc(x.error || "")} <button type="button" class="btn sm ghost" data-retry="${x.id}">Send again</button></div>` : ""}</div>`;
  }).join("") || `<p class="sub" style="text-align:center;margin:30px 0">No messages yet. Write the first one below.</p>`;
  const who = c.channel === "email" ? c.address : [c.handle, "Telegram"].filter(Boolean).join(" · ");
  const needSubj = c.channel === "email" && !c.subject;
  main.innerHTML = `<div class="ib-head"><button class="ib-back" data-ib="back" aria-label="Back to all chats"><svg class="ic" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></button>
      <div class="av">${esc(initials(ibName(c)))}${chBadge(c.channel)}</div>
      <div class="ib-who"><b>${esc(ibName(c))}</b><small>${esc(who)}${c.subject && c.channel === "email" ? " · " + esc(c.subject) : ""}</small></div>
      <div class="ib-acts">${c.customer_id && can("customers") ? `<button class="btn sm ghost" data-ib="customer">${c.orders ? `${c.orders} order${c.orders > 1 ? "s" : ""}` : "Customer"}</button>` : ""}
        ${c.last_order && can("orders") ? `<button class="btn sm ghost" data-ib="order">#${c.last_order.number}</button>` : ""}
        <button class="btn sm ghost" data-ib="menu" aria-label="More actions">⋯</button></div></div>
    <div class="ib-msgs" id="ibMsgs">${msgs}</div>
    <form class="ib-comp" id="ibComp">${needSubj ? `<input class="in" id="ibSubj" placeholder="Subject" maxlength="200" aria-label="Subject">` : ""}
      <div class="ib-row2"><textarea class="in" id="ibText" rows="1" placeholder="${c.channel === "email" ? "Write an email…" : "Write in Telegram…"}" aria-label="Your reply" maxlength="4000"></textarea>
      <button class="btn" id="ibSend" type="submit" aria-label="Send"><svg class="ic" viewBox="0 0 24 24"><path d="M4 12l16-8-6 16-2.5-6.5z"/></svg></button></div>
      <small class="ib-hint">${chBadge(c.channel)}${c.can_send ? (c.channel === "email" ? `Sent as an email from your store address` : `Sent by your bot in Telegram`) + (wide() ? " · Ctrl/⌘ + Enter sends" : "") : c.channel === "email" ? `Email is not set up — <a href="#" data-go="settings:email">Settings → Email</a>` : `Telegram is not connected — <a href="#" data-go="settings:telegram">Settings → Telegram</a>`}</small></form>`;
  const mb = $("ibMsgs"); if (atBottom || !keep) mb.scrollTop = mb.scrollHeight;
  mb.querySelectorAll("img").forEach(i => i.addEventListener("load", () => { if (atBottom || !keep) mb.scrollTop = mb.scrollHeight; }, { once: true }));
  const ta = $("ibText");
  if (draft) { ta.value = draft[0]; if ($("ibSubj")) $("ibSubj").value = draft[1]; }
  const grow = () => { ta.style.height = "auto"; ta.style.height = Math.min(ta.scrollHeight, 180) + "px"; }; ta.oninput = grow; grow();
  ta.onkeydown = e => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) { e.preventDefault(); $("ibComp").requestSubmit(); } };
  $("ibComp").onsubmit = async e => {
    e.preventDefault();
    if (!ta.value.trim() || IB.sending) return;
    IB.sending = true;
    await busy($("ibSend"), async () => {
      const r = await api(`/api/admin/inbox/${c.id}/reply`, { text: ta.value, subject: $("ibSubj") ? $("ibSubj").value : "" });
      ta.value = ""; IB.data = r; setUnread(r.unread); drawChat(false); loadInbox(false, true);
    });
    IB.sending = false;
  };
  main.onclick = async e => {
    const rt = e.target.closest("[data-retry]");
    if (rt) return busy(rt, async () => { await api(`/api/admin/inbox/msg/${rt.dataset.retry}/retry`, {}); toast("Sending again…"); setTimeout(() => openChat(c.id, true), 1500); });
    const o = e.target.closest("[data-ord]"); if (o) { e.preventDefault(); return openOrder(+o.dataset.ord); }
    const b = e.target.closest("[data-ib]"); if (!b) return;
    const a = b.dataset.ib;
    if (a === "back") { IB.cur = null; $("ib").classList.remove("open"); document.body.classList.remove("ib-thread"); try { history.replaceState(null, "", "/admin/inbox"); } catch {} loadInbox(false, true); if (wide()) drawEmptyChat(); }
    if (a === "customer") openCustomer(c.customer_id);
    if (a === "order") openOrder(c.last_order.id);
    if (a === "menu") sheet(`<h2 class="t">${esc(ibName(c))}</h2><div class="menu">
        <button data-ibm="unread">${ICON.chat}Mark as unread</button>
        ${c.channel === "email" ? `<a href="mailto:${esc(c.address)}" class="btn-like">${ICON.mail}Open in your mail app <small>${esc(c.address)}</small></a>` : c.handle ? `<a href="https://t.me/${esc(c.handle.slice(1))}" target="_blank" rel="noopener" class="btn-like">${ICON.tg}Open in Telegram <small>${esc(c.handle)}</small></a>` : ""}
        <button data-ibm="delete" class="bad">${ICON.x}Delete this chat <small>for spam or mistakes</small></button></div>`);
    if (a === "menu") $("sheetBody").onclick = async ev => {
      const m = ev.target.closest("[data-ibm]"); if (!m) return;
      if (m.dataset.ibm === "unread") await busy(m, async () => { const r = await api(`/api/admin/inbox/${c.id}/unread`, {}); setUnread(r.unread); closeSheet(); IB.cur = null; $("ib").classList.remove("open"); document.body.classList.remove("ib-thread"); if (wide()) drawEmptyChat(); loadInbox(); });
      if (m.dataset.ibm === "delete" && await ask("The messages and photos are removed from the Inbox.", { title: `Delete the chat with ${ibName(c)}?`, ok: "Delete chat", danger: true })) await busy(m, async () => { await api(`/api/admin/inbox/${c.id}/delete`, {}); closeSheet(); IB.cur = null; $("ib").classList.remove("open"); document.body.classList.remove("ib-thread"); if (wide()) drawEmptyChat(); toast("Chat deleted"); loadInbox(); });
    };
  };
}
/* "Message" on a customer or an order: opens their chat (email, or the Telegram chat they started). */
async function startChat(customerId, channel){
  let r; try { r = await api("/api/admin/inbox/start", { customer_id: customerId, channel }); } catch (e) { return toastErr(e.message); }
  closeSheet(); go("inbox", "c" + r.id);
}

/* ================= STOCK: products, variants, photos ================= */
let PRODUCTS = [];
RENDER.stock = async () => {
  const el = $("s-stock");
  if (!el.dataset.ready) {
    el.dataset.ready = 1;
    el.innerHTML = `<section class="grid2" id="stockKpi"></section>
      <div class="search"><input id="pq" type="search" placeholder="Search products" aria-label="Search products"><button class="btn" data-act="newProduct">+ Add</button></div>
      <section class="card"><div class="list" id="prods"></div></section>
      <p class="sub" style="margin:10px 4px">Use ↑ ↓ inside a product to change its order in the shop and on the waitlist.</p>
      <section class="card"><div class="head"><h2>Bundle</h2><button class="btn sm ghost" data-act="bundle">Edit</button></div><div id="bundleInfo" class="sub">…</div></section>`;
    $("pq").oninput = drawStock;
  }
  try { PRODUCTS = (await api("/api/admin/products")).products; } catch (e) { toastErr(e.message); }
  drawStock(); loadBundle();
};
let BUNDLES = [];
async function loadBundle(){
  try { BUNDLES = (await api("/api/admin/bundles")).bundles; } catch { return; }
  const b = BUNDLES.find(x => +x.active), names = b ? b.product_ids.map(id => (PRODUCTS.find(p => p.id == id) || {}).name).filter(Boolean) : [];
  $("bundleInfo").textContent = b && names.length >= 2 ? `${b.name}: ${names.join(" + ")} · ${b.discount_pct}% off when all are in the bag` : "No bundle in the shop. Tap Edit to make one.";
}
function bundleSheet(){
  const b = BUNDLES.find(x => +x.active) || BUNDLES[0] || { id: 0, name: "The full drop", discount_pct: 15, active: 1, product_ids: [] };
  sheet(`<h2 class="t">Bundle</h2><form id="bForm"><p class="sub" style="margin:0 0 12px">Customers get the discount on one of each product when all of them are in the bag.</p>
    <div class="cols"><label class="field"><span>Name</span><input class="in" name="name" value="${esc(b.name)}"></label><label class="field"><span>Discount %</span><input class="in" name="pct" inputmode="numeric" value="${b.discount_pct}"></label></div>
    <div class="card" style="padding:4px 14px;background:var(--card2)">${PRODUCTS.filter(p => p.active == 1).map(p => toggle("p" + p.id, esc(p.name), b.product_ids.includes(+p.id), eur(p.price_cents))).join("")}</div>
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-top:10px">${toggle("active", "Show in the shop", +b.active === 1)}</div>
    <button class="btn holo block" style="margin-top:12px">Save bundle</button></form>`);
  $("bForm").onsubmit = async e => { e.preventDefault(); const f = e.target;
    await busy(f.querySelector("button"), async () => { BUNDLES = (await api("/api/admin/bundles/save", { id: b.id, name: f.name.value, discount_pct: +f.pct.value, active: f.active.checked,
      product_ids: PRODUCTS.filter(p => f["p" + p.id] && f["p" + p.id].checked).map(p => +p.id) })).bundles; closeSheet(); toast("Bundle saved ✓"); loadBundle(); }); };
}
async function stockSheet(p, v){
  let h; try { h = (await api(`/api/admin/stock/${v.id}/history`)).rows; } catch (e) { return toastErr(e.message); }
  const R = { sale: "Sold", reserve: "Held at checkout", release: "Hold released", receive: "Received", adjust: "Adjusted", damage: "Damaged", return: "Returned" };
  sheet(`<h2 class="t">${esc(p.name)}${v.label !== "Default" ? " · " + esc(v.label) : ""}</h2>
    <div class="grid2"><div class="card kpi"><div class="mono">In stock</div><div class="v num">${v.stock}</div></div><div class="card kpi"><div class="mono">Sold</div><div class="v num">${-h.filter(m => m.reason === "sale").reduce((s, m) => s + +m.delta, 0)}</div></div></div>
    <form id="adjForm" style="margin-top:14px"><div class="cols"><label class="field"><span>Change</span><input class="in" name="delta" inputmode="numeric" placeholder="+10 or -2" required></label>
      <label class="field"><span>Reason</span><select class="in" name="reason"><option value="receive">Received new stock</option><option value="adjust">Counted / correction</option><option value="damage">Damaged</option><option value="return">Customer return</option></select></label></div>
      <label class="field"><span>Note (optional)</span><input class="in" name="note"></label><button class="btn holo block">Save change</button></form>
    <div class="mono" style="margin:18px 0 8px">History</div>
    ${h.map(m => `<div class="row" style="justify-content:space-between;padding:7px 0;border-top:1px solid var(--line);font-size:13px"><span>${esc(R[m.reason] || m.reason)}${m.ref ? ` <span class="sub">· ${esc(m.ref)}</span>` : ""}${m.by_name ? ` <span class="sub">· ${esc(m.by_name)}</span>` : ""}</span><span class="num" style="color:${+m.delta > 0 ? "var(--good)" : "var(--ink2)"}">${+m.delta > 0 ? "+" : ""}${m.delta}</span><span class="sub">${esc(ago(m.created_at))}</span></div>`).join("") || `<p class="sub">No changes yet.</p>`}
    <button class="btn ghost block" style="margin-top:12px" data-back>Back to product</button>`);
  $("adjForm").onsubmit = async e => { e.preventDefault(); const f = e.target;
    await busy(f.querySelector("button"), async () => { PRODUCTS = (await api("/api/admin/stock/adjust", { variant_id: v.id, delta: parseInt(f.delta.value, 10), reason: f.reason.value, note: f.note.value })).products;
      drawStock(); toast("Stock updated ✓"); const np = PRODUCTS.find(x => x.id == p.id); stockSheet(np, np.variants.find(x => x.id == v.id)); }); };
  $("sheetBody").querySelector("[data-back]").onclick = () => openProduct(p.id);
}
document.addEventListener("click", e => { if (e.target.closest("[data-act=bundle]")) bundleSheet(); });
function drawStock(){
  const value = PRODUCTS.reduce((s, p) => s + p.stock * p.cost_cents, 0);
  const low = PRODUCTS.filter(p => p.active == 1 && p.stock > 0 && p.stock <= p.low_stock_at).length, out = PRODUCTS.filter(p => p.active == 1 && p.stock === 0).length;
  $("stockKpi").innerHTML = `<div class="card kpi"><div class="mono">Stock value</div><div class="v num">${eur(value)}</div><span class="sub">at purchase price</span></div>
    <div class="card kpi"><div class="mono">Running low</div><div class="v num" style="${low ? "color:var(--warn)" : ""}">${low}</div><span class="sub">${out} sold out</span></div>`;
  const q = ($("pq").value || "").toLowerCase();
  const rows = PRODUCTS.filter(p => !q || p.name.toLowerCase().includes(q));
  $("prods").innerHTML = rows.map((p, i) => { const m = p.price_cents ? Math.round((p.price_cents - p.cost_cents) / p.price_cents * 100) : 0;
    const vs = p.variants.filter(v => v.active == 1).map(v => v.label + (v.shelf ? ` (${v.shelf})` : "")).join(" · ");
    const tags = [p.is_demo == 1 ? "Demo" : "", p.on_waitlist == 1 ? "Waitlist" : "", p.reveal == 1 ? "" : "Sealed", p.active == 1 ? "" : "Hidden"].filter(Boolean);
    return `<div class="prod" role="button" tabindex="0" data-pid="${p.id}"><div class="thumb">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : String(i + 1).padStart(2, "0")}</div>
      <div><div class="n">${esc(p.name)}${tags.length ? `<span class="tags">${tags.map(t => `<span class="tag">${t}</span>`).join("")}</span>` : ""}</div><div class="m">${esc(vs)} · ${eur(p.price_cents)} · ${m}% margin</div></div>
      <div class="qty"><b class="num">${p.stock}</b>${p.stock === 0 ? `<span class="out0">✕ Sold out</span>` : p.stock <= p.low_stock_at ? `<span class="low">▲ Low</span>` : `<span class="sub">in stock</span>`}</div></div>`; }).join("")
    || `<p class="sub" style="padding:14px 0;margin:0">${q ? "No product matches." : "No products yet. Tap + Add to create your first one."}</p>`;
}
document.addEventListener("click", e => {
  const p = e.target.closest("[data-pid]"); if (p) openProduct(+p.dataset.pid);
  if (e.target.closest("[data-act=newProduct]")) openProduct(0);
});
document.addEventListener("keydown", e => { if (e.key === "Enter" && e.target.matches("[data-pid],[role=button][data-go]")) e.target.click(); });

function openProduct(id){
  const p = PRODUCTS.find(x => x.id == id) || { id: 0, name: "", slug: "", price_cents: 0, cost_cents: 0, vat_rate: 21, category: "", note: "Reveal soon", featured: 0, reveal: 0, on_waitlist: 1, active: 1, run_size: 0, low_stock_at: 5, description: "", variants: [{ label: "Default", stock: 0 }], images: [] };
  const vars = p.variants.filter(v => v.active == 1);
  sheet(`<h2 class="t">${id ? esc(p.name) : "New product"}</h2>
    <form id="pForm" novalidate>
      ${p.is_demo == 1 ? `<p class="note">This is a demo product. Edit it into a real one, or remove all demo data in More → System.</p>` : ""}
      <label class="field"><span>Name</span><input class="in" name="name" value="${esc(p.name)}" required maxlength="160"></label>
      <div class="cols"><label class="field"><span>Price incl. BTW (€)</span><input class="in" name="price" inputmode="decimal" value="${euroIn(p.price_cents)}"></label>
        <label class="field"><span>Your cost (€)</span><input class="in" name="cost" inputmode="decimal" value="${euroIn(p.cost_cents)}"></label></div>
      <div class="cols3"><label class="field"><span>BTW</span><select class="in" name="vat_rate">${[21, 9, 0].map(r => `<option value="${r}" ${p.vat_rate == r ? "selected" : ""}>${r}%</option>`).join("")}</select></label>
        <label class="field"><span>Edition of</span><input class="in" name="run_size" inputmode="numeric" value="${p.run_size}"></label>
        <label class="field"><span>Low at</span><input class="in" name="low_stock_at" inputmode="numeric" value="${p.low_stock_at}"></label></div>
      <div class="sec-h">Photos</div>
      ${id ? `<div class="photos" id="photos">${p.images.map(im => `<figure><img src="${esc(im.path_sm || im.path)}" alt=""><button type="button" data-delimg="${im.id}" aria-label="Remove photo">×</button></figure>`).join("")}
        <label>+ Add photo<input type="file" accept="image/jpeg,image/png,image/webp" id="pPhoto" hidden multiple></label></div>
        <p class="sub" style="margin:-4px 0 12px">Big photos are fine. The server shrinks them and removes location data.</p>` : `<p class="sub">Save the product first, then add photos.</p>`}
      <div class="sec-h">Variants and stock</div><p class="sub" style="margin:-2px 0 8px">Change stock of an existing variant with ⟲ so every change has a reason. Shelf (e.g. A1) is where it lies in the stock room; the packing screen sends you there.</p>
      <div class="pv-h sub"><span>Variant</span><span>Stock</span><span>Shelf</span></div>
      <div id="vars">${vars.map(v => varRow(v)).join("")}</div>
      <button type="button" class="btn sm ghost" data-act="addVar">+ Variant</button>
      <div class="sec-h">On the waitlist</div>
      <div class="card" style="padding:4px 14px">
        ${toggle("on_waitlist", "Show on the waitlist", p.on_waitlist == 1)}
        ${toggle("reveal", "Reveal name and photo", p.reveal == 1, "Off: shown sealed as “Mystery item”, photo blurred")}
        ${toggle("featured", "Featured in the shop", p.featured == 1)}
        ${toggle("active", "Active", p.active == 1, "Off hides it everywhere")}
      </div>
      <label class="field" style="margin-top:12px"><span>Caption under the card</span><input class="in" name="note" value="${esc(p.note || "")}" maxlength="160"></label>
      <div class="cols"><label class="field"><span>Category</span><input class="in" name="category" value="${esc(p.category || "")}"></label>
        <label class="field"><span>Link name</span><input class="in" name="slug" value="${esc(p.slug)}" placeholder="from the name"><small>Keep it once people have voted.</small></label></div>
      <label class="field"><span>Description</span><textarea class="in" name="description" style="font-family:var(--f-b);font-size:14px">${esc(p.description || "")}</textarea></label>
      ${id ? `<div class="row" style="gap:8px;margin-bottom:12px"><button type="button" class="btn sm ghost" data-move="-1">↑ Earlier</button><button type="button" class="btn sm ghost" data-move="1">↓ Later</button></div>` : ""}
      <div class="acts2"><button class="btn wide" type="submit">Save</button>${id ? `<button type="button" class="btn bad" data-act="delProduct" style="grid-column:1/3">Delete product</button>` : ""}</div>
    </form>`);
  const f = $("pForm");
  f.onsubmit = async e => { e.preventDefault(); await busy(f.querySelector("[type=submit]"), async () => {
    const r = await api("/api/admin/products/save", productData(f, p)); PRODUCTS = r.products; drawStock();
    toast("Saved ✓"); if (!id) openProduct(r.id); else closeSheet(); }); };
  f.onclick = async e => {
    const hi = e.target.closest("[data-hist]"); if (hi) return stockSheet(p, p.variants.find(x => x.id == hi.dataset.hist));
    if (e.target.closest("[data-act=addVar]")) { $("vars").insertAdjacentHTML("beforeend", varRow({ label: "", stock: 0 })); $("vars").lastElementChild.querySelector("input").focus(); }
    const rm = e.target.closest("[data-rmvar]"); if (rm) { if ($("vars").children.length > 1) rm.closest(".vrow").remove(); else toastErr("A product needs at least one variant."); }
    const di = e.target.closest("[data-delimg]"); if (di && await ask("It is removed from the shop straight away.", { title: "Remove this photo?", ok: "Remove", danger: true })) await busy(di, async () => { PRODUCTS = (await api(`/api/admin/images/${di.dataset.delimg}/delete`, {})).products; drawStock(); openProduct(id); });
    if (e.target.closest("[data-act=delProduct]") && await ask("Its votes are deleted too. This cannot be undone.", { title: `Delete ${p.name}?`, ok: "Delete product", danger: true })) await busy(e.target, async () => { PRODUCTS = (await api(`/api/admin/products/${id}/delete`, {})).products; drawStock(); closeSheet(); toast("Deleted"); });
    const mv = e.target.closest("[data-move]"); if (mv) await busy(mv, async () => {
      const ids = PRODUCTS.map(x => x.id), i = ids.indexOf(p.id), j = i + +mv.dataset.move; if (j < 0 || j >= ids.length) return;
      [ids[i], ids[j]] = [ids[j], ids[i]]; await api("/api/admin/products/order", { ids }); PRODUCTS = (await api("/api/admin/products")).products; drawStock(); toast("Order changed"); });
  };
  const ph = $("pPhoto");
  if (ph) ph.onchange = async () => {
    for (const file of ph.files) {
      toast("Uploading " + file.name + "…");
      const fd = new FormData(); fd.append("photo", file);
      try { PRODUCTS = (await api(`/api/admin/products/${id}/image`, fd, true)).products; } catch (e) { toastErr(e.message); break; }
    }
    drawStock(); openProduct(id);
  };
}
const varRow = v => `<div class="vrow pv" data-vid="${v.id || ""}"><input class="in" placeholder="e.g. M or Black" value="${esc(v.label)}" aria-label="Variant"><input class="in" inputmode="numeric" value="${v.stock}" aria-label="Stock" ${v.id ? "readonly title=\"Use History to change stock\"" : ""}><input class="in" value="${esc(v.shelf || "")}" placeholder="A1" maxlength="20" aria-label="Shelf" title="Shelf in the stock room" autocapitalize="characters">${v.id ? `<button type="button" data-hist="${v.id}" aria-label="Stock history" title="Stock history">⟲</button>` : `<button type="button" data-rmvar aria-label="Remove variant">×</button>`}</div>`;
function productData(f, p){
  const g = n => f.elements[n];
  return { id: p.id, name: g("name").value, slug: g("slug").value, price: g("price").value, cost: g("cost").value, vat_rate: +g("vat_rate").value,
    run_size: +g("run_size").value || 0, low_stock_at: +g("low_stock_at").value || 0, note: g("note").value, category: g("category").value, description: g("description").value,
    on_waitlist: g("on_waitlist").checked, reveal: g("reveal").checked, featured: g("featured").checked, active: g("active").checked,
    variants: [...f.querySelectorAll(".vrow")].map(r => { const [l, s, sh] = r.querySelectorAll("input"); return { id: +r.dataset.vid || 0, label: l.value, stock: +s.value || 0, shelf: sh.value }; }) };
}

/* ================= WAITLIST: prototypes/waitlist-dashboard.html on real data ================= */
const WL = { range: 7, metric: "visits", shown: 8, q: "", data: null, rows: [], total: 0 };
RENDER.waitlist = async () => {
  const el = $("s-waitlist");
  if (!el.dataset.ready) {
    el.dataset.ready = 1;
    el.innerHTML = `<div class="top2"><div class="seg" role="group" aria-label="Time range"><button data-r="1">Today</button><button data-r="7" aria-pressed="true">7 days</button><button data-r="30">30 days</button><button data-r="0">All</button></div>
      <div class="wl-live"><i></i><span id="wlLive">… on site now</span></div></div>
    <section id="overview"><div class="card hero"><div class="mono">On the waitlist</div><div class="big num" id="total">0</div>
      <span class="delta up num" id="totalDelta">▲ +0</span> <span class="sub" id="totalSub"></span>
      <div class="meter" id="giftMeter"><div class="row"><span>Free gifts claimed</span><span class="num"><b id="gClaimed">0</b> / <span id="gLimit">0</span></span></div>
        <div class="track"><i id="gBar" style="width:0"></i></div><div class="row" style="margin:7px 0 0"><span id="gLeft"></span><span id="gEta"></span></div></div>
      <div class="drop"><span class="mono">Drop goes live in</span><span class="cd num" id="cd">--</span></div></div></section>
    <section><div class="kpis" id="kpis"></div></section>
    <section><div class="card"><div class="head"><h2>Trend</h2><div class="tabs" role="group" aria-label="Metric"><button data-m="visits" aria-pressed="true">Visitors</button><button data-m="signups">Signups</button></div></div>
      <div class="chart-total"><div><b class="num" id="ctTotal">0</b><span id="ctLabel">visitors</span></div><div><b class="num" id="ctAvg">0</b>per day</div><div><b class="num" id="ctPeak">0</b>best day</div></div>
      <div class="chart" id="chart"><svg id="svg" role="img" aria-label="Trend chart"></svg><div class="tip" id="tip"></div></div></div></section>
    <section id="funnel"><div class="card"><div class="head"><h2>Funnel</h2><span class="sub">where people drop off</span></div><div id="fun"></div></div></section>
    <section><div class="card"><div class="head"><h2>Traffic sources</h2><span class="sub">visitors</span></div><div id="src"></div></div></section>
    <section><div class="two"><div class="card mini"><div class="head"><h2>Devices</h2></div><div id="dev"></div></div><div class="card mini"><div class="head"><h2>Countries</h2></div><div id="geo"></div></div></div></section>
    <section id="products"><div class="card"><div class="head"><h2>Product votes</h2><div class="legend"><span><i style="background:var(--blue)"></i>Likes</span><span><i style="background:var(--orange)"></i>Dislikes</span></div></div><div id="wprods"></div></div></section>
    <section id="signups"><div class="card"><div class="head"><h2>Signups</h2><span class="sub num" id="suCount"></span></div>
      <div class="search"><input id="q" type="search" placeholder="Search email or phone" autocomplete="off" aria-label="Search signups"><a class="btn" id="csv" href="/api/admin/waitlist/export.csv" download data-sudo>Export CSV</a></div>
      <div id="list"></div><button class="btn more" id="moreBtn" hidden>Show more</button></div></section>`;
    el.querySelectorAll(".seg button").forEach(b => b.onclick = () => { el.querySelectorAll(".seg button").forEach(x => x.removeAttribute("aria-pressed")); b.setAttribute("aria-pressed", "true"); WL.range = +b.dataset.r; loadWaitlist(); });
    el.querySelectorAll(".tabs button").forEach(b => b.onclick = () => { el.querySelectorAll(".tabs button").forEach(x => x.removeAttribute("aria-pressed")); b.setAttribute("aria-pressed", "true"); WL.metric = b.dataset.m; renderChart(); });
    let qt; $("q").oninput = () => { clearTimeout(qt); qt = setTimeout(() => { WL.q = $("q").value.trim(); WL.rows = []; loadSignups(); }, 250); };
    $("moreBtn").onclick = () => loadSignups(true);
    addEventListener("resize", () => { if (current === "waitlist" && WL.data) renderChart(); });
    setInterval(() => { if (current === "waitlist" && !document.hidden) refreshLive(); }, 30000);
    setInterval(() => { if (current === "waitlist") tickCd(); }, 30000);
  }
  WL.rows = [];
  await Promise.all([loadWaitlist(), loadSignups()]);
};
async function loadWaitlist(){
  try { WL.data = await api("/api/admin/waitlist?range=" + WL.range); } catch (e) { toastErr(e.message); return; }
  renderOverview(); renderChart(); renderProducts(); tickCd();
}
async function refreshLive(){ try { const d = await api("/api/admin/waitlist?range=1"); $("wlLive").textContent = d.live + " on site now"; } catch {} }
function tickCd(){ if (!WL.data) return; const s = Math.max(0, (new Date(WL.data.dropDate) - Date.now()) / 1000), d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
  $("cd").textContent = s ? `${d}d ${String(h).padStart(2, "0")}h ${String(m).padStart(2, "0")}m` : "Live now"; }
function countUp(el, to, suffix = ""){
  const from = parseFloat(el.dataset.v || 0); el.dataset.v = to; const t0 = performance.now();
  (function f(t){ const k = Math.min(1, (t - t0) / 500), e = 1 - Math.pow(1 - k, 3);
    el.textContent = fmt(Math.round(from + (to - from) * e)) + suffix; if (k < 1) requestAnimationFrame(f); })(t0);
}
function renderOverview(){
  const D = WL.data, r = WL.range;
  $("wlLive").textContent = D.live + " on site now";
  countUp($("total"), D.total);
  $("totalDelta").textContent = "▲ +" + fmt(D.inRange);
  $("totalSub").textContent = r === 1 ? "today" : r === 0 ? "since launch" : `in the last ${r} days`;
  const g = D.gift;
  $("giftMeter").hidden = !g.limit;
  if (g.limit) {
    $("gClaimed").textContent = fmt(g.claimed); $("gLimit").textContent = fmt(g.limit); $("gBar").style.width = Math.min(100, g.claimed / g.limit * 100) + "%";
    $("gLeft").textContent = fmt(g.left) + " gifts left";
    $("gEta").textContent = g.left === 0 ? "all claimed" : g.etaDays ? "gone in ~" + g.etaDays + " days" : "";
  }
  const K = D.kpis;
  const ch = (k, isPct) => { const { v, prev } = K[k]; if (prev == null || v == null) return null; if (isPct) return (v - prev) / 100; return prev ? (v - prev) / prev : null; };
  const mmss = s => s == null ? "–" : Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
  const tiles = [
    ["Visitors", fmt(K.visitors.v), "", ch("visitors")],
    ["Unique visitors", fmt(K.unique.v), "", ch("unique")],
    ["Conversion", K.conversion.v.toFixed(1).replace(".", ","), "%", K.conversion.prev == null ? null : (K.conversion.v - K.conversion.prev) / 100, true],
    ["Opened the pack", K.opened.v == null ? "–" : K.opened.v, K.opened.v == null ? "" : "%", ch("opened", true), true],
    ["Avg. time on site", mmss(K.avgTime.v), "", ch("avgTime")],
    ["Signups", fmt(D.inRange), "", false],
  ];
  $("kpis").innerHTML = tiles.map(([l, val, suf, d, pts]) => `<div class="card kpi"><div class="mono">${l}</div><div class="v num">${val}<small>${suf}</small></div>${
    d === false ? "" : d == null || !isFinite(d) ? `<span class="sub">${r === 1 || r === 7 ? "nothing to compare yet" : ""}</span>` : `<span class="delta ${d >= 0 ? "up" : "down"} num">${d >= 0 ? "▲" : "▼"} ${pts ? Math.abs(d * 100).toFixed(1).replace(".", ",") + " pt" : Math.abs(d * 100).toFixed(0) + "%"}</span> <span class="sub">vs. prev.</span>`}</div>`).join("");
  const steps = D.funnel, v = steps[0][1];
  $("fun").innerHTML = v ? steps.map(([l, n], i) => `<div class="fstep"><div class="l"><span>${l}</span><span class="num"><b>${fmt(n)}</b> · ${pct(n, v)}%</span></div>
    <div class="hb" style="width:${Math.max(2, pct(n, v))}%"></div>${i ? `<div class="lost num"><em>−${fmt(Math.max(0, steps[i - 1][1] - n))}</em> lost here (${steps[i - 1][1] ? 100 - pct(n, steps[i - 1][1]) : 0}%)</div>` : ""}</div>`).join("")
    : `<p class="sub" style="margin:0">No visits in this period yet.</p>`;
  const bars = (rows, lab = x => x) => rows.length ? rows.map(([l, f, n]) => `<div class="brow"><span>${esc(lab(l))}</span><div class="hb" style="width:${f / rows[0][1] * 100}%"></div><span class="num" title="${n}">${Math.round(f * 100)}%</span></div>`).join("") : `<p class="sub" style="margin:0">No data yet.</p>`;
  $("src").innerHTML = bars(D.sources);
  $("dev").innerHTML = bars(D.devices);
  $("geo").innerHTML = bars(D.countries.slice(0, 6), c => flag(c) + " " + (COUNTRY[c] || c));
}
function renderProducts(){
  const P = WL.data.products;
  $("wprods").innerHTML = P.map((p, i) => { const t = p.likes + p.dislikes, lp = pct(p.likes, t);
    return `<div class="prod"><div class="thumb">${String(i + 1).padStart(2, "0")}</div><div>
      <div class="n"><span>${esc(p.name)} ${p.qty ? `<span class="sub" style="font-weight:500">· ${p.qty} pcs</span>` : ""}</span>${p.best ? `<span class="badge">★ Most wanted</span>` : ""}</div>
      <div class="split" role="img" aria-label="${p.likes} likes, ${p.dislikes} dislikes">${t ? `<i style="width:${lp}%"></i><i style="width:${100 - lp}%"></i>` : `<i style="width:100%;background:var(--card2);border-radius:4px"></i>`}</div>
      <div class="pv num"><span>♥ ${fmt(p.likes)} like it · ${lp}%</span><span>${fmt(p.dislikes)} not for me</span></div></div></div>`; }).join("") || `<p class="sub" style="margin:0">No products on the waitlist. Add them in Stock.</p>`;
}
function renderChart(){
  const data = WL.data.days.map(d => ({ d: new Date(d.d + "T12:00:00"), visits: d.visits, signups: d.signups })), metric = WL.metric;
  const vals = data.map(d => d[metric]), max = Math.max(...vals, 1), niceMax = Math.ceil(max / (max > 200 ? 100 : max > 20 ? 10 : 5)) * (max > 200 ? 100 : max > 20 ? 10 : 5);
  const svg = $("svg"), W = svg.clientWidth || 340, H = 180, padL = 30, padB = 22, padT = 8, iw = W - padL - 4, ih = H - padB - padT;
  const x = i => padL + (data.length === 1 ? iw / 2 : i / (data.length - 1) * iw), y = v => padT + ih - v / niceMax * ih;
  const pts = vals.map((v, i) => [x(i), y(v)]);
  const line = pts.map((p, i) => (i ? "L" : "M") + p[0].toFixed(1) + " " + p[1].toFixed(1)).join("");
  const ticks = [0, .5, 1].map(f => Math.round(niceMax * f));
  const lbl = data.length <= 7 ? data.map((_, i) => i) : [0, Math.floor((data.length - 1) / 2), data.length - 1];
  svg.setAttribute("viewBox", `0 0 ${W} ${H}`);
  svg.innerHTML = `<defs><linearGradient id="ar" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4A93F0" stop-opacity=".28"/><stop offset="1" stop-color="#4A93F0" stop-opacity="0"/></linearGradient></defs>
    <g class="grid">${ticks.map(t => `<line x1="${padL}" x2="${W - 4}" y1="${y(t)}" y2="${y(t)}"/>`).join("")}</g>
    <g class="ax">${ticks.map(t => `<text x="${padL - 6}" y="${y(t) + 3}" text-anchor="end">${t >= 1000 ? (t / 1000) + "k" : t}</text>`).join("")}
      ${lbl.map(i => `<text x="${x(i)}" y="${H - 4}" text-anchor="${i === 0 && data.length > 7 ? "start" : i === data.length - 1 && data.length > 7 ? "end" : "middle"}">${data[i].d.toLocaleDateString("en-GB", data.length <= 7 ? { weekday: "short" } : { day: "numeric", month: "short" })}</text>`).join("")}</g>
    <path d="${line}L${x(data.length - 1)} ${y(0)}L${x(0)} ${y(0)}Z" fill="url(#ar)"/>
    <path d="${line}" fill="none" stroke="#4A93F0" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
    <line id="cross" y1="${padT}" y2="${y(0)}" stroke="rgba(243,241,236,.35)" stroke-dasharray="3 3" opacity="0"/>
    <circle id="dot" r="5" fill="#4A93F0" stroke="#15151B" stroke-width="2" opacity="0"/>`;
  const total = vals.reduce((a, b) => a + b, 0);
  $("ctTotal").textContent = fmt(total); $("ctLabel").textContent = metric === "visits" ? "visitors" : "signups";
  $("ctAvg").textContent = fmt(Math.round(total / data.length)); $("ctPeak").textContent = fmt(Math.max(...vals));
  const tip = $("tip"), cross = svg.querySelector("#cross"), dot = svg.querySelector("#dot"), box = $("chart");
  const show = e => { const r = svg.getBoundingClientRect(), px = (e.clientX - r.left) / r.width * W;
    const i = Math.max(0, Math.min(data.length - 1, Math.round((px - padL) / iw * (data.length - 1))));
    cross.setAttribute("x1", pts[i][0]); cross.setAttribute("x2", pts[i][0]); cross.setAttribute("opacity", 1);
    dot.setAttribute("cx", pts[i][0]); dot.setAttribute("cy", pts[i][1]); dot.setAttribute("opacity", 1);
    tip.innerHTML = `<span class="sub">${data[i].d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" })}</span><b class="num">${fmt(vals[i])} ${metric === "visits" ? "visitors" : "signups"}</b>`;
    const lx = Math.max(60, Math.min(r.width - 60, pts[i][0] / W * r.width)); tip.style.left = lx + "px"; tip.style.top = "-6px"; tip.style.opacity = 1; };
  const hide = () => { tip.style.opacity = 0; cross.setAttribute("opacity", 0); dot.setAttribute("opacity", 0); };
  box.onpointermove = box.onpointerdown = show; box.onpointerleave = hide; box.onpointerup = e => { if (e.pointerType !== "mouse") setTimeout(hide, 1600); };
}
const mask = e => { const [u, d] = String(e).split("@"); return u.slice(0, 2) + "•••" + u.slice(-1) + "@" + d; };
async function loadSignups(more){
  try {
    const r = await api(`/api/admin/waitlist/signups?q=${encodeURIComponent(WL.q)}&offset=${more ? WL.rows.length : 0}&limit=${more ? 20 : 8}`);
    WL.rows = more ? WL.rows.concat(r.rows) : r.rows; WL.total = r.total;
    $("suCount").textContent = fmt(r.all) + " total";
  } catch (e) { toastErr(e.message); return; }
  $("list").innerHTML = WL.rows.map(s => `<details class="su"><summary>
      <div class="av">${esc(s.email[0].toUpperCase())}</div>
      <div style="min-width:0"><div class="e">${esc(mask(s.email))}</div><div class="m">${flag(s.country)} ${esc((s.phone_pretty || s.phone).slice(0, 7))} ••• · ${esc(s.source || "Direct")}</div></div>
      <div class="t">${esc(s.ago)}</div></summary>
      <dl><dt>Email</dt><dd>${esc(s.email)}</dd><dt>Phone</dt><dd><a href="tel:${esc(s.phone)}" style="color:inherit">${esc(s.phone_pretty || s.phone)}</a></dd><dt>Position</dt><dd>#${s.position}</dd>
      <dt>Gift code</dt><dd>${esc(s.gift_code || "— (gifts were gone)")}</dd><dt>Source</dt><dd>${esc(s.source || "Direct")}</dd><dt>Device</dt><dd>${esc(s.device || "")}</dd>
      <dt>Launch messages</dt><dd>${s.unsubscribed_at ? "✕ unsubscribed" : s.consent == 1 ? "✓ agreed " + esc(new Date(s.created_at).toLocaleDateString("en-GB")) : "✕ no"}</dd>
      ${s.likes.length ? `<dt>Liked</dt><dd>${esc(s.likes.join(", "))}</dd>` : ""}</dl></details>`).join("")
    || `<p class="sub" style="padding:12px 0">${WL.q ? `No one matches “${esc(WL.q)}”.` : "No signups yet."}</p>`;
  $("moreBtn").hidden = WL.rows.length >= WL.total;
}

/* ================= SETTINGS ================= */
let S = null;
RENDER.settings = async sub => {
  const el = $("s-settings"), keep = keepDirty(el);
  el.innerHTML = `<p class="sub" style="margin:18px 2px">Loading…</p>`;
  try { S = await api("/api/admin/settings"); } catch (e) { el.innerHTML = `<p class="sub">${esc(e.message)}</p>`; return; }
  const c = S.company, g = S.gift, m = S.smtp, im = S.imap, tg = S.telegram, ist = S.imap_state || {};
  const warn = { store: !c.legal_name || !c.kvk || !c.btw || !c.depot, email: !m.host, legal: !S.legal_reviewed };
  el.innerHTML = `<div class="set-wrap"><nav class="set-nav" aria-label="Settings sections">${SET_SECTIONS.map(([k, l, h]) => `<button type="button" data-sec="${k}"><span><b>${l}</b><small>${h}</small></span>${warn[k] ? '<i class="dot" style="--c:var(--warn)" title="Needs attention"></i>' : ""}<em>unsaved</em></button>`).join("")}</nav><div class="set-body">
  <section class="card" id="set-store"><div class="head"><h2>Store</h2></div><form data-group="store" data-track>
    <div class="cols"><label class="field"><span>Store name</span><input class="in" name="store_name" value="${esc(S.store_name)}" required></label>
      <label class="field"><span>Logo text</span><input class="in" name="logo_text" value="${esc(S.logo_text)}"><small>The part after “/” is printed in foil.</small></label></div>
    <label class="field"><span>Website address</span><input class="in" name="site_url" value="${esc(S.site_url)}" placeholder="https://yourdomain.nl" inputmode="url"><small>Used in every link in emails. Change it when you move to another domain.</small></label>
    <label class="field"><span>Company legal name</span><input class="in" name="company.legal_name" value="${esc(c.legal_name)}"></label>
    <div class="cols"><label class="field"><span>KvK number</span><input class="in" name="company.kvk" value="${esc(c.kvk)}"></label>
      <label class="field"><span>BTW-id</span><input class="in" name="company.btw" value="${esc(c.btw)}" placeholder="NL…B01"></label></div>
    <label class="field"><span>Business address</span><input class="in" name="company.address" value="${esc(c.address)}"></label>
    <label class="field"><span>Depot address (routes start here)</span><input class="in" name="company.depot" value="${esc(c.depot)}"></label>
    <div class="cols"><label class="field"><span>Contact email</span><input class="in" name="company.email" type="email" value="${esc(c.email)}"></label>
      <label class="field"><span>WhatsApp number</span><input class="in" name="company.whatsapp" value="${esc(c.whatsapp)}" placeholder="+31 6…"></label></div>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save store</button></div></form></section>

  <section class="card" id="set-waitlist"><div class="head"><h2>Waitlist and launch</h2></div><form data-group="waitlist" data-track>
    <label class="field"><span>Drop date and time (Amsterdam)</span><input class="in" type="datetime-local" name="drop_date" value="${esc(S.drop_date)}" required></label>
    <label class="field"><span>Gift title</span><input class="in" name="gift.title" value="${esc(g.title)}"></label>
    <label class="field"><span>Gift text</span><input class="in" name="gift.text" value="${esc(g.text)}"></label>
    <div class="cols3"><label class="field"><span>Gift limit</span><input class="in" name="gift.limit" inputmode="numeric" value="${g.limit == null ? "" : g.limit}" placeholder="no limit"></label>
      <label class="field"><span>Code prefix</span><input class="in" name="gift.prefix" value="${esc(g.prefix)}" maxlength="6"></label>
      <label class="field"><span>“Most wanted” at</span><input class="in" name="most_wanted_min" inputmode="numeric" value="${S.most_wanted_min}"></label></div>
    <label class="field"><span>Show “X already in line” from</span><input class="in" name="proof_min" inputmode="numeric" value="${S.proof_min}"><small>Below this number the count stays hidden. It is always the real count.</small></label>
    <label class="field"><span>Marquee texts (one per line)</span><textarea class="in" name="marquee" style="min-height:90px">${esc(S.marquee.join("\n"))}</textarea></label>
    <label class="field"><span>Phone country codes (one per line, e.g. NL +31)</span><textarea class="in" name="countries" style="min-height:90px">${esc(S.countries.map(x => x.join(" ")).join("\n"))}</textarea></label>
    <div class="sec-h">Store mode</div>
    <div class="card" style="padding:4px 14px;background:var(--card2)">
      <label class="tog"><span>Waitlist mode<small>The homepage is the waitlist</small></span><input type="radio" name="store_mode" value="waitlist" ${S.store_mode !== "shop" ? "checked" : ""}><i></i></label>
      <label class="tog"><span>Shop mode<small>The homepage is the shop. The waitlist stays at /waitlist.</small></span><input type="radio" name="store_mode" value="shop" ${S.store_mode === "shop" ? "checked" : ""}><i></i></label>
      ${toggle("redirect_waitlist", "After launch, send /waitlist to the shop", S.waitlist_after_launch === "redirect")}
    </div>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save waitlist</button></div></form></section>

  <section class="card" id="set-email"><div class="head"><h2>Email</h2><span class="sub">${m.host ? "set up" : "not set up"}</span></div><form data-group="email" data-track>
    <p class="sub" style="margin:0 0 12px">GoDaddy email: server smtpout.secureserver.net, port 465 (SSL) or 587 (TLS), login = your full email address.</p>
    <div class="cols"><label class="field"><span>SMTP server</span><input class="in" name="smtp.host" value="${esc(m.host)}"></label>
      <label class="field"><span>Port</span><input class="in" name="smtp.port" inputmode="numeric" value="${m.port}"></label></div>
    <label class="field"><span>Security</span><select class="in" name="smtp.secure">${[["tls", "TLS (port 587)"], ["ssl", "SSL (port 465)"], ["none", "None"]].map(([v, l]) => `<option value="${v}" ${m.secure === v ? "selected" : ""}>${l}</option>`).join("")}</select></label>
    <label class="field"><span>Login</span><input class="in" name="smtp.user" value="${esc(m.user)}" autocomplete="off"></label>
    <label class="field"><span>Password</span><input class="in" name="password" type="password" autocomplete="new-password" placeholder="${S.secrets.smtp_pass ? "•••••••• saved, leave empty to keep" : ""}"></label>
    <div class="cols"><label class="field"><span>From address</span><input class="in" name="smtp.from" type="email" value="${esc(m.from)}"></label>
      <label class="field"><span>From name</span><input class="in" name="smtp.from_name" value="${esc(m.from_name)}" placeholder="${esc(S.store_name)}"></label></div>
    <div class="sec-h">Incoming mail · customer emails in the Inbox</div>
    <p class="sub" style="margin:-2px 0 10px">The Inbox reads new emails in this mailbox (it does not mark them as read or delete them). Replies from the Inbox are sent from the address above. GoDaddy email: imap.secureserver.net · cPanel email: mail.yourdomain.com · port 993, SSL.</p>
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${toggle("imap.enabled", "Show customer emails in the Inbox", im.enabled, ist.error ? `<span style="color:var(--bad)">${esc(ist.error)}</span>` : ist.checked_at ? "Last checked " + esc(ago(ist.checked_at)) : "")}</div>
    <div class="cols"><label class="field"><span>IMAP server</span><input class="in" name="imap.host" value="${esc(im.host)}" placeholder="imap.secureserver.net"></label>
      <label class="field"><span>Port</span><input class="in" name="imap.port" inputmode="numeric" value="${im.port}"></label></div>
    <label class="field"><span>Security</span><select class="in" name="imap.secure">${[["ssl", "SSL (port 993)"], ["tls", "STARTTLS (port 143)"], ["none", "None"]].map(([v, l]) => `<option value="${v}" ${im.secure === v ? "selected" : ""}>${l}</option>`).join("")}</select></label>
    <div class="cols"><label class="field"><span>Login</span><input class="in" name="imap.user" value="${esc(im.user)}" placeholder="same as SMTP login" autocomplete="off"></label>
      <label class="field"><span>Password</span><input class="in" name="imap_password" type="password" autocomplete="new-password" placeholder="${S.secrets.imap_pass ? "•••••••• saved" : "same as SMTP password"}"></label></div>
    <label class="field"><span>Which emails</span><select class="in" name="imap.who"><option value="all" ${im.who !== "customers" ? "selected" : ""}>Everyone who writes (newsletters and automatic mail are skipped)</option><option value="customers" ${im.who === "customers" ? "selected" : ""}>Only customers, waitlist members and people you wrote to</option></select></label>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save email</button></div></form>
    <div class="search" style="margin-top:12px"><input id="testTo" type="email" placeholder="Send a test to…" value="${esc(ME.email)}" aria-label="Test email address"><button class="btn ghost" data-act="testMail">Send test</button></div>
    <button class="btn ghost block" style="margin-top:10px" data-act="imapCheck">Check incoming mail now</button></section>

  <section class="card" id="set-telegram"><div class="head"><h2>Telegram</h2><span class="sub">${S.secrets.telegram ? "@" + esc(tg.bot) : "not connected"}</span></div><form id="tgForm" data-track>
    ${S.secrets.telegram ? `<p class="note" style="background:rgba(42,171,238,.08);border-color:rgba(42,171,238,.3)">${ICON.tg} Connected to <a href="https://t.me/${esc(tg.bot)}" target="_blank" rel="noopener" style="color:#A8DCFF">@${esc(tg.bot)}</a>${tg.name ? " (" + esc(tg.name) + ")" : ""}. Customer messages to the bot appear in the Inbox. ${tg.mode === "poll" ? "Your site has no HTTPS yet, so new messages are picked up every few minutes." : "New messages arrive instantly."}<br><span id="tgStatus" class="sub"></span></p>`
      : `<p class="sub" style="margin:0 0 10px">Customers write to your bot and you answer here, in the Inbox. Their order page gets a “Chat on Telegram” button that links the chat to their orders.</p>
    <ol class="sub" style="margin:0 0 12px;padding-left:18px;line-height:1.7"><li>Open Telegram, search <b>@BotFather</b>, tap Start.</li><li>Send <b>/newbot</b>, type a name (e.g. ${esc(S.store_name)}), then a username that ends in <b>bot</b>.</li><li>BotFather replies with a token like <b>123456789:AAH…</b>. Copy it and paste it below.</li></ol>`}
    <label class="field"><span>${S.secrets.telegram ? "New bot token (only to switch bots)" : "Bot token"}</span><input class="in" name="token" autocomplete="off" spellcheck="false" placeholder="123456789:AAH…"></label>
    <label class="field"><span>Welcome message (sent when someone starts the chat)</span><textarea class="in" name="welcome" style="font-family:var(--f-b);font-size:14px;min-height:70px">${esc(tg.welcome)}</textarea><small>{store} becomes your store name. Empty = no welcome message.</small></label>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">${S.secrets.telegram ? "Save Telegram" : "Connect bot"}</button></div></form>
    ${S.secrets.telegram ? `<div class="acts2" style="margin-top:10px"><button class="btn ghost" data-act="tgAgain">Connect again</button><button class="btn bad" data-act="tgOff">Disconnect</button></div>` : ""}</section>

  <section class="card" id="set-payments"><div class="head"><h2>Payments</h2><span class="sub">Tikkie</span></div><form id="payForm" data-track>
    <p class="sub" style="margin:0 0 12px">Customers order and you send them a Tikkie on WhatsApp from the order sheet. Cash and bank transfers: tap “Mark as paid”. Online card/iDEAL checkout is switched off.</p>
    <label class="field"><span>What customers read after ordering</span><textarea class="in" name="tikkie_text" style="font-family:var(--f-b);font-size:14px;min-height:70px">${esc(S.tikkie_text)}</textarea></label>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save payments</button></div></form></section>

  <section class="card" id="set-shop"><div class="head"><h2>Shop</h2></div><form id="shopForm" data-track>
    <div class="cols"><label class="field"><span>Free delivery from (€)</span><input class="in" name="free_from" inputmode="decimal" value="${euroIn(S.shop.free_from_cents)}"></label>
      <label class="field"><span>First order number</span><input class="in" name="order_start" inputmode="numeric" value="${S.shop.order_start}"></label></div>
    <label class="field"><span>Drop closes at (optional countdown)</span><input class="in" type="datetime-local" name="ends_at" value="${esc(S.shop.ends_at)}"><small>Empty hides the timer in the shop.</small></label>
    <div class="cols3"><label class="field"><span>Hold stock (min)</span><input class="in" name="reservation_min" inputmode="numeric" value="${S.shop.reservation_min}"></label>
      <label class="field"><span>Cancel unpaid (min)</span><input class="in" name="unpaid_timeout_min" inputmode="numeric" value="${S.shop.unpaid_timeout_min}"></label>
      <label class="field"><span>Tikkie wait (h)</span><input class="in" name="tikkie_timeout_hours" inputmode="numeric" value="${S.shop.tikkie_timeout_hours}"></label></div>
    <label class="field"><span>Most of one product per order</span><input class="in" name="max_per_item" inputmode="numeric" value="${S.shop.max_per_item}"><small>All sizes together. Stops one person from holding a whole drop with an unpaid order. 0 = no limit.</small></label>
    <label class="field"><span>Top bar texts (one per line)</span><textarea class="in" name="announce" style="min-height:80px">${esc(S.shop.announce.join("\n"))}</textarea></label>
    <div class="cols"><label class="field"><span>Referral: friend gets (€)</span><input class="in" name="ref_friend" inputmode="decimal" value="${euroIn(S.referral.friend_cents)}"></label>
      <label class="field"><span>Referrer gets (€)</span><input class="in" name="ref_referrer" inputmode="decimal" value="${euroIn(S.referral.referrer_cents)}"></label></div>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save shop</button></div></form></section>

  <section class="card" id="set-delivery"><div class="head"><h2>Delivery</h2></div><form id="delForm" data-track>
    <div class="sec-h" style="margin-top:0">Windows</div><div class="vhead" style="grid-template-columns:1fr 1fr 70px 36px"><span>Start</span><span>End</span><span>Orders</span><span></span></div><div id="wins">${S.delivery.windows.map(winRow).join("")}</div>
    <button type="button" class="btn sm ghost" data-addwin>+ Window</button>
    <div class="sec-h">Delivery days</div><div class="chips" style="margin:0;padding:0;flex-wrap:wrap">${["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d, i) => `<label class="chip" style="cursor:pointer"><input type="checkbox" name="day" value="${i + 1}" ${S.delivery.days.includes(i + 1) ? "checked" : ""} style="margin-right:6px">${d}</label>`).join("")}</div>
    <div class="cols" style="margin-top:12px"><label class="field"><span>Window closes (min before start)</span><input class="in" name="cutoff_min" inputmode="numeric" value="${S.delivery.cutoff_min}"><small>120 → the 18:00 window closes at 16:00.</small></label>
      <label class="field"><span>Days ahead</span><input class="in" name="days_ahead" inputmode="numeric" value="${S.delivery.days_ahead}"></label></div>
    <div class="sec-h">Routes</div>
    <div class="cols"><label class="field"><span>Average speed (km/h)</span><input class="in" name="speed_kmh" inputmode="numeric" value="${S.delivery.speed_kmh}"><small>For ETAs. 25 by car in town, 15 by bike.</small></label>
      <label class="field"><span>Minutes per stop</span><input class="in" name="stop_min" inputmode="numeric" value="${S.delivery.stop_min}"></label></div>
    <label class="field"><span>Google Maps directions by</span><select class="in" name="travel">${[["driving", "Car"], ["bicycling", "Bike"], ["walking", "Walking"]].map(([v, l]) => `<option value="${v}" ${S.delivery.travel === v ? "selected" : ""}>${l}</option>`).join("")}</select><small>Routes start at the depot address in Store details.</small></label>
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${toggle("self_reschedule", "Customers can change their window", S.delivery.self_reschedule !== false, "On their order page, until the window’s cut-off")}</div>
    <div class="sec-h">Email the customer when</div>
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${[["out_for_delivery", "The route starts", "With their ETA window"], ["on_my_way", "“On my way” is tapped", "About 10 minutes before"], ["delivered", "It is delivered", ""], ["failed_delivery", "Nobody was home", "Asks when to come back"], ["rescheduled", "It gets a new window", ""]].map(([k, l, h]) => toggle("notify_" + k, l, S.delivery.notify[k] !== false, h)).join("")}</div>
    <label class="field"><span>No delivery on (dates, one per line)</span><textarea class="in" name="blackout" style="min-height:60px" placeholder="2026-12-25">${esc(S.delivery.blackout.join("\n"))}</textarea></label>
    <div class="sec-h">Zones · 4-digit postcodes</div><p class="sub" style="margin:-2px 0 8px">The narrowest matching range wins, so a catch-all like 9700–9759 can sit below the districts. Free-from empty = the shop setting.</p>
    <div class="vhead" style="grid-template-columns:1.6fr .9fr .9fr .9fr .9fr 30px;gap:6px"><span>Name</span><span>From</span><span>To</span><span>Fee €</span><span>Free from €</span><span></span></div><div id="zones">${S.zones.filter(z => +z.active).map(zoneRow).join("")}</div>
    <button type="button" class="btn sm ghost" data-addzone>+ Zone</button>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save delivery</button></div></form></section>

  <section class="card" id="set-legal"><div class="head"><h2>Legal pages</h2><span class="sub">${S.legal_reviewed ? "reviewed" : "drafts"}</span></div><form data-group="legal" data-track>
    <p class="note">These are drafts. Have them checked by a lawyer or bookkeeper. <b>[[MARKERS]]</b> fill in automatically from your Store details.</p>
    ${[["privacy", "Privacy"], ["terms", "Terms"], ["returns", "Returns"], ["contact", "Contact"]].map(([k, l]) => `<label class="field"><span>${l} · <a href="/pages/${k}" target="_blank" rel="noopener" style="color:var(--ink3)">view</a></span><textarea class="in" name="legal.${k}">${esc(S["legal." + k])}</textarea></label>`).join("")}
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${toggle("legal_reviewed", "The texts have been checked", S.legal_reviewed)}</div>
    <div class="set-save"><span class="set-st">Unsaved changes</span><button class="btn block" type="submit">Save legal pages</button></div></form></section>

  <section class="card" id="set-account"><div class="head"><h2>Your account</h2><span class="sub">${esc(ME.email)}</span></div>
    <form id="pwForm"><div class="cols"><label class="field"><span>Current password</span><input class="in" type="password" name="current" autocomplete="current-password"></label>
      <label class="field"><span>New password</span><input class="in" type="password" name="new" autocomplete="new-password" minlength="10"></label></div>
      <button class="btn ghost block" type="submit">Change password</button></form>
    <div class="sec-h">Two-step login</div>
    <p class="sub" style="margin:0 0 10px">${ME.twofa ? "✓ On. You enter a code from your authenticator app at login." : "Off. Turn it on to also ask for a 6-digit code from an app like Google Authenticator."}</p>
    <button class="btn ghost block" data-act="${ME.twofa ? "2faOff" : "2faOn"}">${ME.twofa ? "Turn off two-step login" : "Turn on two-step login"}</button></section></div></div>`;
  el.querySelectorAll("form[data-group]").forEach(f => f.onsubmit = saveSettings);
  const post = async (f, group, values) => busy(f.querySelector("[type=submit]"), async () => { await api("/api/admin/settings", { group, values }); delete f.dataset.dirty; toast("Saved ✓"); RENDER.settings(group); });
  $("tgForm").onsubmit = e => { e.preventDefault(); post(e.target, "telegram", { token: e.target.token.value, telegram: { welcome: e.target.welcome.value } }); };
  if (S.secrets.telegram) api("/api/admin/telegram/status").then(t => { const x = $("tgStatus"); if (x) x.innerHTML = t.error ? `<span style="color:var(--bad)">Telegram reports: ${esc(t.error)}</span>` : t.pending ? `${t.pending} message${t.pending > 1 ? "s" : ""} waiting to be delivered` : "✓ Working"; }).catch(() => {});
  $("payForm").onsubmit = e => { e.preventDefault(); post(e.target, "payments", { tikkie_text: e.target.tikkie_text.value }); };
  $("shopForm").onsubmit = e => { e.preventDefault(); const f = e.target;
    post(f, "shop", { shop: { free_from: f.free_from.value, order_start: f.order_start.value, ends_at: f.ends_at.value, reservation_min: f.reservation_min.value, unpaid_timeout_min: f.unpaid_timeout_min.value,
      tikkie_timeout_hours: f.tikkie_timeout_hours.value, max_per_item: f.max_per_item.value, announce: f.announce.value.split("\n").map(x => x.trim()).filter(Boolean) }, referral: { friend: f.ref_friend.value, referrer: f.ref_referrer.value } }); };
  $("delForm").onclick = e => {
    if (e.target.closest("[data-addwin]")) $("wins").insertAdjacentHTML("beforeend", winRow({ start: "", end: "", capacity: 10 }));
    if (e.target.closest("[data-addzone]")) $("zones").insertAdjacentHTML("beforeend", zoneRow({ id: 0, name: "", postcode_from: "", postcode_to: "", fee_cents: 395, free_from_cents: null }));
    const rm = e.target.closest("[data-rm]"); if (rm) rm.closest(".vrow").remove();
  };
  $("delForm").onsubmit = e => { e.preventDefault(); const f = e.target;
    post(f, "delivery", { delivery: { windows: [...f.querySelectorAll("#wins .vrow")].map(r => { const [a, b, c] = r.querySelectorAll("input"); return { start: a.value, end: b.value, capacity: +c.value }; }),
      days: [...f.querySelectorAll("[name=day]:checked")].map(x => +x.value), cutoff_min: f.cutoff_min.value, days_ahead: f.days_ahead.value, speed_kmh: f.speed_kmh.value, stop_min: f.stop_min.value, travel: f.travel.value, self_reschedule: f.self_reschedule.checked,
      notify: Object.fromEntries(["out_for_delivery", "on_my_way", "delivered", "failed_delivery", "rescheduled"].map(k => [k, f["notify_" + k].checked])), blackout: f.blackout.value.split("\n").map(x => x.trim()).filter(Boolean) },
      zones: [...f.querySelectorAll("#zones .vrow")].map(r => { const i = r.querySelectorAll("input"); return { id: +r.dataset.zid || 0, name: i[0].value, from: i[1].value, to: i[2].value, fee: i[3].value, free: i[4].value }; }) }); };
  $("pwForm").onsubmit = async e => { e.preventDefault(); const f = e.target; await busy(f.querySelector("button"), async () => { await api("/api/admin/password", { current: f.current.value, new: f.new.value }); f.reset(); toast("Password changed ✓"); }); };
  el.querySelectorAll("form[data-track]").forEach(f => {
    const mark = () => { f.dataset.dirty = "1"; markDirty(); };
    f.addEventListener("input", mark); f.addEventListener("change", mark);
    f.addEventListener("click", e => { if (e.target.closest("[data-addwin],[data-addzone],[data-rm]")) mark(); });
  });
  restoreDirty(el, keep);
  el.querySelector(".set-nav").onclick = e => { const b = e.target.closest("[data-sec]"); if (b) showSection(b.dataset.sec, true); };
  const start = SET_SECTIONS.some(([k]) => k === sub) ? sub : SET.cur;
  showSection(start, false);
  if (sub && !wide()) setTimeout(() => { const t = $("set-" + sub); if (t) t.scrollIntoView({ block: "start" }); }, 60);
};
/* Settings sections: on a computer one section at a time with a menu on the left; on a phone the menu is a row of chips that jump down the page. */
const SET_SECTIONS = [["store", "Store", "Name, company, depot"], ["waitlist", "Waitlist", "Drop date, gift, store mode"], ["email", "Email", "Sending, receiving, test"], ["telegram", "Telegram", "Bot for customer chats"], ["payments", "Payments", "Tikkie text"],
  ["shop", "Shop", "Free delivery, timers, referrals"], ["delivery", "Delivery", "Windows, days, zones"], ["legal", "Legal pages", "Privacy, terms, returns"], ["account", "Your account", "Password, two-step login"]];
const SET = { cur: "store" };
const wide = () => matchMedia("(min-width:1024px)").matches;
function showSection(k, user){
  SET.cur = k;
  const el = $("s-settings");
  el.querySelectorAll(".set-body > section").forEach(x => x.classList.toggle("cur", x.id === "set-" + k));
  el.querySelectorAll("[data-sec]").forEach(b => b.setAttribute("aria-current", b.dataset.sec === k ? "true" : "false"));
  if (!user) return;
  try { history.replaceState(null, "", "/admin/settings#" + k); } catch {}
  if (wide()) scrollTo(0, 0); else $("set-" + k).scrollIntoView({ block: "start", behavior: "smooth" });
}
const settingsDirty = () => !!document.querySelector("#s-settings form[data-dirty]");
function markDirty(){ document.querySelectorAll("#s-settings [data-sec]").forEach(b => b.classList.toggle("dirty", !!document.querySelector(`#set-${b.dataset.sec} form[data-dirty]`))); }
// Saving one section redraws the screen; unsaved edits in the other sections are carried over.
function keepDirty(el){ return [...el.querySelectorAll("form[data-dirty]")].map(f => [f.closest("section").id, [...f.elements].map(x => x.type === "checkbox" || x.type === "radio" ? x.checked : x.value)]); }
function restoreDirty(el, keep){
  keep.forEach(([id, vals]) => { const f = el.querySelector(`#${id} form[data-track]`); if (!f || f.elements.length !== vals.length) return;
    [...f.elements].forEach((x, i) => { if (x.type === "checkbox" || x.type === "radio") x.checked = vals[i]; else if (x.type !== "file") x.value = vals[i]; }); f.dataset.dirty = "1"; });
  markDirty();
}
addEventListener("beforeunload", e => { if (settingsDirty() || txDirty()) { e.preventDefault(); e.returnValue = ""; } });
// Ctrl/⌘+S saves the section you are in.
document.addEventListener("keydown", e => {
  if (!["settings", "texts"].includes(current) || !(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== "s") return;
  e.preventDefault();
  if (current === "texts") { txSave(); return; }
  const f = (document.activeElement && document.activeElement.closest("form[data-track]")) || (wide() && document.querySelector(`#set-${SET.cur} form[data-track]`));
  if (f) f.requestSubmit();
});
const winRow = w => `<div class="vrow" style="grid-template-columns:1fr 1fr 70px 36px"><input class="in" type="time" value="${esc(w.start)}" aria-label="Start"><input class="in" type="time" value="${esc(w.end)}" aria-label="End"><input class="in" inputmode="numeric" value="${w.capacity}" aria-label="Capacity" title="Orders per window"><button type="button" data-rm aria-label="Remove">×</button></div>`;
const zoneRow = z => `<div class="vrow" data-zid="${z.id || ""}" style="grid-template-columns:1.6fr .9fr .9fr .9fr .9fr 30px;gap:6px"><input class="in" value="${esc(z.name)}" placeholder="Name" aria-label="Zone name"><input class="in" inputmode="numeric" value="${z.postcode_from}" placeholder="from" aria-label="From"><input class="in" inputmode="numeric" value="${z.postcode_to}" placeholder="to" aria-label="To"><input class="in" inputmode="decimal" value="${euroIn(+z.fee_cents)}" aria-label="Fee €" title="Fee €"><input class="in" inputmode="decimal" value="${z.free_from_cents == null ? "" : euroIn(+z.free_from_cents)}" placeholder="free" aria-label="Free from €" title="Free from €"><button type="button" data-rm aria-label="Remove">×</button></div>`;
async function saveSettings(e){
  e.preventDefault();
  const f = e.target, group = f.dataset.group, values = {};
  for (const el of f.elements) {
    if (!el.name) continue;
    let v = el.type === "checkbox" ? el.checked : el.value;
    if (el.type === "radio") { if (!el.checked) continue; }
    const [a, b] = el.name.split(".");
    if (b && a !== "legal") { (values[a] = values[a] || {})[b] = v; } else values[el.name] = v;
  }
  if (group === "waitlist") {
    values.marquee = values.marquee.split("\n").map(s => s.trim()).filter(Boolean);
    values.countries = values.countries.split("\n").map(s => s.trim().split(/\s+/)).filter(x => x.length === 2);
    values.waitlist_after_launch = values.redirect_waitlist ? "redirect" : "keep";
    if (values.store_mode !== S.store_mode && !await ask(values.store_mode === "shop" ? "Visitors will no longer land on the waitlist." : "Visitors land on the waitlist again instead of the shop.", { title: values.store_mode === "shop" ? "Switch the homepage to the shop?" : "Switch the homepage back to the waitlist?", ok: values.store_mode === "shop" ? "Open the shop" : "Back to waitlist" })) return;
  }
  await busy(f.querySelector("[type=submit]"), async () => { await api("/api/admin/settings", { group, values }); delete f.dataset.dirty; markDirty(); toast("Saved ✓"); if (group === "email" || group === "store") RENDER.settings(group === "store" ? "store" : "email"); });
}
document.addEventListener("click", async e => {
  const b = e.target.closest("[data-act]"); if (!b) return;
  const act = b.dataset.act;
  if (act === "testMail") await busy(b, async () => { await api("/api/admin/email/test", { to: $("testTo").value }); toast("Test email sent ✓ Check your inbox."); });
  if (act === "imapCheck") await busy(b, async () => { const r = await api("/api/admin/inbox/check", {}); toast(r.message); });
  if (act === "tgAgain") await busy(b, async () => { await api("/api/admin/settings", { group: "telegram", values: { reconnect: true, telegram: { welcome: $("tgForm").welcome.value } } }); delete $("tgForm").dataset.dirty; toast("Telegram connected again ✓"); RENDER.settings("telegram"); });
  if (act === "tgOff" && await ask("Customers can no longer reach you there until you connect it again. Old chats stay in the Inbox.", { title: "Disconnect the Telegram bot?", ok: "Disconnect", danger: true })) await busy(b, async () => { await api("/api/admin/telegram/disconnect", {}); toast("Telegram disconnected"); RENDER.settings("telegram"); });
  if (act === "2faOn") await busy(b, async () => {
    const r = await api("/api/admin/2fa/start", {});
    sheet(`<h2 class="t">Turn on two-step login</h2><p class="sub">1. Open Google Authenticator (or 1Password, Authy) → Add → Enter a setup key. Use this key:</p>
      <pre class="cmd">${esc(r.secret.replace(/(.{4})/g, "$1 ").trim())}</pre><p class="sub">Account: ${esc(ME.email)} · Type: time based. <a href="${esc(r.uri)}" style="color:#A8DCFF">Open in app</a> (on this phone)</p>
      <p class="sub">2. Type the 6-digit code the app shows:</p><form id="totpForm"><input class="in" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required><button class="btn holo block" style="margin-top:10px">Confirm</button></form>`);
    $("totpForm").onsubmit = async ev => { ev.preventDefault(); await busy(ev.target.querySelector("button"), async () => { await api("/api/admin/2fa/confirm", { code: ev.target.code.value }); ME.twofa = true; closeSheet(); toast("Two-step login is on ✓"); RENDER.settings("account"); }); };
  });
  if (act === "2faOff") { const pw = await ask("Type your password to turn off two-step login.", { title: "Turn off two-step login?", ok: "Turn off", danger: true, input: "", type: "password", placeholder: "Your password" }); if (pw) await busy(b, async () => { await api("/api/admin/2fa/disable", { password: pw }); ME.twofa = false; toast("Two-step login is off"); RENDER.settings("account"); }); }
});

/* ================= TEXTS ================= */
/* Every text customers see (shop, waitlist, emails, WhatsApp, errors). One group at a time (menu on the left on a computer, chips on a phone),
   search across all, unsaved changes kept per text until Save. The texts file goes out and comes back through Download / Upload. */
const TXS = { data: null, cur: null, q: "", edits: {} };
const txItems = () => TXS.data ? TXS.data.groups.flatMap(g => g.items.map(i => Object.assign(i, { g }))) : [];
const txDirty = () => Object.keys(TXS.edits).length > 0;
const txVarsIn = s => [...new Set([...String(s).matchAll(/\{(\w+)\}/g)].map(m => m[1]))];
const txVal = it => it.key in TXS.edits ? TXS.edits[it.key] : it.value;
RENDER.texts = async sub => {
  const el = $("s-texts");
  el.innerHTML = `<p class="sub" style="margin:18px 2px">Loading…</p>`;
  try { TXS.data = await api("/api/admin/texts"); } catch (e) { el.innerHTML = `<p class="sub">${esc(e.message)}</p>`; return; }
  const G = TXS.data.groups;
  if (sub && G.some(g => g.id === sub)) TXS.cur = sub;
  if (!G.some(g => g.id === TXS.cur)) TXS.cur = G[0].id;
  const total = G.reduce((n, g) => n + g.items.length, 0);
  el.innerHTML = `<div class="card tx-top">
      <p class="sub">Every text your customers see: shop, waitlist, emails, WhatsApp messages and error messages. Change a text and press <b>Save</b>, the site shows it at once. An empty field brings the original back.</p>
      <p class="sub tx-help"><code>{store}</code> words in braces are filled in by the shop, keep them. <code>**word**</code> is <b>bold</b>, <code>[label](/pages/returns)</code> is a link.
        Store name and logo are in <a href="/admin/settings#store" data-go="settings:store">Settings → Store</a>; gift, marquee and the top bar in <a href="/admin/settings#waitlist" data-go="settings:waitlist">Waitlist</a> and <a href="/admin/settings#shop" data-go="settings:shop">Shop</a>.</p>
      <div class="tx-tools"><input class="in" type="search" id="txQ" placeholder="Search all ${total} texts" aria-label="Search texts" value="${esc(TXS.q)}">
        <a class="btn ghost" href="/api/admin/texts/export" download>Download file</a>
        <label class="btn ghost" tabindex="0">Upload file<input type="file" id="txFile" accept=".json,application/json" hidden></label>
        <button type="button" class="btn bad" data-tx="reset"${TXS.data.changed ? "" : " disabled"}>Reset all</button></div>
      <p class="sub tx-sum">${TXS.data.changed ? `<b>${TXS.data.changed}</b> of ${total} texts changed.` : `All ${total} texts are the originals.`} The file is for filling in by hand, e.g. for a new client: download, change, upload.</p></div>
    <div class="set-wrap"><nav class="set-nav" id="txNav" aria-label="Text groups"></nav><div class="set-body" id="txBody"></div></div>
    <div class="tx-save" id="txSave" hidden><span id="txSaveN"></span><button type="button" class="btn ghost" data-tx="discard">Discard</button><button type="button" class="btn holo" data-tx="save">Save</button></div>`;
  drawTxNav(); drawTxBody();
  $("txQ").oninput = () => { TXS.q = $("txQ").value.trim(); drawTxBody(); };
  $("txFile").onchange = () => txImport($("txFile"));
  el.querySelector(".tx-tools label").onkeydown = e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); $("txFile").click(); } };
};
function drawTxNav(){
  const nav = $("txNav"); if (!nav) return;
  nav.innerHTML = TXS.data.groups.map(g => { const ch = g.items.filter(i => i.changed).length, dirty = g.items.some(i => i.key in TXS.edits);
    return `<button type="button" data-g="${g.id}" aria-current="${!TXS.q && g.id === TXS.cur}" class="${dirty ? "dirty" : ""}"><span><b>${esc(g.page)} · ${esc(g.title)}</b><small>${g.items.length} texts${ch ? ` · ${ch} changed` : ""}</small></span><em>unsaved</em></button>`; }).join("");
  const n = Object.keys(TXS.edits).length;
  $("txSave").hidden = !n; $("txSaveN").textContent = n === 1 ? "1 unsaved change" : n + " unsaved changes";
}
function drawTxBody(){
  const body = $("txBody"); if (!body) return;
  let html;
  if (TXS.q) {
    const q = TXS.q.toLowerCase(), hit = s => String(s).toLowerCase().includes(q);
    const found = txItems().filter(i => hit(i.label) || hit(i.key) || hit(JSON.stringify(txVal(i))) || hit(JSON.stringify(i.original)) || hit(i.g.page + " " + i.g.title));
    html = `<section class="card cur"><div class="head"><h2>${found.length ? `${found.length} text${found.length > 1 ? "s" : ""} with “${esc(TXS.q)}”` : `Nothing has “${esc(TXS.q)}”`}</h2></div>
      ${found.slice(0, 80).map(i => txItem(i, true)).join("")}${found.length > 80 ? `<p class="sub">Showing the first 80. Type a little more.</p>` : ""}</section>`;
  } else {
    const g = TXS.data.groups.find(x => x.id === TXS.cur);
    html = `<section class="card cur"><div class="head tx-head"><h2>${esc(g.page)} · ${esc(g.title)}</h2>${g.url ? `<a class="btn ghost sm" href="${esc(g.url)}" target="_blank" rel="noopener">Open the page ↗</a>` : ""}</div>
      ${g.items.map(i => txItem(i)).join("")}</section>`;
  }
  body.innerHTML = html;
  body.querySelectorAll("textarea.tx-in").forEach(txGrow);
  drawTxNav();
}
const txGrow = ta => { ta.style.height = "auto"; ta.style.height = ta.scrollHeight + 2 + "px"; };
function txItem(i, where){
  const v = txVal(i), changed = JSON.stringify(v) !== JSON.stringify(i.original);
  const head = `<span class="tx-lbl">${where ? `<small>${esc(i.g.page)} · ${esc(i.g.title)}</small>` : ""}${esc(i.label)}${changed ? ` <em class="tx-ch">changed</em>` : ""}${i.key in TXS.edits ? ` <em class="tx-ch un">unsaved</em>` : ""}</span>`;
  const vars = i.vars.length ? `<span class="tx-vars">${i.vars.map(x => `<code>{${esc(x)}}</code>`).join(" ")}</span>` : "";
  const orig = changed ? `<button type="button" class="tx-orig" data-tx="orig">↺ Original</button>` : "";
  if (i.list) {
    return `<div class="tx-it" data-k="${esc(i.key)}" data-list>${head}<div class="tx-list">${v.map(txRow).join("")}</div>
      <div class="tx-meta"><button type="button" class="btn ghost sm" data-tx="addq">+ Add a question</button>${vars}${orig}</div>
      ${changed ? `<details class="tx-was"><summary>Original questions</summary>${i.original.map(([q, a]) => `<p><b>${esc(q)}</b><br>${esc(a)}</p>`).join("")}</details>` : ""}</div>`;
  }
  return `<div class="tx-it" data-k="${esc(i.key)}"><label class="field">${head}
      <textarea class="in tx-in" rows="1" maxlength="${i.max}"${i.long ? "" : " data-line"} placeholder="${esc(i.original)}" aria-label="${esc(i.label)}">${esc(v)}</textarea></label>
    <div class="tx-meta">${vars}<span class="tx-warn">${txWarn(i, v)}</span>${i.max <= 60 ? `<span class="tx-len">${String(v).length}/${i.max}</span>` : ""}${orig}</div>
    ${changed ? `<small class="tx-was">Original: ${esc(i.original)}</small>` : ""}</div>`;
}
const txRow = ([q, a]) => `<div class="tx-row"><input class="in" value="${esc(q)}" placeholder="Question" aria-label="Question"><textarea class="in tx-in" rows="1" placeholder="Answer" aria-label="Answer">${esc(a)}</textarea>
  <div class="tx-rb"><button type="button" data-tx="up" aria-label="Move up" title="Move up">↑</button><button type="button" data-tx="down" aria-label="Move down" title="Move down">↓</button><button type="button" data-tx="rmq" aria-label="Remove" title="Remove">×</button></div></div>`;
// {words} the shop does not know show nothing useful, so they are flagged at once (the server refuses them too)
function txWarn(i, v){
  const s = Array.isArray(v) ? v.flat().join(" ") : String(v);
  const bad = txVarsIn(s).filter(x => !i.vars.includes(x));
  if (bad.length) return `<b class="bad">{${esc(bad[0])}} is not known here.</b>`;
  const gone = txVarsIn(Array.isArray(i.original) ? i.original.flat().join(" ") : i.original).filter(x => !s.includes("{" + x + "}"));
  return gone.length && s.trim() ? `Without ${gone.map(x => `{${esc(x)}}`).join(", ")}.` : "";
}
// read one text back from the page and remember it as an unsaved change (or forget it when it is back to what is saved)
function txRead(box){
  const it = txItems().find(i => i.key === box.dataset.k); if (!it) return;
  let v;
  if (box.hasAttribute("data-list")) v = [...box.querySelectorAll(".tx-row")].map(r => [r.querySelector("input").value, r.querySelector("textarea").value]);
  else v = box.querySelector("textarea").value;
  const same = Array.isArray(v) ? JSON.stringify(v.filter(r => r[0].trim() || r[1].trim()).map(r => r.map(x => x.trim()))) === JSON.stringify(it.value) : v === it.value || (v.trim() === "" && !it.changed);
  if (same) delete TXS.edits[it.key]; else TXS.edits[it.key] = v;
  const w = box.querySelector(".tx-warn"); if (w) w.innerHTML = txWarn(it, v.length || Array.isArray(v) ? v : it.original);
  const l = box.querySelector(".tx-len"); if (l) l.textContent = v.length + "/" + it.max;
  drawTxNav();
}
document.addEventListener("input", e => { const box = e.target.closest("#s-texts .tx-it"); if (!box) return; if (e.target.matches("textarea")) txGrow(e.target); txRead(box); });
document.addEventListener("keydown", e => { if (e.key === "Enter" && e.target.matches("#s-texts textarea[data-line]")) e.preventDefault(); });
document.addEventListener("click", async e => {
  const g = e.target.closest("#txNav [data-g]");
  if (g) { TXS.cur = g.dataset.g; TXS.q = ""; $("txQ").value = ""; drawTxBody(); try { history.replaceState(null, "", "/admin/texts#" + TXS.cur); } catch {} if (wide()) scrollTo(0, 0); else $("txBody").scrollIntoView({ block: "start", behavior: "smooth" }); return; }
  const b = e.target.closest("#s-texts [data-tx]"); if (!b) return;
  const act = b.dataset.tx, box = b.closest(".tx-it");
  if (act === "orig") {
    const it = txItems().find(i => i.key === box.dataset.k);
    if (it.changed) TXS.edits[it.key] = it.original; else delete TXS.edits[it.key];
    box.outerHTML = txItem(it, !!TXS.q); document.querySelectorAll(`#s-texts .tx-it[data-k="${CSS.escape(it.key)}"] textarea.tx-in`).forEach(txGrow); drawTxNav();
  }
  if (act === "addq") { box.querySelector(".tx-list").insertAdjacentHTML("beforeend", txRow(["", ""])); const r = box.querySelector(".tx-row:last-child input"); r.focus(); txRead(box); }
  if (act === "rmq") { b.closest(".tx-row").remove(); txRead(box); }
  if (act === "up" || act === "down") { const r = b.closest(".tx-row"), o = act === "up" ? r.previousElementSibling : r.nextElementSibling; if (o) { act === "up" ? o.before(r) : o.after(r); txRead(box); } }
  if (act === "discard" && await ask("Your unsaved text changes are thrown away.", { title: "Discard the changes?", ok: "Discard", danger: true })) { TXS.edits = {}; drawTxBody(); }
  if (act === "save") await txSave(b);
  if (act === "reset" && await ask("Every text goes back to the original wording, on the site and in emails. Download the file first if you want to keep your version.", { title: "Reset all texts?", ok: "Reset all", danger: true }))
    await busy(b, async () => { await api("/api/admin/texts/reset", {}); TXS.edits = {}; toast("All texts are the originals again"); RENDER.texts(TXS.cur); });
});
async function txSave(btn){
  if (!txDirty()) return;
  const changes = {};
  for (const [k, v] of Object.entries(TXS.edits)) changes[k] = Array.isArray(v) ? v.filter(r => r[0].trim() || r[1].trim()) : v;
  await busy(btn || $("txSave").querySelector("[data-tx=save]"), async () => {
    await api("/api/admin/texts", { changes });
    const n = Object.keys(changes).length; TXS.edits = {};
    toast(`Saved ✓ ${n === 1 ? "1 text" : n + " texts"} · live on the site now`);
    await RENDER.texts(TXS.cur);
  });
}
async function txImport(input){
  const f = input.files[0]; input.value = ""; if (!f) return;
  if (txDirty() && !await ask("Your unsaved text changes are thrown away before the file is applied.", { title: "Upload the texts file?", ok: "Upload" })) return;
  const fd = new FormData(); fd.append("file", f);
  await busy(null, async () => {
    const r = await api("/api/admin/texts/import", fd, true);
    TXS.edits = {};
    await RENDER.texts(TXS.cur);
    toast(r.changed ? `${r.changed} text${r.changed > 1 ? "s" : ""} changed from the file` : "The file had no new changes");
    if (r.skipped.length) sheet(`<h2 class="t">${r.skipped.length} text${r.skipped.length > 1 ? "s" : ""} not taken over</h2><p class="sub">Everything else from the file is saved. Fix these here or in the file and upload it again.</p>
      ${r.skipped.map(([k, why]) => `<div class="card" style="margin-top:8px"><b class="mono" style="font-size:12px">${esc(k)}</b><p class="sub" style="margin:4px 0 0">${esc(why)}</p></div>`).join("")}`);
  });
}

/* ================= TEAM ================= */
const ROLE_TEXT = { owner: "Everything", manager: "Orders, customers, stock, delivery, marketing", warehouse: "Packing orders and stock", courier: "Only their delivery route", accountant: "Money and exports" };
RENDER.team = async () => {
  const el = $("s-team");
  let r; try { r = await api("/api/admin/team"); } catch (e) { el.innerHTML = `<p class="sub">${esc(e.message)}</p>`; return; }
  el.innerHTML = `<section class="card" style="padding-top:4px">${r.team.map(a => `<button class="cust" data-admin='${esc(JSON.stringify(a))}'><div class="av">${esc(a.name.split(" ").map(w => w[0]).join("").slice(0, 2).toUpperCase())}</div>
    <div><div style="font-weight:700;font-size:14px">${esc(a.name)}<span class="tag">${esc(a.role)}</span>${a.active == 1 ? "" : '<span class="tag" style="color:var(--bad);background:rgba(229,96,106,.1)">off</span>'}</div><div class="sub" style="margin-top:2px">${esc(a.email)}${a.twofa == 1 ? " · 2-step" : ""}</div></div>
    <div class="sub" style="text-align:right">${a.last_login_at ? "seen " + ago(a.last_login_at) : "never logged in"}</div></button>`).join("")}</section>
    <button class="btn holo block" style="margin-top:14px" data-act="addAdmin">+ Add person</button>
    <p class="sub" style="margin:12px 4px">A courier only sees Delivery: their own routes, in courier mode. Give each window a courier under Delivery (with one courier, every route is theirs).</p>`;
  el.onclick = e => { const b = e.target.closest("[data-admin]"); if (b) teamSheet(JSON.parse(b.dataset.admin), r.roles); if (e.target.closest("[data-act=addAdmin]")) teamSheet(null, r.roles); };
};
function teamSheet(a, roles){
  sheet(`<h2 class="t">${a ? esc(a.name) : "Add a person"}</h2><form id="tForm">
    <label class="field"><span>Name</span><input class="in" name="name" value="${esc(a ? a.name : "")}" required></label>
    <label class="field"><span>Email (their login)</span><input class="in" type="email" name="email" value="${esc(a ? a.email : "")}" required></label>
    <label class="field"><span>Role</span><select class="in" name="role">${roles.map(x => `<option value="${x}" ${a && a.role === x ? "selected" : ""}>${x} — ${ROLE_TEXT[x]}</option>`).join("")}</select></label>
    <label class="field"><span>${a ? "New password (leave empty to keep)" : "Password (at least 10 characters)"}</span><input class="in" type="password" name="password" autocomplete="new-password" ${a ? "" : "required minlength=10"}></label>
    ${a ? `<div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${toggle("active", "Can log in", a.active == 1)}</div>` : ""}
    <button class="btn holo block" type="submit">Save</button></form>`);
  $("tForm").onsubmit = async e => { e.preventDefault(); const f = e.target; await busy(f.querySelector("button"), async () => {
    await api("/api/admin/team/save", { id: a ? a.id : 0, name: f.name.value, email: f.email.value, role: f.role.value, password: f.password.value, active: f.active ? f.active.checked : true });
    closeSheet(); toast("Saved ✓"); RENDER.team(); }); };
}

/* ================= PACKING LIST ================= */
const PK = { date: null, data: null };
RENDER.packing = async sub => {
  if (sub && /^\d{4}-\d{2}-\d{2}$/.test(sub)) PK.date = sub;
  const el = $("s-packing");
  let d; try { d = await api("/api/admin/packing" + (PK.date ? "?date=" + PK.date : "")); } catch (e) { el.innerHTML = `<p class="sub" style="margin:18px 2px">${esc(e.message)}</p>`; return; }
  PK.date = d.date; PK.data = d;
  const done = d.total ? Math.round(d.packed / d.total * 100) : 0, todo = pmQueue().filter(o => !o.packed).length;
  el.innerHTML = `<div class="chips" style="margin-top:14px">${d.days.map(x => `<button class="chip" data-pday="${x.date}" aria-pressed="${x.date === d.date}">${esc(x.label)}${x.todo ? `<i>${x.todo}</i>` : ""}</button>`).join("")}</div>
    <section class="card pk-top"><div class="head"><h2>${esc(d.label)}</h2><span class="sub num">${d.packed} of ${d.total} packed</span></div>
      <div class="track"><i style="width:${done}%"></i></div>
      ${todo ? `<button class="btn holo block pk-start" data-pmstart>${ICON.box}Start packing · ${todo} order${todo > 1 ? "s" : ""}</button>` : ""}
      ${d.total ? `<div class="acts2"><button class="btn ghost" data-print="list">Print list</button><button class="btn ghost" data-print="labels">Print labels</button></div>` : ""}</section>
    ${d.total ? `<section class="card"><div class="head"><h2>To pick</h2><span class="sub">by shelf, everything not packed yet</span></div>
      ${d.pick.length || d.gifts ? d.pick.map(p => `<div class="row pk-pick">${shelfTag(p.shelf)}<span>${esc(p.name)}</span><b class="num">× ${p.qty}</b></div>`).join("") + (d.gifts ? `<div class="row pk-pick">${shelfTag(null)}<span>🎁 Waitlist gift</span><b class="num">× ${d.gifts}</b></div>` : "")
        : `<p class="sub" style="margin:0">Everything is packed ✓</p>`}</section>` : ""}
    ${d.windows.map(w => { const n = w.orders.filter(o => o.packed).length;
      return `<section class="card"><div class="head"><h2>${esc(w.label)}</h2><span class="sub num">${n} / ${w.orders.length} packed</span></div>${w.orders.map(pkRow).join("")}</section>`; }).join("")
      || `<section class="card"><div class="empty">${ICON.box}<h2>Nothing to pack</h2><p>No orders for this day yet.</p></div></section>`}`;
  el.onclick = async e => {
    const day = e.target.closest("[data-pday]"); if (day) { PK.date = day.dataset.pday; try { history.replaceState(null, "", "/admin/packing#" + PK.date); } catch {} return RENDER.packing(); }
    const pr = e.target.closest("[data-print]"); if (pr) return printPacking(pr.dataset.print);
    if (e.target.closest("[data-pmstart]")) return pmOpen();
    const op = e.target.closest("[data-pkopen]"); if (op) { e.preventDefault(); return openOrder(+op.dataset.pkopen); }
    const b = e.target.closest("[data-pack]"); if (!b || b.disabled) return;
    const o = PK.data.windows.flatMap(w => w.orders).find(x => x.id == b.dataset.pack);
    const back = o.unpaid ? "new" : "paid", set = st => api(`/api/admin/orders/${o.id}/status`, { status: st }).then(() => current === "packing" && RENDER.packing());
    await busy(b, async () => { await set(o.packed ? back : "packing");
      const msg = o.packed ? `#${o.number} not packed` : `#${o.number} packed ✓` + (o.unpaid && !o.collect ? " · not paid yet" : "");
      toast(msg, { act: "Undo", fn: () => set(o.packed ? "packing" : back).catch(err => toastErr(err.message)) }); });
  };
};
function pkRow(o){
  const gone = ["out_for_delivery", "delivered", "failed_delivery"].includes(o.status);
  return `<div class="pk ${o.packed ? "done" : ""}"><button class="pk-chk" data-pack="${o.id}" aria-pressed="${o.packed}" aria-label="${o.packed ? "Packed" : "Mark packed"}: order ${o.number}" ${gone ? "disabled" : ""}>${o.packed ? "✓" : ""}</button>
    <div style="min-width:0"><div class="pk-h"><b>#${o.number}</b> ${esc(o.name || "")} <span class="sub">${esc(o.address)}${o.zone ? " · " + esc(o.zone) : ""}</span></div>
      <ul class="pk-items">${o.items.map(i => `<li>${shelfTag(i.shelf)}<b class="num">${i.qty}×</b> ${esc(i.name_snapshot)}</li>`).join("")}${o.gift_code ? `<li>🎁 Waitlist gift</li>` : ""}</ul>
      ${o.notes ? `<div class="sub" style="margin-top:4px">📝 ${esc(o.notes)}</div>` : ""}
      <div style="margin-top:6px">${o.collect ? `<span class="tag" style="margin:0">Collect ${eur(o.collect)} cash</span>` : o.unpaid ? `<span class="tag" style="margin:0;background:rgba(229,96,106,.1);color:var(--bad)">Not paid yet</span>` : ""}${gone ? `<span class="sub"> ${esc(stLabel(o.status))}</span>` : ""}</div></div>
    <a href="#" class="sub" data-pkopen="${o.id}">open</a></div>`;
}
const stLabel = st => ((PK.data && PK.data.labels) || OL.labels || {})[st] || st.replace(/_/g, " ");
const shelfTag = sh => `<span class="shelf${sh ? "" : " none"}">${sh ? esc(sh) : "–"}</span>`;
function printPacking(kind){
  const d = PK.data;
  let p = $("print"); if (!p) { p = document.createElement("div"); p.id = "print"; document.body.appendChild(p); }
  const items = o => o.items.map(i => `${i.shelf ? `[${esc(i.shelf)}] ` : ""}${i.qty}× ${esc(i.name_snapshot)}`).join("<br>") + (o.gift_code ? "<br>🎁 Waitlist gift" : "");
  const pay = o => o.collect ? `<b>COLLECT ${eur(o.collect)} CASH</b>` : o.unpaid ? "<b>NOT PAID</b>" : "Paid";
  p.innerHTML = kind === "labels"
    ? `<div class="labels">${d.windows.flatMap(w => w.orders.map(o => `<div class="lab"><div class="n">#${o.number}</div><div class="w">${esc(w.label)}</div><div class="nm">${esc(o.name || "")}</div>
        <div>${esc(o.address)}<br>${esc(o.postcode || "")} ${esc(o.city || "")}</div><div>${esc(o.phone || "")}</div><div class="f">${o.items.reduce((s, i) => s + +i.qty, 0)} item(s)${o.gift_code ? " + 🎁" : ""} · ${pay(o)}</div></div>`)).join("")}</div>`
    : `<h1>${esc(window.ADMIN.store || "")} · packing list · ${esc(d.label)}</h1>
      <h2>To pick</h2>${d.pick.map(x => `<div class="pr-o"><span class="box"></span><b style="width:18mm">${esc(x.shelf || "")}</b><span>${x.qty}× ${esc(x.name)}</span></div>`).join("")}${d.gifts ? `<div class="pr-o"><span class="box"></span><span>${d.gifts}× 🎁 Waitlist gift</span></div>` : ""}
      ${d.windows.map(w => `<h2>${esc(w.label)}</h2>${w.orders.map(o => `<div class="pr-o"><span class="box">${o.packed ? "✓" : ""}</span><div style="flex:1"><b>#${o.number}</b> ${esc(o.name || "")} · ${esc(o.address)}, ${esc(o.postcode || "")}${o.notes ? `<br><i>${esc(o.notes)}</i>` : ""}</div><div style="flex:1">${items(o)}</div><div style="width:32mm;text-align:right">${pay(o)}</div></div>`).join("")}`).join("")}`;
  document.body.classList.add("printing");
  const done = () => { document.body.classList.remove("printing"); removeEventListener("afterprint", done); };
  addEventListener("afterprint", done);
  window.print();
  setTimeout(done, 1000);
}

/* ---- packing mode: one order at a time, items in shelf order, tick them into the bag ---- */
const PM = { i: 0, ticks: {}, dir: 0, on: false };
/** Orders that can still be packed (or un-packed) today, in packing-list order; the ones already gone stay out. */
const pmQueue = () => (PK.data ? PK.data.windows.flatMap(w => w.orders.map(o => Object.assign(o, { win: w.label }))) : []).filter(o => !["out_for_delivery", "delivered", "failed_delivery"].includes(o.status));
const pmLines = o => o.items.map((it, k) => ({ k: String(k), it })).concat(o.gift_code ? [{ k: "g", it: null }] : []);
function pmOpen(id){
  const q = pmQueue(); if (!q.length) return;
  const at = id ? q.findIndex(o => o.id == id) : q.findIndex(o => !o.packed);
  PM.i = Math.max(0, at); PM.ticks = {}; PM.dir = 0; PM.on = true; PM.end = false;
  let el = $("pm"); if (!el) { el = document.createElement("div"); el.id = "pm"; el.className = "pm"; el.setAttribute("role", "dialog"); el.setAttribute("aria-modal", "true"); el.setAttribute("aria-label", "Packing"); document.body.appendChild(el); pmWire(el); }
  el.hidden = false; document.documentElement.classList.add("pm-on");
  pmDraw(); setTimeout(() => { const b = el.querySelector(".pm-go"); if (b) b.focus(); }, 30);
}
function pmClose(){
  const el = $("pm"); if (!el || el.hidden) return;
  el.hidden = true; PM.on = false; document.documentElement.classList.remove("pm-on");
  if (current === "packing") RENDER.packing();
}
function pmDraw(){
  const el = $("pm"), q = pmQueue(), d = PK.data, left = q.filter(o => !o.packed).length;
  if (!q.length) return pmClose();
  PM.i = Math.min(PM.i, q.length - 1);
  const strip = `<div class="pm-strip" role="tablist" aria-label="Orders">${q.map((o, i) => `<button role="tab" data-pmto="${i}" class="${o.packed ? "done" : ""}${!PM.end && i === PM.i ? " cur" : ""}" aria-selected="${!PM.end && i === PM.i}" title="#${o.number}${o.packed ? " · packed" : ""}"><span>${o.number}</span></button>`).join("")}</div>`;
  const head = `<header class="pm-top"><button class="pm-x" data-pmclose aria-label="Close packing">${ICON.x}</button>
      <div class="pm-ttl"><small class="mono">Packing · ${esc(d.label)}</small><b>${PM.end ? "Done" : `Order ${PM.i + 1} of ${q.length}`}</b></div>
      <span class="pm-left num">${left ? `${left} to go` : "all packed ✓"}</span>${strip}</header>`;
  if (PM.end) {
    const rest = q.filter(o => !o.packed);
    el.innerHTML = head + `<div class="pm-stage"><div class="pm-done"><div class="pm-big">${rest.length ? ICON.box : "✓"}</div>
      <h2>${rest.length ? `${rest.length} order${rest.length > 1 ? "s" : ""} still to pack` : `All ${q.length} order${q.length > 1 ? "s" : ""} packed`}</h2>
      <p class="sub">${rest.length ? "You skipped these. Pack them now or come back later." : "Stick the labels on and hand the bags to the courier."}</p>
      <div class="pm-dacts">${rest.length ? `<button class="btn holo" data-pmto="${q.indexOf(rest[0])}">Pack #${rest[0].number}</button>` : `<button class="btn holo" data-print="labels">Print labels</button>`}<button class="btn ghost" data-pmclose>Back to the list</button></div></div></div>`;
    return;
  }
  const o = q[PM.i], lines = pmLines(o), t = PM.ticks[o.id] || (PM.ticks[o.id] = {});
  const all = o.packed || lines.every(l => t[l.k]), n = lines.filter(l => t[l.k]).length, mayShelf = can("stock");
  const units = o.items.reduce((s, i) => s + +i.qty, 0) + (o.gift_code ? 1 : 0), nx = q.find((x, i) => i > PM.i && !x.packed);
  el.innerHTML = head + `<div class="pm-stage"><article class="pm-card${PM.dir ? (PM.dir > 0 ? " in-r" : " in-l") : ""}${o.packed ? " packed" : ""}">
      <div class="pm-oh"><span class="pm-no">#${o.number}</span><span class="pm-win">${ICON.clock}${esc(o.win)}</span>${o.packed ? `<span class="pm-ok">✓ Packed</span>` : ""}</div>
      <div class="pm-who"><b>${esc(o.name || "")}</b><span class="sub">${esc(o.address)}${o.zone ? " · " + esc(o.zone) : ""}</span></div>
      ${o.collect || o.unpaid || o.notes ? `<div class="pm-flags">${o.collect ? `<span class="tag">Collect ${eur(o.collect)} cash</span>` : o.unpaid ? `<span class="tag bad">Not paid yet</span>` : ""}${o.notes ? `<span class="pm-note">📝 ${esc(o.notes)}</span>` : ""}</div>` : ""}
      <div class="pm-sec"><span class="mono">Put in the bag</span><span class="mono num">${units} item${units > 1 ? "s" : ""}</span></div>
      <ul class="pm-items">${lines.map(({ k, it }) => `<li class="${o.packed || t[k] ? "on" : ""}">
          ${it ? `<button class="pm-shelf${it.shelf ? "" : " none"}" ${mayShelf && it.variant_id ? `data-pmshelf="${k}"` : "disabled"} aria-label="${it.shelf ? "Shelf " + esc(it.shelf) : "No shelf yet"}${mayShelf && it.variant_id ? ", change" : ""}"><small>shelf</small>${it.shelf ? esc(it.shelf) : mayShelf && it.variant_id ? "+" : "–"}</button>` : `<span class="pm-shelf none"><small>gift</small>🎁</span>`}
          <button class="pm-it" data-pmi="${k}" aria-pressed="${!!(o.packed || t[k])}" ${o.packed ? "disabled" : ""}>
            <span class="pm-img">${it && it.image ? `<img src="${esc(it.image)}" alt="" loading="lazy">` : ICON.box}</span>
            <span class="pm-nm">${it ? esc(it.name_snapshot) : "Waitlist gift"}</span>
            <span class="pm-q num">×${it ? it.qty : 1}</span><span class="pm-tick" aria-hidden="true">✓</span></button></li>`).join("")}</ul>
      <div class="pm-end"><a href="#" class="sub" data-pkopen="${o.id}">Order details</a>${nx ? `<button class="pm-next" data-pmto="${q.indexOf(nx)}">Next <b>#${nx.number}</b> · ${nx.items.reduce((s, i) => s + +i.qty, 0) + (nx.gift_code ? 1 : 0)} item(s) ›</button>` : ""}</div>
    </article></div>
    <footer class="pm-foot"><button class="btn ghost pm-nav" data-pmstep="-1" ${PM.i ? "" : "disabled"} aria-label="Previous order">‹</button>
      ${o.packed ? `<button class="btn ghost pm-go" data-pmundo>Not packed after all</button>`
        : `<button class="btn ${all ? "holo" : "ghost"} pm-go" data-pmdone>${all ? "Packed · next order →" : `Tick each item · ${n} of ${lines.length}`}</button>`}
      <button class="btn ghost pm-nav" data-pmstep="1" aria-label="${PM.i < q.length - 1 ? "Skip to the next order" : "Finish"}">›</button></footer>
    <p class="pm-keys sub">Enter ticks the next item, then packs · ← → move between orders · Esc closes</p>`;
}
function pmStep(dir){
  const q = pmQueue();
  if (PM.end) { if (dir < 0) { PM.end = false; PM.i = q.length - 1; PM.dir = -1; pmDraw(); } return; }
  const j = PM.i + dir;
  if (j < 0) return;
  if (j >= q.length) { PM.end = true; pmDraw(); return; }
  PM.i = j; PM.dir = dir; pmDraw();
}
async function pmPack(btn, packed){
  const o = pmQueue()[PM.i], back = o.unpaid ? "new" : "paid";
  await busy(btn, async () => {
    await api(`/api/admin/orders/${o.id}/status`, { status: packed ? "packing" : back });
    o.status = packed ? "packing" : back; o.packed = packed;
    PK.data.packed += packed ? 1 : -1;
    if (packed) {
      toast(`#${o.number} packed ✓` + (o.unpaid && !o.collect ? " · not paid yet" : ""));
      const q = pmQueue(), nx = q.findIndex((x, i) => i > PM.i && !x.packed), any = q.findIndex(x => !x.packed);
      if (nx >= 0) { PM.i = nx; PM.dir = 1; } else if (any >= 0) { PM.i = any; PM.dir = 1; } else PM.end = true;
    } else { PM.ticks[o.id] = {}; PM.dir = 0; }
    pmDraw(); const g = $("pm").querySelector(".pm-go"); if (g) g.focus();
  });
}
function pmWire(el){
  el.addEventListener("click", async e => {
    if (e.target.closest("[data-pmclose]")) return pmClose();
    const pr = e.target.closest("[data-print]"); if (pr) return printPacking(pr.dataset.print);
    const op = e.target.closest("[data-pkopen]"); if (op) { e.preventDefault(); return openOrder(+op.dataset.pkopen); }
    const to = e.target.closest("[data-pmto]"); if (to) { const j = +to.dataset.pmto; PM.dir = PM.end ? 1 : Math.sign(j - PM.i); PM.end = false; PM.i = j; return pmDraw(); }
    const st = e.target.closest("[data-pmstep]"); if (st) return pmStep(+st.dataset.pmstep);
    const o = pmQueue()[PM.i];
    const it = e.target.closest("[data-pmi]"); if (it && !it.disabled) { const t = PM.ticks[o.id]; t[it.dataset.pmi] = !t[it.dataset.pmi]; PM.dir = 0; return pmDraw(); }
    const dn = e.target.closest("[data-pmdone]"); if (dn) {
      const lines = pmLines(o), t = PM.ticks[o.id];
      if (!lines.every(l => t[l.k])) { const l = lines.find(x => !t[x.k]); t[l.k] = true; PM.dir = 0; return pmDraw(); }
      return pmPack(dn, true);
    }
    const un = e.target.closest("[data-pmundo]"); if (un) return pmPack(un, false);
    const sh = e.target.closest("[data-pmshelf]"); if (sh) {
      const i = o.items[+sh.dataset.pmshelf];
      const v = await ask("Where does it lie in the stock room? For example A1 or B3. Leave empty for none.", { title: i.name_snapshot, input: i.shelf || "", placeholder: "A1", ok: "Save shelf" });
      if (v == null) return;
      await busy(sh, async () => {
        const r = await api("/api/admin/shelf", { variant_id: i.variant_id, shelf: v });
        PK.data.windows.forEach(w => w.orders.forEach(x => x.items.forEach(y => { if (y.variant_id == i.variant_id) y.shelf = r.shelf; })));
        PK.data.pick.forEach(y => { if (y.variant_id == i.variant_id) y.shelf = r.shelf; });
        if (typeof PRODUCTS !== "undefined" && PRODUCTS) PRODUCTS.forEach(p => p.variants.forEach(y => { if (y.id == i.variant_id) y.shelf = r.shelf; }));
        toast(r.shelf ? `Shelf ${r.shelf} saved` : "Shelf removed"); PM.dir = 0; pmDraw();
      });
    }
  });
  let x0 = null, y0 = null;
  el.addEventListener("touchstart", e => { const p = e.touches[0]; x0 = p.clientX; y0 = p.clientY; }, { passive: true });
  el.addEventListener("touchend", e => { if (x0 == null) return; const p = e.changedTouches[0], dx = p.clientX - x0, dy = p.clientY - y0; x0 = null;
    if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) pmStep(dx < 0 ? 1 : -1); }, { passive: true });
}
document.addEventListener("keydown", e => {
  if (!PM.on || ASK.esc || $("sheet").classList.contains("on") || e.metaKey || e.ctrlKey || e.altKey) return;
  if (e.key === "Escape") { e.stopImmediatePropagation(); return pmClose(); }
  if (e.key === "ArrowRight") { e.preventDefault(); return pmStep(1); }
  if (e.key === "ArrowLeft") { e.preventDefault(); return pmStep(-1); }
  if (e.key === "Enter" && !e.target.closest("[data-pmshelf],[data-pmi],[data-pmto],[data-pmstep],[data-pmclose],a")) { const b = $("pm").querySelector("[data-pmdone]"); if (b) { e.preventDefault(); b.click(); } }
}, true);

/* ---- cash from couriers (Delivery tab) ---- */
function cashCard(d, cm){
  if (!d.cash || !d.cash.length) return "";
  if (d.courier_view) { const c = d.cash.find(x => x.courier_id === (ME && ME.id)) || d.cash[0];
    return `<section class="cv-cashc" id="cashCard"><small>Cash with you</small><b class="num">${eur(c.total)}</b><p>${c.orders.length} order${c.orders.length > 1 ? "s" : ""} paid at the door: ${c.orders.map(o => "#" + o.number).join(", ")}. Hand it to the owner; they confirm it here.</p></section>`; }
  if (cm) { const c = d.cash.find(x => x.courier_id === (ME && ME.id)) || (d.courier_view ? d.cash[0] : null); if (!c) return "";
    return `<section class="card" id="cashCard"><div class="head"><h2>Cash with you</h2><b class="num">${eur(c.total)}</b></div><p class="sub" style="margin:0">${c.orders.length} order${c.orders.length > 1 ? "s" : ""} paid at the door: ${c.orders.map(o => "#" + o.number).join(", ")}. Hand it to the owner; they confirm it here.</p></section>`; }
  return `<section class="card" id="cashCard"><div class="head"><h2>Cash with couriers</h2><button class="btn sm ghost" data-cashhist>History</button></div>
    ${d.cash.map(c => `<div class="row" style="justify-content:space-between;padding:10px 0;border-top:1px solid var(--line)"><span><b>${esc(c.courier)}</b><br><span class="sub">${c.orders.length} order${c.orders.length > 1 ? "s" : ""} · since ${esc(ago(c.since))}</span></span>
      <span class="row" style="gap:10px"><b class="num">${eur(c.total)}</b><button class="btn sm" data-cashin="${c.courier_id}">Received</button></span></div>`).join("")}</section>`;
}
function cashSheet(c){
  sheet(`<h2 class="t">Cash from ${esc(c.courier)}</h2>
    <div class="card" style="padding:12px 14px"><div class="lines">${c.orders.map(o => `<div class="row"><span>#${o.number} <span class="sub">${esc(o.customer || "")} · ${esc(new Date(o.at).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }))}</span></span><span class="num">${eur(o.cents)}</span></div>`).join("")}
      <div class="row"><b>Should be</b><b class="num">${eur(c.total)}</b></div></div></div>
    <form id="cashForm" style="margin-top:14px"><label class="field"><span>You received (€)</span><input class="in" name="received" inputmode="decimal" value="${euroIn(c.total)}" required></label>
      <label class="field"><span>Note (optional)</span><input class="in" name="note" placeholder="e.g. €5 short, change for #1062"></label><p class="sub" id="cashDiff" style="margin:-4px 2px 12px"></p>
      <button class="btn holo block" type="submit">Confirm received</button></form>`);
  const f = $("cashForm");
  const diff = () => { const v = Math.round(parseFloat(f.received.value.replace(/\./g, "").replace(",", ".")) * 100), dlt = v - c.total;
    $("cashDiff").innerHTML = isNaN(v) ? "" : dlt === 0 ? "✓ Matches" : `<span style="color:${dlt < 0 ? "var(--bad)" : "var(--warn)"}">${dlt < 0 ? eur(-dlt) + " short" : eur(dlt) + " more than expected"}</span>`; };
  f.received.oninput = diff; diff();
  f.onsubmit = async e => { e.preventDefault(); await busy(f.querySelector("button"), async () => {
    await api("/api/admin/cash/handover", { courier_id: c.courier_id, order_ids: c.orders.map(o => o.id), received: f.received.value, note: f.note.value });
    closeSheet(); toast("Cash confirmed ✓"); RENDER.delivery(); }); };
}
async function cashHistory(){
  let r; try { r = await api("/api/admin/cash"); } catch (e) { return toastErr(e.message); }
  sheet(`<h2 class="t">Cash handovers</h2>${r.history.map(h => { const dl = +h.received_cents - +h.expected_cents;
    return `<div style="padding:10px 0;border-top:1px solid var(--line);font-size:13px"><div class="row" style="justify-content:space-between"><b>${esc(h.courier || "—")}</b><b class="num">${eur(+h.received_cents)}</b></div>
      <div class="sub" style="margin-top:3px">${esc(new Date(h.created_at).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }))} · ${h.orders} order${h.orders == 1 ? "" : "s"} · ${dl === 0 ? "✓ matched" : `<span style="color:${dl < 0 ? "var(--bad)" : "var(--warn)"}">${dl < 0 ? eur(-dl) + " short" : eur(dl) + " over"}</span>`}${h.by_name ? " · by " + esc(h.by_name) : ""}${h.note ? "<br>" + esc(h.note) : ""}</div></div>`; }).join("") || `<p class="sub">No handovers yet.</p>`}`);
}

/* ================= MARKETING ================= */
const MK_TABS = [["funnel", "Shop funnel"], ["codes", "Codes"], ["referrals", "Referrals"], ["email", "Email"], ["restock", "Back in stock"], ["carts", "Left bags"]];
const MK = {};
RENDER.marketing = async sub => {
  const tab = MK_TABS.some(([k]) => k === sub) ? sub : "funnel";
  const el = $("s-marketing");
  el.innerHTML = `<div class="chips" id="mkTabs" style="margin-top:4px">${MK_TABS.map(([k, l]) => `<button class="chip" data-mk="${k}" aria-pressed="${k === tab}">${l}</button>`).join("")}</div><div id="mkBody"><p class="sub" style="margin:18px 2px">Loading…</p></div>`;
  $("mkTabs").onclick = e => { const b = e.target.closest("[data-mk]"); if (!b) return; try { history.replaceState(null, "", "/admin/marketing#" + b.dataset.mk); } catch {} RENDER.marketing(b.dataset.mk); };
  try { await MK[tab]($("mkBody")); } catch (e) { $("mkBody").innerHTML = `<p class="sub" style="margin:18px 2px">${esc(e.message)}</p>`; }
};
const localDate = iso => iso ? new Date(iso).toLocaleDateString("sv-SE", { timeZone: "Europe/Amsterdam" }) : "";
const shortDate = iso => new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
const copy = async (t, msg) => { try { await navigator.clipboard.writeText(t); toast(msg || "Copied ✓"); } catch { ask("Your browser blocked copying. Select the text and copy it yourself.", { title: "Copy this", input: t, ok: "Done", no: "Close" }); } };

/* ---- shop funnel: where shoppers drop off between a product and a paid order ---- */
const MKF = { days: 30 };
MK.funnel = async el => {
  const r = await api("/api/admin/marketing/funnel?days=" + MKF.days), steps = r.steps, top = Math.max(...steps.map(x => x[2]));
  // the biggest drop between two steps, so the owner knows where to look first
  let leak = null;
  steps.forEach((x, i) => { if (!i || !steps[i - 1][2]) return; const lost = Math.max(0, steps[i - 1][2] - x[2]); if (lost && (!leak || lost / steps[i - 1][2] > leak.f)) leak = { i, f: lost / steps[i - 1][2], lost }; });
  const tip = { bag: "Better photos, a clear price and stock on the product page help here.", checkout: "Check the delivery fee and the free-delivery threshold: people often leave when they see the total.",
    order: "Look at the checkout: are there delivery windows left, and does the postcode check work for them?", paid: "Send the Tikkie quickly and follow up unpaid orders (Orders → Unpaid)." };
  el.innerHTML = `<div class="seg" role="group" aria-label="Period" id="mkfDays" style="margin-top:14px">${[[7, "7 days"], [30, "30 days"], [90, "90 days"], [0, "All"]].map(([d, l]) => `<button data-d="${d}" aria-pressed="${d === MKF.days}">${l}</button>`).join("")}</div>
    <section class="card"><div class="head"><h2>Shop funnel</h2><span class="sub">where shoppers drop off</span></div>
      ${top ? `<div id="mkfSteps">` + steps.map(([k, l, n], i) => `<div class="fstep" data-step="${k}"><div class="l"><span>${l}</span><span class="num"><b>${fmt(n)}</b> · ${pct(n, steps[0][2] || top)}%</span></div>
        <div class="hb" style="width:${Math.max(2, pct(n, top))}%"></div>${i && steps[i - 1][2] > n ? `<div class="lost num"><em>−${fmt(steps[i - 1][2] - n)}</em> lost here (${100 - pct(n, steps[i - 1][2])}%)</div>` : ""}</div>`).join("") + `</div>`
        : `<p class="sub" style="margin:0">No shop visits in this period yet.</p>`}
      <p class="sub" style="margin:12px 0 0">Counted per visit, without cookies. “Paid” counts web orders placed in this period that are paid now, also when you marked them paid later.</p></section>
    ${leak ? `<section class="card" id="mkfLeak"><div class="head"><h2>Biggest drop</h2></div><p style="margin:0;font-size:14px;line-height:1.5"><b>${Math.round(leak.f * 100)}%</b> leave between <b>${esc(steps[leak.i - 1][1].toLowerCase())}</b> and <b>${esc(steps[leak.i][1].toLowerCase())}</b> (${fmt(leak.lost)} ${leak.lost === 1 ? "visit" : "visits"}).</p>
      <p class="sub" style="margin:8px 0 0">${tip[steps[leak.i][0]] || ""}</p></section>` : ""}`;
  $("mkfDays").onclick = e => { const b = e.target.closest("[data-d]"); if (!b) return; MKF.days = +b.dataset.d; MK.funnel(el).catch(err => toastErr(err.message)); };
};

/* ---- codes ---- */
MK.codes = async el => {
  const r = await api("/api/admin/marketing/codes");
  const own = r.codes.filter(c => c.source !== "referral"), rewards = r.codes.filter(c => c.source === "referral");
  const icon = c => c.type === "pct" ? "%" : c.type === "fixed" ? "€" : ICON.truck;
  const state = c => !+c.active ? '<span class="tag" style="background:var(--card2);color:var(--ink3)">off</span>' : c.expired ? '<span class="tag" style="background:var(--card2);color:var(--ink3)">ended</span>'
    : c.max_uses != null && +c.used >= +c.max_uses ? '<span class="tag" style="background:var(--card2);color:var(--ink3)">used up</span>' : '<span class="tag" style="background:rgba(91,201,140,.12);color:var(--good)">live</span>';
  const row = c => `<button class="cust" data-code="${c.id}"><div class="av mk-ic">${icon(c)}</div>
    <div style="min-width:0"><div style="font-weight:700;font-size:14px"><span class="mono mk-code">${esc(c.code)}</span>${state(c)}</div>
    <div class="sub" style="margin-top:2px">${esc(c.label)}${+c.min_cents ? " · from " + eur(+c.min_cents) : ""}${c.ends_at ? " · until " + esc(shortDate(c.ends_at)) : ""}${c.customer_name ? " · for " + esc(c.customer_name) : ""}</div></div>
    <div style="text-align:right"><div class="num" style="font-weight:700">${+c.used}${c.max_uses != null ? " / " + c.max_uses : ""}</div><div class="sub">${+c.orders ? eur(+c.revenue) + " sales" : "used"}</div></div></button>`;
  el.innerHTML = `<section class="card" style="margin-top:14px"><div class="head"><h2>Discount codes</h2><button class="btn sm" data-newcode>+ New code</button></div>
      ${own.length ? own.map(row).join("") : `<div class="empty" style="padding:14px 6px"><p>Make a code for Instagram, a giveaway or a friend. Customers type it at checkout, or open a link that applies it for them.</p></div>`}</section>
    ${rewards.length ? `<section class="card"><div class="head"><h2>Referral rewards</h2><span class="sub">personal · works once</span></div>${rewards.map(row).join("")}</section>` : ""}`;
  el.onclick = e => { const b = e.target.closest("[data-code]"); if (b) codeSheet(r.codes.find(c => c.id == b.dataset.code)); if (e.target.closest("[data-newcode]")) codeSheet(null, r.suggest); };
};
function codeSheet(c, suggest){
  const n = !c, t = c ? c.type : "pct";
  const link = c ? location.origin + "/?code=" + encodeURIComponent(c.code) : "";
  sheet(`<h2 class="t">${n ? "New code" : esc(c.code)}</h2>
    ${c ? `<div class="grid2" style="margin-bottom:14px"><div class="card kpi"><div class="mono">Used</div><div class="v num">${+c.used}${c.max_uses != null ? `<span class="sub"> / ${c.max_uses}</span>` : ""}</div><span class="sub">${c.orders} paid order${c.orders == 1 ? "" : "s"}</span></div>
      <div class="card kpi"><div class="mono">Sales</div><div class="v num">${eur(+c.revenue)}</div><span class="sub">${eur(+c.given)} given off</span></div></div>` : ""}
    <form id="cdForm">
    <label class="field"><span>Code</span><input class="in mono" name="code" value="${esc(c ? c.code : suggest)}" maxlength="30" autocapitalize="characters" spellcheck="false" required ${c && +c.used ? "readonly" : ""}><small>${c && +c.used ? "Used codes keep their name." : "Letters, digits and “-”. Customers can type it in any case."}</small></label>
    <div class="cols"><label class="field"><span>Gives</span><select class="in" name="type">${Object.entries({ pct: "% off", fixed: "€ off", free_delivery: "Free delivery" }).map(([k, l]) => `<option value="${k}" ${k === t ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label class="field" id="cdVal"><span></span><input class="in" name="value" inputmode="decimal" value="${c ? (t === "fixed" ? euroIn(+c.value) : t === "pct" ? c.value : "") : "10"}"></label></div>
    <div class="cols"><label class="field"><span>Minimum bag (€)</span><input class="in" name="min" inputmode="decimal" placeholder="none" value="${c && +c.min_cents ? euroIn(+c.min_cents) : ""}"></label>
      <label class="field"><span>Max uses</span><input class="in" name="max_uses" inputmode="numeric" placeholder="no limit" value="${c && c.max_uses != null ? c.max_uses : ""}"></label></div>
    <div class="cols"><label class="field"><span>Starts</span><input class="in" type="date" name="starts" value="${c ? localDate(c.starts_at) : ""}"></label>
      <label class="field"><span>Ends (last day)</span><input class="in" type="date" name="ends" value="${c ? localDate(c.ends_at) : ""}"></label></div>
    <label class="field"><span>Note (only you see it)</span><input class="in" name="note" value="${esc(c ? c.note || "" : "")}" placeholder="e.g. Instagram giveaway"></label>
    <div class="card" style="padding:4px 14px;background:var(--card2);margin-bottom:12px">${toggle("active", "Live", c ? +c.active : true, "Off: customers can’t use it")}</div>
    <button class="btn holo block" type="submit">${n ? "Make code" : "Save"}</button></form>
    ${c ? `<div class="acts2"><button class="btn ghost" data-cd="copy">Copy shop link</button>${!+c.used && !c.orders ? `<button class="btn ghost" data-cd="del" style="color:var(--bad)">Delete</button>` : `<button class="btn ghost" data-cd="code">Copy code</button>`}</div>
      <p class="sub" style="margin:10px 2px 0">The link opens the shop with the code saved for checkout: <span class="mono" style="word-break:break-all">${esc(link)}</span></p>` : ""}`);
  const f = $("cdForm");
  const upd = () => { const ty = f.type.value; $("cdVal").hidden = ty === "free_delivery"; $("cdVal").querySelector("span").textContent = ty === "pct" ? "Percent off" : "Euros off"; };
  f.type.onchange = upd; upd();
  f.onsubmit = async e => { e.preventDefault(); await busy(f.querySelector("[type=submit]"), async () => {
    await api("/api/admin/marketing/codes/save", { id: c ? c.id : 0, code: f.code.value, type: f.type.value, value: f.value.value, min: f.min.value, max_uses: f.max_uses.value, starts: f.starts.value, ends: f.ends.value, note: f.note.value, active: f.active.checked });
    closeSheet(); toast("Saved ✓"); RENDER.marketing("codes"); }); };
  $("sheetBody").querySelectorAll("[data-cd]").forEach(b => b.onclick = async () => {
    if (b.dataset.cd === "copy") copy(link, "Link copied ✓");
    if (b.dataset.cd === "code") copy(c.code, "Code copied ✓");
    if (b.dataset.cd === "del" && await ask("Links with this code stop working.", { title: `Delete ${c.code}?`, ok: "Delete code", danger: true })) await busy(b, async () => { await api(`/api/admin/marketing/codes/${c.id}/delete`, {}); closeSheet(); toast("Deleted"); RENDER.marketing("codes"); });
  });
}

/* ---- referrals ---- */
MK.referrals = async el => {
  const r = await api("/api/admin/marketing/referrals"), s = r.settings;
  const on = +s.friend_cents > 0;
  el.innerHTML = `<section class="grid2" style="margin-top:14px">
      <div class="card kpi"><div class="mono">Friends’ orders</div><div class="v num">${r.friends}</div><span class="sub">${eur(r.friend_off)} given off</span></div>
      <div class="card kpi"><div class="mono">Sales from friends</div><div class="v num">${eurShort(r.revenue)}</div><span class="sub">paid orders</span></div>
      <div class="card kpi"><div class="mono">Rewards sent</div><div class="v num">${r.rewards}</div><span class="sub">${r.rewards_used} used</span></div>
      <div class="card kpi"><div class="mono">Codes handed out</div><div class="v num">${r.codes}</div><span class="sub">one per customer</span></div></section>
    <section class="card"><div class="head"><h2>How it works</h2>${can("settings") ? `<button class="btn sm ghost" data-go="settings:shop">Change amounts</button>` : ""}</div>
      <p class="sub" style="font-size:13px;line-height:1.5;margin:0">${on ? `Every customer gets a code like <b class="mono">LOTTE-D01</b> on their order page. A friend gets <b>${eur(+s.friend_cents)}</b> off their first order with it. When the friend’s order is delivered, the customer gets a personal <b>${eur(+s.referrer_cents)}</b> code by email (works once).`
        : `The referral programme is off (friend amount €0). Set the amounts in Settings → Shop.`}</p></section>
    <section class="card"><div class="head"><h2>Top referrers</h2></div>
      ${r.top.length ? r.top.map(t => `<div class="row" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line);font-size:13px"><span>${esc(t.name || "—")} <span class="mono sub">${esc(t.code)}</span></span><b class="num">${t.uses} friend${t.uses > 1 ? "s" : ""}</b></div>`).join("") : `<p class="sub" style="margin:0">Nobody has brought a friend yet.</p>`}</section>
    <section class="card"><div class="head"><h2>Friends’ orders</h2></div>
      ${r.orders.length ? r.orders.map(o => `<div class="row" role="button" tabindex="0" data-open="${o.id}" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line);font-size:13px;cursor:pointer"><span>#${o.number} · ${esc(o.name || "—")} <span class="sub">via ${esc(o.referrer || o.referral_code)}</span></span><span class="num">${eur(+o.total_cents)}</span></div>`).join("") : `<p class="sub" style="margin:0">No orders with a friend’s code yet.</p>`}</section>`;
  el.onclick = e => { const o = e.target.closest("[data-open]"); if (o) openOrder(+o.dataset.open); };
};

/* ---- email broadcasts ---- */
MK.email = async el => {
  const r = await api("/api/admin/marketing/broadcasts");
  const draft = (() => { try { return JSON.parse(localStorage.getItem("d01-bc") || "null") || {}; } catch { return {}; } })();
  const seg = draft.segment && r.segments[draft.segment] ? draft.segment : "everyone";
  el.innerHTML = `${r.email_ready ? "" : `<p class="note bad" style="margin-top:14px">Email is not set up yet, so nothing can be sent. ${can("settings") ? `<a href="#" data-go="settings:email" style="color:inherit">Settings → Email</a>` : "Ask the owner to set it up."}</p>`}
    <section class="card" style="margin-top:14px"><div class="head"><h2>New email</h2><span class="sub">only to people who said yes</span></div><form id="bcForm">
      <label class="field"><span>To</span><select class="in" name="segment">${Object.entries(r.segments).map(([k, [l, d]]) => `<option value="${k}" ${k === seg ? "selected" : ""}>${esc(l)} · ${r.counts[k]}</option>`).join("")}</select><small id="bcSegHint"></small></label>
      <label class="field"><span>Subject</span><input class="in" name="subject" maxlength="200" value="${esc(draft.subject || "")}" placeholder="Drop 02 opens Friday at 18:00" required></label>
      <label class="field"><span>Heading (optional)</span><input class="in" name="heading" maxlength="200" value="${esc(draft.heading || "")}" placeholder="Hi {first_name}, it’s almost time."></label>
      <label class="field"><span>Message</span><textarea class="in" name="body" rows="7" maxlength="5000" style="font-family:var(--f-b);font-size:14px;min-height:150px" required>${esc(draft.body || "")}</textarea><small>An empty line starts a new paragraph. {first_name} becomes their first name (or “there”).</small></label>
      <div class="cols"><label class="field"><span>Button text (optional)</span><input class="in" name="button_label" maxlength="60" value="${esc(draft.button_label || "")}" placeholder="Shop the drop"></label>
        <label class="field"><span>Button link</span><input class="in" name="button_url" inputmode="url" value="${esc(draft.button_url || "")}" placeholder="${esc(location.origin)}/"></label></div>
      <div class="acts2" style="margin-top:4px"><button class="btn ghost" type="button" data-bc="test" ${r.email_ready ? "" : "disabled"}>Send me a test</button><button class="btn holo" type="submit" id="bcSend" ${r.email_ready ? "" : "disabled"}></button></div>
      <p class="sub" style="margin:10px 2px 0">Every email has an unsubscribe link. Up to <b>${r.per_hour}</b> go out per hour, so your email provider doesn’t block you; the rest follow by themselves.</p>
    </form></section>
    <section class="card"><div class="head"><h2>Sent</h2></div>
      ${r.history.length ? r.history.map(b => `<div style="padding:10px 0;border-top:1px solid var(--line)"><div class="row" style="justify-content:space-between;font-size:13.5px"><b style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(b.subject)}</b><span class="sub" style="flex:none">${esc(shortDate(b.created_at))}</span></div>
        <div class="sub" style="margin-top:3px">${esc((r.segments[b.segment] || [b.segment])[0])} · ✓ ${b.sent} of ${b.recipients} sent${b.waiting ? ` · ${b.waiting} waiting` : ""}${b.failed ? ` · <span style="color:var(--bad)">${b.failed} failed</span>` : ""}${b.cancelled ? ` · ${b.cancelled} unsubscribed` : ""}</div></div>`).join("") : `<p class="sub" style="margin:0">Nothing sent yet.</p>`}</section>
    <section class="card"><div class="head"><h2>Sending limit</h2></div><form id="bcLim" class="row" style="gap:8px;align-items:end">
      <label class="field" style="flex:1;margin:0"><span>Emails per hour</span><input class="in" name="per_hour" inputmode="numeric" value="${r.per_hour}"><small>Keep it under your email provider’s limit (check their help page; it is often a few hundred per hour or per day).</small></label><button class="btn" type="submit" style="margin-bottom:22px">Save</button></form></section>`;
  const f = $("bcForm");
  const vals = () => ({ segment: f.segment.value, subject: f.subject.value, heading: f.heading.value, body: f.body.value, button_label: f.button_label.value, button_url: f.button_url.value });
  const upd = () => { const n = r.counts[f.segment.value]; $("bcSend").textContent = n ? `Send to ${n} ${n === 1 ? "person" : "people"}` : "Nobody to send to"; $("bcSend").disabled = !n || !r.email_ready;
    $("bcSegHint").textContent = r.segments[f.segment.value][1]; try { localStorage.setItem("d01-bc", JSON.stringify(vals())); } catch {} };
  f.oninput = upd; f.onchange = upd; upd();
  f.querySelector("[data-bc=test]").onclick = e => busy(e.target, async () => { const x = await api("/api/admin/marketing/broadcasts/test", vals()); toast("Test sent to " + x.to); });
  f.onsubmit = async e => { e.preventDefault(); const n = r.counts[f.segment.value];
    if (!await ask(`“${f.subject.value}” goes to ${n} ${n === 1 ? "person" : "people"}. This can’t be undone.`, { title: "Send this email?", ok: `Send to ${n}` })) return;
    await busy($("bcSend"), async () => { const x = await api("/api/admin/marketing/broadcasts/send", { ...vals(), expect: n });
      try { localStorage.removeItem("d01-bc"); } catch {} toast(`On its way to ${x.recipients} ✓`); RENDER.marketing("email"); }); };
  $("bcLim").onsubmit = async e => { e.preventDefault(); await busy(e.target.querySelector("button"), async () => { await api("/api/admin/marketing/settings", { per_hour: e.target.per_hour.value }); toast("Saved ✓"); RENDER.marketing("email"); }); };
};

/* ---- back in stock ---- */
MK.restock = async el => {
  const r = await api("/api/admin/marketing/restock");
  el.innerHTML = `<section class="card" style="margin-top:14px"><div class="head"><h2>Waiting for stock</h2>${can("stock") ? `<button class="btn sm ghost" data-go="stock">Open stock</button>` : ""}</div>
      ${r.waiting.length ? r.waiting.map(w => `<div class="row" style="justify-content:space-between;padding:9px 0;border-top:1px solid var(--line);font-size:13.5px"><span><b>${esc(w.name)}</b><br><span class="sub">${w.emails} by email · ${w.people - w.emails} by WhatsApp</span></span><b class="num">${w.people} waiting</b></div>`).join("")
        : `<p class="sub" style="margin:0">Nobody is waiting. When something sells out, the shop shows “Notify me”.</p>`}
      <p class="sub" style="margin:10px 0 0">When you add stock, everyone waiting is told once: by email straight away, WhatsApp-only requests below.</p></section>
    <section class="card"><div class="head"><h2>Send on WhatsApp</h2><span class="sub">${r.whatsapp.length} to send</span></div>
      ${r.whatsapp.length ? r.whatsapp.map(m => `<div class="row" style="gap:10px;padding:9px 0;border-top:1px solid var(--line);font-size:13px" data-wrow="${m.id}"><span style="flex:1;min-width:0"><b class="num">${esc(m.to_addr)}</b><br><span class="sub" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(m.text)}</span></span>
        <a class="btn sm" target="_blank" rel="noopener" href="${esc(m.link)}" data-wa="${m.id}">WhatsApp</a></div>`).join("") : `<p class="sub" style="margin:0">Nothing to send.</p>`}</section>
    <section class="card"><div class="head"><h2>Told lately</h2></div>
      ${r.sent.length ? r.sent.map(s => `<div class="row" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line);font-size:13px"><span>${esc(s.name)}</span><span class="sub">${s.people} people · ${esc(ago(s.at))}</span></div>`).join("") : `<p class="sub" style="margin:0">No back-in-stock messages in the last 30 days.</p>`}</section>`;
  el.onclick = e => { const a = e.target.closest("[data-wa]"); if (a) { api("/api/admin/marketing/wa/" + a.dataset.wa, {}).catch(() => {}); setTimeout(() => { const row = el.querySelector(`[data-wrow="${a.dataset.wa}"]`); if (row) row.remove(); }, 300); } };
};

/* ---- abandoned carts ---- */
MK.carts = async el => {
  const r = await api("/api/admin/marketing/carts");
  el.innerHTML = `<section class="card" style="margin-top:14px">${toggle("abandoned_cart", "Reminder email for left bags", r.settings.abandoned_cart, "One email, an hour after someone left their bag at checkout. Only if they ticked “tell me about the next drop”.")}</section>
    <section class="grid2">
      <div class="card kpi"><div class="mono">Reminders sent</div><div class="v num">${r.reminded}</div><span class="sub">${r.waiting} bag${r.waiting === 1 ? "" : "s"} waiting now</span></div>
      <div class="card kpi"><div class="mono">Won back</div><div class="v num">${r.recovered}</div><span class="sub">${r.reminded ? pct(r.recovered, r.reminded) + "% · " : ""}${eur(r.revenue)}</span></div></section>
    <section class="card"><div class="head"><h2>Latest reminders</h2></div>
      ${r.recent.length ? r.recent.map(c => `<div style="padding:9px 0;border-top:1px solid var(--line);font-size:13px"><div class="row" style="justify-content:space-between"><b style="overflow:hidden;text-overflow:ellipsis">${esc(c.email)}</b><span class="sub" style="flex:none">${esc(ago(c.reminded_at))}</span></div>
        <div class="sub" style="margin-top:3px">${esc(c.items.join(", ") || "items no longer in the shop")} · ${eur(c.value)}${c.recovered_order_id ? ` · <a href="#" data-open="${c.recovered_order_id}" style="color:var(--good)">ordered #${c.number}${c.payment_status === "paid" ? "" : " (not paid yet)"}</a>` : ""}</div></div>`).join("") : `<p class="sub" style="margin:0">No reminders sent yet.</p>`}</section>`;
  el.querySelector("[name=abandoned_cart]").onchange = async e => { try { await api("/api/admin/marketing/settings", { abandoned_cart: e.target.checked }); toast(e.target.checked ? "Reminders on ✓" : "Reminders off"); } catch (err) { toastErr(err.message); e.target.checked = !e.target.checked; } };
  el.onclick = e => { const o = e.target.closest("[data-open]"); if (o) { e.preventDefault(); openOrder(+o.dataset.open); } };
};

/* ================= MONEY → MARGINS ================= */
/* What each product really earns: plan per sale at today's price (purchase + packaging + share of delivery and payment fees),
   real results from paid orders, a verdict per product and reorder hints. Plain arithmetic in Margins.php; this only shows it. */
const MN = { days: 30, sort: "profit", d: null };
const MN_TABS = [["products", "Products"], ["prices", "Price calculator"], ["costs", "Costs & packaging"]];
const MN_DAYS = [[7, "7 days"], [30, "30 days"], [90, "90 days"], [365, "Year"], [0, "All time"]];
const VERDICT = {
  star: ["Best", "good", "Sells well and earns well. Keep it in stock and reorder it first."],
  raise: ["Raise price", "warn", "Sells well, but the margin is under your target. Try a higher price."],
  slow: ["Slow", "blue", "Earns well but sells slowly. Show it more: Instagram, a bundle, featured in the shop."],
  weak: ["Rethink", "ink3", "Thin margin and slow sales. Raise the price, buy it cheaper, or stop selling it."],
  loss: ["Loss", "bad", "You lose money on every sale. Raise the price or cut its costs."],
  no_sales: ["No sales", "ink3", "Not sold in this period."],
  no_price: ["No price", "ink3", "Give it a price in Stock first."],
};
const vtag = v => { const [l, c] = VERDICT[v] || VERDICT.no_sales; return `<span class="tag vd" style="--c:var(--${c})">${l}</span>`; };
const pctTxt = v => v == null ? "–" : String(v).replace(".", ",") + "%";
const signed = c => (c < 0 ? "−" : "") + eur(Math.abs(c));
RENDER.money = async sub => {
  const tab = MN_TABS.some(([k]) => k === sub) ? sub : "products";
  const el = $("s-money");
  el.innerHTML = `<div class="chips" id="mnTabs" style="margin-top:4px">${MN_TABS.map(([k, l]) => `<button class="chip" data-mn="${k}" aria-pressed="${k === tab}">${l}</button>`).join("")}</div><div id="mnBody"><p class="sub" style="margin:18px 2px">Loading…</p></div>`;
  $("mnTabs").onclick = e => { const b = e.target.closest("[data-mn]"); if (!b) return; try { history.replaceState(null, "", "/admin/money#" + b.dataset.mn); } catch {} RENDER.money(b.dataset.mn); };
  try { MN.d = await api("/api/admin/money/margins?days=" + MN.days); MN.tab = tab; MNR[tab]($("mnBody")); }
  catch (e) { $("mnBody").innerHTML = `<p class="sub" style="margin:18px 2px">${esc(e.message)}</p>`; }
};
const MNR = {};
/* a hint when the costs that make the numbers honest are still empty */
function mnSetupNote(){
  const c = MN.d.costs, miss = [];
  if (!c.delivery_cents) miss.push("delivery cost per order");
  if (!MN.d.materials.length) miss.push("packaging");
  const noCost = MN.d.products.filter(p => p.active && !p.cost_cents).map(p => p.name);
  if (noCost.length) miss.push("purchase cost of " + (noCost.length > 2 ? noCost.length + " products" : noCost.join(" and ")));
  return miss.length ? `<p class="note" style="margin:14px 0 0">Not filled in yet: ${esc(miss.join(", "))}. Until then profits look higher than they are. <a href="#" data-go="money:costs">Fill in costs →</a></p>` : "";
}
function mnMixNote(){
  const m = MN.d.mix;
  return m.real ? `Based on your last 90 days (${m.orders} orders): ${String(m.items_per_order).replace(".", ",")} items per order, ${pctTxt(Math.round(m.discount_rate * 1000) / 10)} average discount, ${eur(Math.round(m.delivery_income))} delivery fee paid per order (excl. BTW).`
    : `Fewer than 10 paid orders in 90 days, so this assumes 1 item per order, no discounts and free delivery. It switches to your real numbers by itself later.`;
}
MNR.products = el => {
  const d = MN.d, t = d.totals, c = d.costs;
  const sorts = { profit: p => p.sold ? p.sold.profit : -1e12 + p.unit.profit, margin: p => p.sold ? p.sold.margin : -1e9 + (p.unit.margin || 0), units: p => p.sold ? p.sold.units : -1, stock: p => p.days_left == null ? 1e9 : p.days_left };
  const list = d.products.slice().sort((a, b) => MN.sort === "stock" ? sorts.stock(a) - sorts.stock(b) : sorts[MN.sort](b) - sorts[MN.sort](a));
  const reorder = d.products.filter(p => p.reorder);
  el.innerHTML = `${mnSetupNote()}
    <div class="chips" id="mnDays">${MN_DAYS.map(([v, l]) => `<button class="chip" data-days="${v}" aria-pressed="${v === MN.days}">${l}</button>`).join("")}</div>
    <section class="grid2" style="margin-top:12px">
      <div class="card kpi"><div class="mono">Profit</div><div class="v num" style="${t.profit < 0 ? "color:var(--bad)" : ""}">${signed(t.profit)}</div><span class="sub">${t.orders ? pctTxt(t.margin) + " margin" : "no paid orders yet"}</span></div>
      <div class="card kpi"><div class="mono">Per order</div><div class="v num">${t.per_order != null ? signed(t.per_order) : t.plan_per_order != null ? "≈ " + signed(t.plan_per_order) : "–"}</div><span class="sub">${t.orders ? `${t.orders} order${t.orders == 1 ? "" : "s"} · ${eurShort(t.revenue)} sales` : "planned, at today's prices"}</span></div>
      <div class="card kpi"><div class="mono">After fixed costs</div><div class="v num" style="${c.fixed_month_cents && t.after_fixed < 0 ? "color:var(--bad)" : ""}">${c.fixed_month_cents ? signed(t.after_fixed) : "–"}</div><span class="sub">${c.fixed_month_cents ? `${eur(t.fixed)} fixed costs in ${t.days} days` : `<a href="#" data-go="money:costs">add fixed costs</a>`}</span></div>
      <div class="card kpi"><div class="mono">Break-even</div><div class="v num">${t.break_even != null ? t.break_even : "–"}</div><span class="sub">${t.break_even != null ? `orders a month cover ${eur(c.fixed_month_cents)} fixed costs` : c.fixed_month_cents ? "no profit per order yet" : "orders/month: add fixed costs"}</span></div>
    </section>
    ${reorder.length ? `<section class="card"><div class="head"><h2>Reorder soon</h2><span class="sub">≤ ${c.reorder_days} days of stock</span></div>${reorder.map(p => `<button class="cust" data-mp="${p.id}"><div class="av mn-av">${ICON.box}</div><div style="min-width:0"><div style="font-weight:700;font-size:14px">${esc(p.name)}</div><div class="sub">${p.stock} left · sells ${String(p.per_week).replace(".", ",")}/week · ${p.days_left} day${p.days_left == 1 ? "" : "s"} left</div></div><div style="text-align:right"><div class="num" style="font-weight:700">+${p.reorder}</div><div class="sub">for ${c.cover_days} days</div></div></button>`).join("")}</section>` : ""}
    <section class="card"><div class="head"><h2>Products</h2><span class="sub">tap for the full breakdown</span></div>
      <div class="chips mn-sort" id="mnSort" style="margin-top:0">${[["profit", "Profit"], ["margin", "Margin"], ["units", "Sold"], ["stock", "Runs out first"]].map(([k, l]) => `<button class="chip" data-sort="${k}" aria-pressed="${k === MN.sort}">${l}</button>`).join("")}</div>
      <div class="list" style="margin-top:6px">${list.map(p => { const s = p.sold;
        return `<div class="prod" role="button" tabindex="0" data-mp="${p.id}"><div class="thumb">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : ICON.box}</div>
          <div style="min-width:0"><div class="n">${esc(p.name)}${vtag(p.verdict)}</div>
          <div class="m">${s ? `${s.units} sold · ${pctTxt(s.margin)} margin${p.days_left != null ? ` · ${p.days_left}d of stock` : ""}` : `not sold · plan ${pctTxt(p.unit.margin)} margin`}</div></div>
          <div class="qty"><b class="num" style="${(s ? s.profit : p.unit.profit) < 0 ? "color:var(--bad)" : ""}">${s ? signed(s.profit) : signed(p.unit.profit)}</b><span class="sub">${s ? "profit" : "per sale"}</span></div></div>`; }).join("") || `<p class="sub" style="padding:14px 0;margin:0">No products yet.</p>`}</div></section>
    <section class="card"><div class="head"><h2>What the labels mean</h2></div>
      <div class="mn-legend">${["star", "raise", "slow", "weak", "loss"].map(k => `<div>${vtag(k)}<span>${esc(VERDICT[k][2])}</span></div>`).join("")}</div>
      <p class="sub" style="margin:10px 0 0">Margin target: ${c.target_pct}%. “Sells well” means at least as fast as your middle product. Profit = what you keep after BTW and discounts, minus purchase cost, packaging, delivery and payment fees.</p></section>`;
  el.onclick = e => {
    const dd = e.target.closest("[data-days]"); if (dd) { MN.days = +dd.dataset.days; return RENDER.money("products"); }
    const so = e.target.closest("[data-sort]"); if (so) { MN.sort = so.dataset.sort; return MNR.products(el); }
    const p = e.target.closest("[data-mp]"); if (p) mnProduct(+p.dataset.mp);
  };
};
MNR.prices = el => {
  const d = MN.d, c = d.costs, list = d.products.filter(p => p.active);
  el.innerHTML = `${mnSetupNote()}
    <section class="card" style="margin-top:14px"><div class="head"><h2>Price per product</h2><span class="sub">target ${c.target_pct}% margin</span></div>
      <p class="sub" style="margin:0 0 6px">For one sale at today's price. <b>Lowest</b> = below this you lose money. <b>Target</b> = the price for a ${c.target_pct}% margin, rounded to ,95.</p>
      <div class="list">${list.map(p => { const u = p.unit, low = u.price && u.min_price != null && u.price < u.min_price, under = u.rec_price != null && u.price < u.rec_price;
        return `<div class="mn-price" role="button" tabindex="0" data-mp="${p.id}"><div class="n">${esc(p.name)}</div>
          <div class="mn-cells">
            <div><span>Price</span><b class="num">${eur(u.price)}</b></div>
            <div><span>Costs</span><b class="num">${eur(u.full)}</b></div>
            <div><span>Profit</span><b class="num" style="color:var(--${u.profit < 0 ? "bad" : "good"})">${signed(u.profit)}</b><small>${pctTxt(u.margin)} · markup ${u.markup == null ? "–" : u.markup + "%"}</small></div>
            <div><span>Lowest</span><b class="num" style="${low ? "color:var(--bad)" : ""}">${u.min_price != null ? eur(u.min_price) : "–"}</b></div>
            <div><span>Target</span><b class="num" style="${under ? "color:var(--warn)" : ""}">${u.rec_price != null ? eur(u.rec_price) : "–"}</b></div>
          </div></div>`; }).join("") || `<p class="sub" style="padding:14px 0;margin:0">No products yet.</p>`}</div>
      <p class="sub" style="margin:10px 0 0">${esc(mnMixNote())}</p></section>`;
  el.onclick = e => { const p = e.target.closest("[data-mp]"); if (p) mnProduct(+p.dataset.mp); };
};
MNR.costs = el => {
  const c = MN.d.costs, mats = MN.d.materials;
  const matRow = m => `<button class="cust" data-mat="${m.id}"><div class="av mn-av">${ICON.box}</div><div style="min-width:0"><div style="font-weight:700;font-size:14px">${esc(m.name)}${+m.active ? "" : '<span class="tag" style="background:var(--card2);color:var(--ink3)">off</span>'}</div>
    <div class="sub">${eur(+m.pack_cents)} for ${fmt(m.pack_qty)} · ${m.per === "order" ? "in every order" : m.used ? `used by ${m.used} product${m.used == 1 ? "" : "s"}` : "not on any product yet"}</div></div>
    <div style="text-align:right"><div class="num" style="font-weight:700">${eur3(m.unit)}</div><div class="sub">each</div></div></button>`;
  el.innerHTML = `<form class="card" id="mnCosts" style="margin-top:14px"><div class="head"><h2>Costs per order</h2></div>
      <label class="field"><span>Delivery cost per order (€)</span><input class="in" name="delivery" inputmode="decimal" value="${euroIn(c.delivery_cents)}"><small>What one delivery costs you: courier pay, fuel or bike, per stop. What the customer pays for delivery is subtracted automatically.</small></label>
      <div class="cols"><label class="field"><span>Payment fee per order (€)</span><input class="in" name="fee" inputmode="decimal" value="${euroIn(c.fee_cents)}"></label>
        <label class="field"><span>+ payment fee (%)</span><input class="in" name="fee_pct" inputmode="decimal" value="${String(c.fee_pct).replace(".", ",")}"></label></div>
      <p class="sub" style="margin:-6px 0 12px">Tikkie: the fixed fee per paid Tikkie. Card payments: fixed + %. Cash and bank transfer: 0.</p>
      <label class="field"><span>Fixed costs per month (€)</span><input class="in" name="fixed_month" inputmode="decimal" value="${euroIn(c.fixed_month_cents)}"><small>Costs you pay even without orders: storage, phone, hosting, subscriptions, ads budget. Used for the break-even.</small></label>
      <div class="cols3"><label class="field"><span>Target margin %</span><input class="in" name="target_pct" inputmode="numeric" value="${c.target_pct}"></label>
        <label class="field"><span>Reorder at (days)</span><input class="in" name="reorder_days" inputmode="numeric" value="${c.reorder_days}"></label>
        <label class="field"><span>Buy for (days)</span><input class="in" name="cover_days" inputmode="numeric" value="${c.cover_days}"></label></div>
      <p class="sub" style="margin:-6px 0 12px">Enter costs without the BTW you get back from the tax office. If you can't reclaim BTW, enter what you really pay.</p>
      <button class="btn holo block">Save costs</button></form>
    <section class="card"><div class="head"><h2>Packaging and materials</h2><button class="btn sm" data-mat="0">+ Add</button></div>
      <p class="sub" style="margin:0 0 6px">Enter the price of a pack and how many are in it, the cost of one is worked out. Materials “in every order” (shipping bag, card, tape) count once per order; the others you put on products (tap a product → Packaging).</p>
      ${mats.length ? mats.map(matRow).join("") : `<div class="empty" style="padding:14px 6px"><p>No materials yet. Add your boxes, bags, stickers and cards.</p></div>`}
      ${mats.length ? `<p class="sub" style="margin:10px 0 0">In every order: ${eur(MN.d.order_materials)}</p>` : ""}</section>`;
  $("mnCosts").onsubmit = async e => { e.preventDefault(); const f = e.target;
    await busy(f.querySelector("button"), async () => { await api("/api/admin/money/costs", Object.fromEntries(new FormData(f))); toast("Costs saved ✓"); RENDER.money("costs"); }); };
  el.onclick = e => { const m = e.target.closest("[data-mat]"); if (m) mnMaterial(mats.find(x => x.id == m.dataset.mat)); };
};
const eur3 = c => c > 0 && c < 10 ? "€" + (c / 100).toLocaleString("nl-NL", { minimumFractionDigits: 3, maximumFractionDigits: 3 }) : eur(Math.round(c));
function mnMaterial(m){
  sheet(`<h2 class="t">${m ? esc(m.name) : "New material"}</h2><form id="matForm">
    <label class="field"><span>Name</span><input class="in" name="name" value="${esc(m ? m.name : "")}" placeholder="Mailer box S" required maxlength="120"></label>
    <div class="cols"><label class="field"><span>Pack price (€)</span><input class="in" name="pack_price" inputmode="decimal" value="${m ? euroIn(+m.pack_cents) : ""}" placeholder="24,00"></label>
      <label class="field"><span>Units in the pack</span><input class="in" name="pack_qty" inputmode="numeric" value="${m ? m.pack_qty : 1}"></label></div>
    <p class="sub" id="matEach" style="margin:-4px 0 12px"></p>
    <label class="field"><span>Used</span><select class="in" name="per"><option value="item" ${!m || m.per === "item" ? "selected" : ""}>Per product (you choose which)</option><option value="order" ${m && m.per === "order" ? "selected" : ""}>Once in every order</option></select></label>
    ${m ? toggle("active", "In use", +m.active, "Off: not counted anywhere") : ""}
    <button class="btn holo block">${m ? "Save" : "Add material"}</button>
    ${m ? `<button type="button" class="btn ghost block" style="margin-top:8px;color:var(--bad)" data-del>Delete</button>` : ""}</form>`);
  const f = $("matForm"), each = () => { const p = parseFloat(String(f.pack_price.value).replace(",", ".")) || 0, q = Math.max(1, parseInt(f.pack_qty.value, 10) || 1); $("matEach").textContent = "= " + eur3(p * 100 / q) + " each"; };
  f.oninput = each; each();
  f.onsubmit = async e => { e.preventDefault();
    await busy(f.querySelector(".holo"), async () => { const b = Object.fromEntries(new FormData(f)); if (m) { b.id = m.id; b.active = f.active.checked; }
      await api("/api/admin/money/material", b); closeSheet(); toast("Saved ✓"); RENDER.money("costs"); }); };
  const del = f.querySelector("[data-del]");
  if (del) del.onclick = async () => await ask("Products that use it will no longer count it.", { title: `Delete “${m.name}”?`, ok: "Delete", danger: true }) && busy(del, async () => { await api("/api/admin/money/material/delete", { id: m.id }); closeSheet(); toast("Deleted"); RENDER.money("costs"); });
}
/* one product: the cost breakdown of one sale, prices, a what-if, real results and its packaging */
function mnBreakdown(u, vat){
  const row = (l, v, cls) => `<div class="mn-row ${cls || ""}"><span>${l}</span><b class="num">${v}</b></div>`;
  return `<div class="mn-break">${row("Customer pays", eur(u.price))}${row(`BTW ${vat}%`, "− " + eur(u.vat), "minus")}${u.discount ? row("Discounts (your average)", "− " + eur(u.discount), "minus") : ""}
    ${row("You keep", eur(u.kept), "sum")}${row("Purchase cost", "− " + eur(u.cost), "minus")}${row("Packaging", "− " + eur(u.pack), "minus")}
    ${row("Delivery, its share", "− " + eur(u.delivery), "minus")}${u.opack ? row("Order packaging, its share", "− " + eur(u.opack), "minus") : ""}${row("Payment fee", "− " + eur(u.fee), "minus")}
    ${row("Profit per sale", signed(u.profit), "total " + (u.profit < 0 ? "neg" : "pos"))}</div>
    <p class="sub" style="margin:8px 0 0">Margin ${pctTxt(u.margin)} of what you keep · markup ${u.markup == null ? "–" : u.markup + "%"} on all costs (${eur(u.full)})</p>`;
}
function mnProduct(id){
  const p = MN.d.products.find(x => x.id === id); if (!p) return;
  const u = p.unit, s = p.sold, c = MN.d.costs, canPrice = can("stock");
  const items = MN.d.materials.filter(m => m.per === "item");
  const qtyOf = mid => (p.packaging.find(x => x.material_id == mid) || {}).qty || "";
  sheet(`<h2 class="t">${esc(p.name)} ${vtag(p.verdict)}</h2><p class="sub" style="margin:-4px 0 14px">${esc((VERDICT[p.verdict] || VERDICT.no_sales)[2])}</p>
    <div class="mono" style="margin:0 0 8px">One sale at ${eur(u.price)}</div>${mnBreakdown(u, p.vat_rate)}
    <div class="grid2" style="margin:14px 0"><div class="card kpi"><div class="mono">Lowest price</div><div class="v num" style="font-size:22px">${u.min_price != null ? eur(u.min_price) : "–"}</div><span class="sub">below this you lose money</span></div>
      <div class="card kpi"><div class="mono">For ${c.target_pct}% margin</div><div class="v num" style="font-size:22px">${u.rec_price != null ? eur(u.rec_price) : "–"}</div>${canPrice && u.rec_price && u.rec_price !== u.price ? `<button class="btn sm ghost" data-use="${u.rec_price}" style="margin-top:4px">Use this price</button>` : `<span class="sub">${u.rec_price === u.price ? "that's your price ✓" : "rounded to ,95"}</span>`}</div></div>
    <form id="mnWhat" class="card" style="padding:14px"><div class="mono" style="margin-bottom:10px">What if…</div>
      <div class="cols"><label class="field"><span>Price incl. BTW (€)</span><input class="in" name="price" inputmode="decimal" value="${euroIn(u.price)}"></label>
        <label class="field"><span>Purchase cost (€)</span><input class="in" name="cost" inputmode="decimal" value="${euroIn(p.cost_cents)}"></label></div>
      <div id="mnWhatOut" class="sub">Change a number to see the profit.</div>
      ${canPrice ? `<button type="button" class="btn sm ghost" id="mnWhatUse" hidden style="margin-top:10px">Use this price in the shop</button>` : ""}</form>
    <div class="mono" style="margin:18px 0 8px">Sold · ${esc((MN_DAYS.find(([v]) => v === MN.days) || [0, ""])[1].toLowerCase())}</div>
    ${s ? `<div class="money mn-sold"><div>Units<b class="num">${s.units}</b></div><div>Orders<b class="num">${s.orders}</b></div><div class="profit">Profit<b class="num">${signed(s.profit)}</b></div><div>Margin<b class="num">${pctTxt(s.margin)}</b></div>
        <div>You kept<b class="num">${eur(s.kept)}</b></div><div>All costs<b class="num">${eur(s.costs)}</b></div><div>Per unit<b class="num">${signed(s.per_unit)}</b></div><div>Speed<b class="num">${String(p.per_week).replace(".", ",")}/wk</b></div></div>`
      : `<p class="sub" style="margin:0">No paid orders with this product in this period.</p>`}
    <p class="sub" style="margin:10px 0 0">Stock: ${p.stock} (${eur(p.stock_value)} at cost)${p.days_left != null ? ` · lasts about ${p.days_left} day${p.days_left == 1 ? "" : "s"}` : ""}${p.reorder ? ` · <b style="color:var(--warn)">reorder ${p.reorder}</b> to last ${c.cover_days} days` : ""}</p>
    <div class="mono" style="margin:18px 0 8px">Packaging for one unit</div>
    ${items.length ? `<form id="mnPack">${items.map(m => `<label class="mn-pk"><span>${esc(m.name)}<small>${eur3(m.unit)} each${+m.active ? "" : " · off"}</small></span><input class="in" name="m${m.id}" inputmode="decimal" placeholder="0" value="${String(qtyOf(m.id)).replace(".", ",")}" aria-label="How many ${esc(m.name)}"></label>`).join("")}
        <button class="btn ghost block" style="margin-top:6px">Save packaging</button></form>`
      : `<p class="sub" style="margin:0">No per-product materials yet. <a href="#" data-go="money:costs">Add them in Costs & packaging →</a></p>`}`);
  const w = $("mnWhat"), out = $("mnWhatOut"), use = $("mnWhatUse");
  let tm;
  w.oninput = () => { clearTimeout(tm); tm = setTimeout(async () => {
    try { const r = await api(`/api/admin/money/quote?id=${p.id}&price=${encodeURIComponent(w.price.value)}&cost=${encodeURIComponent(w.cost.value)}`), q = r.unit;
      out.innerHTML = `Profit per sale <b class="num" style="color:var(--${q.profit < 0 ? "bad" : "good"})">${signed(q.profit)}</b> (${signed(q.profit - u.profit)} vs now) · margin ${pctTxt(q.margin)} · markup ${q.markup == null ? "–" : q.markup + "%"}`;
      if (use) { use.hidden = q.price === u.price || q.price <= 0; use.dataset.use = q.price; use.textContent = "Use " + eur(q.price) + " in the shop"; }
    } catch (e) { out.textContent = e.message; } }, 250); };
  w.onsubmit = e => e.preventDefault();
  $("sheetBody").querySelectorAll("[data-use]").forEach(b => b.onclick = async () => {
    const price = +b.dataset.use;
    if (!await ask(`From ${eur(u.price)} to ${eur(price)}. Customers see the new price right away.`, { title: `Change the price of ${p.name}?`, ok: "Change price" })) return;
    busy(b, async () => { await api("/api/admin/money/price", { product_id: p.id, price }); toast("Price changed ✓"); closeSheet(); RENDER.money(MN.tab); });
  });
  const pk = $("mnPack");
  if (pk) pk.onsubmit = async e => { e.preventDefault();
    await busy(pk.querySelector("button"), async () => { await api("/api/admin/money/packaging", { product_id: p.id, items: items.map(m => ({ material_id: m.id, qty: pk["m" + m.id].value })) });
      toast("Packaging saved ✓"); closeSheet(); await RENDER.money(MN.tab); mnProduct(p.id); }); };
}

/* ================= SYSTEM ================= */
RENDER.system = async () => {
  const el = $("s-system");
  let d; try { d = await api("/api/admin/system"); } catch (e) { el.innerHTML = `<p class="sub">${esc(e.message)}</p>`; return; }
  const kb = n => n > 1048576 ? (n / 1048576).toFixed(1) + " MB" : Math.round(n / 1024) + " KB";
  el.innerHTML = `
    <section class="grid2"><div class="card kpi"><div class="mono">Version</div><div class="v" style="font-size:20px">${esc(d.version)}</div><span class="sub">PHP ${esc(d.php)} · ${esc(d.db)}</span></div>
      <div class="card kpi"><div class="mono">Scheduled jobs</div><div class="v" style="font-size:20px;color:${d.cron.ok ? "var(--good)" : "var(--bad)"}">${d.cron.ok ? "✓ Running" : "✕ Not running"}</div><span class="sub">last run ${esc(ago(d.cron.last))}</span></div></section>
    <section class="card"><div class="head"><h2>Updates</h2><span class="sub">${d.pending.length ? d.pending.length + " waiting" : "up to date"}</span></div>
      <p class="sub" style="margin:0 0 10px">Updates to the database run by themselves when you log in. A copy of the database is saved first.</p>
      <button class="btn ${d.pending.length ? "holo" : "ghost"} block" data-sys="migrate">Run updates now</button></section>
    <section class="card"><div class="head"><h2>Backups</h2><span class="sub">nightly, last 14 kept</span></div>
      <p class="sub" style="margin:0 0 10px">Download a backup before every update. It contains the database and all photos.</p>
      <button class="btn holo block" data-sys="backup">Make and download a backup</button>
      <div class="list" style="margin-top:10px">${d.backups.slice(0, 20).map(b => `<div class="row" style="justify-content:space-between;padding:8px 0;font-size:13px"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(b.name)}</span><a class="btn sm ghost" data-sudo href="/api/admin/system/backup?name=${encodeURIComponent(b.name)}">${kb(b.size)} ↓</a></div>`).join("") || `<p class="sub" style="margin:0">No backups yet.</p>`}</div></section>
    <section class="card" id="offsite"><div class="head"><h2>Copy outside this server</h2><span class="sub">${d.offsite.email && d.offsite.pass ? "on, every night" : "off"}</span></div>
      <p class="sub" style="margin:0 0 10px">Every night the database is emailed to an address outside this hosting (for example your Gmail), locked with a password. If the server is hacked or lost, your orders and customers are still safe. Photos are not included.</p>
      ${d.offsite.mail ? "" : `<p class="note bad">Set up email first (Settings → Email).</p>`}
      ${d.offsite.last ? `<p class="note ${d.offsite.last.ok ? "" : "bad"}" style="margin:0 0 10px">${d.offsite.last.ok ? `✓ Last copy sent ${esc(ago(d.offsite.last.at))} (${kb(d.offsite.last.size)})` : `✕ Last try ${esc(ago(d.offsite.last.at))}: ${esc(d.offsite.last.error || "")}`}</p>` : ""}
      <form id="offForm"><label class="field"><span>Send the copy to</span><input class="in" name="email" type="email" value="${esc(d.offsite.email)}" placeholder="you@gmail.com" autocomplete="off"><small>Not the store’s own mailbox. Empty = off.</small></label>
        <label class="field"><span>Backup password ${d.offsite.pass ? "(set; type a new one to change it)" : ""}</span><input class="in" name="password" type="password" autocomplete="new-password" placeholder="${d.offsite.pass ? "••••••••••" : "at least 10 characters"}"><small>Write it down in a safe place, away from this server. Without it nobody can open the copies, not even you.</small></label>
        <div class="row" style="gap:8px"><button class="btn holo" type="submit">Save</button>${d.offsite.email && d.offsite.pass ? `<button class="btn ghost" type="button" data-sys="offsite">Send a copy now</button>` : ""}</div></form></section>
    <section class="card"><div class="head"><h2>Cron job</h2></div>
      ${d.cron.ok ? "" : `<p class="note bad">Add this in cPanel → Cron Jobs → “Once Per Five Minutes”. Without it, failed emails are not retried and no nightly backups are made.</p>`}
      <p class="sub" style="margin:0">Command:</p><pre class="cmd">${esc(d.cron.command)} &gt;/dev/null 2&gt;&amp;1</pre>
      <p class="sub" style="margin:8px 0 0">Or, if your host only allows a web address, call this every 5 minutes (keep it secret):</p><pre class="cmd">${esc(d.cron.url)}</pre></section>
    <section class="card"><div class="head"><h2>Server check</h2></div>${d.checks.map(c => `<div class="chk"><b class="${c.ok ? "okline" : c.optional ? "warnline" : "badline"}">${c.ok ? "✓" : c.optional ? "!" : "✕"}</b><span>${esc(c.name)}</span>${c.ok ? "" : `<small>${esc(c.fix)}</small>`}</div>`).join("")}
      <p class="sub" style="margin:10px 0 0">Data folder: ${esc(d.dataDir)} ${d.dataOutside ? "(outside the website folder ✓)" : "(inside public_html, locked by .htaccess)"} · Max photo upload: ${esc(d.uploadMax)}</p></section>
    <section class="card"><div class="head"><h2>Emails sent</h2></div><p class="sub" style="margin:0">${d.messages.map(m => `${esc(m.status.replace("_", " "))}: ${m.n}`).join(" · ") || "None yet."}</p></section>
    <section class="card"><div class="head"><h2>Demo data</h2></div>
      <p class="sub" style="margin:0 0 10px">${d.demo.products ? `${d.demo.products} demo products are still in the store (and their votes).` : "No demo data left."}</p>
      ${d.demo.products ? `<button class="btn bad block" data-sys="demo">Remove demo data</button>` : ""}</section>`;
  $("offForm").onsubmit = async e => { e.preventDefault(); const f = e.target;
    await busy(f.querySelector("[type=submit]"), async () => { await api("/api/admin/system/offsite", { email: f.email.value, password: f.password.value }); toast("Saved ✓"); RENDER.system(); }); };
  el.onclick = async e => {
    const b = e.target.closest("[data-sys]"); if (!b) return;
    if (b.dataset.sys === "offsite") await busy(b, async () => { await api("/api/admin/system/offsite/send", {}); toast("Copy sent ✓ Check that mailbox."); RENDER.system(); });
    if (b.dataset.sys === "migrate") await busy(b, async () => { const r = await api("/api/admin/system/migrate", {}); toast(r.applied.length ? "Applied: " + r.applied.join(", ") : "Already up to date ✓"); RENDER.system(); });
    if (b.dataset.sys === "backup") await busy(b, async () => { const r = await api("/api/admin/system/backup", {}); location.href = "/api/admin/system/backup?name=" + encodeURIComponent(r.name); setTimeout(RENDER.system, 800); });
    if (b.dataset.sys === "demo" && await ask("All demo products and their votes are removed. This cannot be undone.", { title: "Remove the demo data?", ok: "Remove", danger: true })) await busy(b, async () => { const r = await api("/api/admin/system/demo/remove", {}); toast(`Removed ${r.removed} demo products`); RENDER.system(); });
  };
};

boot();

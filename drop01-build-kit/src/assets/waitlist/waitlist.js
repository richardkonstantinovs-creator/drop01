/* ================= STORE SETTINGS =================
   Everything here comes from the admin (Settings, Products) and is rendered by PHP
   into window.DROP_CONFIG, so the page needs no extra request at boot.
==================================================== */
const CFG = window.DROP_CONFIG || {};
const PREVIEW_MODE = false;
const STORE_NAME = CFG.store || "Drop 01";
const LOGO = CFG.logo || "DROP/01";
const LOGO_SP = LOGO.replace("/", " ");
const DROP_DATE  = CFG.dropDate || "2026-11-27T18:00:00+01:00";
const GIFT = CFG.gift || { title: "A free mystery gift", text: "Packed with your first order, on us.", limit: null };
let WAITLIST_COUNT = typeof CFG.count === "number" ? CFG.count : null;
let GIFTS_LEFT = typeof CFG.giftsLeft === "number" ? CFG.giftsLeft : null;
const PRODUCTS = CFG.products || [];
const MOST_WANTED_MIN = CFG.mostWantedMin || 3;
const MARQUEE = (CFG.marquee && CFG.marquee.length) ? CFG.marquee : [STORE_NAME];
const COUNTRIES = (CFG.countries && CFG.countries.length) ? CFG.countries : [["NL","+31"]];
/* customer-facing texts (Admin → Texts): t() is plain text, th() safe HTML with **bold** and [links](/x); a var given as { h: "<b>…</b>" } is already HTML */
const TX = CFG.t || {};
const escT = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const tvar = (v, k, html) => { const x = v[k]; return x && typeof x === "object" ? (html ? x.h : String(x.h).replace(/<[^>]*>/g, "")) : html ? escT(x) : String(x); };
const fmtT = (s, v = {}, attr = "") => escT(s).replace(/\*\*(.+?)\*\*/g, "<b>$1</b>")
  .replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (m, l, u) => /^(\/(?!\/)|#|https?:\/\/|mailto:|tel:|\{\w+\})/.test(u) ? `<a href="${u}"${attr}>${l}</a>` : m)
  .replace(/\n/g, "<br>").replace(/\{(\w+)\}/g, (m, k) => k in v ? tvar(v, k, 1) : m);
function t(k, v = {}){ return String(TX[k] ?? k).replace(/\*\*(.+?)\*\*/g, "$1").replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, "$1").replace(/\{(\w+)\}/g, (m, n) => n in v ? tvar(v, n) : m); }
function th(k, v, attr){ return fmtT(String(TX[k] ?? k), v, attr); }
const GIFT_LOW = String((CFG.gift && CFG.gift.title) || "").toLowerCase();
/* ================================================== */

/* ---------- analytics: cookieless events via sendBeacon (session id lives in sessionStorage only) ---------- */
const Track = (function(){
  let sid = null;
  try { sid = sessionStorage.getItem("drop01-sid"); } catch {}
  if (!sid) { sid = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()).replace(/-/g, "").slice(0, 24); try { sessionStorage.setItem("drop01-sid", sid); } catch {} }
  const q = []; let t = null; const once = {};
  function flush(){
    t = null; if (!q.length) return;
    const body = JSON.stringify({ sid, events: q.splice(0, 25) });
    try { if (!(navigator.sendBeacon && navigator.sendBeacon("/api/e", new Blob([body], { type: "text/plain" })))) fetch("/api/e", { method: "POST", body, keepalive: true }); } catch {}
  }
  function send(type, extra){ q.push(Object.assign({ type, path: location.pathname }, extra || {})); if (!t) t = setTimeout(flush, 400); }
  addEventListener("pagehide", () => { send("leave"); flush(); });
  document.addEventListener("visibilitychange", () => { if (document.hidden) flush(); });
  const sp = new URLSearchParams(location.search);
  const utm = { source: sp.get("utm_source") || "", medium: sp.get("utm_medium") || "", campaign: sp.get("utm_campaign") || "", content: sp.get("utm_content") || "" };
  try { if (utm.source) sessionStorage.setItem("drop01-utm", JSON.stringify(utm)); else Object.assign(utm, JSON.parse(sessionStorage.getItem("drop01-utm") || "{}")); } catch {}
  const ref = document.referrer && !document.referrer.startsWith(location.origin) ? document.referrer : "";
  send("pageview", { ref, utm });
  return { send, once(type, extra){ if (once[type]) return; once[type] = 1; send(type, extra); }, sid, utm, ref };
})();

/* ---------- API helper ---------- */
async function api(path, body){
  const r = await fetch(path, body === undefined ? { cache: "no-store" } : { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  let j = null; try { j = await r.json(); } catch {}
  if (!r.ok) { const e = new Error((j && j.error) || t("common.error")); e.status = r.status; e.field = j && j.field; throw e; }
  return j;
}
const T0 = Date.now();

const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
const wait = ms => new Promise(r => setTimeout(r, reduce ? 0 : ms));
const store = {
  get(k){ try { return JSON.parse(localStorage.getItem(k)); } catch { return null; } },
  set(k,v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch {} },
  del(k){ try { localStorage.removeItem(k); } catch {} },
};

/* ---------- copy from settings ---------- */
$("lead").innerHTML = th("wl.lead", { store: STORE_NAME, gift: GIFT.title.toLowerCase() });
$("gift-title").textContent = GIFT.title;
$("gift-name").textContent = GIFT.title;
$("gift-text").textContent = GIFT.text;
function scarceLine(){ $("scarce").textContent = GIFT.limit ? (GIFTS_LEFT === 0 ? t("wl.scarce_out") : t("wl.scarce", { limit: GIFT.limit })) : ""; }
scarceLine();
$("final-text").textContent = t("wl.final.text", { store: STORE_NAME, gift: GIFT.title.toLowerCase() });
$("foot-name").textContent = t("wl.foot.copy", { year: new Date().getFullYear(), store: STORE_NAME });
if (typeof WAITLIST_COUNT === "number") $("proof").textContent = t("wl.proof", { n: WAITLIST_COUNT.toLocaleString("en-US") });

/* ---------- products ---------- */
const maxQty = Math.max(...PRODUCTS.map(p => p.qty), 1);
$("grid").innerHTML = PRODUCTS.map((p, i) => {
  const n = String(i + 1).padStart(2, "0");
  const id = esc(p.id || "item-" + n);
  return `<article class="card" data-id="${id}">
    <div class="ph" data-id="${id}">
      ${p.image && !p.reveal ? `<img class="tease" src="${esc(p.image)}" alt="" loading="lazy"><span class="num display">${n}</span><span class="q">${th("wl.item.peek")}</span>`
      : p.image ? `<img src="${esc(p.image)}"${p.image_lg ? ` srcset="${esc(p.image)} 600w, ${esc(p.image_lg)} 1600w" sizes="(min-width:700px) 300px, 50vw"` : ""} alt="${esc(p.name)}" loading="lazy">`
                : `<div class="sealed"></div><span class="strip"><i>${th("wl.item.tear")}</i></span><span class="num display">${n}</span>`}
      ${p.reveal ? "" : `<span class="seal" aria-hidden="true"><svg viewBox="0 0 100 100"><defs><path id="sp${n}" d="M50 50m-36 0a36 36 0 1 1 72 0a36 36 0 1 1-72 0"/></defs><circle cx="50" cy="50" r="48" fill="url(#holoGrad)"/><circle cx="50" cy="50" r="48" fill="none" stroke="rgba(18,18,22,.25)"/><text><textPath href="#sp${n}" textLength="222" lengthAdjust="spacing">${th("wl.item.seal", { logo: LOGO_SP.toUpperCase(), store: STORE_NAME })}</textPath></text></svg><b>${n}</b></span>`}
      <span class="tag">${esc(STORE_NAME)}</span>
    </div>
    <h3 class="display">${p.reveal ? esc(p.name) : th("wl.item.mystery", { n })}</h3>
    <div class="meta"><span>${esc(p.note || "")}</span><span class="qty">${p.qty ? th("wl.item.edition", { n: p.qty }) : th("wl.item.limited")}</span></div>
    <div class="vote" data-id="${id}">
      <button type="button" class="vbtn up" data-v="1" aria-pressed="false" aria-label="I want this"><svg><use href="#i-up"/></svg><span class="vn">0</span></button>
      <button type="button" class="vbtn down" data-v="-1" aria-pressed="false" aria-label="Not for me"><svg><use href="#i-down"/></svg><span class="vn">0</span></button>
    </div>
    <p class="vline">${th("wl.vote.first")}</p>
  </article>`;
}).join("");
const words = MARQUEE.map(w => `<span>${esc(w)}</span>`).join("");
$("marquee").innerHTML = words + words;
$("marquee2").innerHTML = words + words;

/* ---------- fit display text ---------- */
function fitH1(){
  const h1 = $("h1"), base = 100;
  h1.style.fontSize = base + "px";
  const widest = Math.max(...[...h1.querySelectorAll(".line > span")].map(s => s.offsetWidth));
  const max = innerWidth >= 901 ? 132 : 82;
  h1.style.fontSize = Math.min(max, Math.floor(base * h1.clientWidth / widest * 0.97)) + "px";
}
function fitCount(){
  const ns = [...document.querySelectorAll(".unit .n")]; if (!ns.length) return;
  const base = 60; let ratio = Infinity;
  ns.forEach(n => n.style.fontSize = base + "px");
  ns.forEach(n => { const u = n.parentElement; ratio = Math.min(ratio, (u.clientWidth - 14) / (n.querySelector(".meas") || n).offsetWidth); });
  const size = Math.min(innerWidth >= 901 ? 64 : 48, Math.floor(base * ratio * 0.95));
  ns.forEach(n => n.style.fontSize = size + "px");
}
(function(){
  const d = $("drops"), txt = d.textContent, n = txt.length;
  d.innerHTML = txt.split("").map((ch, i) => `<span class="dl${ch === "." ? " dlp" : ""}" style="--i:${i};--bs:${n * 100}%;--bp:${n > 1 ? i / (n - 1) * 100 : 0}%;--r:${(Math.random() * 30 - 15).toFixed(1)}deg"><span class="jl">${ch}</span></span>`).join("");
  d.setAttribute("aria-label", txt);
  // a little shimmer every few seconds once it has landed
  setInterval(() => { if (document.hidden || reduce) return; d.classList.remove("shine"); void d.offsetWidth; d.classList.add("shine"); }, 6500);
  setInterval(() => {
    if (document.hidden || reduce || document.documentElement.classList.contains("booting")) return;
    const js = d.querySelectorAll(".jl"), j = js[(Math.random() * js.length) | 0]; if (!j) return;
    j.classList.remove("hop"); void j.offsetWidth; j.classList.add("hop");
  }, 4200);
})();
const fitAll = () => { fitH1(); fitCount(); };
fitAll();
(document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fitAll);
let rz; addEventListener("resize", () => { clearTimeout(rz); rz = setTimeout(() => { fitAll(); sizeCanvases(); }, 80); });

/* ---------- countdown ---------- */
const target = new Date(DROP_DATE).getTime();
$("drop-date").textContent = new Date(target).toLocaleString("en-GB", {day:"numeric", month:"short", hour:"2-digit", minute:"2-digit"});
const last = {}; let daysLen = 0;
function setUnit(id, val){
  const el = $(id), prev = last[id];
  if (prev === val) return;
  last[id] = val;
  if (!el.firstChild || reduce || prev === undefined) {
    el.innerHTML = `<span class="meas">${val}</span><span class="fh t st"><b>${val}</b></span><span class="fh b sb"><b>${val}</b></span><span class="fh t ft"><b>${val}</b></span><span class="fh b fb"><b>${val}</b></span>`;
    return;
  }
  el.classList.remove("go");
  el.querySelector(".meas").textContent = val;
  el.querySelector(".st b").textContent = val;
  el.querySelector(".sb b").textContent = prev;
  el.querySelector(".ft b").textContent = prev;
  el.querySelector(".fb b").textContent = val;
  void el.offsetWidth; el.classList.add("go");
  if (id === "cd-s" && window.sfx && window.__countVisible) sfx.tick();
  clearTimeout(el._t); el._t = setTimeout(() => { el.querySelector(".sb b").textContent = val; }, 640);
}
function tickCountdown(){
  let diff = Math.max(0, target - Date.now());
  if (diff === 0) {
    $("count").outerHTML = `<div class="live display"><span>${th("wl.count.live")}</span></div>`;
    $("sticky-cd").textContent = t("wl.count.live_short"); $("foil-cd").textContent = t("wl.count.foil_live");
    $("status-text").textContent = t("wl.status_live");
    return false;
  }
  const d = Math.floor(diff / 864e5); diff %= 864e5;
  const h = Math.floor(diff / 36e5);  diff %= 36e5;
  const m = Math.floor(diff / 6e4);   diff %= 6e4;
  const s = Math.floor(diff / 1e3);
  const p = n => String(n).padStart(2, "0");
  setUnit("cd-d", p(d)); setUnit("cd-h", p(h)); setUnit("cd-m", p(m)); setUnit("cd-s", p(s));
  if (p(d).length !== daysLen) { daysLen = p(d).length; fitCount(); }
  $("sticky-cd").textContent = t("wl.sticky.cd", { time: `${d}d ${p(h)}:${p(m)}:${p(s)}` });
  $("foil-cd").textContent = `${d}d ${p(h)}:${p(m)}:${p(s)}`;
  const fc = $("final-cd"); if (fc) fc.textContent = `${d}d ${p(h)}h ${p(m)}m ${p(s)}s`;
  return true;
}
if (tickCountdown()) { const iv = setInterval(() => { if (!tickCountdown()) clearInterval(iv); }, 1000); }
if ("IntersectionObserver" in window) new IntersectionObserver(([e]) => { window.__countVisible = e.isIntersecting; }).observe($("count"));

/* ---------- smoke engine (shared sprites) ---------- */
const DPR = 0.5; // smoke is soft, render at half resolution for speed
function makePuff(r, g, b){
  const s = 256, c = document.createElement("canvas"); c.width = c.height = s;
  const x = c.getContext("2d");
  for (let i = 0; i < 14; i++) {
    const px = s/2 + (Math.random() - .5) * s * .35, py = s/2 + (Math.random() - .5) * s * .35;
    const pr = s * (.18 + Math.random() * .3);
    const gr = x.createRadialGradient(px, py, 0, px, py, pr);
    gr.addColorStop(0, `rgba(${r},${g},${b},.16)`); gr.addColorStop(1, `rgba(${r},${g},${b},0)`);
    x.fillStyle = gr; x.beginPath(); x.arc(px, py, pr, 0, Math.PI * 2); x.fill();
  }
  return c;
}
const PUFFS = [makePuff(214,214,224), makePuff(214,214,224), makePuff(220,200,255), makePuff(190,220,255), makePuff(255,205,228)];
const amb = $("ambient"), actx = amb.getContext("2d");
const intro = { el: null, cv: null, ctx: null };
function sizeCanvases(){
  [amb, conf].forEach(c => { if (!c) return; const k = c === conf ? Math.min(devicePixelRatio || 1, 1.5) : DPR; c.width = Math.ceil(innerWidth * k); c.height = Math.ceil(innerHeight * k); c._k = k; });
}

/* ---------- smoke intro: domain-warped noise shader with a burning, iridescent dissolve ---------- */
let introDone = false;
const FRAG = `
#ifdef GL_FRAGMENT_PRECISION_HIGH
precision highp float;
#else
precision mediump float;
#endif
uniform vec2 r, m; uniform float t, a, ms, tl, amp, tx, sep, pf, k;
float h(vec2 q){ return fract(sin(dot(q, vec2(127.1, 311.7))) * 43758.5453); }
float n(vec2 x){ vec2 i = floor(x), f = fract(x); f = f*f*(3.0-2.0*f);
  return mix(mix(h(i), h(i+vec2(1.0,0.0)), f.x), mix(h(i+vec2(0.0,1.0)), h(i+vec2(1.0,1.0)), f.x), f.y); }
float fbm(vec2 x){ float v = 0.0, s = 0.5; mat2 mm = mat2(1.6, 1.2, -1.2, 1.6);
  for (int i = 0; i < 5; i++){ v += s*n(x); x = mm*x; s *= 0.5; } return v; }
float jag(float x){ return 0.5*sin(x*37.0+1.3) + 0.3*sin(x*91.0+4.1) + 0.15*sin(x*203.0+2.2) + 0.05*sin(x*411.0+0.7); }
void main(){
  float sx = gl_FragCoord.x / r.x;
  float ly = tl - jag(sx) * amp;                     // tear line (GL pixels, y up)
  float y = gl_FragCoord.y, srcY = y, alpha = 1.0, edge = 1e4;
  if (sep > 0.0) {
    if (y >= ly + sep) { srcY = y - sep; edge = y - (ly + sep); }
    else if (y <= ly - sep) { srcY = y + sep; edge = (ly - sep) - y; }
    else alpha = 0.0;
  }
  vec2 uv = (vec2(gl_FragCoord.x, srcY) - 0.5*r) / min(r.x, r.y);
  float rad = length(uv);
  vec2 dir = rad > 0.0001 ? uv/rad : vec2(0.0);
  vec2 w = uv;
  vec2 dm = w - m; float fall = exp(-dot(dm, dm) * 7.0) * ms;
  w += vec2(-dm.y, dm.x) * fall * 0.9;               // finger stirs the smoke
  float T = t*0.6;
  vec2 q  = vec2(fbm(w*1.8 + vec2(0.0, T*0.25)), fbm(w*1.8 + vec2(5.2, 1.3) - T*0.2));
  vec2 rr = vec2(fbm(w*1.8 + 3.5*q + vec2(1.7, 9.2) + T*0.35), fbm(w*1.8 + 3.5*q + vec2(8.3, 2.8) - T*0.3));
  float d = fbm(w*1.8 + 3.5*rr + vec2(0.0, -T*0.4));
  float d2 = fbm(w*0.85 + 2.0*q + vec2(T*0.12, -T*0.08));
  d = mix(d, d2, 0.32);
  float dens = smoothstep(0.22, 0.88, d);
  vec3 holo = 0.5 + 0.5*cos(6.2832*(d*1.2 + length(rr)*0.6 + t*0.04 + vec3(0.0, 0.33, 0.67)));
  holo = mix(holo, vec3(1.0), 0.55);
  vec3 col = mix(vec3(0.02, 0.02, 0.028), vec3(0.6, 0.6, 0.66), dens*dens);
  col += holo * pow(dens, 3.0) * 0.32;
  col *= a;
  float glow = exp(-rad*rad*4.5) * (0.4 + 0.12*sin(t*2.2)) * a;
  col += glow * mix(vec3(1.0, 0.9, 0.72), holo, 0.5) * (0.6 + dens);
  float shafts = n(dir*3.0 + vec2(t*0.18, 0.0)) * n(dir*7.0 + vec2(5.0, -t*0.14));
  shafts = pow(shafts, 1.4) * exp(-rad*1.5);
  col += shafts * mix(vec3(1.0, 0.92, 0.76), holo, 0.4) * (0.35 + dens) * 0.9 * a;
  col *= 1.0 - 0.38*rad;
  vec3 warm = vec3(1.0, 0.93, 0.8);
  float dist = abs(y - ly);
  // perforation, before the tear reaches it
  float untorn = step(tx, sx);
  float dash = step(0.45, fract(gl_FragCoord.x / (11.0*k)));
  col += warm * dash * exp(-dist*dist / (1.6*k*k)) * pf * untorn * 0.9;
  // the tear: glowing crack behind the head, a white-hot head with sparks
  if (sep <= 0.0 && tx > 0.0) {
    float torn = 1.0 - untorn;
    col += mix(warm, holo, 0.35) * exp(-dist*dist / (4.0*k*k)) * torn * 2.2;
    col += mix(warm, holo, 0.5) * exp(-dist*dist / (90.0*k*k)) * torn * 0.45;
    float hx = (sx - tx) * r.x;
    col += vec3(1.0) * exp(-(hx*hx + dist*dist) / (90.0*k*k)) * step(tx, 0.999) * 2.2;
  }
  // torn paper edges once the halves part
  if (sep > 0.0 && alpha > 0.0) {
    float fib = n(vec2(gl_FragCoord.x * 0.6 / k, srcY * 0.02));
    float e1 = 1.0 - smoothstep(0.0, (1.5 + 2.5*fib) * k, edge);
    col = mix(col, mix(warm, holo, 0.4) * 1.2, e1);
    col *= 1.0 - 0.45 * exp(-edge / (14.0*k)) * (1.0 - e1);       // shadow just inside the rip
    col += mix(warm, holo, 0.6) * exp(-edge / (6.0*k)) * 0.35;
  }
  col += (h(gl_FragCoord.xy + t) - 0.5) * 0.05;     // film grain
  gl_FragColor = vec4(col*alpha, alpha);
}`;
function makeGL(cv){
  const gl = cv.getContext("webgl", { alpha: true, premultipliedAlpha: true, antialias: false });
  if (!gl) return null;
  const sh = (type, src) => { const o = gl.createShader(type); gl.shaderSource(o, src); gl.compileShader(o); return gl.getShaderParameter(o, gl.COMPILE_STATUS) ? o : null; };
  const vs = sh(gl.VERTEX_SHADER, "attribute vec2 v;void main(){gl_Position=vec4(v,0.,1.);}"), fs = sh(gl.FRAGMENT_SHADER, FRAG);
  if (!vs || !fs) return null;
  const pr = gl.createProgram(); gl.attachShader(pr, vs); gl.attachShader(pr, fs); gl.linkProgram(pr);
  if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) return null;
  gl.useProgram(pr);
  const buf = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, buf);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 1,-1, -1,1, 1,1]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(pr, "v"); gl.enableVertexAttribArray(loc); gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const u = k => gl.getUniformLocation(pr, k);
  return { gl, ur: u("r"), ut: u("t"), ua: u("a"), um: u("m"), ums: u("ms"), utl: u("tl"), uamp: u("amp"), utx: u("tx"), usep: u("sep"), upf: u("pf"), uk: u("k") };
}
/* logo as particles: sample the wordmark, fly in as glowing dust, resolve into crisp type, burst as light streaks */
const PCOLS = ["#F3F1EC","#FFB3D9","#FFE7A3","#B8FFD9","#A8DCFF","#D4B8FF"];
function glowSprite(col){
  const c = document.createElement("canvas"); c.width = c.height = 32; const x = c.getContext("2d");
  const g = x.createRadialGradient(16, 16, 0, 16, 16, 16);
  g.addColorStop(0, "#fff"); g.addColorStop(.18, col); g.addColorStop(.45, col + "66"); g.addColorStop(1, col + "00");
  x.fillStyle = g; x.fillRect(0, 0, 32, 32); return c;
}
async function buildLogoPoints(W, H, k){
  try { await Promise.race([document.fonts.load('800 100px "Bricolage Grotesque"'), new Promise(r => setTimeout(r, 700))]); } catch {}
  const text = LOGO;
  const c = document.createElement("canvas"), x = c.getContext("2d");
  const maxW = Math.min(W * 0.84, 980 * k);
  let fs = 200 * k;
  const setF = (ctx) => { ctx.font = `800 ${fs}px "Bricolage Grotesque", "Arial Narrow", sans-serif`; try { ctx.fontStretch = "condensed"; } catch {} };
  setF(x); fs = fs * maxW / x.measureText(text).width; setF(x);
  const tw = Math.ceil(x.measureText(text).width);
  c.width = tw + 4; c.height = Math.ceil(fs * 1.05);
  const base = Math.ceil(fs * 0.86);
  setF(x); x.fillStyle = "#fff"; x.fillText(text, 2, base);
  const img = x.getImageData(0, 0, c.width, c.height).data;
  const target = innerWidth < 700 ? 1500 : 2400;
  let filled = 0; for (let i = 3; i < img.length; i += 4) if (img[i] > 128) filled++;
  const step = Math.max(2, Math.round(Math.sqrt(filled / target)));
  const ox = Math.round((W - c.width) / 2), oy = Math.round((H - c.height) / 2 - H * 0.03);
  const pts = [];
  for (let y = 0; y < c.height; y += step) for (let xx = 0; xx < c.width; xx += step) {
    if (img[(y * c.width + xx) * 4 + 3] > 128) {
      const a = Math.random() * 6.283, rr = (0.55 + Math.random() * 0.8) * Math.max(W, H);
      const jx = (Math.random() - .5) * step * .6, jy = (Math.random() - .5) * step * .6;
      pts.push({ tx: ox + xx + jx, ty: oy + y + jy, sx: W/2 + Math.cos(a) * rr, sy: H/2 + Math.sin(a) * rr * .8,
        d: Math.random() * 0.35 + (xx / c.width) * 0.3, s: (1.3 + Math.random() * 1.4) * k,
        ci: Math.random() < 0.5 ? 0 : 1 + Math.floor(xx / c.width * 5), vx: 0, vy: 0, ph: Math.random() * 6.283 });
    }
  }
  // crisp wordmark, pre-rendered once with its glow
  const pad = Math.ceil(40 * k), tc = document.createElement("canvas"), t2 = tc.getContext("2d");
  tc.width = c.width + pad * 2; tc.height = c.height + pad * 2;
  setF(t2);
  const gr = t2.createLinearGradient(pad, 0, pad + c.width, 0);
  gr.addColorStop(0, "#F3F1EC"); gr.addColorStop(.35, "#FFF6DC"); gr.addColorStop(.5, "#E4FFF1"); gr.addColorStop(.65, "#E6F2FF"); gr.addColorStop(1, "#F3F1EC");
  t2.shadowColor = "rgba(255,231,163,.55)"; t2.shadowBlur = 34 * k;
  t2.fillStyle = gr; t2.fillText(text, pad + 2, pad + base);
  t2.shadowBlur = 0; t2.fillText(text, pad + 2, pad + base);
  return { pts, bottom: oy + c.height, top: oy, txt: tc, tx0: ox - pad, ty0: oy - pad, w: c.width };
}
/* intro: the logo forms in the smoke, the visitor tears the drop open along a perforation */
const jagCss = x => 0.5*Math.sin(x*37+1.3) + 0.3*Math.sin(x*91+4.1) + 0.15*Math.sin(x*203+2.2) + 0.05*Math.sin(x*411+0.7);
function startIntro(){
  if (reduce || window.__skipIntro) { finishIntro(); if (window.__skipIntro) setTimeout(() => { $("stage").scrollIntoView({ block: "start" }); try { $("email").focus({ preventScroll: true }); } catch {} }, 60); return; }
  let seen = false; try { seen = !PREVIEW_MODE && sessionStorage.getItem("drop01-intro") === "1"; } catch {}
  document.documentElement.classList.add("booting");
  const limit = (GIFT && GIFT.limit) ? String(GIFT.limit).padStart(3, "0") : "500";
  const el = document.createElement("div"); el.id = "intro";
  el.innerHTML = `<canvas></canvas><canvas class="pts"></canvas><div class="flash"></div>
    <div class="pk-wrap"><div class="pk" id="pk">
      <div class="pk-shadow"></div>
      <div class="pk-ring"></div>
      <div class="pk-beams"></div><div class="pk-glow"></div>
      <div class="pk-light"></div>
      <div class="pk-body"><canvas class="pk-gl"></canvas><div class="pk-pillow"></div><div class="pk-crinkle b"></div><div class="pk-spec"></div><div class="pk-shade"></div><div class="pk-glint"></div>
        <div class="pk-print"><small class="pk-k1">${th("wl.intro.kicker", { store: STORE_NAME })}</small><span class="pk-logo">${esc(LOGO)}</span><span class="pk-rule"></span><span class="pk-gift">${th("wl.intro.gift", { store: STORE_NAME })}</span><small class="pk-no">${th("wl.intro.no", { limit })}</small></div>
        <div class="pk-perf"></div>
      </div>
      <div class="pk-guide"></div>
      <div class="pk-sb"><canvas class="pk-cb"></canvas><div class="pk-crinkle"></div><div class="pk-shade"></div><div class="pk-strip-print"><span>${th("wl.intro.tear")}</span><span>${esc(STORE_NAME)}</span></div></div>
      <div class="pk-sa"><canvas class="pk-ca"></canvas><div class="pk-crinkle"></div><div class="pk-shade"></div><div class="pk-strip-print"><span>${th("wl.intro.tear")}</span><span>${esc(STORE_NAME)}</span></div></div>
    </div></div>
    <small class="intro-sub intro-chrome" id="intro-sub">${th("wl.intro.sub", { store: STORE_NAME })}</small>
    <div class="tear-hint" id="tear-hint"><svg viewBox="0 0 24 24"><circle cx="6" cy="7" r="3"/><circle cx="6" cy="17" r="3"/><path d="M8.6 8.6 20 18M8.6 15.4 20 6"/></svg><span>${th("wl.intro.swipe")}</span><i></i></div>
    <span class="intro-skip intro-chrome">${th("wl.intro.skip")}</span>
    <div class="intro-meta intro-chrome"><div><span>${th("wl.intro.unsealing", { store: STORE_NAME })}</span><span id="intro-pct">000</span></div><div class="track"><i></i></div></div>`;
  document.body.appendChild(el);
  Track.once("intro_shown");
  intro.el = el; intro.cv = el.querySelector("canvas");
  const pc = el.querySelector(".pts"), px = pc.getContext("2d");
  const pk = el.querySelector("#pk"), wrapEl = el.querySelector(".pk-wrap");
  const body = pk.querySelector(".pk-body"), sa = pk.querySelector(".pk-sa"), sb = pk.querySelector(".pk-sb");
  const glC = pk.querySelector(".pk-gl"), cA = pk.querySelector(".pk-ca"), cB = pk.querySelector(".pk-cb");
  const hintEl = el.querySelector("#tear-hint"), sub = el.querySelector("#intro-sub");
  const pctEl = el.querySelector("#intro-pct"), meta = el.querySelector(".intro-meta");
  const R = makeGL(intro.cv);
  const kP = Math.min(devicePixelRatio || 1, 1.5);
  const AUTO = seen ? 600 : 1500, TEAR_AUTO = 560;

  // ---------- pack geometry ----------
  let W, H, SH, CW, CH, kH = Math.min(devicePixelRatio || 1, 1.5);
  const tooth = 7, depth = 4.5;
  function crimp(y0, down, x0 = 0, x1 = W){            // zigzag edge points from x0 to x1
    const pts = []; for (let x = x0; x <= x1 + .1; x += tooth / 2) pts.push([x, y0 + (Math.round(x / (tooth / 2)) % 2 ? (down ? depth : -depth) : 0)]);
    return pts;
  }
  const jagY = x => SH + jagCss(x / W) * 4;
  function poly(pts){ return "polygon(" + pts.map(p => p[0].toFixed(1) + "px " + p[1].toFixed(1) + "px").join(",") + ")"; }
  function layout(){
    W = Math.round(Math.min(innerWidth * .62, 290, innerHeight * .44)); H = Math.round(W * 1.46); SH = Math.round(H * .13);
    Object.assign(pk.style, { width: W + "px", height: H + "px" });
    const print = pk.querySelector(".pk-print");
    Object.assign(print.style, { paddingTop: (SH + H * .07) + "px", gap: (H * .028) + "px" });
    const lg = print.querySelector(".pk-logo"); let fs = W * .27; lg.style.fontSize = fs + "px"; lg.style.whiteSpace = "nowrap";
    const fit = () => { fs = W * .27; lg.style.fontSize = fs + "px"; const w = lg.scrollWidth; if (w > W * .84) lg.style.fontSize = (fs * W * .84 / w) + "px"; };
    fit(); (document.fonts && document.fonts.ready || Promise.resolve()).then(fit);
    print.querySelectorAll("small").forEach(n => n.style.fontSize = Math.max(8, W * .034) + "px");
    print.querySelector(".pk-gift").style.fontSize = Math.max(11, W * .055) + "px";
    print.querySelector(".pk-no").style.marginTop = (H * .2) + "px";
    pk.querySelector(".pk-perf").style.top = SH + "px";
    pk.querySelectorAll(".pk-strip-print").forEach(n => n.style.height = SH + "px");
    const sh = pk.querySelector(".pk-shadow"); sh.style.top = (H + 18) + "px";
    pk.querySelector(".pk-beams").style.bottom = (H - SH) + "px";
    const gw = pk.querySelector(".pk-glow"); gw.style.top = (SH - 30) + "px";
    pk.querySelector(".pk-light").style.top = (SH - 60) + "px";
    const g = pk.querySelector(".pk-guide"); g.style.top = SH + "px"; g.style.setProperty("--gw", (W * .92) + "px");
    CW = W * .72; CH = CW * 1.375;
    pk.querySelectorAll(".pk-card").forEach(c => {
      Object.assign(c.style, { width: CW + "px", height: CH + "px", left: (W - CW) / 2 + "px", top: (SH + 6) + "px" });
      c.querySelector("small").style.fontSize = Math.max(7, CW * .045) + "px";
      c.querySelector(".big").style.fontSize = (c.classList.contains("c2") ? CW * .62 : CW * .36) + "px";
      c.querySelector("b").style.fontSize = Math.max(10, CW * .075) + "px";
    });
    [glC].forEach(c => { c.width = Math.round(W * kH); c.height = Math.round(H * kH); });
    [cA, cB].forEach(c => { c.width = Math.round(W * kH); c.height = Math.round(SH * kH); c.style.height = SH + "px"; });
    sa.style.height = sb.style.height = SH + "px";
    sub.style.top = (innerHeight / 2 - H / 2 - 52) + "px";
    hintEl.style.top = (innerHeight / 2 + H / 2 + 46) + "px";
    clipFor(0);
  }
  // body: crimped bottom, top edge = perforation (straight) where untorn, rip where torn
  // strip A: the torn part (lifts away), strip B: the part still attached
  function clipFor(t){
    const tx = t * W;
    const ripPts = []; for (let x = 0; x <= tx + .1; x += 3) ripPts.push([x, jagY(x)]);
    const bodyTop = [...ripPts, [tx, SH], [W, SH]];
    const bottom = crimp(H, false).reverse().map(([x, y]) => [x, y - depth]);
    body.style.clipPath = poly([[0, SH], ...bodyTop, ...bottom]);
    const top = crimp(depth, false);
    sb.style.clipPath = poly([...top.filter(p => p[0] >= tx - .1), [W, SH], [Math.max(tx, 0), SH]]);
    sa.style.clipPath = tx < 1 ? "inset(0 100% 0 0)" : poly([...top.filter(p => p[0] <= tx + .1), ...ripPts.slice().reverse().map(([x, y]) => [x, Math.min(y, SH + 6)])]);
  }
  layout(); addEventListener("resize", layout);

  // ---------- holographic surface ----------
  const HOLO = makeHolo(glC); if (HOLO) HOLO.size(W, H);
  addEventListener("resize", () => { if (HOLO) HOLO.size(W, H); });
  const xA = cA.getContext("2d"), xB = cB.getContext("2d");
  function paintStrips(){
    if (!HOLO) return;
    xA.drawImage(glC, 0, 0, glC.width, cA.height, 0, 0, cA.width, cA.height);
    xB.drawImage(glC, 0, 0, glC.width, cB.height, 0, 0, cB.width, cB.height);
  }

  // ---------- state ----------
  let springBack = false;
  let state = "enter", tReady = 0, tOpen = 0, tx = 0, target = 0, autoRun = false, dragging = false, startX = 0;
  let lastBuzz = 0, lastGrain = 0, wantAuto = false, rx = 0, ry = 0, prx = 0, pry = 0, mu = [9, 9], mStr = 0, lastP = null;
  const t0 = performance.now(), sparks = [];

  // pack flies in from the depth of the smoke
  pk.animate([
    { transform: "translate3d(0,40px,-900px) rotateY(-150deg) rotateX(24deg) scale(.6)", filter: "blur(16px) brightness(.4)", opacity: 0 },
    { transform: "translate3d(0,-10px,40px) rotateY(16deg) rotateX(-4deg) scale(1.04)", filter: "blur(0) brightness(1.2)", opacity: 1, offset: .72 },
    { transform: "translate3d(0,0,0) rotateY(0) rotateX(0) scale(1)", filter: "none", opacity: 1 }
  ], { duration: seen ? 800 : 1350, delay: seen ? 80 : 260, easing: "cubic-bezier(.2,.8,.2,1)", fill: "backwards" }).finished.then(() => {
    pk.classList.add("landed"); if (state === "enter") goReady(performance.now());
    // landing: a soft bounce, the smoke is pushed away, a ring of light
    pk.animate([{ transform: "scale(1,1)" }, { transform: "scale(1.04,.96)" }, { transform: "scale(.99,1.01)" }, { transform: "scale(1,1)" }], { duration: 520, easing: "ease-out", composite: "add" });
    mu = [0, 0]; mStr = 1.4;
    const r = pk.getBoundingClientRect();
    for (let i = 0; i < 46; i++) { const a = Math.random() * 6.283, sp = (2 + Math.random() * 6) * kP;
      sparks.push({ x: (r.left + r.width / 2 + Math.cos(a) * r.width * .5) * kP, y: (r.top + r.height / 2 + Math.sin(a) * r.height * .5) * kP, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, l: 0, m: 34 + Math.random() * 26, c: (Math.random() * PCOLS.length) | 0, s: (1.2 + Math.random() * 2) * kP, g: .04 }); }
  }).catch(() => {});

  function goReady(now){ state = "ready"; tReady = now; el.classList.add("ready"); }
  function startAuto(){ autoRun = true; if (state === "ready") { state = "tearing"; el.classList.add("tearing"); sfx.rip(); } else wantAuto = true; }
  function open(now){
    state = "open"; tOpen = now; clipFor(1);
    Track.once("intro_opened");
    pk.classList.add("burst");
    sa.animate([{ transform: sa.style.transform || "none", opacity: 1 }, { transform: "translate(70px,-190px) rotate(-38deg)", opacity: 0 }], { duration: 750, easing: "cubic-bezier(.3,.6,.4,1)", fill: "forwards" });
    vib([18, 30, 40]); sfx.pop();
    const r = pk.getBoundingClientRect();
    for (let i = 0; i < 80; i++) sparks.push({ x: (r.left + Math.random() * r.width) * kP, y: (r.top + SH) * kP, vx: (Math.random() - .5) * 6 * kP, vy: -(3 + Math.random() * 9) * kP, l: 0, m: 40 + Math.random() * 30, c: (Math.random() * PCOLS.length) | 0, s: (1.6 + Math.random() * 2.6) * kP, g: .12 });
    el.classList.add("opened"); mu = [0, .15]; mStr = 1.4;
    setTimeout(dive, 160);
    setTimeout(() => finishIntro(), 9000);                // safety net: the site always opens
  }
  /* ---------- the dive ---------- */
  const DIVE = 560, TUN = 1500, COLLAPSE = 360, HOLDP = 240, BANG = 1450;
  let diving = false, tun = null, out = null;
  const eOut = t => 1 - Math.pow(1 - Math.min(1, Math.max(0, t)), 3);
  const eIn = t => Math.pow(Math.min(1, Math.max(0, t)), 2.4);
  const TFRAG = `precision mediump float;
uniform vec2 r; uniform float z, tw, wh, wi, sp, cl, tk;
float h(vec2 p){ return fract(sin(dot(p, vec2(41.3, 289.1))) * 43758.5453); }
float pn(vec2 x, float N){ vec2 i = floor(x), f = fract(x); f = f*f*(3.0-2.0*f);
  float a = mod(i.x, N), b = mod(i.x + 1.0, N);
  return mix(mix(h(vec2(a, i.y)), h(vec2(b, i.y)), f.x), mix(h(vec2(a, i.y + 1.0)), h(vec2(b, i.y + 1.0)), f.x), f.y); }
void main(){
  vec2 uv0 = (gl_FragCoord.xy - 0.5*r) / r.y;
  float rc = max(0.004, 1.0 - cl), r0 = length(uv0);
  vec2 uv = uv0 / rc;
  float rr = length(uv) + 0.001;
  float u = atan(uv.y, uv.x) / 6.2832 + 0.5 + tw;
  float zz = 0.3 / rr + z;
  float crk = pn(vec2(u*20.0, zz*1.1), 20.0)*0.55 + pn(vec2(u*48.0 + 3.0, zz*2.6), 48.0)*0.3 + pn(vec2(u*110.0, zz*6.0), 110.0)*0.15;
  float ph = zz*0.35 + crk*1.8 + u*3.0;
  vec3 film = 0.5 + 0.5*cos(6.2832*(ph + vec3(0.0, 0.33, 0.67)));
  float refl = smoothstep(0.36, 0.84, crk);
  vec3 col = vec3(0.03, 0.03, 0.045) + film*refl*1.15 + vec3(1.0, 0.97, 0.92)*pow(refl, 6.0)*0.9;
  float st = pow(pn(vec2(u*120.0, zz*0.35), 120.0), 14.0);
  col += vec3(1.0, 0.95, 0.86) * st * (0.6 + 2.6*sp);
  col += vec3(1.0, 0.92, 0.78) * pow(abs(sin(zz*1.5708)), 90.0) * 0.45;
  col *= 0.35 + 0.65*smoothstep(1.5, 0.3, rr);
  col += vec3(1.0, 0.9, 0.78) * exp(-rr*7.0) * 0.45;
  float core = 0.14 + 2.2*wh*wh;
  col = mix(col, vec3(1.0, 0.988, 0.955), smoothstep(core, core*0.15, rr));
  col += vec3(1.0, 0.9, 0.75) * exp(-rr*rr*18.0) * 0.35;
  col = mix(col, vec3(1.0, 0.988, 0.955), wi);
  float rs = rc * 1.6;
  col *= smoothstep(rs, rs * 0.62, r0);                                       // the universe folds into a point
  col += vec3(1.0, 0.96, 0.9) * smoothstep(0.08, 0.0, abs(r0 - rs * 0.62)) * cl * (1.0 - cl) * 2.5;
  float star = exp(-r0*r0 / (0.00006 + 0.004*rc*rc)) * cl * tk;
  float spikes = (exp(-abs(uv0.y)*260.0) * exp(-abs(uv0.x)*9.0) + exp(-abs(uv0.x)*260.0) * exp(-abs(uv0.y)*9.0)) * cl * cl * tk;
  col += vec3(1.0, 0.97, 0.92) * (star * 2.2 + spikes * 0.9);
  gl_FragColor = vec4(col, 1.0);
}`;
  const BFRAG = `precision mediump float;
uniform vec2 r; uniform float t, R;
float h(vec2 p){ return fract(sin(dot(p, vec2(41.3, 289.1))) * 43758.5453); }
float n(vec2 x){ vec2 i = floor(x), f = fract(x); f = f*f*(3.0-2.0*f);
  return mix(mix(h(i), h(i + vec2(1.0, 0.0)), f.x), mix(h(i + vec2(0.0, 1.0)), h(i + vec2(1.0, 1.0)), f.x), f.y); }
float fbm(vec2 p){ float s = 0.0, a = 0.5; for (int i = 0; i < 4; i++) { s += a*n(p); p = p*2.03 + 1.7; a *= 0.5; } return s; }
void main(){
  vec2 uv = (gl_FragCoord.xy - 0.5*r) / r.y;
  float d = length(uv), ang = atan(uv.y, uv.x) / 6.2832 + 0.5;
  float fade = 1.0 - smoothstep(0.62, 1.0, t);
  // the new universe outside the shock: iridescent nebula and stars streaking away
  vec2 q = uv * (1.5 - 0.45*t);
  float n1 = fbm(q*2.0 + vec2(t*0.5, -t*0.35));
  float n2 = fbm(q*3.6 - n1*1.6 + 4.0);
  vec3 film = 0.5 + 0.5*cos(6.2832*(n2*1.5 + ang + t*0.5 + vec3(0.0, 0.33, 0.67)));
  float hug = exp(-max(0.0, d - R)*2.2);
  vec3 neb = film * pow(n2, 2.6) * (0.12 + 1.5*hug*hug);
  float id = floor(ang*720.0), sv = h(vec2(id, 7.0));
  float line = pow(1.0 - abs(fract(ang*720.0) - 0.5)*2.0, 5.0);
  float streak = step(0.93, sv) * line * exp(-max(0.0, d - R)*(1.5 + sv*5.0)) * (1.2 - t);
  float dust = step(0.995, h(floor(gl_FragCoord.xy / 2.0) + floor(t*6.0))) * 1.2;
  vec3 outside = vec3(0.012, 0.012, 0.025) + neb + vec3(1.0, 0.96, 0.9)*(streak*1.6 + dust*hug);
  // the shockwave itself, with chromatic fringes
  float w = 0.01 + 0.06*t;
  vec3 ringCol = mix(vec3(1.0, 0.98, 0.94), 0.5 + 0.5*cos(6.2832*(ang*2.0 + t + vec3(0.0, 0.33, 0.67))), 0.5);
  vec3 ring = vec3(exp(-pow((d - R - 0.014)/w, 2.0)), exp(-pow((d - R)/w, 2.0)), exp(-pow((d - R + 0.014)/w, 2.0))) * ringCol * 1.7;
  ring += ringCol * exp(-pow((d - R*0.9)/(w*3.0), 2.0)) * 0.35;
  float inside = 1.0 - smoothstep(R - 0.004, R + 0.03, d);
  vec3 spill = ringCol * exp(-max(0.0, R - d)*7.0) * inside * 0.3;
  float flare = exp(-abs(uv.y)*110.0) * exp(-abs(uv.x)*1.1) * exp(-t*4.5) * 1.6;
  vec3 add = ring + spill + vec3(0.7, 0.88, 1.0)*flare + vec3(1.0)*exp(-t*14.0);
  float A = (1.0 - inside) * fade;
  gl_FragColor = vec4(outside*A + add*(0.35 + 0.65*fade), A);
}`;
  function makeBang(){
    if (!gl) return null;
    const mk = (ty, src) => { const o = gl.createShader(ty); gl.shaderSource(o, src); gl.compileShader(o); return gl.getShaderParameter(o, gl.COMPILE_STATUS) ? o : null; };
    const vs = mk(gl.VERTEX_SHADER, "attribute vec2 v;void main(){gl_Position=vec4(v,0.,1.);}"), fs = mk(gl.FRAGMENT_SHADER, BFRAG);
    if (!vs || !fs) return null;
    const pr = gl.createProgram(); gl.attachShader(pr, vs); gl.attachShader(pr, fs); gl.linkProgram(pr);
    if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) return null;
    return { pr, loc: gl.getAttribLocation(pr, "v"), ur: gl.getUniformLocation(pr, "r"), ut: gl.getUniformLocation(pr, "t"), uR: gl.getUniformLocation(pr, "R") };
  }
  function makeTunnel(){
    if (!gl) return null;
    const mk = (ty, src) => { const o = gl.createShader(ty); gl.shaderSource(o, src); gl.compileShader(o); return gl.getShaderParameter(o, gl.COMPILE_STATUS) ? o : null; };
    const vs = mk(gl.VERTEX_SHADER, "attribute vec2 v;void main(){gl_Position=vec4(v,0.,1.);}"), fs = mk(gl.FRAGMENT_SHADER, TFRAG);
    if (!vs || !fs) return null;
    const pr = gl.createProgram(); gl.attachShader(pr, vs); gl.attachShader(pr, fs); gl.linkProgram(pr);
    if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) return null;
    const u = n => gl.getUniformLocation(pr, n);
    return { pr, loc: gl.getAttribLocation(pr, "v"), ur: u("r"), uz: u("z"), utw: u("tw"), uwh: u("wh"), uwi: u("wi"), usp: u("sp"), ucl: u("cl"), utk: u("tk") };
  }
  let TP = null, BP = null;                                          // compiled up front (below), so the dive never stutters
  const twEl = document.createElement("div"); twEl.className = "tw";
  twEl.innerHTML = `<span class="tw-w">${th("wl.intro.w1")}</span><span class="tw-w">${th("wl.intro.w2")}</span><span class="tw-w last">${th("wl.intro.w3")}</span>`;
  const whiteEl = document.createElement("div"); whiteEl.className = "tw-white";
  el.append(twEl, whiteEl);
  function dive(){
    if (diving) return; diving = true;
    // the camera falls into the opening of the pack
    const r = pk.getBoundingClientRect(), sc = r.width / W, oyS = r.top + SH * sc;
    pk.style.transformOrigin = `50% ${SH}px`;
    pk.animate([{ transform: pk.style.transform || "none" }, { transform: `translate3d(0,${(innerHeight * .5 - oyS).toFixed(0)}px,0) rotateX(-16deg) scale(16)` }],
      { duration: DIVE, easing: "cubic-bezier(.62,0,.88,.35)", fill: "forwards" });
    pk.querySelector(".pk-light").animate([{ transform: "scale(.6)", opacity: .2 }, { transform: "scale(2.5)", opacity: 1, offset: .5 }, { transform: "scale(9)", opacity: 1 }], { duration: DIVE, easing: "cubic-bezier(.5,0,.85,.4)", fill: "forwards" });
    intro.cv.animate([{ transform: "scale(1)" }, { transform: "scale(1.7)" }], { duration: DIVE, easing: "cubic-bezier(.62,0,.88,.35)", fill: "forwards" });
    whiteEl.animate([{ opacity: 0 }, { opacity: 0, offset: .55 }, { opacity: 1 }], { duration: DIVE, easing: "ease-in", fill: "forwards" });
    sfx.whoosh(); vib(14);
    setTimeout(TP ? enterTunnel : landing, DIVE);
  }
  function enterTunnel(){
    pk.style.visibility = "hidden"; intro.cv.getAnimations().forEach(a => a.cancel());
    whiteEl.getAnimations().forEach(a => a.cancel());
    gl.useProgram(TP.pr); gl.enableVertexAttribArray(TP.loc); gl.vertexAttribPointer(TP.loc, 2, gl.FLOAT, false, 0, 0);
    tun = { t0: performance.now(), words: [...twEl.children], next: 0, beat: [150, 470, 780], ended: false, implode: false, bang: 0 };
    sfx.charge(); setTimeout(() => sfx.charge(), 720);
  }
  function stepTunnel(now){
    const t = now - tun.t0, T = Math.min(t, TUN) / 1000;
    if (t >= TUN + HOLDP && BP) { if (!tun.bang) bigBang(now); drawBang(now - tun.bang); return; }
    if (t >= TUN && !BP) { if (!tun.ended) { tun.ended = true; landing(); } return; }
    // speed ramps up the whole way down... then everything folds into a single point
    const z = 1.6 * T + 7.5 * Math.pow(T, 2.3), sp = Math.min(1, T / 1.2);
    const wi = Math.max(1 - t / 260, 0) ** 2, cl = eIn((t - (TUN - COLLAPSE)) / COLLAPSE);
    const tk = t < TUN ? 1 : 1 + .45 * Math.sin(t / 22) + .25 * Math.sin(t / 9);
    if (cl > 0 && !tun.implode) { tun.implode = true; sfx.whoosh(); }
    gl.uniform2f(TP.ur, intro.cv.width, intro.cv.height); gl.uniform1f(TP.uz, z); gl.uniform1f(TP.utw, .05 * T + .12 * T * T);
    gl.uniform1f(TP.usp, sp); gl.uniform1f(TP.uwh, 0); gl.uniform1f(TP.uwi, wi); gl.uniform1f(TP.ucl, cl); gl.uniform1f(TP.utk, tk);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    // the words come at you on the beat
    while (tun.next < tun.beat.length && t >= tun.beat[tun.next]) {
      const w = tun.words[tun.next], last = tun.next === tun.beat.length - 1;
      const C = "translate(-50%,-50%) ";
      w.animate([{ transform: C + "scale(.06)", opacity: 0 }, { transform: C + "scale(.5)", opacity: 1, offset: .35 }, { transform: C + (last ? "scale(1.05)" : "scale(1.4)"), opacity: 1, offset: last ? .62 : .7 }, { transform: C + (last ? "scale(9)" : "scale(6)"), opacity: 0 }],
        { duration: last ? 600 : 440, easing: "cubic-bezier(.3,.1,.85,.4)", fill: "forwards" });
      setTimeout(() => { sfx.thump(); vib(last ? [10, 20, 30] : 12); }, last ? 280 : 190);
      tun.next++;
    }
  }
  // ...and the point explodes into a new universe, with the site born inside the shockwave
  function bigBang(now){
    tun.bang = now; twEl.remove(); whiteEl.remove();
    gl.useProgram(BP.pr); gl.enableVertexAttribArray(BP.loc); gl.vertexAttribPointer(BP.loc, 2, gl.FLOAT, false, 0, 0);
    gl.clearColor(0, 0, 0, 0);
    document.documentElement.classList.remove("booting"); el.style.pointerEvents = "none"; el.style.background = "transparent";
    vib([50, 30, 90]); sfx.thump(); setTimeout(() => sfx.thump(), 70); sfx.reveal(); setTimeout(() => sfx.sparkle(), 160);
    el.animate([{ transform: "none" }, { transform: "translate(-9px,6px)" }, { transform: "translate(8px,-7px)" }, { transform: "translate(-5px,4px)" }, { transform: "translate(3px,-2px)" }, { transform: "none" }], { duration: 420, easing: "ease-out" });
    const ox = innerWidth / 2, oy = innerHeight / 2;
    const kS = Math.min(devicePixelRatio || 1, 2), cvs = document.createElement("canvas"); cvs.className = "sh-cv";
    cvs.width = Math.round(innerWidth * kS); cvs.height = Math.round(innerHeight * kS);
    document.body.append(cvs); intro.fx = [cvs];
    const burst = [];
    for (let i = 0; i < 240; i++) { const a = Math.random() * 6.283, sp = .15 + Math.random() ** 1.3 * 1.25;
      burst.push({ vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, c: (Math.random() * PCOLS.length) | 0, s: 2 + Math.random() * 7, life: 700 + Math.random() * 1100, fall: .00012 + Math.random() * .00025 }); }
    out = { t0: now, ox, oy, cvs, ctx: cvs.getContext("2d"), kS, burst, bang: true, maxR: Math.hypot(innerWidth, innerHeight) };
  }
  function drawBang(t){
    const p = Math.min(1, t / BANG), W2 = intro.cv.width, H2 = intro.cv.height;
    const Rmax = Math.hypot(.5 * W2 / H2, .5) + .12, R = Rmax * (1 - Math.pow(1 - p, 3.2));
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.uniform2f(BP.ur, W2, H2); gl.uniform1f(BP.ut, p); gl.uniform1f(BP.uR, R);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }
  // out of the light, onto the page
  function landing(){
    whiteEl.getAnimations().forEach(a => a.cancel()); whiteEl.style.opacity = 1;
    twEl.remove(); pk.style.visibility = "hidden";
    document.documentElement.classList.remove("booting"); el.style.pointerEvents = "none";
    vib([20, 30, 60]); sfx.reveal(); setTimeout(() => sfx.sparkle(), 120);
    const ox = innerWidth / 2, oy = innerHeight * .42;
    const kS = Math.min(devicePixelRatio || 1, 2), cvs = document.createElement("canvas"); cvs.className = "sh-cv";
    cvs.width = Math.round(innerWidth * kS); cvs.height = Math.round(innerHeight * kS);
    const ring = document.createElement("div"); ring.className = "sh-ring"; ring.style.setProperty("--ox", ox + "px"); ring.style.setProperty("--oy", oy + "px");
    document.body.append(ring, cvs); intro.fx = [ring, cvs];
    const burst = [];
    for (let i = 0; i < 170; i++) { const a = Math.random() * 6.283, sp = .12 + Math.random() ** 1.4 * .95;
      burst.push({ vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, c: (Math.random() * PCOLS.length) | 0, s: 2.5 + Math.random() * 7, life: 600 + Math.random() * 900, fall: .00018 + Math.random() * .0003 }); }
    out = { t0: performance.now(), ox, oy, cvs, ctx: cvs.getContext("2d"), kS, ring, burst, maxR: Math.hypot(innerWidth, innerHeight) };
  }
  function stepOut(now){
    const O = out, t = now - O.t0, x = O.ctx, k = O.kS;
    if (!O.bang) {
      el.style.opacity = (1 - eOut(t / 520)).toFixed(3);         // the light lifts off the page
      const pr = eOut(t / 900);
      O.ring.style.opacity = t < 16 ? 0 : Math.max(0, 1 - pr).toFixed(3);
      O.ring.style.transform = `scale(${((150 + pr * O.maxR) / 100).toFixed(3)})`;
    }
    x.setTransform(k, 0, 0, k, 0, 0); x.clearRect(0, 0, innerWidth, innerHeight); x.globalCompositeOperation = "lighter";
    let alive = 0;
    for (const b of O.burst) { if (t > b.life) continue; alive++;
      const e = t / b.life, d = 1 - (1 - e) ** 3, z = b.s * (1 - e * .6);
      x.globalAlpha = (1 - e) * .95;
      x.drawImage(SPR[b.c], O.ox + b.vx * b.life * d - z, O.oy + b.vy * b.life * d + b.fall * t * t - z, z * 2, z * 2); }
    x.globalAlpha = 1; x.globalCompositeOperation = "source-over";
    if ((O.bang ? t > BANG : t > 560) && !alive) finishIntro();
  }

  // ---------- the visitor's hand ----------
  lastP = null;
  el.addEventListener("pointerdown", e => {
    sfx.unlock(); Tilt.enable();
    if (state === "open") return;
    dragging = true; startX = e.clientX - (state === "tearing" ? tx * innerWidth * .6 : 0); try { el.setPointerCapture(e.pointerId); } catch {}
  });
  el.addEventListener("pointermove", e => {
    if (R) { const k = intro.cv.width / innerWidth, W2 = intro.cv.width, H2 = intro.cv.height, mn = Math.min(W2, H2);
      mu = [(e.clientX * k - W2 / 2) / mn, ((innerHeight - e.clientY) * k - H2 / 2) / mn];
      if (lastP) mStr = Math.min(1.4, mStr + Math.hypot(e.clientX - lastP[0], e.clientY - lastP[1]) / 120);
      lastP = [e.clientX, e.clientY]; }
    prx = ((e.clientY / innerHeight) - .5) * -16; pry = ((e.clientX / innerWidth) - .5) * 22;
    if (!dragging || state === "open") return;
    const prog = Math.min(1, Math.abs(e.clientX - startX) / (innerWidth * .6));
    if (prog > .03 && state !== "tearing") { if (state === "enter") goReady(performance.now()); state = "tearing"; el.classList.add("tearing"); }
    if (!autoRun) { springBack = false; target = prog; }
  });
  const release = () => { if (!dragging) return; dragging = false;
    if (state === "tearing" && !autoRun && target < .42) { target = 0; springBack = true; }
    else if (state === "tearing") startAuto(); };
  el.addEventListener("pointerup", release); el.addEventListener("pointercancel", release);

  // ---------- sparks canvas ----------
  const sizeP = () => { pc.width = Math.ceil(innerWidth * kP); pc.height = Math.ceil(innerHeight * kP); };
  sizeP();
  const SPR = PCOLS.map(glowSprite);
  function drawSparks(){
    px.clearRect(0, 0, pc.width, pc.height);
    px.globalCompositeOperation = "lighter";
    for (let i = sparks.length - 1; i >= 0; i--) {
      const sp = sparks[i]; sp.vy += (sp.g || .28) * kP; sp.vx *= .97; sp.x += sp.vx; sp.y += sp.vy; sp.l++;
      if (sp.l > sp.m) { sparks.splice(i, 1); continue; }
      px.globalAlpha = 1 - sp.l / sp.m; const z = sp.s * 4;
      px.drawImage(SPR[sp.c], sp.x - z / 2, sp.y - z / 2, z, z);
    }
    px.globalAlpha = 1; px.globalCompositeOperation = "source-over";
  }

  // ---------- the loop ----------
  const gl = R ? R.gl : null;
  const size = () => { if (!gl) return; const k = Math.min(devicePixelRatio || 1, 2) * (innerWidth < 700 ? .5 : .6);
    intro.cv.width = Math.ceil(innerWidth * k); intro.cv.height = Math.ceil(innerHeight * k);
    gl.viewport(0, 0, intro.cv.width, intro.cv.height); sizeP(); };
  size(); addEventListener("resize", size);
  if (!gl) el.classList.add("nogl");
  TP = makeTunnel(); BP = TP && makeBang();
  let lastNow = t0, slowFrames = 0, frames = 0, downscaled = false;
  const specEl = pk.querySelector(".pk-spec"); let lastPct = -1, tick = 0, pkRect = null;
  (function frame(now){
    if (introDone) return;
    requestAnimationFrame(frame);
    try { step(now); } catch (err) { console.error(err); if (state === "open") finishIntro(); }
  })(t0);
  function step(now){
    tick++;
    const dt = Math.min(64, now - lastNow); lastNow = now;
    const el_ms = now - t0;
    if (gl && !downscaled && ++frames > 8 && frames < 60) {
      if (dt > 24) slowFrames++;
      if (slowFrames > 12) { downscaled = true; const k2 = intro.cv.width / innerWidth * .66;
        intro.cv.width = Math.ceil(innerWidth * k2); intro.cv.height = Math.ceil(innerHeight * k2); gl.viewport(0, 0, intro.cv.width, intro.cv.height); }
    }
    if (state === "tearing") {
      pkRect = pk.getBoundingClientRect();                // read before any write this frame
      if (autoRun && !dragging) target = Math.min(1, Math.max(target, tx) + dt / TEAR_AUTO);
      if (springBack) { if (dragging) springBack = false; else { tx += (0 - tx) * .22; if (tx < .004) { tx = 0; target = 0; springBack = false; lastBuzz = 0; lastGrain = 0; state = "ready"; el.classList.remove("tearing"); clipFor(0); sa.style.transform = ""; } } }
      else tx += (target - tx) * (dragging ? .35 : .5);
      if (target >= 1 && tx > .985) tx = 1;
      if (tx - lastBuzz > .07) { lastBuzz = tx; vib(6); }
      if (tx - lastGrain > .035 && dragging) { lastGrain = tx; sfx.tear(); }
      clipFor(tx);
      // the torn piece curls up around the tear point
      sa.style.transformOrigin = `${(tx * W).toFixed(1)}px ${SH}px`;
      sa.style.transform = `rotate(${(-Math.pow(tx, 1.4) * 18).toFixed(2)}deg) translateY(${(-tx * 4).toFixed(1)}px)`;
      // sparks fly from the head of the tear
      if (tx < .999 && pkRect) { const r = pkRect, hx = (r.left + tx * r.width) * kP, hy = (r.top + jagY(tx * W) * (r.height / H)) * kP;
        for (let i = 0; i < 3; i++) sparks.push({ x: hx, y: hy, vx: (Math.random() * 5 - 1) * kP, vy: (Math.random() - .7) * 6 * kP, l: 0, m: 24 + Math.random() * 20, c: (Math.random() * PCOLS.length) | 0, s: (1.6 + Math.random() * 2.4) * kP }); }
      if (tx >= 1) open(now);
    }
    // the pack floats, sways, and leans toward the finger or the tilt of the phone
    { const tt = el_ms / 1000, tiltX = TILT.mouse ? TILT.x * 14 : 0, tiltY = TILT.mouse ? TILT.y * -10 : 0;
      rx += ((prx || 0) + tiltY + Math.sin(tt * .9) * 3 - rx) * .08; ry += ((pry || 0) + tiltX + Math.sin(tt * .6) * 7 - ry) * .08;
      if (out) { stepOut(now); if (introDone) return; }
      if (diving) {} else if (pk.classList.contains("landed")) { pk.style.transform = `translateY(${(Math.sin(tt * 1.4) * 6).toFixed(1)}px) rotateX(${rx.toFixed(2)}deg) rotateY(${ry.toFixed(2)}deg)`; specEl.style.setProperty("--sp", (ry / 14).toFixed(3)); }
      if (HOLO && !diving) { HOLO.draw(tt, ry / 12 + Math.sin(tt * .5) * .4, rx / 10); if (state !== "open" && (tick & 1)) paintStrips(); }
    }
    const pct = state === "open" ? 100 : Math.round(tx * 100);
    if (pct !== lastPct) { lastPct = pct; pctEl.textContent = String(pct).padStart(3, "0"); meta.style.setProperty("--pct", pct + "%"); }
    if (tun) { if (!(out && !out.bang)) stepTunnel(now); }
    else if (gl) {
      mStr *= 0.94;
      gl.uniform2f(R.ur, intro.cv.width, intro.cv.height);
      gl.uniform2f(R.um, mu[0], mu[1]); gl.uniform1f(R.ums, mStr);
      gl.uniform1f(R.ut, el_ms / 1000 + 3.0); gl.uniform1f(R.ua, Math.min(1, el_ms / 500));
      gl.uniform1f(R.utl, -9999); gl.uniform1f(R.uamp, 0); gl.uniform1f(R.utx, 0); gl.uniform1f(R.usep, 0); gl.uniform1f(R.upf, 0); gl.uniform1f(R.uk, 1);
      gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    }
    drawSparks();
  }
}
function finishIntro(instant){
  if (introDone) return; introDone = true;
  if (intro.el) Track.once("intro_completed");
  document.documentElement.classList.remove("booting");
  if (intro.el) intro.el.remove();
  if (intro.fx) intro.fx.forEach(n => n.remove());
  try { sessionStorage.setItem("drop01-intro", "1"); } catch {}
  fitAll(); updSticky();
}

/* ---------- ambient smoke behind the page ---------- */
function startAmbient(){
  if (reduce) return;
  sizeCanvases(); amb.classList.add("on");
  const ps = Array.from({ length: innerWidth < 700 ? 9 : 14 }, () => ({
    x: Math.random(), y: Math.random(), s: .6 + Math.random() * .8, rot: Math.random() * 6.28,
    vx: (Math.random() - .5) * .00012, vy: -.00006 - Math.random() * .0001, vr: (Math.random() - .5) * .0012,
    sp: PUFFS[(Math.random() * PUFFS.length) | 0], o: .22 + Math.random() * .2 }));
  let lastT = performance.now(), visible = true;
  document.addEventListener("visibilitychange", () => { visible = !document.hidden; lastT = performance.now(); });
  (function loop(now){
    const dt = Math.min(64, now - lastT); lastT = now;
    if (visible) {
      const w = amb.width, h = amb.height, m = Math.max(w, h);
      actx.clearRect(0, 0, w, h);
      for (const p of ps) {
        p.x += p.vx * dt; p.y += p.vy * dt; p.rot += p.vr * dt * .06;
        if (p.y < -.4) { p.y = 1.3; p.x = Math.random(); }
        if (p.x < -.4) p.x = 1.3; if (p.x > 1.4) p.x = -.3;
        const size = m * p.s;
        actx.globalAlpha = p.o;
        actx.save(); actx.translate(p.x * w, p.y * h); actx.rotate(p.rot); actx.drawImage(p.sp, -size/2, -size/2, size, size); actx.restore();
      }
    }
    requestAnimationFrame(loop);
  })(lastT);
}

/* ---------- ticket shimmer / tilt ---------- */
const ticket = $("ticket");
const TILT = { x: 0, y: 0, mouse: false };
if (!reduce && matchMedia("(hover: hover) and (pointer: fine)").matches) {
  addEventListener("pointermove", e => {
    TILT.mouse = true;
    document.body.style.setProperty("--mx", e.clientX + "px");
    document.body.style.setProperty("--my", e.clientY + "px");
    const r = ticket.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
    const near = x > -0.3 && x < 1.3 && y > -0.3 && y < 1.3;
    ticket.style.setProperty("--ry", near ? ((x - .5) * 12) + "deg" : "0deg");
    ticket.style.setProperty("--rx", near ? ((.5 - y) * 8) + "deg" : "0deg");
    ticket.style.setProperty("--gx", (Math.min(Math.max(x, 0), 1) * 100) + "%");
    ticket.style.setProperty("--gy", (Math.min(Math.max(y, 0), 1) * 100) + "%");
    TILT.x = near ? (x - .5) * 2 : 0; TILT.y = near ? (y - .5) * 2 : 0;
  });
} else if (!reduce) {
  ticket.classList.add("idle");
}

/* ---------- sticky bar ---------- */
let signedUp = false, formVisible = true, finalVisible = false;
const sticky = $("sticky");
function updSticky(){ sticky.classList.toggle("off", !introDone || signedUp || formVisible || finalVisible); }
new IntersectionObserver(([e]) => { formVisible = e.isIntersecting; updSticky(); }, { rootMargin: "0px 0px -40px 0px" }).observe($("stage"));
new IntersectionObserver(([e]) => { finalVisible = e.isIntersecting; updSticky(); }).observe($("final"));
function goToForm(){
  $("stage").scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "start" });
  if (!signedUp) setTimeout(() => { const f = $("step2").hidden ? $("email") : $("phone"); f.focus({ preventScroll: true }); }, reduce ? 0 : 550);
}
$("sticky-btn").addEventListener("click", goToForm);
$("final-btn").addEventListener("click", () => {
  const st = $("final-stub");
  if (reduce) { goToForm(); return; }
  st.classList.remove("torn", "back"); void st.offsetWidth; st.classList.add("torn");
  sfx.unlock(); sfx.rip(); vib([6, 20, 6, 20, 10]);
  setTimeout(goToForm, 480);
  setTimeout(() => { st.classList.remove("torn"); st.classList.add("back"); }, 1700);
});

/* ---------- toast + copy ---------- */
let tt;
function toast(msg){ const t = $("toast"); t.textContent = msg; t.classList.add("on"); clearTimeout(tt); tt = setTimeout(() => t.classList.remove("on"), 1800); }
async function copy(text){ try { await navigator.clipboard.writeText(text); toast(t("wl.done.copied")); } catch { toast(t("wl.done.copy_fail")); } }

/* ---------- phone country code ---------- */
const region = ((navigator.language || "").split("-")[1] || "").toUpperCase();
$("cc").innerHTML = COUNTRIES.map(([c, code]) => `<option value="${code}">${c} ${code}</option>`).join("");
const pre = Math.max(0, COUNTRIES.findIndex(([c]) => c === region));
$("cc").selectedIndex = pre;

/* ---------- email typo suggestion ---------- */
const FIX = { "gmial.com":"gmail.com","gmai.com":"gmail.com","gmal.com":"gmail.com","gamil.com":"gmail.com","gmail.co":"gmail.com","gmail.con":"gmail.com","gnail.com":"gmail.com","gmaill.com":"gmail.com",
  "hotmial.com":"hotmail.com","hotmai.com":"hotmail.com","hotmail.co":"hotmail.com","outlok.com":"outlook.com","outloo.com":"outlook.com",
  "yaho.com":"yahoo.com","yahooo.com":"yahoo.com","icloud.co":"icloud.com","iclod.com":"icloud.com","icoud.com":"icloud.com" };
function checkTypo(){
  const v = $("email").value.trim(), at = v.lastIndexOf("@"), s = $("suggest");
  if (at < 1) { s.hidden = true; return; }
  const dom = v.slice(at + 1).toLowerCase();
  const fixed = FIX[dom] || (dom.endsWith(".con") ? dom.slice(0, -4) + ".com" : null);
  if (fixed && fixed !== dom) { s.dataset.v = v.slice(0, at + 1) + fixed; s.innerHTML = th("wl.typo", { email: { h: `<u>${esc(s.dataset.v)}</u>` } }); s.hidden = false; }
  else s.hidden = true;
}
$("suggest").addEventListener("click", () => { $("email").value = $("suggest").dataset.v; $("suggest").hidden = true; validEmail(true); $("email").dispatchEvent(new Event("input")); });

/* ---------- validation ---------- */
function setErr(input, hintId, msg){ $(hintId).textContent = msg; input.classList.toggle("bad", !!msg); input.classList.toggle("good", !msg); return !msg; }
const emailOk = v => /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(v);
function validEmail(show){ const ok = emailOk($("email").value.trim()); if (show || ok) setErr($("email"), "email-hint", ok ? "" : t("wl.err_email")); return ok; }
function phoneFull(){
  const raw = $("phone").value.trim();
  if (raw.startsWith("+") || raw.startsWith("00")) return raw.replace(/^00/, "+").replace(/[^\d+]/g, "");
  return $("cc").value + raw.replace(/\D/g, "").replace(/^0/, "");
}
function validPhone(show){ const d = phoneFull().replace(/\D/g, ""); const ok = d.length >= 9 && d.length <= 15; if (show || ok) setErr($("phone"), "phone-hint", ok ? "" : t("wl.err_phone")); return ok; }
$("email").addEventListener("input", () => { checkTypo(); if ($("email").classList.contains("bad")) validEmail(false); else $("email").classList.remove("good"); });
$("email").addEventListener("blur", () => { if ($("email").value) validEmail(true); checkTypo(); });
$("phone").addEventListener("input", () => { if ($("phone").classList.contains("bad")) validPhone(false); });
$("phone").addEventListener("blur", () => { if ($("phone").value) validPhone(true); });

/* ---------- steps ---------- */
function showStep(n){
  const a = $("step1"), b = $("step2");
  a.hidden = n !== 1; b.hidden = n !== 2;
  const cur = n === 1 ? a : b; cur.classList.remove("in"); void cur.offsetWidth; if (!reduce) cur.classList.add("in");
  $("dot2").classList.toggle("on", n === 2);
  $("step-txt").textContent = t(n === 1 ? "wl.step1" : "wl.step2");
}
function goStep2(){
  if (!validEmail(true)) { $("email").focus(); return; }
  punchTicket();
  $("email-echo").textContent = $("email").value.trim();
  showStep(2);
  $("phone").focus(); // same tap, so the keyboard stays open on phones
}
$("next").addEventListener("click", goStep2);
$("email").addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); goStep2(); } });
$("back").addEventListener("click", () => { showStep(1); $("email").focus(); });

/* ---------- confetti + sparks: one loop, many bursts ---------- */
const conf = $("confetti"), cctx = conf.getContext("2d");
const CCOL = ["#FFB3D9","#FFE7A3","#B8FFD9","#A8DCFF","#D4B8FF","#F3F1EC"];
const CP = []; let cRun = false; window.TS = 1;
const SPARK_SPR = CCOL.map(c => glowSprite(c));
function burst(x, y, o = {}){
  if (reduce) return;
  const { count = 150, angle = -Math.PI / 2, spread = Math.PI * 1.1, power = [6, 17], gravity = .32, kind = "confetti", jitter = 0 } = o;
  if (!cRun) sizeCanvases();
  const k = conf._k || 1;
  for (let i = 0; i < count; i++) {
    const a = angle + (Math.random() - .5) * spread, v = (power[0] + Math.random() * (power[1] - power[0])) * k;
    const spark = kind === "spark", star = kind === "star";
    CP.push({ x: (x + (Math.random() - .5) * jitter * 2) * k, y: (y + (Math.random() - .5) * jitter * 2) * k, vx: Math.cos(a) * v, vy: Math.sin(a) * v, g: gravity * k, spark, star,
      w: (spark ? 2 + Math.random() * 3 : 5 + Math.random() * 6) * k, h: (8 + Math.random() * 8) * k,
      r: Math.random() * 6.28, vr: (Math.random() - .5) * .35, t: Math.random() * 6.28, vt: .1 + Math.random() * .2,
      ci: (Math.random() * CCOL.length) | 0, c: CCOL[(Math.random() * CCOL.length) | 0], round: spark || Math.random() < .25, life: 0, max: spark ? 60 + Math.random() * 40 : star ? 90 + Math.random() * 40 : 150 + Math.random() * 50 });
    if (star) { const q = CP[CP.length - 1]; q.w = (5 + Math.random() * 6) * k; q.vr = (Math.random() - .5) * .2; }
  }
  if (!cRun) { cRun = true; requestAnimationFrame(cLoop); }
}
function cLoop(){
  cctx.setTransform(1, 0, 0, 1, 0, 0); cctx.clearRect(0, 0, conf.width, conf.height);
  for (let i = CP.length - 1; i >= 0; i--) {
    const p = CP[i];
    const ts = window.TS || 1, dr = p.spark ? .96 : .985, dk = 1 - (1 - dr) * ts;
    p.vy += p.g * ts; p.vx *= dk; p.vy *= dk; p.r += p.vr * ts; p.t += p.vt * ts; p.life += ts;
    if (!p.spark && !p.star && p.vy > 0) { p.vy = Math.min(p.vy, 3.2 * (conf._k || 1)); p.vx += Math.sin(p.t * .6) * .12 * (conf._k || 1) * ts; }
    p.x += p.vx * ts; p.y += p.vy * ts;
    if (p.y > conf.height + 40 || p.life > p.max) { CP.splice(i, 1); continue; }
    if (p.star || p.spark) cctx.setTransform(1, 0, 0, 1, p.x, p.y);
    else { const c = Math.cos(p.r), sn = Math.sin(p.r), sy = Math.cos(p.t); cctx.setTransform(c, sn, -sn * sy, c * sy, p.x, p.y); }
    if (p.star) {
      cctx.globalCompositeOperation = "lighter"; cctx.globalAlpha = 1 - p.life / p.max; cctx.setTransform(Math.cos(p.r), Math.sin(p.r), -Math.sin(p.r), Math.cos(p.r), p.x, p.y); cctx.fillStyle = p.c;
      const R = p.w, r2 = p.w * .28; cctx.beginPath();
      for (let j = 0; j < 8; j++) { const ang = j * Math.PI / 4, rr = j % 2 ? r2 : R; cctx.lineTo(Math.cos(ang) * rr, Math.sin(ang) * rr); }
      cctx.closePath(); cctx.fill();
    } else if (p.spark) {
      cctx.globalCompositeOperation = "lighter"; cctx.globalAlpha = Math.pow(1 - p.life / p.max, 1.5);
      const z = p.w * 5; cctx.drawImage(SPARK_SPR[p.ci], -z / 2, -z / 2, z, z);
    } else {
      cctx.globalCompositeOperation = "source-over"; cctx.globalAlpha = Math.min(1, (p.max - p.life) / 45);
      cctx.fillStyle = p.c;
      if (p.round) { cctx.beginPath(); cctx.arc(0, 0, p.w / 2, 0, 6.28); cctx.fill(); } else cctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
    }
  }
  cctx.setTransform(1, 0, 0, 1, 0, 0); cctx.globalAlpha = 1; cctx.globalCompositeOperation = "source-over";
  if (CP.length) requestAnimationFrame(cLoop); else { cRun = false; cctx.clearRect(0, 0, conf.width, conf.height); }
}

/* ---------- code scramble ---------- */
const CH = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
function scramble(el, final){
  if (reduce) { el.textContent = final; return; }
  let frame = 0; const pre = final.indexOf("-") + 1 || 5; const lock = final.split("").map((c, i) => i < pre ? 0 : 8 + (i - pre) * 6);
  (function step(){
    frame++;
    el.textContent = final.split("").map((c, i) => frame >= lock[i] ? c : CH[(Math.random() * CH.length) | 0]).join("");
    if (frame < lock[lock.length - 1]) requestAnimationFrame(step); else el.textContent = final;
  })();
}

/* ---------- success ---------- */
const pageUrl = location.href.split("#")[0];
const calFmt = ms => new Date(ms).toISOString().replace(/[-:]|\.\d{3}/g, "");
function fillDone(data){
  $("done-text").textContent = [data.position ? t("wl.done.position", { n: data.position }) : "", t(data.gift === false ? "wl.done.will_email" : "wl.done.will_send", { email: data.email })].filter(Boolean).join(" ");
  $("share-wa").href = "https://wa.me/?text=" + encodeURIComponent(t("wl.done.share_wa_msg", { store: STORE_NAME, gift: GIFT.title.toLowerCase(), url: pageUrl }));
  $("cal").href = "https://calendar.google.com/calendar/render?action=TEMPLATE"
    + "&text=" + encodeURIComponent(t("wl.done.cal_title", { store: STORE_NAME }))
    + "&dates=" + calFmt(target) + "/" + calFmt(target + 36e5)
    + "&details=" + encodeURIComponent((data.code ? t("wl.done.cal_code", { code: data.code }) + "\n" : "") + pageUrl);
}
/* gifts can run out: the success card then confirms the spot without a code */
function applyGift(data){
  const noGift = data.gift === false || !data.code;
  document.querySelector("#done h2").textContent = t(noGift ? "wl.done.title_nogift" : "wl.done.title", { store: STORE_NAME });
  $("gift-name").textContent = noGift ? t("wl.done.nogift_name") : GIFT.title;
  $("gift-text").textContent = noGift ? t("wl.done.nogift_text") : GIFT.text;
  document.querySelector("#done .code-box").hidden = noGift;
}
function showDoneInstant(data){
  signedUp = true; updSticky(); fillDone(data); applyGift(data);
  $("code").textContent = data.code; scratchDone(true);
  $("form").hidden = true; $("final").hidden = true;
  const d = $("done"); d.hidden = false; d.classList.add("instant", "open");
}
$("copy-code").addEventListener("click", () => { if (!revealed) { revealCode(); return; } copy($("code").textContent); });
$("share-copy").addEventListener("click", () => copy(pageUrl));
$("reset").addEventListener("click", () => {
  store.del("drop01-signup"); signedUp = false;
  const d = $("done"); d.hidden = true; d.className = "done";
  $("stage").classList.remove("focus"); $("scene").classList.remove("on","flash"); ticket.classList.remove("flip","shake");
  $("ticket-body").classList.remove("tear","print"); document.querySelector(".trust").hidden = false;
  if (!reduce && !matchMedia("(hover: hover) and (pointer: fine)").matches) ticket.classList.add("idle");
  $("stamp").classList.remove("hit"); resetScratch();
  $("form").hidden = false; $("final").hidden = false; $("form").reset(); $("cc").selectedIndex = pre;
  ["email","phone"].forEach(id => $(id).classList.remove("good","bad"));
  showStep(1); updSticky(); $("email").focus();
  punched = false; $("punch").classList.remove("on"); emboss(); live();
});

function shockAt(host, x, y){
  if (reduce) return;
  const r = host.getBoundingClientRect(), el = document.createElement("span");
  el.className = "shock"; el.style.left = (x - r.left) + "px"; el.style.top = (y - r.top) + "px";
  host.appendChild(el); setTimeout(() => el.remove(), 800);
}
const vib = p => { try { navigator.vibrate && navigator.vibrate(p); } catch {} };
function waitForTap(el, ms){
  return new Promise(res => {
    const t = setTimeout(done, reduce ? 0 : ms);
    function done(){ clearTimeout(t); el.removeEventListener("click", done); res(); }
    el.addEventListener("click", done);
  });
}


/* a beat of slow motion at the big moment */
function slowMo(ms){
  if (reduce) return;
  window.TS = .2;
  const anims = document.getAnimations ? document.getAnimations() : [];
  anims.forEach(a => { try { a.playbackRate = .25; } catch {} });
  const col = document.querySelector(".stage-col"); col.classList.add("slowmo");
  setTimeout(() => {
    window.TS = 1;
    (document.getAnimations ? document.getAnimations() : []).forEach(a => { try { a.playbackRate = 1; } catch {} });
    col.classList.remove("slowmo");
  }, ms);
}
/* the "pulled a rare card" moment */
async function heroCard(){
  if (reduce) return;
  const wrap = $("hcard-wrap"), card = $("hcard"), mc = document.querySelector(".mcard");
  if (!mc) return;
  const nm = (typeof window.__giftName === "string" && window.__giftName) ? window.__giftName : "";
  $("hc-name").textContent = nm ? t("wl.reserved_for", { name: nm }) : t("wl.card.name", { store: STORE_NAME });
  wrap.hidden = false;
  const from = mc.getBoundingClientRect(), to = card.getBoundingClientRect();
  const dx = (from.left + from.width / 2) - (to.left + to.width / 2), dy = (from.top + from.height / 2) - (to.top + to.height / 2);
  const sc = from.width / to.width;
  mc.style.visibility = "hidden";
  const fly = card.animate([
    { transform: `translate(${dx}px,${dy}px) scale(${sc}) rotateY(180deg)` },
    { transform: `translate(0,-4%) scale(1.08) rotateY(-14deg)`, offset: .72 },
    { transform: `translate(0,0) scale(1) rotateY(0deg)` }
  ], { duration: 900, easing: "cubic-bezier(.2,.8,.2,1)", fill: "forwards" });
  sfx.whoosh(); vib(12);
  await fly.finished.catch(() => {});
  wrap.classList.add("shown");
  const hc = $("hc-gl"); if (!hc._h) hc._h = makeHolo(hc);
  let holoOn = !!hc._h; const ht0 = performance.now();
  if (holoOn) { const b = card.getBoundingClientRect(); hc._h.size(b.width, b.height); hc.classList.add("on");
    (function hl(now){ if (!holoOn) return; const ph = (now - ht0) / 1800 * Math.PI;
      hc._h.draw((now - ht0) / 1000, Math.sin(ph) * .9 + (TILT.mouse ? TILT.x : 0), Math.cos(ph * .7) * .4); requestAnimationFrame(hl); })(ht0); }
  const r = card.getBoundingClientRect();
  burst(r.left + r.width / 2, r.top + r.height / 2, { count: 46, spread: Math.PI * 2, power: [4, 11], kind: "star", gravity: .06 });
  sfx.reveal(); vib([20, 40, 20]);
  // a gentle idle sway while it is in view
  const sway = card.animate([{ transform: "rotateY(-6deg) rotateX(3deg)" }, { transform: "rotateY(6deg) rotateX(-3deg)" }], { duration: 1800, direction: "alternate", iterations: Infinity, easing: "ease-in-out" });
  await new Promise(res => { const t = setTimeout(res, 2600); wrap.addEventListener("click", () => { clearTimeout(t); res(); }, { once: true }); });
  sway.cancel(); holoOn = false;
  wrap.classList.remove("shown"); wrap.classList.add("leaving");
  const back = mc.getBoundingClientRect(), now = card.getBoundingClientRect();
  const bx = (back.left + back.width / 2) - (now.left + now.width / 2), by = (back.top + back.height / 2) - (now.top + now.height / 2);
  const home = card.animate([
    { transform: "translate(0,0) scale(1) rotateY(0deg)" },
    { transform: `translate(${bx}px,${by}px) scale(${back.width / now.width}) rotateY(0deg)` }
  ], { duration: 650, easing: "cubic-bezier(.6,0,.3,1)", fill: "forwards" });
  await home.finished.catch(() => {});
  mc.style.visibility = ""; wrap.hidden = true; wrap.classList.remove("leaving"); fly.cancel(); home.cancel();
}

$("form").addEventListener("submit", async e => {
  e.preventDefault();
  if (!validEmail(true)) { showStep(1); $("email").focus(); return; }
  if (!validPhone(true)) { $("phone").focus(); return; }

  const btn = $("submit"), stage = $("stage"), scene = $("scene"), d = $("done");
  sfx.unlock();
  btn.disabled = true;
  $("submit-label").textContent = t("wl.submitting");
  $("submit-icon").innerHTML = `<span class="spin"></span>`;
  document.activeElement && document.activeElement.blur();

  // the server validates, de-duplicates and issues the gift code
  const t0 = performance.now();
  let res;
  try {
    res = await api("/api/waitlist/signup", {
      email: $("email").value.trim(), phone: phoneFull(), cc: $("cc").value, region,
      consent: true, consent_text: $("consent-text").textContent.trim(),
      utm: Track.utm, ref: Track.ref, sid: Track.sid, likes: (window.Votes && Votes.likes) ? Votes.likes() : [],
      website: $("website").value, t: Date.now() - T0 });
  } catch (err) {
    btn.disabled = false;
    $("submit-label").textContent = t("wl.submit", { store: STORE_NAME, gift: GIFT_LOW });
    $("submit-icon").innerHTML = `<svg><use href="#i-gift"/></svg>`;
    if (err.field === "email") { showStep(1); setErr($("email"), "email-hint", err.message); $("email").focus(); }
    else { setErr($("phone"), "phone-hint", err.message); $("phone").focus(); }
    ticket.classList.remove("shake"); void ticket.offsetWidth; ticket.classList.add("shake"); vib([20, 40, 20]);
    return;
  }
  const code = res.code || "";
  const data = { email: $("email").value.trim(), phone: phoneFull(), code, gift: !!res.code, position: res.position, at: new Date().toISOString() };
  if (typeof res.giftsLeft === "number") { GIFTS_LEFT = res.giftsLeft; scarceLine(); }
  if (typeof res.count === "number") $("proof").textContent = t("wl.proof", { n: res.count.toLocaleString("en-US") });
  VNudge.hide();
  store.set("drop01-signup", data); store.del("drop01-draft");
  applyGift(data);

  await wait(Math.max(0, 650 - (performance.now() - t0)));
  // 1 · confirm on the button
  $("submit-label").textContent = t("wl.submitted");
  $("submit-icon").innerHTML = `<svg class="check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  await wait(320);

  // 2 · lights down, ticket steps forward
  signedUp = true; updSticky();
  stage.classList.add("focus"); scene.classList.add("on");
  ticket.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" });
  await wait(450);

  // 3 · stamp slams with a shockwave
  const stamp = $("stamp"); stamp.classList.add("hit");
  await wait(230);
  const sr = stamp.getBoundingClientRect(), foil = ticket.querySelector(".foil");
  sfx.thump();
  shockAt(foil, sr.left + sr.width / 2, sr.top + sr.height / 2);
  ticket.classList.remove("shake"); void ticket.offsetWidth; ticket.classList.add("shake");
  vib([22, 30, 12]);
  burst(sr.left + sr.width / 2, sr.top + sr.height / 2, { count: 70, spread: Math.PI * 2, power: [3, 10], kind: "spark", gravity: .05 });
  await wait(750);

  // 4 · the perforation lights up and the form half tears off
  fillDone(data);
  const body = $("ticket-body"), perf = ticket.querySelector(".perf");
  ticket.classList.remove("shake", "idle");
  perf.classList.add("rip"); vib(8);
  await wait(320);
  body.classList.add("tear"); vib([6, 20, 6, 20, 10]); sfx.rip();
  burst(innerWidth / 2, perf.getBoundingClientRect().top + 11, { count: 40, angle: Math.PI / 2, spread: Math.PI * 1.6, power: [2, 7], kind: "spark", gravity: .12 });
  await wait(760);

  // 5 · the gift side prints out under a scan beam
  $("form").hidden = true; $("final").hidden = true; document.querySelector(".trust").hidden = true;
  $("code").textContent = code; resetScratch();
  d.hidden = false; d.classList.add("big", "waiting");
  const tapped = waitForTap($("gift-btn"), 4200);
  body.classList.remove("tear"); void body.offsetWidth; body.classList.add("print"); sfx.scan();
  perf.classList.remove("rip");
  await wait(500);
  $("gift-btn").scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" });
  await wait(650);
  body.classList.remove("print");

  // 6 · the box waits for a tap (opens by itself after a few seconds)
  await tapped;
  d.classList.remove("waiting"); d.classList.add("charge"); sfx.charge();
  vib([8, 60, 8, 50, 8, 40, 12]);
  await wait(700);

  // 7 · it pops
  d.classList.remove("charge"); d.classList.add("open"); sfx.pop(); setTimeout(() => sfx.sparkle(), 500);
  slowMo(560);
  requestAnimationFrame(() => setupScratch());
  scene.classList.remove("flash"); void scene.offsetWidth; scene.classList.add("flash");
  vib([35, 40, 25]);
  const gr = $("gift-btn").getBoundingClientRect(), gx = gr.left + gr.width / 2, gy = gr.top + gr.height * .35;
  burst(gx, gy, { count: 90, angle: -Math.PI / 2, spread: .9, power: [7, 16], gravity: .2, kind: "spark" });
  burst(gx, gy, { count: 60, spread: Math.PI * 1.4, power: [5, 13] });
  burst(gx, gy, { count: 26, angle: -Math.PI / 2, spread: 1.6, power: [6, 12], gravity: .1, kind: "star" });
  setTimeout(() => {
    for (let i = 0; i < 3; i++) setTimeout(() => {
      burst(-10, innerHeight * (.75 + Math.random() * .2), { count: 55, angle: -Math.PI / 3.4, spread: .75, power: [15, 29], gravity: .24, jitter: 40 });
      burst(innerWidth + 10, innerHeight * (.75 + Math.random() * .2), { count: 55, angle: -Math.PI + Math.PI / 3.4, spread: .75, power: [15, 29], gravity: .24, jitter: 40 });
    }, i * 110);
  }, reduce ? 0 : 180);
  await wait(1250);

  // 7b · the gift card flies to the viewer, turns over, and goes back to the box
  if (data.gift) await heroCard();

  // 8 · settle: lights up, scratch card ready
  d.classList.remove("big");
  scene.classList.remove("on");
  await wait(700);
  stage.classList.remove("focus");
  if (!revealed) setupScratch();
  btn.disabled = false;
});

/* ---------- email domain chips ---------- */
const DOMAINS = ["gmail.com","icloud.com","outlook.com","hotmail.com","yahoo.com"];
function renderChips(){
  const v = $("email").value, at = v.indexOf("@"), c = $("chips");
  if (at < 1 || v.indexOf("@", at + 1) > -1) { c.hidden = true; return; }
  const part = v.slice(at + 1).toLowerCase();
  const list = DOMAINS.filter(d => d.startsWith(part) && d !== part);
  if (!list.length || DOMAINS.includes(part)) { c.hidden = true; return; }
  c.innerHTML = list.map(d => `<button type="button" data-d="${d}"><b>@</b>${d}</button>`).join("");
  c.hidden = false;
}
$("chips").addEventListener("pointerdown", e => e.preventDefault()); // keep the keyboard open
$("chips").addEventListener("click", e => {
  const b = e.target.closest("button"); if (!b) return;
  const v = $("email").value; $("email").value = v.slice(0, v.indexOf("@") + 1) + b.dataset.d;
  $("chips").hidden = true; $("suggest").hidden = true; validEmail(true); saveDraft(); $("email").dispatchEvent(new Event("input"));
});
$("email").addEventListener("input", renderChips);
$("email").addEventListener("input", () => Track.once("email_started"));
$("phone").addEventListener("input", () => { if (phoneFull().replace(/\D/g, "").length >= 9) Track.once("phone_added"); });

/* ---------- draft: keep what they typed if they leave ---------- */
function saveDraft(){ if (!signedUp) store.set("drop01-draft", { email: $("email").value, phone: $("phone").value, cc: $("cc").selectedIndex }); }
["email","phone"].forEach(id => $(id).addEventListener("input", saveDraft));
$("cc").addEventListener("change", saveDraft);
const draft = store.get("drop01-draft");
if (draft && !signedUp) {
  if (draft.email) $("email").value = draft.email;
  if (draft.phone) $("phone").value = draft.phone;
  if (typeof draft.cc === "number" && draft.cc >= 0) $("cc").selectedIndex = draft.cc;
  if (draft.email && emailOk(draft.email.trim())) $("email").classList.add("good");
}

/* ---------- tab title nudge ---------- */
const baseTitle = document.title;
document.addEventListener("visibilitychange", () => {
  document.title = document.hidden && !signedUp ? t("wl.tab_away") : baseTitle;
});

/* ---------- native share (falls back to copying the link) ---------- */
$("share-native").addEventListener("click", async () => {
  const text = t("wl.done.share_msg", { store: STORE_NAME, gift: GIFT.title.toLowerCase() });
  try { if (navigator.share) { await navigator.share({ title: STORE_NAME, text, url: pageUrl }); return; } } catch (e) { if (e && e.name === "AbortError") return; }
  copy(pageUrl);
});

/* ---------- manifesto words light up on scroll ---------- */
(function(){
  const p = $("manifesto");
  const wrapWords = node => {
    [...node.childNodes].forEach(n => {
      if (n.nodeType === 3) {
        const frag = document.createDocumentFragment();
        n.textContent.split(/(\s+)/).forEach(t => {
          if (!t) return;
          if (/^\s+$/.test(t)) frag.appendChild(document.createTextNode(t));
          else { const sp = document.createElement("span"); sp.className = "w"; sp.textContent = t; frag.appendChild(sp); }
        });
        n.replaceWith(frag);
      } else if (n.nodeType === 1) wrapWords(n);
    });
  };
  wrapWords(p);
  const ws = [...p.querySelectorAll(".w")];
  ws.forEach(w => { const t = w.textContent.replace(/[^A-Za-z]/g, "");
    if (t === "Limited") w.classList.add("k-lim"); else if (t === "Sealed") w.classList.add("k-seal"); else if (t === "Numbered") w.classList.add("k-num"); });
  if (reduce) { ws.forEach(w => w.classList.add("lit")); return; }
  let ticking = false;
  const upd = () => {
    ticking = false;
    const r = p.getBoundingClientRect(), vh = innerHeight;
    const prog = Math.min(1, Math.max(0, (vh * .85 - r.top) / (r.height + vh * .35)));
    const n = Math.round(prog * ws.length);
    ws.forEach((w, i) => w.classList.toggle("lit", i < n));
  };
  addEventListener("scroll", () => { if (!ticking) { ticking = true; requestAnimationFrame(upd); } }, { passive: true });
  upd();
})();

/* ---------- scroll rise ---------- */
(function(){
  const els = [...document.querySelectorAll(".rise, .grid .card")];
  document.querySelectorAll(".grid .card").forEach((c, i) => c.style.setProperty("--d", (i % 4) * .08 + "s"));
  if (reduce || !("IntersectionObserver" in window)) { els.forEach(e => e.classList.add("in")); return; }
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } }), { rootMargin: "0px 0px -8% 0px" });
  els.forEach(e => io.observe(e));
})();


/* ---------- scratch card ---------- */
let revealed = false, scratching = false, scratchCtx = null, lastS = null, moves = 0;
function scratchDone(instant){
  revealed = true;
  $("scratch").classList.add("gone"); $("scratch-hint").classList.add("gone");
  $("copy-label").textContent = t("wl.done.copy");
  if (!instant) { const c = $("code"); c.classList.remove("pop"); void c.offsetWidth; c.classList.add("pop"); }
}
function resetScratch(){ revealed = false; $("scratch").classList.remove("gone"); $("scratch-hint").classList.remove("gone"); $("copy-label").textContent = t("wl.done.reveal"); }
function setupScratch(){
  resetScratch(); $("code").closest(".code-wrap").classList.remove("ready");
  const cv = $("scratch"), r = cv.getBoundingClientRect(), k = Math.min(devicePixelRatio || 1, 2);
  cv.width = Math.max(10, Math.round(r.width * k)); cv.height = Math.max(10, Math.round(r.height * k));
  const x = cv.getContext("2d"); scratchCtx = x;
  const g = x.createLinearGradient(0, 0, cv.width, cv.height);
  ["#FFB3D9","#FFE7A3","#B8FFD9","#A8DCFF","#D4B8FF"].forEach((c, i) => g.addColorStop(i / 4, c));
  x.globalCompositeOperation = "source-over"; x.fillStyle = g; x.fillRect(0, 0, cv.width, cv.height);
  x.globalAlpha = .18; x.strokeStyle = "#121216"; x.lineWidth = k;
  for (let i = -cv.height; i < cv.width; i += 7 * k) { x.beginPath(); x.moveTo(i, cv.height); x.lineTo(i + cv.height, 0); x.stroke(); }
  x.globalAlpha = 1;
  x.globalCompositeOperation = "destination-out"; x.lineCap = "round"; x.lineJoin = "round"; x.lineWidth = 26 * k;
  cv.closest(".code-wrap").classList.add("ready");
}
function scratchAt(e){
  const cv = $("scratch"), r = cv.getBoundingClientRect(), k = cv.width / r.width;
  const pt = [(e.clientX - r.left) * k, (e.clientY - r.top) * k];
  const x = scratchCtx; if (!x) return;
  x.beginPath(); x.moveTo(...(lastS || pt)); x.lineTo(...pt); x.stroke(); lastS = pt;
  $("scratch-hint").classList.add("gone");
  if (moves % 3 === 0) sfx.scratch();
  if (++moves % 6 === 0) {
    try { navigator.vibrate && navigator.vibrate(4); } catch {}
    const d = x.getImageData(0, 0, cv.width, cv.height).data; let clear = 0, tot = 0;
    for (let i = 3; i < d.length; i += 4 * 24) { tot++; if (d[i] < 40) clear++; }
    if (clear / tot > .42) revealCode();
  }
}
function revealCode(){
  if (revealed) return;
  scratchDone(false);
  const r = $("code").getBoundingClientRect(); burst(r.left + r.width / 2, r.top + r.height / 2);
  try { navigator.vibrate && navigator.vibrate([20, 40, 20]); } catch {}
  toast(t("wl.done.revealed")); sfx.reveal();
}
$("scratch").addEventListener("pointerdown", e => { scratching = true; lastS = null; $("scratch").setPointerCapture(e.pointerId); scratchAt(e); });
$("scratch").addEventListener("pointermove", e => { if (scratching) scratchAt(e); });
["pointerup","pointercancel"].forEach(t => $("scratch").addEventListener(t, () => { scratching = false; lastS = null; }));

/* ---------- button ripple ---------- */
document.addEventListener("pointerdown", e => {
  const b = e.target.closest(".btn"); if (!b || reduce) return;
  const r = b.getBoundingClientRect(), d = Math.max(r.width, r.height) * 2.2, sp = document.createElement("span");
  sp.className = "ripple"; sp.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d/2}px;top:${e.clientY - r.top - d/2}px`;
  b.appendChild(sp); setTimeout(() => sp.remove(), 700);
});

/* ---------- badge easter egg ---------- */
let badgeTaps = 0;
document.querySelector(".badge").addEventListener("click", e => {
  const b = e.currentTarget; badgeTaps++;
  b.classList.remove("boost"); void b.offsetWidth; b.classList.add("boost");
  setTimeout(() => b.classList.remove("boost"), 1200);
  const r = b.getBoundingClientRect(); burst(r.left + r.width / 2, r.top + r.height / 2);
  try { navigator.vibrate && navigator.vibrate(10); } catch {}
  const lines = [t("wl.egg.1"), t("wl.egg.2"), t("wl.egg.3"), t("wl.egg.4")];
  toast(lines[(badgeTaps - 1) % lines.length]);
});


/* ---------- holographic foil: thin-film rainbow, glitter and a glare that follows the tilt ---------- */
const FOIL = { x: 0, y: 0 };                      // shared, smoothed tilt of the holographic surfaces
function makeHolo(cv){
  const gl = cv.getContext("webgl", { alpha: false, antialias: false, premultipliedAlpha: false });
  if (!gl) return null;
  const FS = `
precision mediump float;
uniform vec2 r; uniform float t; uniform vec2 tl;
float h(vec2 p){ return fract(sin(dot(p, vec2(41.3, 289.1))) * 43758.5453); }
float n(vec2 x){ vec2 i = floor(x), f = fract(x); f = f*f*(3.0-2.0*f);
  return mix(mix(h(i), h(i+vec2(1.0,0.0)), f.x), mix(h(i+vec2(0.0,1.0)), h(i+vec2(1.0,1.0)), f.x), f.y); }
void main(){
  vec2 uv = gl_FragCoord.xy / r; vec2 a = vec2(uv.x * r.x / r.y, uv.y);
  float warp = n(a*2.2 + t*0.05) * 0.6 + n(a*6.0 - t*0.03) * 0.15;
  float ph = (uv.x*0.9 + uv.y*0.55) * 1.4 + tl.x*0.9 + tl.y*0.5 + warp;
  vec3 film = 0.5 + 0.5*cos(6.2832*(ph + vec3(0.0, 0.33, 0.67)));
  film = mix(film, vec3(1.0, 0.98, 0.96), 0.56);
  vec2 c = a - vec2(0.5 * r.x / r.y, 0.5);
  float ang = atan(c.y, c.x);
  float burst = 0.5 + 0.5*sin(ang*36.0 + tl.x*3.0);
  float rings = 0.5 + 0.5*sin(length(c)*70.0 - tl.y*6.0);
  film *= 0.9 + 0.07*burst*rings;
  float lines = 0.5 + 0.5*sin((uv.x + uv.y) * r.x * 0.9);
  film += 0.025 * lines;
  vec2 gp = floor(gl_FragCoord.xy / 2.5);
  float g = h(gp);
  float glit = step(0.955, g) * pow(max(0.0, sin(t*2.2 + g*60.0 + tl.x*9.0 - tl.y*7.0)), 24.0);
  film += glit * 1.1;
  float band = (uv.x - uv.y*0.6) - (tl.x*0.55 + 0.55);
  film += exp(-band*band*40.0) * 0.38;
  film *= 0.97 + 0.06*n(gl_FragCoord.xy*0.7);
  gl_FragColor = vec4(film, 1.0);
}`;
  const mk = (ty, src) => { const o = gl.createShader(ty); gl.shaderSource(o, src); gl.compileShader(o); return gl.getShaderParameter(o, gl.COMPILE_STATUS) ? o : null; };
  const vs = mk(gl.VERTEX_SHADER, "attribute vec2 v;void main(){gl_Position=vec4(v,0.,1.);}"), fs = mk(gl.FRAGMENT_SHADER, FS);
  if (!vs || !fs) return null;
  const pr = gl.createProgram(); gl.attachShader(pr, vs); gl.attachShader(pr, fs); gl.linkProgram(pr);
  if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) return null;
  gl.useProgram(pr);
  gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1,1,-1,-1,1,1,1]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(pr, "v"); gl.enableVertexAttribArray(loc); gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const uR = gl.getUniformLocation(pr, "r"), uT = gl.getUniformLocation(pr, "t"), uTl = gl.getUniformLocation(pr, "tl");
  return {
    size(w, h){ const k = Math.min(devicePixelRatio || 1, 1.5); cv.width = Math.max(2, Math.round(w * k)); cv.height = Math.max(2, Math.round(h * k)); gl.viewport(0, 0, cv.width, cv.height); },
    draw(t, x, y){ gl.uniform2f(uR, cv.width, cv.height); gl.uniform1f(uT, t); gl.uniform2f(uTl, x, y); gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4); }
  };
}
(function(){
  if (reduce) return;
  const cv = $("foil-gl"), foil = cv.parentElement, H = makeHolo(cv);
  if (!H) return;
  const size = () => { const b = foil.getBoundingClientRect(); H.size(b.width, b.height); };
  size(); addEventListener("resize", size);
  if ("ResizeObserver" in window) new ResizeObserver(size).observe(foil);
  const t0 = performance.now(), stageS = $("stage").style, gridS = $("grid").style;
  let on = true, running = false, gridOn = false, lx = "", ly = "", lastGrid = 0;
  new IntersectionObserver(([e]) => { gridOn = e.isIntersecting; if (gridOn) wake(); }, { rootMargin: "60px" }).observe($("grid"));
  new IntersectionObserver(([e]) => { on = e.isIntersecting; if (on) wake(); }, { rootMargin: "80px" }).observe($("stage"));
  document.addEventListener("visibilitychange", () => { if (!document.hidden) wake(); });
  foil.classList.add("gl");
  function wake(){ if (!running) { running = true; requestAnimationFrame(loop); } }
  function loop(now){
    if ((!on && !gridOn) || document.hidden) { running = false; return; }
    const ph = (now - t0) / 7000 * 6.2832;
    const wantX = (TILT.mouse ? TILT.x : -Math.cos(ph)) + scrollY * 0.0015, wantY = TILT.mouse ? TILT.y : Math.sin(ph) * .5;
    FOIL.x += (wantX - FOIL.x) * .12; FOIL.y += (wantY - FOIL.y) * .12;
    const nx = Math.max(-1.5, Math.min(1.5, FOIL.x)).toFixed(3), ny = Math.max(-1, Math.min(1, FOIL.y)).toFixed(3);
    if (on) {
      H.draw((now - t0) / 1000, FOIL.x, FOIL.y);
      if (nx !== lx) { stageS.setProperty("--lx", nx); lx = nx; }
      if (ny !== ly) { stageS.setProperty("--ly", ny); ly = ny; }
    }
    if (gridOn && now - lastGrid > 66) { gridS.setProperty("--lx", nx); lastGrid = now; }   // seals: ~15 updates a second is plenty
    requestAnimationFrame(loop);
  }
  wake();
})();

/* ---------- sound design: synthesized live, no files ---------- */
var sfx;
sfx = window.sfx = (function(){
  /* Sound design: everything tonal lives in D major pentatonic so any overlap stays consonant.
     Chain: voices -> dry bus + reverb send -> soft high cut -> gentle compressor -> out. */
  let AC = null, out = null, verb = null, noise = null;
  let enabled = store.get("drop01-sound"); if (enabled === null) enabled = true;
  const btn = $("snd");
  const paint = () => { btn.setAttribute("aria-pressed", String(enabled)); btn.setAttribute("aria-label", enabled ? "Sound on" : "Sound off"); };
  paint();
  btn.addEventListener("click", () => { enabled = !enabled; store.set("drop01-sound", enabled); paint(); if (enabled) { unlock(); arp([N.D6, N.A6], .07, .06); } });
  const N = { D4: 293.66, A4: 440, D5: 587.33, E5: 659.25, Fs5: 739.99, A5: 880, B5: 987.77, D6: 1174.66, E6: 1318.51, Fs6: 1479.98, A6: 1760, B6: 1975.53, D7: 2349.32, E7: 2637.02, Fs7: 2959.96 };
  function impulse(sec, decay){
    const len = Math.floor(AC.sampleRate * sec), buf = AC.createBuffer(2, len, AC.sampleRate);
    for (let ch = 0; ch < 2; ch++) {
      const d = buf.getChannelData(ch); let lp = 0;
      for (let i = 0; i < len; i++) {
        const t = i / len, damp = .55 + .4 * t;                 // darker as it fades, like a real room
        lp = lp * damp + (Math.random() * 2 - 1) * (1 - damp);
        d[i] = lp * Math.pow(1 - t, decay) * (i < AC.sampleRate * .012 ? i / (AC.sampleRate * .012) : 1);
      }
    }
    return buf;
  }
  function unlock(){
    try {
      if (!AC) {
        AC = new (window.AudioContext || window.webkitAudioContext)();
        const comp = AC.createDynamicsCompressor();
        comp.threshold.value = -20; comp.knee.value = 24; comp.ratio.value = 3; comp.attack.value = .004; comp.release.value = .22;
        const hcut = AC.createBiquadFilter(); hcut.type = "lowpass"; hcut.frequency.value = 9500; hcut.Q.value = .5;
        const warm = AC.createBiquadFilter(); warm.type = "lowshelf"; warm.frequency.value = 220; warm.gain.value = 2.5;
        out = AC.createGain(); out.gain.value = .8;
        out.connect(warm); warm.connect(hcut); hcut.connect(comp); comp.connect(AC.destination);
        const conv = AC.createConvolver(); conv.buffer = impulse(2.4, 2.6);
        verb = AC.createGain(); verb.gain.value = .9;
        const ret = AC.createGain(); ret.gain.value = .32;
        verb.connect(conv); conv.connect(ret); ret.connect(out);
        noise = AC.createBuffer(1, AC.sampleRate * 2, AC.sampleRate);
        const d = noise.getChannelData(0); for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
      }
      if (AC.state === "suspended") AC.resume();
    } catch {}
    return AC;
  }
  const ok = () => enabled && AC && AC.state !== "closed";
  const now = d => AC.currentTime + (d || 0);
  // route a voice: dry to the output, a share to the reverb, optional stereo position
  function route(node, wet = .2, pan = 0){
    let n = node;
    if (pan && AC.createStereoPanner) { const p = AC.createStereoPanner(); p.pan.value = pan; n.connect(p); n = p; }
    n.connect(out);
    if (wet > 0) { const s = AC.createGain(); s.gain.value = wet; n.connect(s); s.connect(verb); }
  }
  // smooth envelope: no clicks at either end
  function env(g, t, a, peak, dec){
    g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(peak, t + a);
    g.gain.setTargetAtTime(0, t + a, dec / 4);
  }
  // glassy FM bell
  function bell(f, d = 0, vol = .08, dec = 1.6, wet = .35, pan = 0, ratio = 3.51){
    if (!ok()) return; const t = now(d);
    const c = AC.createOscillator(), m = AC.createOscillator(), mg = AC.createGain(), g = AC.createGain();
    c.type = "sine"; m.type = "sine"; c.frequency.value = f; m.frequency.value = f * ratio;
    mg.gain.setValueAtTime(f * 1.6, t); mg.gain.setTargetAtTime(0, t, .06);
    m.connect(mg); mg.connect(c.frequency);
    env(g, t, .004, vol, dec); c.connect(g); route(g, wet, pan);
    const h = AC.createOscillator(), hg = AC.createGain(); h.type = "sine"; h.frequency.value = f * 2.0;
    env(hg, t, .003, vol * .22, dec * .4); h.connect(hg); route(hg, wet, pan);
    [c, m, h].forEach(o => { o.start(t); o.stop(t + dec * 1.4 + .1); });
  }
  // soft tone with optional glide
  function tone(f0, f1, dur, vol, d = 0, type = "sine", wet = .15, pan = 0){
    if (!ok()) return; const t = now(d), o = AC.createOscillator(), g = AC.createGain();
    o.type = type; o.frequency.setValueAtTime(f0, t); if (f1) o.frequency.exponentialRampToValueAtTime(f1, t + dur);
    env(g, t, Math.min(.012, dur * .2), vol, dur); o.connect(g); route(g, wet, pan); o.start(t); o.stop(t + dur * 1.6 + .05);
  }
  // filtered noise, with an optional sweep
  function air(dur, vol, type, f0, f1, d = 0, q = .8, wet = .2, att = .005, pan = 0){
    if (!ok()) return; const t = now(d), src = AC.createBufferSource(), f = AC.createBiquadFilter(), g = AC.createGain();
    src.buffer = noise; src.loop = true; f.type = type; f.Q.value = q;
    f.frequency.setValueAtTime(f0, t); if (f1) f.frequency.exponentialRampToValueAtTime(f1, t + dur);
    g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol, t + att); g.gain.setTargetAtTime(0, t + att, dur / 3.5);
    src.connect(f); f.connect(g); route(g, wet, pan);
    src.start(t, Math.random()); src.stop(t + dur * 1.5 + .05);
  }
  function arp(notes, step = .07, vol = .08, wet = .4){ notes.forEach((f, i) => bell(f, i * step, vol, 1.8, wet, (i / Math.max(1, notes.length - 1) - .5) * .5)); }
  let tickAlt = false;
  return {
    unlock,
    // a soft, woody press
    tap(){ tone(1150, 720, .05, .2, 0, "sine", .05); air(.012, .12, "highpass", 3500, 0, 0, .7, 0); },
    // the countdown: a whisper of a clock
    tick(){ tickAlt = !tickAlt; air(.014, .5, "bandpass", tickAlt ? 2600 : 2200, 0, 0, 4, .05); },
    // stamp: a felt-covered thud with a little room
    thump(){ tone(120, 44, .38, .5, 0, "sine", .18); air(.09, .22, "lowpass", 520, 180, 0, .7, .15); tone(240, 90, .06, .08, 0, "triangle", 0); },
    // paper tearing: dozens of tiny fibre snaps over a soft body
    rip(){
      if (!ok()) return;
      air(.5, .14, "bandpass", 700, 1100, 0, 1.2, .1, .03);
      for (let i = 0; i < 22; i++) { const d = i * .021 + Math.random() * .012; air(.018 + Math.random() * .02, .22 + Math.random() * .28, "bandpass", 1800 + Math.random() * 2200, 0, d, 1.6, .08, .002, (Math.random() - .5) * .4); }
    },
    tear(){ air(.02 + Math.random() * .02, .5 + Math.random() * .4, "bandpass", 1900 + Math.random() * 2000, 0, 0, 1.6, .06, .002, (Math.random() - .5) * .3); },
    // printing: an airy shimmer that rises
    scan(){ air(1.1, .12, "bandpass", 900, 5500, 0, 5, .5, .15); tone(N.D5, N.A5, 1.1, .045, 0, "sine", .5); tone(N.A5 * 1.003, N.D6, 1.1, .028, 0, "sine", .5); },
    // tension before the box opens
    charge(){ air(.75, .13, "lowpass", 220, 4200, 0, 1.1, .2, .5); tone(N.D4, N.D5, .75, .035, 0, "triangle", .3); tone(N.A4, N.A5, .75, .025, 0, "triangle", .3); },
    // the box opens: impact, a warm chord blooming, a bell arpeggio on top
    pop(){
      if (!ok()) return;
      tone(110, 40, .5, .45, 0, "sine", .2); air(.35, .18, "highpass", 1200, 500, 0, .7, .35);
      [N.D5, N.Fs5, N.A5, N.D6].forEach((f, i) => { tone(f, 0, 2.4, .045, .02 + i * .01, "sine", .65, (i - 1.5) * .25); tone(f * 1.004, 0, 2.4, .02, .03 + i * .01, "triangle", .65); });
      arp([N.A5, N.D6, N.Fs6, N.A6, N.D7], .065, .07, .5);
    },
    // glints drifting left and right
    sparkle(){ const P = [N.A6, N.B6, N.D7, N.E7, N.Fs7]; for (let i = 0; i < 8; i++) bell(P[(Math.random() * P.length) | 0], i * .075 + Math.random() * .03, .025, 1.1, .6, (Math.random() - .5) * 1.4, 2.0); },
    scratch(){ air(.03, .45, "bandpass", 4500 + Math.random() * 1500, 0, 0, 2.5, .05, .003); },
    // the code is yours: an ascending chime
    reveal(){ arp([N.D6, N.Fs6, N.A6, N.D7], .075, .085, .45); },
    // a like: two bright glass notes going up
    like(){ bell(N.Fs6, 0, .1, 1.2, .3, -.15); bell(N.A6, .06, .1, 1.4, .35, .15); },
    // a pass: a soft, muted step down
    nope(){ tone(330, 247, .16, .2, 0, "sine", .1); air(.05, .14, "lowpass", 600, 0, 0, .7, .05); },
    // the intro opening: air rushing past and a low drop
    whoosh(){ air(1.1, .16, "bandpass", 2400, 260, 0, .9, .4, .12); tone(72, 34, 1.1, .22, 0, "sine", .25); },
  };
})();
document.addEventListener("pointerdown", e => { if (e.target.closest(".ticket")) Tilt.enable(); if (e.target.closest(".btn,.ghost,.chips button,.badge,#gift-btn,.faq summary,.totop,.back,.vn-x")) { sfx.unlock(); sfx.tap(); } else if (e.target.closest(".vbtn,.ph")) sfx.unlock(); }, true);




/* ---------- physical tilt: the phone's motion sensor drives the holographic ticket ---------- */
var Tilt = window.Tilt = (function(){
  let on = false, asked = false, b0 = null, tx = 0, ty = 0, cx = 0, cy = 0, running = false;
  const clamp = v => Math.max(-1, Math.min(1, v));
  function onOri(e){
    if (e.gamma == null || e.beta == null) return;
    if (b0 === null) b0 = Math.max(10, Math.min(80, e.beta));
    b0 += (Math.max(10, Math.min(80, e.beta)) - b0) * .004;   // slowly re-centre on how the phone is held
    tx = clamp(e.gamma / 24); ty = clamp((e.beta - b0) / 24);
    if (!on) { on = true; TILT.mouse = true; ticket.classList.remove("idle"); }
    if (!running) { running = true; requestAnimationFrame(loop); }
  }
  function loop(){
    cx += (tx - cx) * .14; cy += (ty - cy) * .14;
    TILT.x = cx; TILT.y = cy;
    ticket.style.setProperty("--ry", (cx * 11).toFixed(2) + "deg");
    ticket.style.setProperty("--rx", (-cy * 8).toFixed(2) + "deg");
    ticket.style.setProperty("--gx", (50 + cx * 50).toFixed(1) + "%");
    ticket.style.setProperty("--gy", (50 + cy * 50).toFixed(1) + "%");
    if (Math.abs(tx - cx) + Math.abs(ty - cy) > .002) requestAnimationFrame(loop); else running = false;
  }
  function start(){ addEventListener("deviceorientation", onOri); }
  function enable(){
    if (asked || reduce) return; asked = true;
    const D = window.DeviceOrientationEvent; if (!D) return;
    if (typeof D.requestPermission === "function") D.requestPermission().then(r => { if (r === "granted") start(); }).catch(() => {});
    else start();
  }
  // Android and others need no permission: start straight away on touch screens
  if (!reduce && window.DeviceOrientationEvent && typeof DeviceOrientationEvent.requestPermission !== "function" && matchMedia("(pointer: coarse)").matches) { asked = true; start(); }
  return { enable, get x(){ return on ? cx : 0; } };
})();

/* ---------- holographic dust: "dropping." blows away into glittering dust and reassembles ----------
   A quick scroll disintegrates the word letter by letter, left to right, carried by the motion;
   the dust swirls, then streams back and the letters reform. Tap a letter to burst it. */
(function(){
  if (reduce) return;
  const word = $("drops"), host = word.closest(".hero-copy"), ls = [...word.querySelectorAll(".jl")]; if (!ls.length) return;
  const cv = document.createElement("canvas"); cv.className = "dust"; host.appendChild(cv);
  const cx = cv.getContext("2d"), k = Math.min(devicePixelRatio || 1, 2);
  const COLS = ["#FFB3D9", "#FFE7A3", "#B8FFD9", "#A8DCFF", "#D4B8FF", "#F3F1EC"];
  const SPR = COLS.map(glowSprite);
  let parts = [], running = false, box = null, shapeCache = new Map(), busyLetters = new Set();
  function place(){
    const hr = host.getBoundingClientRect(), wr = word.getBoundingClientRect();
    const pad = { l: 20, t: wr.height * 1.4, r: 30, b: wr.height * .6 };
    box = { x: wr.left - hr.left - pad.l, y: wr.top - hr.top - pad.t, w: Math.min(hr.width + 40, wr.width + pad.l + pad.r + 60), h: wr.height + pad.t + pad.b };
    Object.assign(cv.style, { left: box.x + "px", top: box.y + "px", width: box.w + "px", height: box.h + "px" });
    cv.width = Math.ceil(box.w * k); cv.height = Math.ceil(box.h * k);
    box.ox = wr.left; box.oy = wr.top;                       // viewport origin of the canvas content at placement
    box.cvLeft = hr.left + box.x; box.cvTop = hr.top + box.y;
  }
  // sample one letter's shape into points (relative to its box)
  function shape(el){
    const ch = el.textContent, cs = getComputedStyle(el), r = el.getBoundingClientRect();
    const key = ch + "|" + Math.round(r.width) + "|" + Math.round(r.height);
    if (shapeCache.has(key)) return shapeCache.get(key);
    const w = Math.max(4, Math.ceil(r.width)), h = Math.max(4, Math.ceil(r.height));
    const c = document.createElement("canvas"); c.width = w; c.height = h; const x = c.getContext("2d");
    x.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`; try { x.fontStretch = "condensed"; } catch {}
    const m = x.measureText(ch), sc = Math.min(1.3, w / Math.max(1, m.width));
    x.save(); x.translate(w / 2, h * .78); x.scale(sc, 1); x.textAlign = "center"; x.fillStyle = "#fff"; x.fillText(ch, 0, 0); x.restore();
    const d = x.getImageData(0, 0, w, h).data, pts = [], step = Math.max(2, Math.round(Math.sqrt(w * h / 260)));
    for (let yy = 0; yy < h; yy += step) for (let xx = 0; xx < w; xx += step) if (d[(yy * w + xx) * 4 + 3] > 120) pts.push([xx, yy]);
    shapeCache.set(key, pts); return pts;
  }
  function dissolve(idxs, wind, burst){
    if (!box) place();
    const hr = host.getBoundingClientRect(), wr = word.getBoundingClientRect();
    const ox = hr.left + box.x, oy = hr.top + box.y;              // canvas origin in viewport now
    const totalW = wr.width;
    idxs.forEach(i => {
      const el = ls[i]; if (busyLetters.has(i)) return;
      const r = el.getBoundingClientRect(), pts = shape(el);
      busyLetters.add(i); el.classList.add("gone");
      const t0 = performance.now();
      pts.forEach(([px, py]) => {
        const hx = r.left - ox + px, hy = r.top - oy + py, frac = (r.left - wr.left + px) / totalW;
        const a = Math.random() * 6.283;
        parts.push({ i, hx, hy, x: hx, y: hy,
          vx: burst ? Math.cos(a) * (2 + Math.random() * 5) : wind.x * (.6 + Math.random() * .9) + (Math.random() - .5) * 1.2,
          vy: burst ? Math.sin(a) * (2 + Math.random() * 5) - 1 : wind.y * (.6 + Math.random() * .9) + (Math.random() - .5) * 1.2,
          start: t0 + (burst ? 0 : frac * 260 + Math.random() * 60), back: t0 + (burst ? 360 : 520 + frac * 200 + Math.random() * 120),
          c: Math.min(5, Math.floor(frac * 5 + Math.random() * .8)) % 6, s: .9 + Math.random() * 1.3, ph: Math.random() * 6.283 });
      });
    });
    if (!running) { running = true; requestAnimationFrame(loop); }
  }
  function loop(now){
    cx.clearRect(0, 0, cv.width, cv.height);
    cx.globalCompositeOperation = "lighter";
    const doneLetters = new Map();
    for (const p of parts) {
      if (now < p.start) { doneLetters.set(p.i, false); continue; }
      if (now < p.back) {                                         // drifting with the motion, swirling a little
        p.vx *= .965; p.vy *= .965; p.vy -= .02;
        p.x += p.vx + Math.sin(now * .004 + p.ph) * .35; p.y += p.vy + Math.cos(now * .005 + p.ph) * .3;
        doneLetters.set(p.i, false);
      } else {                                                     // streaming home
        p.x += (p.hx - p.x) * .16; p.y += (p.hy - p.y) * .16;
        const near = Math.abs(p.hx - p.x) + Math.abs(p.hy - p.y) < .6;
        if (!near) doneLetters.set(p.i, false); else if (!doneLetters.has(p.i)) doneLetters.set(p.i, true);
      }
      const z = p.s * 3.2 * k;
      cx.globalAlpha = .9; cx.drawImage(SPR[p.c], p.x * k - z / 2, p.y * k - z / 2, z, z);
    }
    // letters whose dust has fully returned come back
    doneLetters.forEach((ok, i) => { if (ok) { ls[i].classList.remove("gone"); busyLetters.delete(i); } });
    parts = parts.filter(p => busyLetters.has(p.i));
    if (parts.length) requestAnimationFrame(loop);
    else { running = false; cx.clearRect(0, 0, cv.width, cv.height); }
  }
  // scroll: a flick blows the word away in the direction of travel
  let lastY = scrollY, lastT = performance.now(), cool = 0;
  addEventListener("scroll", () => {
    const now = performance.now(), dy = scrollY - lastY, raw = now - lastT; lastY = scrollY; lastT = now;
    if (raw > 120) return;                                        // first event of a gesture: no speed yet
    const v = dy / Math.max(8, raw) * 16;                         // px per frame
    if (Math.abs(v) < 6 || now < cool) return;
    const wr = word.getBoundingClientRect(); if (wr.bottom < 0 || wr.top > innerHeight) return;
    cool = now + 900; if (!running) place();
    dissolve(ls.map((_, i) => i), { x: 2.2, y: -Math.sign(v) * Math.min(4, Math.abs(v) * .12) }, false);
    sfx.sparkle && sfx.sparkle();
  }, { passive: true });
  addEventListener("resize", () => { shapeCache.clear(); if (!running) box = null; });
  // tap a letter: it bursts into sparks and reforms
  word.addEventListener("pointerdown", e => {
    const l = e.target.closest(".jl"); if (!l) return;
    if (!running) place(); dissolve([ls.indexOf(l)], { x: 0, y: 0 }, true);
    vib(8); sfx.unlock(); sfx.like();
  });
})();

/* ---------- conversion: personal ticket, stall nudges, vote-to-join, #join links ---------- */
(function(){
  const forEl = $("gift-for"), base = forEl.textContent;
  function nameFrom(email){
    const local = (email.split("@")[0] || "").split(/[._+\-0-9]/)[0];
    if (!/^[a-zA-ZÀ-ɏЀ-ӿ]{2,14}$/.test(local)) return "";
    return local.charAt(0).toUpperCase() + local.slice(1).toLowerCase();
  }
  let lastName = "";
  function personalise(){
    const n = nameFrom($("email").value.trim()); window.__giftName = n;
    if (n === lastName) return; lastName = n;
    forEl.textContent = n ? t("wl.reserved_for", { name: n }) : base;
    forEl.classList.remove("typed"); void forEl.offsetWidth; forEl.classList.add("typed");
  }
  $("email").addEventListener("input", () => { clearTimeout(personalise._t); personalise._t = setTimeout(personalise, 220); });
  personalise();

  // stall nudges: a valid email that never continues, or a phone step left hanging
  const hint = $("nudge-hint"); let stallT;
  function nudge(btn, text){
    if (signedUp || btn.closest("[hidden]")) return;
    btn.classList.remove("wiggle"); void btn.offsetWidth; btn.classList.add("wiggle");
    hint.textContent = text; hint.hidden = false; vib(8);
  }
  function arm(){
    clearTimeout(stallT); hint.hidden = true;
    if (signedUp) return;
    if (!$("step1").hidden && emailOk($("email").value.trim())) stallT = setTimeout(() => nudge($("next"), t("wl.nudge_email")), 6000);
    else if (!$("step2").hidden) stallT = setTimeout(() => nudge($("submit"), t("wl.nudge_phone")), 9000);
  }
  ["input","focus"].forEach(ev => { $("email").addEventListener(ev, arm); $("phone").addEventListener(ev, arm); });
  $("next").addEventListener("click", () => setTimeout(arm, 50));
  $("form").addEventListener("submit", () => { clearTimeout(stallT); hint.hidden = true; });

  // #join links skip the intro and land on the form
  if (location.hash === "#join") {
    try { sessionStorage.setItem("drop01-intro", "1"); } catch {}
    window.__skipIntro = true;
  }
})();

/* vote-to-join card: shown once, after the first like, to people not yet on the list */
const VNudge = (function(){
  const el = $("vnudge"); let shown = false, hideT;
  try { shown = sessionStorage.getItem("drop01-vnudge") === "1"; } catch {}
  function hide(){ if (el.hidden) return; el.classList.add("out"); setTimeout(() => { el.hidden = true; el.classList.remove("out"); }, 300); }
  $("vn-x").addEventListener("click", hide);
  $("vn-go").addEventListener("click", () => { hide(); goToForm(); });
  return {
    offer(title){
      if (shown || signedUp) return; shown = true;
      try { sessionStorage.setItem("drop01-vnudge", "1"); } catch {}
      setTimeout(() => {
        if (signedUp) return;
        $("vn-title").textContent = t("wl.vnudge.title_on", { product: title });
        el.hidden = false; vib(6);
        clearTimeout(hideT); hideT = setTimeout(hide, 9000);
      }, 700);
    },
    hide
  };
})();

/* ---------- product votes: likes and dislikes everyone can see ----------
   Storage: the store's own database. Each browser gets a random anonymous id (kept in localStorage);
   the server keeps one vote per id per product and returns the shared counts. */
var Votes = window.Votes = (function(){
  const mine = {};                 // productId -> 1 | -1
  const counts = {};               // productId -> {up, down}
  const pending = {};              // productId -> value not yet confirmed by the server
  let readOnly = false;
  let vid = store.get("drop01-vid");
  if (typeof vid !== "string" || !/^[a-f0-9-]{16,64}$/i.test(vid)) {
    vid = crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, "0")).join("");
    store.set("drop01-vid", vid);
  }
  Object.assign(mine, store.get("drop01-votes") || {});
  Object.assign(counts, CFG.votes || {});
  const fmt = n => n >= 1e6 ? (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M" : n >= 1e4 ? Math.round(n / 1e3) + "k" : n >= 1e3 ? (n / 1e3).toFixed(1).replace(/\.0$/, "") + "k" : String(n);
  function setNum(el, n){ const t = fmt(Math.max(0, n)); if (el.textContent !== t) { el.textContent = t; el.classList.remove("roll"); void el.offsetWidth; if (!reduce) el.classList.add("roll"); } }
  function render(){
    let hot = null, hotN = MOST_WANTED_MIN - 1;
    document.querySelectorAll(".vote").forEach(v => {
      const id = v.dataset.id, c = counts[id] || { up: 0, down: 0 }, m = mine[id] || 0;
      const up = v.querySelector(".up"), down = v.querySelector(".down");
      setNum(up.querySelector(".vn"), c.up); setNum(down.querySelector(".vn"), c.down);
      up.setAttribute("aria-pressed", String(m === 1)); down.setAttribute("aria-pressed", String(m === -1));
      v.classList.toggle("locked", readOnly);
      const line = v.nextElementSibling, tot = c.up + c.down;
      if (!tot) line.textContent = t("wl.vote.first");
      else if (c.up === 0) line.textContent = t(c.down === 1 ? "wl.vote.pass_one" : "wl.vote.pass_many", { n: fmt(c.down) });
      else line.innerHTML = th(c.up === 1 ? "wl.vote.want_one" : "wl.vote.want_many", { n: fmt(c.up), pct: Math.round(c.up / tot * 100) });
      if (c.up > hotN) { hotN = c.up; hot = id; }
    });
    document.querySelectorAll(".card").forEach(card => {
      const isHot = card.dataset.id === hot, tag = card.querySelector(".tag");
      card.classList.toggle("hot", isHot); tag.classList.toggle("hot", isHot);
      const p = PRODUCTS.find(x => (x.id || "") === card.dataset.id);
      tag.textContent = isHot ? t("wl.vote.hot") : STORE_NAME;
    });
  }
  function apply(c){
    if (!c || Object.keys(pending).length) return;      // never overwrite an optimistic vote that is still in flight
    for (const k in counts) delete counts[k]; Object.assign(counts, c); render();
  }
  async function flush(){
    store.set("drop01-votes", mine);
    for (const id of Object.keys(pending)) {
      const value = pending[id]; delete pending[id];
      try { const r = await api("/api/votes", { visitor: vid, productId: id, value }); if (!Object.keys(pending).length) apply(r.counts); }
      catch (e) { if (e.status === 429) toast(t("wl.vote.slow")); }
    }
  }
  let flushT;
  function cast(id, v, el){
    if (readOnly) { toast(t("wl.vote.closed")); return; }
    const prev = mine[id] || 0, next = prev === v ? 0 : v;
    const c = counts[id] = counts[id] || { up: 0, down: 0 };
    if (prev === 1) c.up--; if (prev === -1) c.down--;
    if (next === 1) c.up++; if (next === -1) c.down++;
    if (next) mine[id] = next; else delete mine[id];
    pending[id] = next; Track.send("vote", { product: id, value: String(next) });
    render();
    if (el && next) {
      el.classList.remove("pop"); void el.offsetWidth; el.classList.add("pop");
      const r = el.getBoundingClientRect();
      if (next === 1) { burst(r.left + r.width / 2, r.top + r.height / 2, { count: 22, spread: Math.PI * 2, power: [2, 6], kind: "spark", gravity: .04 }); sfx.like(); vib(10);
        const card = el.closest(".card"); VNudge.offer(card ? card.querySelector("h3").textContent : "this");
        const hf = document.createElement("i"); hf.className = "vheart"; hf.innerHTML = `<svg viewBox="0 0 24 24"><use href="#i-heart"/></svg>`;
        hf.style.left = (r.left + r.width / 2) + "px"; hf.style.top = r.top + "px"; document.body.appendChild(hf); setTimeout(() => hf.remove(), 1000); }
      else { sfx.nope(); vib(6); }
    }
    clearTimeout(flushT); flushT = setTimeout(flush, 350);
  }
  async function load(){
    try {
      const r = await api("/api/votes?v=" + encodeURIComponent(vid));
      if (Object.keys(pending).length) return;
      for (const k in mine) delete mine[k]; Object.assign(mine, r.mine || {}); store.set("drop01-votes", mine);
      apply(r.counts);
    } catch {}
  }
  // other people's votes arrive every 20 s while the products are on screen
  let gridOn = false;
  if ("IntersectionObserver" in window) new IntersectionObserver(([e]) => { gridOn = e.isIntersecting; }, { rootMargin: "200px 0px" }).observe($("grid"));
  setInterval(() => { if (!document.hidden && gridOn) load(); }, 20000);
  render(); load();
  const grid = document.getElementById("grid");
  grid.addEventListener("click", e => {
    const b = e.target.closest(".vbtn"); if (!b) return;
    cast(b.closest(".vote").dataset.id, Number(b.dataset.v), b);
  });
  // double-tap a photo to like it
  let lastTap = { id: null, t: 0 };
  grid.addEventListener("pointerup", e => {
    const ph = e.target.closest(".ph"); if (!ph) return;
    const now = performance.now(), id = ph.dataset.id;
    if (lastTap.id === id && now - lastTap.t < 340) {
      lastTap = { id: null, t: 0 };
      const h = document.createElement("i"); h.className = "dheart"; h.innerHTML = `<svg viewBox="0 0 24 24"><use href="#i-heart"/></svg>`;
      ph.appendChild(h); setTimeout(() => h.remove(), 950);
      if ((mine[id] || 0) !== 1) cast(id, 1, ph.closest(".card").querySelector(".vbtn.up"));
      else { sfx.like(); vib(8); }
    } else lastTap = { id, t: now };
  });
  grid.addEventListener("dblclick", e => { if (e.target.closest(".ph")) e.preventDefault(); });
  return { render, likes: () => Object.keys(mine).filter(k => mine[k] === 1) };
})();


/* ---------- page polish: timeline, FAQ, wordmark, progress, peek ---------- */
(function(){
  // timeline dates and live position
  const fmtD = ms => new Date(ms).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
  $("tl-d1").textContent = fmtD(target - 864e5);
  $("tl-d2").textContent = fmtD(target);
  const items = [...document.querySelectorAll(".tl-i")], now = Date.now();
  const stage = now >= target ? 2 : now >= target - 864e5 ? 1 : 0;
  items[stage].classList.add("now");
  const tl = $("tl");
  // footer wordmark size
  const wm = $("wordmark");
  function fitWM(){ wm.style.fontSize = "100px"; const w = wm.querySelector(".wm-o").scrollWidth || 1; wm.style.fontSize = Math.floor(100 * wm.clientWidth / w * .98) + "px"; }
  fitWM(); (document.fonts && document.fonts.ready || Promise.resolve()).then(fitWM); addEventListener("resize", fitWM);
  // one rAF-batched scroll handler
  const bar = $("sprog"); let tick = false;
  const phs = [...document.querySelectorAll(".ph")], gridEl = $("grid"), docEl = document.documentElement;
  let docH = docEl.scrollHeight; addEventListener("resize", () => { docH = docEl.scrollHeight; });
  if ("ResizeObserver" in window) new ResizeObserver(() => { docH = docEl.scrollHeight; }).observe(document.body);
  function onScroll(){
    tick = false;
    // --- reads ---
    const vh = innerHeight, y = scrollY, max = docH - vh;
    const phR = phs.map(ph => ph.getBoundingClientRect());
    const r = tl.getBoundingClientRect();
    const litR = items.map(li => li.getBoundingClientRect().top);
    const wmTop = wm.classList.contains("in") ? 0 : wm.getBoundingClientRect().top;
    // --- writes ---
    bar.style.transform = `scaleX(${max > 0 ? Math.min(1, y / max) : 0})`;
    gridEl.style.setProperty("--sh", ((y * .08) % 360).toFixed(1));
    phR.forEach((pr, i) => { if (pr.bottom < 0 || pr.top > vh) return; phs[i].style.setProperty("--py", (((pr.top + pr.height / 2) - vh / 2) / vh).toFixed(3)); });
    const prog = Math.min(1, Math.max(0, (vh * .7 - r.top) / r.height));
    tl.style.setProperty("--tp", reduce ? 1 : prog.toFixed(3));
    items.forEach((li, i) => li.classList.toggle("lit", reduce || litR[i] + 12 < vh * .7));
    if (!wm.classList.contains("in") && wmTop < vh * .92) wm.classList.add("in");
  }
  addEventListener("scroll", () => { if (!tick) { tick = true; requestAnimationFrame(onScroll); } }, { passive: true });
  onScroll();
  $("totop").addEventListener("click", () => scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" }));
  // FAQ: smooth open and close, one at a time
  document.querySelectorAll(".faq details").forEach(d => {
    const sum = d.querySelector("summary"), p = d.querySelector("p");
    const ans = document.createElement("div"); ans.className = "ans"; p.replaceWith(ans); ans.appendChild(p);
    sum.addEventListener("click", e => {
      if (reduce) return;
      e.preventDefault();
      if (d.open) {
        const h = ans.offsetHeight;
        ans.animate([{ height: h + "px", opacity: 1 }, { height: "0px", opacity: 0 }], { duration: 280, easing: "cubic-bezier(.4,0,.2,1)" }).onfinish = () => { d.open = false; };
      } else {
        document.querySelectorAll(".faq details[open]").forEach(o => { if (o !== d) o.querySelector("summary").click(); });
        d.open = true; const h = ans.offsetHeight;
        ans.animate([{ height: "0px", opacity: 0 }, { height: h + "px", opacity: 1 }], { duration: 340, easing: "cubic-bezier(.2,.8,.2,1)" });
      }
    });
  });
  // hold a sealed photo to peek
  document.querySelectorAll(".ph").forEach(ph => {
    if (!ph.querySelector("img.tease")) return;
    let t;
    ph.addEventListener("pointerdown", () => { t = setTimeout(() => { ph.classList.add("peek"); vib(8); }, 260); });
    ["pointerup","pointerleave","pointercancel"].forEach(ev => ph.addEventListener(ev, () => { clearTimeout(t); ph.classList.remove("peek"); }));
    ph.addEventListener("contextmenu", e => e.preventDefault());
  });
})();


/* ---------- headings reveal, desktop cursor ---------- */
(function(){
  const hs = [...document.querySelectorAll("section h2.display, .drop-head h2")].filter(h => !h.closest(".final") && !h.closest(".done"));
  hs.forEach(h => {
    h.classList.add("hrev");
    let w = 0;
    h.innerHTML = h.textContent.trim().split(/\s+/).map(t => `<span class="hw"><span style="--w:${w++}">${esc(t)}</span></span>`).join(" ");
  });
  if (reduce || !("IntersectionObserver" in window)) { hs.forEach(h => h.classList.add("in")); return; }
  const io = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return;
    const h = e.target; h.classList.add("in"); io.unobserve(h);
    setTimeout(() => h.classList.add("glint"), 650);
  }), { rootMargin: "0px 0px -12% 0px" });
  hs.forEach(h => io.observe(h));
})();
(function(){
  if (reduce || !matchMedia("(hover: hover) and (pointer: fine)").matches) return;
  const c = $("cur"); let x = -100, y = -100, cx = x, cy = y, run = false;
  const loop = () => { cx += (x - cx) * .22; cy += (y - cy) * .22; c.style.transform = `translate3d(${cx}px,${cy}px,0)`;
    if (Math.abs(x - cx) + Math.abs(y - cy) > .3) requestAnimationFrame(loop); else run = false; };
  addEventListener("pointermove", e => { if (e.pointerType !== "mouse") return; x = e.clientX; y = e.clientY; c.classList.add("on");
    c.classList.toggle("big", !!e.target.closest("button,a,summary,.ph,.badge,input,select,.jl"));
    if (!run) { run = true; requestAnimationFrame(loop); } }, { passive: true });
  addEventListener("pointerdown", () => c.classList.add("down")); addEventListener("pointerup", () => c.classList.remove("down"));
  document.addEventListener("mouseleave", () => c.classList.remove("on"));
})();


/* ---------- ticket details: embossed email, live buttons, hole punch ---------- */
const PLACE = "•••• •••• •••• ••••";
let embossed = "";
function emboss(){
  const el = $("emboss"), v = $("email").value.trim().toLowerCase();
  if (!v) { el.textContent = PLACE; embossed = ""; return; }
  if (v.startsWith(embossed) && v.length === embossed.length + 1 && !reduce) {
    const sp = document.createElement("span"); sp.textContent = v.slice(-1);
    if (!embossed) el.textContent = "";
    el.appendChild(sp);
  } else el.textContent = v;
  embossed = v;
}
function live(){
  const n = $("next"), sb = $("submit");
  const eOk = emailOk($("email").value.trim()), pOk = phoneFull().replace(/\D/g, "").length >= 9;
  [[n, eOk], [sb, pOk]].forEach(([b, ok]) => {
    const was = !b.classList.contains("dim");
    b.classList.toggle("dim", !ok);
    if (ok && !was && !reduce) { b.classList.remove("ready"); void b.offsetWidth; b.classList.add("ready"); vib(6); }
  });
}
$("email").addEventListener("input", () => { emboss(); live(); });
["change", "blur", "focus"].forEach(ev => $("email").addEventListener(ev, () => { emboss(); live(); }));
["change", "blur"].forEach(ev => $("phone").addEventListener(ev, live));
setTimeout(() => { emboss(); live(); }, 800);   // catches browser autofill
$("phone").addEventListener("input", live); $("cc").addEventListener("change", live);
let punched = false;
function punchTicket(){
  if (punched) return; punched = true;
  const p = $("punch"); p.classList.add("on");
  if (reduce) return;
  sfx.tick(); sfx.thump(); vib([10, 20, 10]);
  const r = p.getBoundingClientRect(), c = document.createElement("i");
  c.className = "chad"; c.style.left = r.left + "px"; c.style.top = r.top + "px";
  document.body.appendChild(c); setTimeout(() => c.remove(), 1100);
}
emboss(); live();


/* ---------- section tones ---------- */
(function(){
  const map = [[$("grid").closest("section"), "violet"], [$("tl").closest("section"), "warm"], [$("faq").closest("section"), "cool"], [$("final"), "warm"], [document.querySelector(".hero"), ""]];
  if (!("IntersectionObserver" in window)) return;
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { const m = map.find(x => x[0] === e.target); document.body.dataset.tone = m ? m[1] : ""; } }), { rootMargin: "-45% 0px -45% 0px" });
  map.forEach(([el]) => el && io.observe(el));
})();


/* ---------- performance: pause what nobody can see ---------- */
(function(){
  if (!("IntersectionObserver" in window)) return;
  const els = [".marquee-clip", ".manifesto", "#grid", "#tl", ".faq", "#final", "footer", "header"].map(q => document.querySelector(q)).filter(Boolean);
  const io = new IntersectionObserver(es => es.forEach(e => e.target.classList.toggle("zz", !e.isIntersecting)), { rootMargin: "120px 0px" });
  els.forEach(el => io.observe(el));
  // the hero's own decorations (headline breathing, aura, badge) rest once it has scrolled away
  const hero = document.querySelector(".hero-copy"), aura = document.querySelector(".aura");
  new IntersectionObserver(([e]) => hero.classList.toggle("zz", !e.isIntersecting), { rootMargin: "80px 0px" }).observe(hero);
  new IntersectionObserver(([e]) => aura.classList.toggle("zz", !e.isIntersecting), { rootMargin: "80px 0px" }).observe($("stage"));
})();

/* ---------- boot ---------- */
const saved = store.get("drop01-signup");
if (saved && saved.email && (saved.code || saved.gift === false)) showDoneInstant(saved);
startIntro();

// Browser tests (Playwright, Chromium, 390×844 mobile with touch) against an installed store.
// Usage: BASE=http://localhost:8091 E2E_EMAIL=… E2E_PASS=… node tools/e2e/run.mjs (OFFLINE=1 skips the live PDOK check)
import { chromium } from "playwright";
import fs from "node:fs";
import path from "node:path";
import zlib from "node:zlib";
import { fileURLToPath } from "node:url";

const BASE = process.env.BASE || "http://localhost:8091";
const EMAIL = process.env.E2E_EMAIL || "owner@example.com", PASS = process.env.E2E_PASS || "correct-horse-1";
const DIR = path.dirname(fileURLToPath(import.meta.url)), SHOTS = path.join(DIR, "shots");
fs.mkdirSync(SHOTS, { recursive: true });
const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, locale: "nl-NL",
  userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1" };
const uniq = Date.now().toString(36);

const browser = await chromium.launch();
const tests = [];
const test = (name, fn) => tests.push([name, fn]);
const assert = (c, msg) => { if (!c) throw new Error(msg); };
async function phone(opts = {}){
  const ctx = await browser.newContext({ ...PHONE, ...opts });
  const page = await ctx.newPage();
  page.errors = [];
  page.on("pageerror", e => page.errors.push(e.message));
  page.on("console", m => { if (m.type() === "error" && !/favicon|Failed to load resource/.test(m.text())) page.errors.push(m.text()); });
  return { ctx, page };
}
async function swipeOpen(page){
  const W = 390, y = 520;
  await page.mouse.move(40, y); await page.mouse.down();
  for (let x = 40; x <= 360; x += 20) { await page.mouse.move(x, y); await page.waitForTimeout(16); }
  await page.mouse.up();
}
/** A real JPEG-sized PNG made in memory, so uploads go through finfo + GD. */
function pngFile(w = 900, h = 1100){
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) { raw[y * (w * 3 + 1)] = 0; for (let x = 0; x < w; x++) { const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = x % 256; raw[o + 1] = y % 256; raw[o + 2] = 160; } }
  const crc = b => { let c, t = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } let r = 0xffffffff; for (const x of b) r = t[(r ^ x) & 255] ^ (r >>> 8); return (r ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const l = Buffer.alloc(4); l.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const f = path.join(DIR, "fixture.png");
  fs.writeFileSync(f, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk("IHDR", ihdr), chunk("IDAT", zlib.deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]));
  return f;
}
async function noHorizontalScroll(page, label){
  const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert(over <= 0, `${label}: horizontal scroll of ${over}px at ${page.viewportSize().width}px`);
}

// ---------------------------------------------------------------- waitlist

test("intro: a tap does not open the pack, a swipe does and the site appears", async () => {
  const { ctx, page } = await phone();
  await page.goto(BASE + "/");
  await page.waitForSelector("#intro.ready", { timeout: 8000 });
  await page.screenshot({ path: path.join(SHOTS, "waitlist-intro-390.png") });
  await page.mouse.click(195, 520); await page.waitForTimeout(1500);
  assert(await page.$("#intro") && !(await page.$("#intro.opened")), "a tap must not open the pack");
  await swipeOpen(page);
  await page.waitForSelector("#intro", { state: "detached", timeout: 15000 });
  assert(!(await page.evaluate(() => document.documentElement.classList.contains("booting"))), "site still booting");
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(SHOTS, "waitlist-site-390.png") });
  await page.screenshot({ path: path.join(SHOTS, "waitlist-full-390.png"), fullPage: true });
  await noHorizontalScroll(page, "waitlist 390");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("intro: prefers-reduced-motion skips straight to the site", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  await page.goto(BASE + "/");
  await page.waitForTimeout(800);
  assert(!(await page.$("#intro")), "intro should not show with reduced motion");
  assert(await page.isVisible("#email"), "form visible");
  await ctx.close();
});

test("waitlist: no horizontal scroll at 360 px, no Google Fonts call, legal links", async () => {
  const { ctx, page } = await phone({ viewport: { width: 360, height: 760 }, reducedMotion: "reduce" });
  const external = [];
  page.on("request", r => { if (!r.url().startsWith(BASE)) external.push(r.url()); });
  await page.goto(BASE + "/"); await page.waitForTimeout(800);
  await noHorizontalScroll(page, "waitlist 360");
  assert(!external.length, "external requests: " + external.join(", "));
  assert(await page.$('a[href="/pages/privacy"]'), "privacy link");
  await ctx.close();
});

let SIGNUP_EMAIL = `e2e.${uniq}@example.com`, SIGNUP_CODE = null;
test("signup: email → phone → server gift code and position; reload remembers it", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  await page.goto(BASE + "/"); await page.waitForTimeout(2000);   // the form refuses instant bot submits
  await page.fill("#email", SIGNUP_EMAIL);
  await page.click("#next");
  await page.fill("#phone", "6 " + String(10000000 + Math.floor(Math.random() * 89999999)));
  await page.click("#submit");
  await page.waitForSelector("#done:not([hidden])", { timeout: 10000 });
  SIGNUP_CODE = await page.textContent("#code");
  assert(/^D01-[A-Z2-9]{4}$/.test(SIGNUP_CODE), "gift code from server, got " + SIGNUP_CODE);
  const t = await page.textContent("#done-text");
  assert(/#\d+ in line/.test(t), "position shown: " + t);
  await page.screenshot({ path: path.join(SHOTS, "waitlist-done-390.png") });
  await page.reload(); await page.waitForTimeout(800);
  assert(await page.isVisible("#done"), "done state after reload");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("signup: same email with another phone is refused without leaking the code", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  await page.goto(BASE + "/"); await page.waitForTimeout(2000);
  await page.fill("#email", SIGNUP_EMAIL); await page.click("#next");
  await page.fill("#phone", "6 99998888"); await page.click("#submit");
  await page.waitForFunction(() => document.getElementById("email-hint").textContent.length > 0, null, { timeout: 8000 });
  assert(/already on the list/.test(await page.textContent("#email-hint")), "explains duplicate");
  assert(await page.isHidden("#done"), "no success card");
  await ctx.close();
});

test("votes: counts are shared between two phones", async () => {
  const a = await phone({ reducedMotion: "reduce" }), b = await phone({ reducedMotion: "reduce" });
  await a.page.goto(BASE + "/"); await b.page.goto(BASE + "/");
  const sel = ".card:nth-child(3) .vbtn.up";
  const before = +(await a.page.textContent(sel + " .vn"));
  await a.page.click(sel);
  await a.page.waitForTimeout(900);    // debounce + POST
  assert(+(await a.page.textContent(sel + " .vn")) === before + 1, "optimistic +1");
  await b.page.reload(); await b.page.waitForTimeout(500);
  assert(+(await b.page.textContent(sel + " .vn")) === before + 1, "other phone sees the like");
  await b.page.click(sel); await b.page.waitForTimeout(900);
  await a.page.reload(); await a.page.waitForTimeout(500);
  assert(+(await a.page.textContent(sel + " .vn")) === before + 2, "first phone sees the second like");
  assert((await a.page.getAttribute(sel, "aria-pressed")) === "true", "own vote remembered");
  await a.ctx.close(); await b.ctx.close();
});

// ---------------------------------------------------------------- admin

async function admin(opts){
  const { ctx, page } = await phone(opts);
  await page.goto(BASE + "/admin");
  await page.fill("#lEmail", EMAIL); await page.fill("#lPass", PASS); await page.click("#lBtn");
  await page.waitForSelector("#app:not([hidden]) #s-home .kpi", { timeout: 8000 });
  return { ctx, page };
}

test("admin: wrong password is refused", async () => {
  const { ctx, page } = await phone();
  await page.goto(BASE + "/admin");
  await page.fill("#lEmail", EMAIL); await page.fill("#lPass", "wrong-password"); await page.click("#lBtn");
  await page.waitForFunction(() => document.getElementById("loginErr").textContent.length > 0);
  assert(await page.isHidden("#app"), "app stays hidden");
  await ctx.close();
});

test("admin: Today and Waitlist show real numbers, signup is listed, CSV has BOM", async () => {
  const { ctx, page } = await admin();
  await page.screenshot({ path: path.join(SHOTS, "admin-today-390.png"), fullPage: true });
  await noHorizontalScroll(page, "admin today");
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=waitlist]");
  await page.waitForSelector("#s-waitlist .fstep", { timeout: 8000 });
  await page.waitForTimeout(700);
  const total = +(await page.textContent("#total")).replace(/,/g, "");
  assert(total >= 1, "waitlist total " + total);
  const funnel = await page.$$eval("#fun .fstep .l b", els => els.map(e => +e.textContent.replace(/,/g, "")));
  assert(funnel[0] >= 1 && funnel[4] >= 1, "funnel has visits and joins: " + funnel);
  assert(funnel[1] >= 1, "pack opens counted: " + funnel);
  assert((await page.textContent("#geo")).includes("Netherlands"), "country from Accept-Language: " + await page.textContent("#geo"));
  await page.fill("#q", SIGNUP_EMAIL.slice(0, 12)); await page.waitForTimeout(700);
  assert((await page.textContent("#list")).includes(SIGNUP_EMAIL.slice(0, 2)), "signup listed");
  await page.click("#list details summary");
  assert((await page.textContent("#list details dl")).includes(SIGNUP_CODE), "full details show the code");
  await page.screenshot({ path: path.join(SHOTS, "admin-waitlist-390.png"), fullPage: true });
  await noHorizontalScroll(page, "admin waitlist");
  const csv = await page.evaluate(async () => { const r = await fetch("/api/admin/waitlist/export.csv"); return Array.from(new Uint8Array(await r.arrayBuffer()).slice(0, 3)).concat([...(await (await fetch("/api/admin/waitlist/export.csv")).text()).slice(0, 0)]); });
  assert(csv.join(",") === "239,187,191", "UTF-8 BOM, got " + csv);
  const text = await page.evaluate(async () => (await fetch("/api/admin/waitlist/export.csv")).text());
  assert(text.includes(SIGNUP_EMAIL) && text.includes(SIGNUP_CODE), "CSV contains the signup");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("admin: add a product with a photo; it shows on the waitlist", async () => {
  const { ctx, page } = await admin();
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=stock]");   // on phones Stock sits under More, next to the Inbox tab
  await page.waitForSelector("#prods .prod");
  await page.click("[data-act=newProduct]");
  const name = "E2E Hoodie " + uniq;
  await page.fill("#pForm [name=name]", name); await page.fill("#pForm [name=price]", "59,90"); await page.fill("#pForm [name=cost]", "22,00");
  await page.fill("#pForm [name=run_size]", "40");
  await page.click("#pForm label.tog:has([name=reveal])");
  assert(await page.isChecked("#pForm [name=reveal]"), "reveal toggled");
  await page.click("#pForm [type=submit]");
  await page.waitForSelector("#pPhoto", { state: "attached", timeout: 8000 });
  await page.setInputFiles("#pPhoto", pngFile());
  await page.waitForSelector("#photos figure img", { timeout: 15000 });
  const src = await page.getAttribute("#photos figure img", "src");
  assert(/^\/uploads\/products\/\d{4}-\d{2}\/[a-f0-9]{24}-600\.(webp|jpg)$/.test(src), "resized random file name: " + src);
  const r = await page.request.get(BASE + src); assert(r.ok() && /image\//.test(r.headers()["content-type"]), "photo served as image");
  await page.screenshot({ path: path.join(SHOTS, "admin-product-390.png"), fullPage: true });
  await ctx.close();
  const v = await phone({ reducedMotion: "reduce" });
  await v.page.goto(BASE + "/");
  const card = v.page.locator(".card", { hasText: name });
  assert(await card.count() === 1, "product card on waitlist");
  assert(await card.locator("img[srcset]").count() === 1, "revealed photo with srcset");
  await v.ctx.close();
});

test("admin: settings save (gift title) is reflected on the waitlist", async () => {
  const { ctx, page } = await admin();
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=settings]");
  await page.waitForSelector("#set-waitlist form");
  const title = "A free holo sticker " + uniq;
  await page.fill("#set-waitlist [name='gift.title']", title);
  await page.click("#set-waitlist [type=submit]");
  await page.waitForFunction(() => document.getElementById("toast").textContent.includes("Saved"));
  await page.screenshot({ path: path.join(SHOTS, "admin-settings-390.png"), fullPage: true });
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=system]");
  await page.waitForSelector("#s-system .chk");
  await page.screenshot({ path: path.join(SHOTS, "admin-system-390.png"), fullPage: true });
  await noHorizontalScroll(page, "admin system");
  await ctx.close();
  const v = await phone({ reducedMotion: "reduce" });
  await v.page.goto(BASE + "/");
  assert((await v.page.textContent("#gift-title")) === title, "gift title updated on the page");
  await v.ctx.close();
});

test("legal pages render with placeholders and no horizontal scroll", async () => {
  const { ctx, page } = await phone({ viewport: { width: 360, height: 760 } });
  for (const p of ["privacy", "terms", "returns", "contact"]) {
    await page.goto(BASE + "/pages/" + p);
    assert(await page.$("h1"), p + " has a title");
    await noHorizontalScroll(page, p);
  }
  assert(await page.$("mark"), "placeholders are highlighted");
  await ctx.close();
});

// ---------------------------------------------------------------- shop (runs last: it switches the homepage to the shop)

// the admin has no native confirm()/prompt() any more: one showing up counts as a JS error
const accept = page => page.on("dialog", d => { page.errors.push("native dialog: " + d.message()); d.accept(); });
/** Clicks OK in the admin's own confirm sheet (ask()). */
const askOk = async page => { await page.waitForSelector("#ask.on #askOk"); await page.click("#askOk"); };
async function stockOf(page, slug){
  await page.goto(BASE + "/p/" + slug);
  await page.waitForSelector("#v-product.on .stockbar");
  return +(await page.textContent("#v-product .stockbar b"));
}

test("mode switch: Settings → Shop mode serves the shop at /, the waitlist stays at /waitlist", async () => {
  const { ctx, page } = await admin(); accept(page);
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=settings]");
  await page.waitForSelector("#set-waitlist form");
  // an error stays on screen, in red, until it is closed
  await page.fill("#testTo", ""); await page.click("[data-act=testMail]");
  await page.waitForSelector("#errs .err");
  await page.waitForTimeout(3000);
  assert(/Enter the address/.test(await page.textContent("#errs")), "the error is still shown after 3 s");
  await page.click("#errs .err button");
  assert(!(await page.$("#errs .err")), "closing the error removes it");
  await page.check("#set-waitlist input[name=store_mode][value=shop]", { force: true });
  // leaving with unsaved changes asks in the app's own sheet; "Stay" keeps you there
  await page.click(".nav button[data-s=home]");
  await page.waitForSelector("#ask.on");
  await page.click("#askNo");
  assert(await page.isVisible("#s-settings"), "Stay keeps Settings open");
  await page.click("#set-waitlist [type=submit]");
  await askOk(page);
  await page.waitForFunction(() => document.getElementById("toast").textContent.includes("Saved"));
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
  const v = await phone({ reducedMotion: "reduce" });
  await v.page.goto(BASE + "/");
  await v.page.waitForSelector("#v-home.on #fan .card, #v-home.on #grid .pcard");
  assert(await v.page.locator("#grid .pcard").count() >= 4, "catalogue rendered from the database");
  await v.page.screenshot({ path: path.join(SHOTS, "shop-home-390.png"), fullPage: true });
  await noHorizontalScroll(v.page, "shop home");
  await v.page.setViewportSize({ width: 360, height: 760 }); await noHorizontalScroll(v.page, "shop home 360");
  const w = await v.page.request.get(BASE + "/waitlist");
  assert(w.ok() && /DROP_CONFIG/.test(await w.text()), "waitlist still reachable");
  assert(!v.page.errors.length, "JS errors: " + v.page.errors.join(" | "));
  await v.ctx.close();
});

test("shop: postcode check, sold-out notify, gift only for waitlist members", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  await page.goto(BASE + "/");
  await page.fill("#pcIn", "9742 BD"); await page.click("[data-act=checkPc]");
  await page.waitForSelector("#pcRes:not([hidden])");
  assert(/Paddepoel/.test(await page.textContent("#pcRes")), "zone from the database: " + await page.textContent("#pcRes"));
  await page.fill("#pcIn", "1012 AB"); await page.click("[data-act=checkPc]");
  await page.waitForFunction(() => !/Paddepoel/.test(document.getElementById("pcRes").textContent));
  const notify = page.locator("#grid [data-notify]").first();
  if (await notify.count()) {
    await notify.click(); await page.waitForSelector("#drawer.on #nfContact");
    await page.fill("#nfContact", "notify." + uniq + "@example.com"); await page.click("#nfForm [type=submit]");
    await page.waitForFunction(() => document.getElementById("toast").textContent.includes("back"));
  }
  const g = async (email) => (await (await page.request.post(BASE + "/api/gift-check", { data: { email, phone: "" } })).json()).gift;
  assert(await g(SIGNUP_EMAIL) === true, "waitlist member gets the gift");
  assert(await g("stranger." + uniq + "@example.com") === false, "a stranger does not");
  await ctx.close();
});

let ORDER_URL = null;
test("shop: bag → checkout (Tikkie) → reserved → paid in the CRM; stock, slot, gift and invoice update", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  const before = await stockOf(page, "item-06");
  // add 2 from the product page
  await page.click("#qp");
  await page.click("#buy");
  await page.waitForSelector("#drawer.on [data-co]");
  assert(/2/.test(await page.textContent("#cartN")), "bag counter");
  await page.screenshot({ path: path.join(SHOTS, "shop-bag-390.png") });
  await page.click("#cartFoot [data-co='']");
  await page.waitForSelector("#v-checkout.on #coForm");
  await page.fill("#nm", "Test Buyer"); await page.fill("#em", SIGNUP_EMAIL); await page.fill("#ph", "+31 6 1234 5678");
  await page.dispatchEvent("#em", "change");
  await page.fill("#coPc", "9742 BD"); await page.fill("#coNo", "165");   // a real address: PDOK fills in the street
  await page.waitForFunction(() => !document.querySelector("#coAddr .confirming"), null, { timeout: 15000 });
  if (process.env.OFFLINE && await page.$("#coStreet")) await page.fill("#coStreet", "Zonnelaan");
  else assert(/Zonnelaan 165/.test(await page.textContent("#coAddr")), "PDOK address lookup: " + await page.textContent("#coAddr"));
  await page.waitForSelector("#b2.valid");
  await page.waitForFunction(() => /gift/.test(document.getElementById("sumT").textContent) || /🎁/.test(document.getElementById("sum").innerHTML), null, { timeout: 5000 });
  const slotId = +(await page.getAttribute(".slot[aria-pressed=true]", "data-i"));
  assert(slotId > 0, "a delivery window is preselected");
  const slotLeft = async (p) => (await (await p.request.get(BASE + "/api/admin/slots")).json()).slots.find(s => s.id === slotId).left;
  await page.screenshot({ path: path.join(SHOTS, "shop-checkout-390.png"), fullPage: true });
  await noHorizontalScroll(page, "checkout");
  await page.click("#payBtn");
  await page.waitForURL(/\/order\/\d+\?t=/, { timeout: 10000 });
  ORDER_URL = page.url();
  await page.waitForSelector("#v-ok.on .ok h1");
  assert(/Almost yours/.test(await page.textContent("#v-ok .ok h1")), "reserved page for Tikkie");
  assert(/🎁/.test(await page.textContent("#v-ok .track-st")), "gift shown on the order");
  await page.screenshot({ path: path.join(SHOTS, "shop-reserved-390.png"), fullPage: true });
  const number = +ORDER_URL.match(/\/order\/(\d+)/)[1];
  const reserved = await stockOf(page, "item-06");
  assert(reserved === before - 2, `stock reserved at checkout: ${before} → ${reserved}`);
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));

  // CRM: the order is there, waiting; mark it paid, then start packing
  const a = await admin(); accept(a.page);
  const leftHeld = await slotLeft(a.page);
  await a.page.click(".nav button[data-s=orders]");
  await a.page.waitForSelector(`#orders .order`);
  await a.page.locator("#orders .order", { hasText: "#" + number }).first().click();
  await a.page.waitForSelector("#sheetBody [data-oact=paid]");
  assert(/D01-/.test(await a.page.textContent("#sheetBody")), "gift code on the order sheet");
  await a.page.screenshot({ path: path.join(SHOTS, "admin-order-390.png"), fullPage: true });
  await a.page.click("#sheetBody [data-oact=paid]");
  await askOk(a.page);
  await a.page.waitForSelector("#sheetBody [data-oact=status][data-to=packing]", { timeout: 8000 });
  assert(/Paid with/.test(await a.page.textContent("#sheetBody")), "payment shows as paid");
  const inv = await a.page.getAttribute("#sheetBody a[href*='/invoice']", "href");
  const pdf = await a.page.request.get(BASE + inv);
  const body = await pdf.body();
  assert(pdf.ok() && /application\/pdf/.test(pdf.headers()["content-type"]) && body.subarray(0, 5).toString() === "%PDF-", "invoice is a PDF");
  assert(new RegExp(String(new Date().getFullYear()) + "-\\d{4}").test(await a.page.textContent("#sheetBody")), "sequential invoice number shown");
  await a.page.click("#sheetBody [data-oact=status][data-to=packing]");
  await a.page.waitForSelector("#sheetBody [data-oact=status][data-to=out_for_delivery]", { timeout: 8000 });
  assert(/Packing|Packed/i.test(await a.page.textContent("#sheetBody .st")), "status changed to packing");
  assert(await slotLeft(a.page) === leftHeld, "the paid order keeps its place in the window");
  const today = await (await a.page.request.get(BASE + "/api/admin/today/shop")).json();
  assert(JSON.stringify(today).length > 50, "Today shop numbers load");
  await a.ctx.close();

  // the customer page now says it's theirs, and the stock is sold (not released)
  await page.goto(ORDER_URL);
  await page.waitForSelector("#v-ok.on .ok h1");
  assert(/It’s yours/.test(await page.textContent("#v-ok .ok h1")), "customer page shows paid");
  assert(await page.$("#v-ok .refer .code"), "referral code on the success page");
  await page.screenshot({ path: path.join(SHOTS, "shop-paid-390.png"), fullPage: true });
  assert(await stockOf(page, "item-06") === before - 2, "stock dropped by the sale");
  // the gift is used up: the same waitlist member gets no second gift
  const g = await (await page.request.post(BASE + "/api/gift-check", { data: { email: SIGNUP_EMAIL, phone: "" } })).json();
  assert(g.gift === false, "gift redeemed once");
  await ctx.close();
});

test("admin: Orders, Customers and Stock tabs render without horizontal scroll", async () => {
  const { ctx, page } = await admin();
  for (const s of ["orders", "inbox", "delivery", "stock", "customers"]) {
    const nav = page.locator(`.nav button[data-s=${s}]`);
    if (await nav.isVisible()) await nav.click(); else { await page.click(".nav button[data-s=more]"); await page.click(`.menu [data-go=${s}]`); }
    await page.waitForSelector(`#s-${s} > *`); await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(SHOTS, `admin-${s}-390.png`), fullPage: true });
    await noHorizontalScroll(page, "admin " + s);
  }
  assert(/Test Buyer/.test(await page.textContent("#s-customers")), "buyer listed as a customer");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

// ---------------------------------------------------------------- delivery (phase 3)

/** The same ten real addresses as tools/tests/DeliveryTest.php (PDOK coordinates). */
const STOPS10 = [["Grote Markt", "1", "", "9712HN", 53.21856902, 6.56719613], ["Zonnelaan", "165", "", "9742BD", 53.22912757, 6.54309949],
  ["Verlengde Hereweg", "100", "", "9722AH", 53.19658922, 6.58150442], ["Oosterweg", "20", "", "9724CH", 53.21250387, 6.57488469],
  ["Korreweg", "100", "", "9715AH", 53.22930448, 6.5661552], ["Kerklaan", "10", "", "9717HE", 53.22383799, 6.55316838],
  ["Beijumerweg", "20", "", "9737AD", 53.25213515, 6.59301099], ["Rijksstraatweg", "150", "", "9752BM", 53.17366615, 6.6026394],
  ["Laan Corpus den Hoorn", "100", "", "9728JR", 53.19801672, 6.54790843], ["Damsterdiep", "263", "50", "9713ED", 53.22034011, 6.58520034]];
const km = (a, b) => { const r = Math.PI / 180, h = Math.sin((b[0] - a[0]) * r / 2) ** 2 + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin((b[1] - a[1]) * r / 2) ** 2; return 12742 * Math.asin(Math.sqrt(h)); };
const pathKm = (start, pts) => pts.reduce((s, p, i) => s + km(i ? pts[i - 1] : start, p), 0);
/** POST from inside the admin page, with its session and CSRF token. */
const adminPost = (page, p, data) => page.evaluate(async ([p, data]) => { const r = await fetch(p, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify(data) }); return [r.status, await r.json()]; }, [p, data]);
let ROUTE = null;

test("delivery: 10 Groningen orders make a sensible route with a Maps link; drag reorder; courier assigned", async () => {
  const { ctx, page } = await admin(); accept(page);
  const slots = (await (await page.request.get(BASE + "/api/admin/slots")).json()).slots;
  const tomorrow = new Date(Date.now() + 864e5).toLocaleDateString("sv-SE", { timeZone: "Europe/Amsterdam" });
  const slot = slots.filter(s => s.date === tomorrow).sort((a, b) => b.left - a.left)[0];
  assert(slot && slot.left >= 10, "a window tomorrow with room for 10 orders");
  const prods = (await (await page.request.get(BASE + "/api/admin/products")).json()).products;
  const v = prods.flatMap(p => p.variants).sort((a, b) => b.stock - a.stock)[0];
  assert(v.stock >= 10, "a variant with stock for 10 orders");
  const ids = [];
  for (const [i, [street, no, add, pc, lat, lng]] of STOPS10.entries()) {
    const [st, r] = await adminPost(page, "/api/admin/orders/new", { channel: "phone", name: `Stop ${i + 1} ${uniq}`, phone: "+31 6 " + (21000000 + i), email: `stop${i}.${uniq}@example.com`,
      postcode: pc, house_no: no, addition: add, street, city: "Groningen", lat, lng, slot_id: slot.id, items: [{ variant_id: v.id, qty: 1 }], payment: i === 0 ? "cash" : "manual" });
    assert(st === 200, "order created: " + JSON.stringify(r));
    ids.push(r.id);
  }
  const [cs] = await adminPost(page, "/api/admin/team/save", { name: "Cora Courier", email: `courier.${uniq}@example.com`, role: "courier", password: "courier-pass-1" });
  assert(cs === 200, "courier added");
  const courier = (await (await page.request.get(BASE + "/api/admin/team")).json()).team.find(a => a.email === `courier.${uniq}@example.com`);

  // the route: all ten in driving order, shorter than the order they came in
  const day = await (await page.request.get(BASE + "/api/admin/delivery?date=" + tomorrow)).json();
  const route = day.routes.find(r => r.slot.id === slot.id);
  const ours = route.stops.filter(s => ids.includes(s.id));
  assert(ours.length === 10, "ten stops on the route");
  const depot = [route.depot.lat, route.depot.lng];
  const driving = pathKm(depot, route.stops.filter(s => s.lat != null).map(s => [s.lat, s.lng]));
  const arrival = pathKm(depot, [...route.stops].filter(s => s.lat != null).sort((a, b) => a.id - b.id).map(s => [s.lat, s.lng]));
  assert(driving < arrival * 0.8, `route ${driving.toFixed(1)} km vs ${arrival.toFixed(1)} km in arrival order`);
  console.log(`      route: ${driving.toFixed(1)} km straight-line (arrival order ${arrival.toFixed(1)} km) · ` + ours.map(s => s.address.split(" ")[0]).join(" → "));
  assert(ours.find(s => s.id === ids[0]).collect > 0, "cash order: collect payment");

  // the screen
  await page.click(".nav button[data-s=delivery]");
  await page.click(`#s-delivery [data-day="${tomorrow}"]`);
  const stops = `#s-delivery .stops[data-slot="${slot.id}"] .stop`;
  await page.waitForFunction(sel => document.querySelectorAll(sel).length >= 10, stops);
  await page.waitForFunction(id => document.querySelectorAll(`#dmap .pin b[data-slot="${id}"]`).length >= 10, slot.id, { timeout: 8000 });
  assert(await page.locator("#dmap .pin.depot").count() === 1, "real map shows the stops and the depot");
  const maps = await page.getAttribute(`#s-delivery .route[data-slot="${slot.id}"] a[href*="google.com/maps/dir"]`, "href");
  const q = new URL(maps).searchParams;
  assert(q.get("waypoints").split("|").length === 9 && q.get("destination"), "Maps link: 9 waypoints + destination");
  assert(/collect/i.test(await page.textContent(`#s-delivery .stops[data-slot="${slot.id}"]`)), "collect payment shown");
  await page.screenshot({ path: path.join(SHOTS, "admin-delivery-390.png"), fullPage: true });
  await noHorizontalScroll(page, "delivery");

  // drag the second stop above the first: the order is saved for this window
  const grips = page.locator(`${stops}.todo .grip`);
  const last = grips.nth(1), first = grips.first();
  await first.scrollIntoViewIfNeeded();
  const lastId = +(await last.evaluate(g => g.closest(".stop").dataset.id));
  const a = await last.boundingBox(), b = await first.boundingBox();
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2); await page.mouse.down();
  for (let y = a.y; y > b.y - 10; y -= 25) await page.mouse.move(a.x + a.width / 2, y);
  await page.mouse.up();
  await page.waitForSelector(`#s-delivery [data-reset="${slot.id}"]`, { timeout: 8000 });
  assert(+(await page.getAttribute(`${stops}.todo >> nth=0`, "data-id")) === lastId, "dragged stop is first");
  await page.click(`#s-delivery [data-reset="${slot.id}"]`);
  await page.waitForSelector(`#s-delivery [data-reset="${slot.id}"]`, { state: "detached" });

  // give the route to the courier
  await page.selectOption(`#s-delivery [data-courier="${slot.id}"]`, String(courier.id));
  await page.waitForFunction(() => /Courier set/.test(document.getElementById("toast").textContent));
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  ROUTE = { slot: slot.id, date: tomorrow, ids, courier: `courier.${uniq}@example.com` };
  await ctx.close();
});

test("courier mode: only Delivery; start route, delivered with photo and cash, not home; owner reschedules", async () => {
  assert(ROUTE, "needs the route from the previous test");
  const { ctx, page } = await phone(); accept(page);
  await page.goto(BASE + "/admin");
  await page.fill("#lEmail", ROUTE.courier); await page.fill("#lPass", "courier-pass-1"); await page.click("#lBtn");
  await page.waitForSelector("#app:not([hidden]) #s-delivery.on.courier");
  const tabs = await page.$$eval(".nav button[data-s]:not([hidden])", b => b.map(x => x.dataset.s));
  assert(tabs.join() === "delivery,more", "courier sees only Delivery: " + tabs);
  assert((await page.request.get(BASE + "/api/admin/orders")).status() === 403, "courier cannot open Orders");
  await page.click(`#s-delivery [data-day="${ROUTE.date}"]`);
  const stops = `#s-delivery .stops[data-slot="${ROUTE.slot}"] .stop`;
  await page.waitForFunction(sel => document.querySelectorAll(sel).length >= 10, stops);
  await page.click(`#s-delivery [data-start="${ROUTE.slot}"]`);
  await askOk(page);
  await page.waitForSelector(`${stops}.next [data-sact=onway]`, { timeout: 10000 });
  await page.screenshot({ path: path.join(SHOTS, "courier-route-390.png"), fullPage: true });
  await noHorizontalScroll(page, "courier mode");

  // the cash stop: delivered, money collected, photo at the door
  const cash = ROUTE.ids[0];
  if (!(await page.$(`${stops}.open[data-id="${cash}"]`))) await page.click(`${stops}[data-id="${cash}"] [data-open]`); // a tap opens the stop's buttons
  await page.click(`${stops}[data-id="${cash}"] [data-sact=done]`);
  await page.waitForSelector("#dvForm [name=collected]");
  await page.setInputFiles("#dvForm [name=photo]", pngFile(800, 600));
  await page.click("#dvForm [type=submit]");
  await page.waitForSelector(`${stops}.done[data-id="${cash}"]`, { timeout: 10000 });
  assert((await page.request.get(BASE + `/api/admin/stops/${cash}/proof`)).ok(), "photo proof stored and served to the courier");

  // the next stop: on my way, then not home
  const nextId = +(await page.getAttribute(`${stops}.next`, "data-id"));
  await page.click(`${stops}.next [data-sact=onway]`);
  await page.waitForSelector("#sheetBody a[href^='https://wa.me/']");
  await page.click("#sheetBody [data-close]");
  await page.click(`${stops}[data-id="${nextId}"] [data-sact=fail]`);
  await page.click("#sheetBody [data-why='Nobody home']");
  await page.waitForSelector(`${stops}.fail[data-id="${nextId}"]`);
  assert(/2 of 1\d delivered/.test(await page.textContent(`#s-delivery .route[data-slot="${ROUTE.slot}"] .head`)), "progress counts done and not home");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();

  // owner: the cash order is paid + delivered; the missed one gets a new window from its order sheet
  const a = await admin(); accept(a.page);
  const o = (await (await a.page.request.get(BASE + "/api/admin/orders/" + cash)).json()).order;
  assert(o.status === "delivered" && o.payment_status === "paid" && o.payment_method === "cash", "cash collected at the door: " + o.status + "/" + o.payment_status);
  assert(/Not home|nobody home/i.test(await a.page.textContent("#s-home")), "Today: needs your attention lists the failed delivery");
  await a.page.click(".nav button[data-s=orders]");
  await a.page.click("#ofilters [data-f=failed_delivery]");
  await a.page.locator("#orders .order").first().click();
  await a.page.click("#sheetBody [data-oact=resched]");
  await a.page.locator("#sheetBody [data-sl]").first().click();
  await a.page.waitForSelector("#sheetBody a[href^='https://wa.me/']");
  const after = (await (await a.page.request.get(BASE + "/api/admin/orders/" + nextId)).json()).order;
  assert(after.status === "packing" && after.events.some(e => e.type === "not_home"), "rescheduled: back to packing, not-home on the timeline");
  await a.ctx.close();
});

// ---------------------------------------------------------------- marketing (phase 4)

const CODE = ("E2E" + uniq).toUpperCase().slice(0, 14);
test("marketing: owner makes a code; its shop link applies it at checkout and the order carries the discount", async () => {
  const { ctx, page } = await admin(); accept(page);
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=marketing]");
  await page.waitForSelector("#mkfDays");   // Marketing opens on the shop funnel
  await page.click("#mkTabs [data-mk=codes]");
  await page.waitForSelector("#mkBody [data-newcode]");
  await page.click("#mkBody [data-newcode]");
  await page.fill("#cdForm [name=code]", CODE); await page.selectOption("#cdForm [name=type]", "pct"); await page.fill("#cdForm [name=value]", "20");
  await page.click("#cdForm [type=submit]");
  await page.waitForSelector(`#mkBody [data-code] >> text=${CODE}`);
  await page.screenshot({ path: path.join(SHOTS, "admin-marketing-codes-390.png"), fullPage: true });
  await noHorizontalScroll(page, "marketing codes");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();

  // the customer opens the shop link, buys, and the code is applied by itself
  const c = await phone({ reducedMotion: "reduce" });
  await c.page.goto(BASE + "/p/item-06?code=" + CODE.toLowerCase());
  await c.page.waitForSelector("#v-product.on #buy");
  assert(!/code=/.test(c.page.url()), "the code is taken out of the address bar");
  await c.page.click("#buy");
  await c.page.click("#cartFoot [data-co='']");
  await c.page.waitForSelector("#v-checkout.on #coForm");
  await c.page.waitForSelector("#promo .pr-on", { timeout: 5000 });
  assert(new RegExp(CODE).test(await c.page.textContent("#promo")) && /20% off/.test(await c.page.textContent("#promo")), "code applied: " + await c.page.textContent("#promo"));
  await c.page.fill("#nm", "Code Buyer"); await c.page.fill("#em", `code.${uniq}@example.com`); await c.page.fill("#ph", "+31 6 2222 3333");
  await c.page.check("#mk");
  await c.page.fill("#coPc", "9742 BD"); await c.page.fill("#coNo", "165");
  await c.page.waitForFunction(() => !document.querySelector("#coAddr .confirming"), null, { timeout: 15000 });
  if (await c.page.$("#coStreet")) await c.page.fill("#coStreet", "Zonnelaan");
  await c.page.waitForSelector("#b2.valid");
  await c.page.click("#sumT");
  assert(new RegExp(CODE + "[\\s\\S]*−€").test(await c.page.textContent("#sum")), "summary shows the code discount");
  await c.page.screenshot({ path: path.join(SHOTS, "shop-checkout-code-390.png"), fullPage: true });
  await noHorizontalScroll(c.page, "checkout with code");
  const shown = await c.page.textContent("#sumTop");
  await c.page.click("#payBtn");
  await c.page.waitForURL(/\/order\/\d+\?t=/, { timeout: 10000 });
  await c.page.waitForSelector("#v-ok.on .osum");
  assert(new RegExp("Code " + CODE).test(await c.page.textContent("#v-ok .osum")), "order page lists the code");
  assert((await c.page.textContent("#v-ok .osum .tot")).includes(shown.trim()), `the server total matches the checkout (${shown})`);
  const number = +c.page.url().match(/\/order\/(\d+)/)[1];
  assert(!c.page.errors.length, "JS errors: " + c.page.errors.join(" | "));
  await c.ctx.close();

  const a = await admin();
  const row = (await (await a.page.request.get(BASE + "/api/admin/orders?q=" + number)).json()).rows.find(r => +r.number === number);
  const o = (await (await a.page.request.get(BASE + "/api/admin/orders/" + row.id)).json()).order;
  assert(o.discount_code === CODE && +o.code_discount_cents > 0, "order carries the code: " + o.discount_code + " / " + o.code_discount_cents);
  await a.ctx.close();
});

test("marketing: a left bag with consent is kept for the reminder; a bad code is explained", async () => {
  const { ctx, page } = await phone({ reducedMotion: "reduce" });
  await page.goto(BASE + "/p/item-06");
  await page.click("#buy");
  await page.click("#cartFoot [data-co='']");
  await page.waitForSelector("#v-checkout.on #coForm");
  await page.click("#prOpen"); await page.fill("#prIn", "NOPE-NOPE"); await page.click("#prBtn");
  await page.waitForFunction(() => /know this code/.test(document.getElementById("prErr").textContent));
  await page.fill("#nm", "Left Bag"); await page.fill("#em", `leftbag.${uniq}@example.com`); await page.fill("#ph", "+31 6 4444 5555");
  await page.check("#mk"); await page.dispatchEvent("#em", "change");
  await page.waitForTimeout(1500);   // the contact is synced to the bag after a short pause
  await ctx.close();
});

test("marketing: back in stock, email broadcast and every tab at 390 px", async () => {
  const { ctx, page } = await admin(); accept(page);
  // the sold-out product someone asked about (shop test) gets stock → the email goes out
  const cfg = await (await page.request.get(BASE + "/api/shop/config")).json();
  const out = cfg.products.find(p => p.stock === 0 && p.variants.length);
  if (out) {
    const [st] = await adminPost(page, "/api/admin/stock/adjust", { variant_id: out.variants[0].id, delta: 3, reason: "receive", note: "e2e" });
    assert(st === 200, "stock adjusted");
  }
  await page.goto(BASE + "/admin/marketing#restock");
  await page.waitForSelector("#mkBody section");
  if (out) assert(new RegExp(out.name).test(await page.textContent("#mkBody")), "told lately lists " + out.name);
  // the shop funnel shows the shop tests' visits: product → bag → checkout → order → paid
  await page.click("#mkTabs [data-mk=funnel]"); await page.waitForSelector("#mkfSteps");
  const fun = await page.$$eval("#mkfSteps .fstep", els => Object.fromEntries(els.map(e => [e.dataset.step, +e.querySelector("b").textContent.replace(/\D/g, "")])));
  assert(fun.view > 0 && fun.bag > 0 && fun.checkout > 0 && fun.order > 0 && fun.paid > 0, "shop funnel has every step: " + JSON.stringify(fun));
  await page.click("#mkfDays [data-d='0']"); await page.waitForSelector("#mkfDays [data-d='0'][aria-pressed=true]");
  for (const t of ["funnel", "referrals", "carts", "restock", "email"]) {
    await page.click(`#mkTabs [data-mk=${t}]`); await page.waitForSelector("#mkBody section"); await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(SHOTS, `admin-marketing-${t}-390.png`), fullPage: true });
    await noHorizontalScroll(page, "marketing " + t);
  }
  assert(/Reminders sent/.test(await (async () => { await page.click("#mkTabs [data-mk=carts]"); await page.waitForSelector("#mkBody .kpi"); return page.textContent("#mkBody"); })()), "left bags tab");
  // email broadcast to everyone who said yes (the code buyer ticked the box)
  await page.click("#mkTabs [data-mk=email]"); await page.waitForSelector("#bcForm");
  await page.selectOption("#bcForm [name=segment]", "customers");
  await page.fill("#bcForm [name=subject]", "E2E broadcast " + uniq);
  await page.fill("#bcForm [name=heading]", "Hi {first_name}");
  await page.fill("#bcForm [name=body]", "Drop 02 is coming.\n\nYou hear it first.");
  const label = await page.textContent("#bcSend");
  assert(/Send to \d+/.test(label), "send button shows the number: " + label);
  await page.click("#bcForm [data-bc=test]");
  await page.waitForFunction(() => /Test sent/.test(document.getElementById("toast").textContent), null, { timeout: 15000 });
  await page.click("#bcSend");
  await askOk(page);
  await page.waitForSelector(`#mkBody >> text=E2E broadcast ${uniq}`, { timeout: 15000 });
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

// ---------------------------------------------------------------- daily operations

test("ops: owner confirms the cash the courier collected; the difference is kept", async () => {
  const { ctx, page } = await admin(); accept(page);
  await page.goto(BASE + "/admin/delivery#cash");
  await page.waitForSelector("#cashCard [data-cashin]", { timeout: 8000 });
  assert(/Cash with couriers/.test(await page.textContent("#cashCard")), "cash card on Delivery");
  await page.screenshot({ path: path.join(SHOTS, "admin-cash-390.png") });
  await page.click("#cashCard [data-cashin]");
  await page.waitForSelector("#cashForm");
  const expected = await page.inputValue("#cashForm [name=received]");
  await page.fill("#cashForm [name=received]", (parseFloat(expected.replace(",", ".")) - 1).toFixed(2).replace(".", ","));
  assert(/€1,00 short/.test(await page.textContent("#cashDiff")), "difference shown: " + await page.textContent("#cashDiff"));
  await page.fill("#cashForm [name=note]", "e2e one euro short");
  await page.click("#cashForm [type=submit]");
  await page.waitForFunction(() => !document.getElementById("cashCard"), null, { timeout: 8000 });
  const h = await (await page.request.get(BASE + "/api/admin/cash")).json();
  assert(h.open.length === 0 && h.history[0].note === "e2e one euro short" && h.history[0].expected_cents - h.history[0].received_cents === 100, "handover stored with the difference");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("ops: packing list per day — pick totals, tick packed, printable list and labels", async () => {
  const { ctx, page } = await admin(); accept(page);
  await page.click(".nav button[data-s=orders]");
  await page.click("#s-orders [data-go=packing]");
  await page.waitForSelector("#s-packing .pk-top");
  const day = await page.locator("#s-packing [data-pday]:has(i)").first().getAttribute("data-pday");   // a day with something left to pack
  await page.click(`#s-packing [data-pday="${day}"]`);
  await page.waitForSelector(`#s-packing [data-pday="${day}"][aria-pressed=true]`);
  await page.waitForSelector("#s-packing .pk");
  assert(await page.locator("#s-packing .pk-pick").count() >= 1, "pick totals listed");
  // packing mode: one order at a time; set a shelf, tick the items (Enter), pack, then take it back
  await page.click("#s-packing [data-pmstart]");
  await page.waitForSelector("#pm .pm-card");
  const num = (await page.textContent("#pm .pm-no")).replace("#", "");
  await page.click("#pm [data-pmshelf]"); await page.waitForSelector("#ask.on"); await page.fill("#askIn", "b 12"); await page.click("#askOk");
  await page.waitForFunction(() => /B12/.test(document.querySelector("#pm .pm-shelf").textContent));
  const lines = await page.locator("#pm .pm-items li").count();
  for (let i = 0; i < lines; i++) await page.keyboard.press("Enter");
  assert(await page.locator("#pm .pm-items li.on").count() === lines && /Packed · next/.test(await page.textContent("#pm .pm-go")), "every item ticked, ready to pack");
  await page.screenshot({ path: path.join(SHOTS, "admin-packmode-390.png") });
  await noHorizontalScroll(page, "packing mode");
  await page.keyboard.press("Enter");
  await page.waitForSelector(`#pm .pm-strip button.done[title^="#${num}"]`, { timeout: 8000 });
  await page.click(`#pm .pm-strip button[title^="#${num}"]`);
  await page.click("#pm [data-pmundo]");
  await page.waitForSelector("#pm [data-pmdone]", { timeout: 8000 });
  await page.keyboard.press("Escape");
  await page.waitForSelector("#pm", { state: "hidden" });
  await page.waitForFunction(() => /B12/.test(document.querySelector("#s-packing").textContent), null, { timeout: 8000 });
  const box = page.locator("#s-packing .pk-chk[aria-pressed=false]:not([disabled])").first();
  const id = await box.getAttribute("data-pack");
  await box.click();
  await page.waitForSelector(`#s-packing .pk-chk[data-pack="${id}"][aria-pressed=true]`, { timeout: 8000 });
  // ticking needs no confirm; the toast's Undo takes it back
  await page.click("#toast.on button");
  await page.waitForSelector(`#s-packing .pk-chk[data-pack="${id}"][aria-pressed=false]`, { timeout: 8000 });
  await page.click(`#s-packing .pk-chk[data-pack="${id}"]`);
  await page.waitForSelector(`#s-packing .pk-chk[data-pack="${id}"][aria-pressed=true]`, { timeout: 8000 });
  await page.screenshot({ path: path.join(SHOTS, "admin-packing-390.png"), fullPage: true });
  await noHorizontalScroll(page, "packing");
  await page.evaluate(() => { window.print = () => {}; });
  await page.click("#s-packing [data-print=labels]");
  await page.evaluate(() => document.body.classList.add("printing"));
  await page.emulateMedia({ media: "print" });
  assert(await page.locator("#print .lab").count() >= 1 && await page.isVisible("#print .lab >> nth=0"), "labels print");
  assert(await page.isHidden("#app"), "only the print sheet is printed");
  await page.screenshot({ path: path.join(SHOTS, "admin-labels-print.png"), fullPage: true });
  await page.emulateMedia({ media: "screen" });
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("ops: the customer changes their delivery time; Order again refills the bag (page and email link)", async () => {
  const c = await phone({ reducedMotion: "reduce" });
  const cfg = await (await c.page.request.get(BASE + "/api/shop/config")).json();
  const v = cfg.products.flatMap(p => p.variants).find(x => x.stock >= 2);
  const slots = cfg.slots.filter(s => s.available);
  assert(slots.length >= 2, "two free windows to move between");
  const r = await (await c.page.request.post(BASE + "/api/checkout", { data: { name: "Move Me", email: `move.${uniq}@example.com`, phone: "+31 6 5555 6666", postcode: "9742 BD", house_no: "165",
    street: "Zonnelaan", city: "Groningen", slot_id: slots[0].id, method: "tikkie", items: [{ variant_id: v.id, qty: 1 }] } })).json();
  await c.page.goto(r.redirect);
  await c.page.waitForSelector("#v-ok.on #mvBtn");
  await c.page.click("#mvBtn");
  await c.page.waitForSelector("#mv [data-mv]");
  const to = await c.page.locator("#mv [data-mv]").first().getAttribute("data-mv");
  await c.page.click(`#mv [data-mv="${to}"]`); await c.page.click("#mvGo");
  await c.page.waitForFunction(() => /New time/.test(document.getElementById("toast").textContent), null, { timeout: 8000 });
  const label = slots.find(s => s.id === +to).label;
  assert((await c.page.textContent("#v-ok .when .wh")).includes(label), "the new window shows: " + label);
  await c.page.screenshot({ path: path.join(SHOTS, "shop-moved-390.png"), fullPage: true });

  // paid → "Order again" puts the same thing back in the bag
  const number = +r.redirect.match(/\/order\/(\d+)/)[1], t = new URL(r.redirect).searchParams.get("t");
  const a = await admin();
  const row = (await (await a.page.request.get(BASE + "/api/admin/orders?q=" + number)).json()).rows.find(x => +x.number === number);
  const sheet = (await (await a.page.request.get(BASE + "/api/admin/orders/" + row.id)).json()).order;
  assert(sheet.slot_id === +to && sheet.events.some(e => e.type === "rescheduled_by_customer"), "the CRM has the new window and the event");
  const [st] = await adminPost(a.page, `/api/admin/orders/${row.id}/paid`, { method: "manual" });
  assert(st === 200, "marked paid");
  await a.ctx.close();
  await c.page.goto(r.redirect);
  await c.page.waitForSelector("#v-ok.on #againBtn");
  await c.page.click("#againBtn");
  await c.page.waitForSelector("#drawer.on");
  assert(+(await c.page.textContent("#cartN")) >= 1, "bag refilled from the order page");
  assert(!c.page.errors.length, "JS errors: " + c.page.errors.join(" | "));
  await c.ctx.close();

  const n = await phone({ reducedMotion: "reduce" });
  await n.page.goto(`${BASE}/?reorder=${number}&t=${t}`);
  await n.page.waitForSelector("#drawer.on", { timeout: 8000 });
  assert(+(await n.page.textContent("#cartN")) === 1 && !/reorder/.test(n.page.url()), "the email link fills the bag on another phone");
  await n.ctx.close();
});

// ---------------------------------------------------------------- inbox
/** Drops an email into the store's test mailbox (GreenMail SMTP), as a customer would. */
async function smtpSend(port, from, to, subject, body){
  const net = await import("node:net");
  const lines = ["HELO e2e", `MAIL FROM:<${from}>`, `RCPT TO:<${to}>`, "DATA",
    [`From: Test Buyer <${from}>`, `To: ${to}`, `Subject: ${subject}`, `Message-ID: <${uniq}@e2e.test>`, "Content-Type: text/plain; charset=utf-8", "", body, "."].join("\r\n"), "QUIT"];
  await new Promise((res, rej) => {
    const s = net.connect(port, "127.0.0.1"); let step = 0, buf = "";
    s.on("data", d => { buf += d; if (!/\r\n$/.test(buf)) return; const code = +buf.slice(0, 3); buf = "";
      if (code >= 400) { s.destroy(); return rej(new Error("SMTP " + code)); } if (step < lines.length) s.write(lines[step++] + "\r\n"); else s.end(); });
    s.on("end", res); s.on("close", res); s.on("error", rej);
  });
}

test("money: costs and packaging feed the profit per product; what-if price; works at 390 px", async () => {
  const { ctx, page } = await admin(); accept(page);
  await page.click(".nav button[data-s=more]"); await page.click("#sheetBody [data-go=money]");
  await page.waitForSelector("#mnBody .prod");
  await page.click("#mnTabs [data-mn=costs]"); await page.waitForSelector("#mnCosts");
  await page.fill("#mnCosts [name=delivery]", "3,00"); await page.fill("#mnCosts [name=fixed_month]", "250");
  await page.click("#mnCosts .holo"); await page.waitForFunction(() => /Costs saved/.test(document.getElementById("toast").textContent));
  await page.waitForSelector("#mnCosts"); assert(await page.inputValue("#mnCosts [name=delivery]") === "3,00", "delivery cost kept");
  await page.click("#mnBody [data-mat='0']"); await page.waitForSelector("#matForm");
  await page.fill("#matForm [name=name]", "E2E box"); await page.fill("#matForm [name=pack_price]", "10,00"); await page.fill("#matForm [name=pack_qty]", "20");
  assert(/= €0,50 each/.test(await page.textContent("#matEach")), "cost of one: " + await page.textContent("#matEach"));
  await page.click("#matForm .holo"); await page.waitForSelector("#mnBody >> text=E2E box");
  await page.screenshot({ path: path.join(SHOTS, "admin-money-costs-390.png"), fullPage: true });
  await noHorizontalScroll(page, "money costs");
  await page.click("#mnTabs [data-mn=products]"); await page.waitForSelector("#mnBody .prod");
  assert(/orders a month/.test(await page.textContent("#mnBody")), "break-even against the fixed costs");
  await page.screenshot({ path: path.join(SHOTS, "admin-money-products-390.png"), fullPage: true });
  await noHorizontalScroll(page, "money products");
  await page.click("#mnBody .prod");
  await page.waitForSelector("#mnPack");
  await page.fill("#mnPack input", "1"); await page.click("#mnPack button");
  await page.waitForFunction(() => /Packaging saved/.test(document.getElementById("toast").textContent)); await page.waitForTimeout(600);   // the sheet re-opens with fresh numbers
  await page.waitForFunction(() => /Packaging\s*− €0,50/.test(document.querySelector(".mn-break")?.textContent || ""), null, { timeout: 8000 });
  assert(/Delivery, its share\s*− €/.test(await page.textContent(".mn-break")), "delivery share in the breakdown");
  await page.fill("#mnWhat [name=price]", "99,95");
  await page.waitForFunction(() => /Profit per sale/.test(document.getElementById("mnWhatOut").textContent), null, { timeout: 8000 });
  assert(!(await page.isHidden("#mnWhatUse")), "offers to use the what-if price");
  await page.screenshot({ path: path.join(SHOTS, "admin-money-product-390.png") });
  await page.keyboard.press("Escape");
  await page.click("#mnTabs [data-mn=prices]"); await page.waitForSelector("#mnBody .mn-price");
  assert(/Lowest/.test(await page.textContent("#mnBody")) && /Target/.test(await page.textContent("#mnBody")), "price calculator");
  await noHorizontalScroll(page, "money prices");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

test("inbox: email and Telegram chats in one place with channel icons; replies go out on the same channel", async () => {
  const TG = process.env.TG_MOCK, SMTP = process.env.IMAP_SMTP;
  assert(TG || SMTP, "set TG_MOCK and/or IMAP_SMTP (test-all.sh does)");
  const a = await admin(); accept(a.page);
  if (TG) {
    await a.page.goto(BASE + "/admin/settings#telegram");
    await a.page.waitForSelector("#tgForm");
    await a.page.fill("#tgForm [name=token]", "123456789:AAH" + "x".repeat(32));
    await a.page.click("#tgForm [type=submit]");
    await a.page.waitForFunction(() => /Connected to @drop01_test_bot/.test(document.getElementById("set-telegram")?.textContent), null, { timeout: 8000 });
    // the customer's order page links to the bot with a signed start code
    const c = await phone({ reducedMotion: "reduce" });
    await c.page.goto(ORDER_URL); await c.page.waitForSelector("#v-ok.on .ok h1");
    const href = await c.page.getAttribute("#v-ok a[href*='t.me/']", "href");
    assert(/^https:\/\/t\.me\/drop01_test_bot\?start=o\d+_[a-f0-9]{10}$/.test(href || ""), "Chat on Telegram on the order page: " + href);
    await c.ctx.close();
    const from = { id: 9001, first_name: "Test", last_name: "Buyer", username: "testbuyer" }, chat = { id: 9001, type: "private" };
    await fetch(TG + "/__push", { method: "POST", body: JSON.stringify({ updates: [
      { message: { message_id: 1, from, chat, text: "/start " + href.split("start=")[1] } },
      { message: { message_id: 2, from, chat, text: "Hi! Is my order on its way?" } },
      { message: { message_id: 3, from, chat, caption: "The box I got last time", photo: [{ file_id: "p1", file_size: 90 }] } }] }) });
  }
  if (SMTP) await smtpSend(+SMTP, SIGNUP_EMAIL, "hello@drop01.test", "Where is my order?", "Hello, can you deliver after 19:00?\r\n\r\nThanks!\r\n\r\nOn Mon, the shop wrote:\r\n> old text");
  const [st, chk] = await adminPost(a.page, "/api/admin/inbox/check", {});
  assert(st === 200, "check incoming: " + JSON.stringify(chk));

  await a.page.goto(BASE + "/admin");
  await a.page.waitForSelector("#app:not([hidden]) #s-home > *"); await a.page.waitForSelector(".nav button[data-s=inbox]:not([hidden])");
  await a.page.click(".nav button[data-s=inbox]");
  await a.page.waitForSelector("#ibList .ib-row");
  assert(await a.page.isVisible("#ibCount"), "unread badge on the Inbox tab");
  if (TG) assert(await a.page.$("#ibList .ib-row.un .chb.telegram"), "unread Telegram chat with the Telegram icon");
  if (SMTP) assert(await a.page.$("#ibList .ib-row.un .chb.email"), "unread email chat with the email icon");
  await a.page.screenshot({ path: path.join(SHOTS, "admin-inbox-390.png") });
  await noHorizontalScroll(a.page, "inbox");
  if (TG) {
    await a.page.locator("#ibList .ib-row", { has: a.page.locator(".chb.telegram") }).first().click();
    await a.page.waitForSelector("#ibMsgs .bub.in");
    const t = await a.page.textContent("#ibMsgs");
    assert(/Is my order on its way/.test(t) && /Opened the chat from order #\d+/.test(t), "Telegram messages in the thread");
    assert(await a.page.$("#ibMsgs .ib-img img"), "the photo is shown");
    assert(await a.page.$(".ib-head [data-ib=customer]"), "the chat is linked to the customer");
    await a.page.fill("#ibText", "Yes, tonight 18–21!"); await a.page.click("#ibSend");
    await a.page.waitForFunction(() => [...document.querySelectorAll("#ibMsgs .bub.out")].some(b => /tonight 18–21/.test(b.textContent) && /✓ sent/.test(b.textContent)), null, { timeout: 8000 });
    await a.page.screenshot({ path: path.join(SHOTS, "admin-inbox-thread-390.png") });
    await noHorizontalScroll(a.page, "inbox thread");
    const sent = (await (await fetch(TG + "/__sent")).json()).result;
    assert(sent.some(m => m.chat_id === "9001" && /tonight/.test(m.text)) && sent.some(m => /chatting with/.test(m.text)), "welcome and reply went out through the bot");
    await a.page.click("[data-ib=back]");
    await a.page.waitForSelector("#ib:not(.open)");
  }
  if (SMTP) {
    await a.page.locator("#ibList .ib-row", { has: a.page.locator(".chb.email") }).first().click();
    await a.page.waitForSelector("#ibMsgs .bub.in");
    const t = await a.page.textContent("#ibMsgs");
    assert(/deliver after 19:00/.test(t) && !/old text/.test(t), "the email in the thread, quoted history cut off");
    assert(await a.page.$("#ibMsgs .ib-auto"), "automatic order emails show in the same thread");
    await a.page.fill("#ibText", "Sure, we come at 19:30."); await a.page.click("#ibSend");
    await a.page.waitForFunction(() => [...document.querySelectorAll("#ibMsgs .bub.out")].some(b => /19:30/.test(b.textContent)), null, { timeout: 8000 });
  }
  // "Email" on the customer card opens the same chat
  if (SMTP) {
    await a.page.click(".ib-head [data-ib=customer]");
    await a.page.waitForSelector("#sheetBody [data-cact=chat][data-ch=email]");
    await a.page.click("#sheetBody [data-cact=chat][data-ch=email]");
    await a.page.waitForFunction(() => /19:30/.test((document.getElementById("ibMsgs") || {}).textContent || ""));
  }
  assert(!a.page.errors.length, "JS errors: " + a.page.errors.join(" | "));
  await a.ctx.close();
});

// ---------------------------------------------------------------- texts

test("texts: owner rewrites shop texts and questions, a bad {word} is refused, the file goes out and comes back", async () => {
  const a = await admin(); accept(a.page);
  const page = a.page, cta = "Grab it " + uniq + " ↓";
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=texts]");
  await page.waitForSelector('#s-texts .tx-it[data-k="shop.hero.cta"] textarea');
  await noHorizontalScroll(page, "admin texts");
  await page.fill('.tx-it[data-k="shop.hero.cta"] textarea', cta);
  await page.fill('.tx-it[data-k="shop.hero.title_foil"] textarea', "is {nope}");
  assert(/not known/.test(await page.textContent('.tx-it[data-k="shop.hero.title_foil"] .tx-warn')), "unknown {word} is flagged while typing");
  assert(/2 unsaved changes/.test(await page.textContent("#txSave")), "save bar counts the changes");
  await page.click("#txSave [data-tx=save]");
  await page.waitForSelector("#errs .err");
  assert(/\{nope\} is not known/.test(await page.textContent("#errs")), "the server refuses the unknown word");
  await page.click("#errs .err button");
  await page.fill('.tx-it[data-k="shop.hero.title_foil"] textarea', "is here.");
  assert(/1 unsaved change/.test(await page.textContent("#txSave")), "typing the original back is not a change");
  // questions live in another group: add one with a {word}
  await page.click('#txNav [data-g="shop.info"]');
  await page.waitForSelector('.tx-it[data-k="shop.faq"] .tx-row');
  await page.click('.tx-it[data-k="shop.faq"] [data-tx=addq]');
  await page.fill('.tx-it[data-k="shop.faq"] .tx-row:last-child input', "Do you gift wrap?");
  await page.fill('.tx-it[data-k="shop.faq"] .tx-row:last-child textarea', "Yes, free from {free_from}.");
  await page.click("#txSave [data-tx=save]");
  await page.waitForFunction(() => /Saved/.test(document.getElementById("toast").textContent));
  await page.waitForFunction(() => /changed/.test((document.querySelector('#txNav [data-g="shop.home"]') || {}).textContent || ""));
  await page.fill("#txQ", "first dibs");
  await page.waitForSelector('#txBody .tx-it[data-k="wl.vnudge.button"]');
  await page.screenshot({ path: path.join(SHOTS, "admin-texts-390.png"), fullPage: true });
  // the shop shows the new words at once
  const v = await phone();
  await v.page.goto(BASE + "/");
  assert((await v.page.textContent(".hero .cta")).includes(cta), "shop button text changed");
  assert(await v.page.locator("details.faq summary", { hasText: "Do you gift wrap?" }).count() === 1, "new question on the shop");
  assert(/Yes, free from €\d/.test(await v.page.locator("details.faq", { hasText: "gift wrap" }).textContent()), "its {word} is filled in");
  assert((await v.page.textContent("#v-home .hero h1")).includes("is here."), "untouched text stays");
  // the texts file: download, change by hand, upload; one bad line is reported, the rest is saved
  const file = JSON.parse(await page.evaluate(() => fetch("/api/admin/texts/export").then(r => r.text())));
  assert(file.format === "drop01-texts" && file.count > 300, "the file holds every text");
  const sec = Object.values(file.texts).find(x => "wl.kicker" in x);
  sec["wl.kicker"] = "Brand new " + uniq;
  sec["wl.lead"] = "Get {nothing}";
  const f = path.join(DIR, "texts-upload.json"); fs.writeFileSync(f, JSON.stringify(file, null, 2));
  await page.setInputFiles("#txFile", f);
  await page.waitForSelector("#sheet.on");
  assert(/1 text not taken over/.test(await page.textContent("#sheetBody")) && /wl\.lead/.test(await page.textContent("#sheetBody")), "the bad line is reported");
  await page.keyboard.press("Escape");
  const w = await phone({ reducedMotion: "reduce" });
  await w.page.goto(BASE + "/waitlist");
  assert((await w.page.textContent(".kicker")).includes("Brand new " + uniq), "waitlist text from the uploaded file");
  assert(!w.page.errors.length && !v.page.errors.length, "JS errors: " + w.page.errors.concat(v.page.errors).join(" | "));
  await w.ctx.close();
  // Reset all puts the originals back (the other tests match the original wording)
  await page.click("#s-texts [data-tx=reset]"); await askOk(page);
  await page.waitForFunction(() => /originals again/.test(document.getElementById("toast").textContent));
  await v.page.goto(BASE + "/");
  assert((await v.page.textContent(".hero .cta")).includes("Shop the drop"), "original text is back");
  await v.ctx.close();
  fs.rmSync(f, { force: true });
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await a.ctx.close();
});

// ---------------------------------------------------------------- desktop (1100 and 1440 px: sidebar, panels, every screen)

const DESKTOP = w => ({ viewport: { width: w, height: 900 }, isMobile: false, hasTouch: false, deviceScaleFactor: 1, locale: "nl-NL",
  userAgent: "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36" });
/** Visible elements of the current screen that stick out on the right or slide under the sidebar (scrolling rows such as chips don't count). */
test("security: order limit, a new password logs out other devices, the nightly copy goes off-site", async () => {
  // more than 5 of one product in a shop order is refused by the server, whatever the page sends
  const { ctx: shopCtx, page: shop } = await phone();
  const cfg = await (await shop.request.get(BASE + "/api/shop/config")).json();
  const v = cfg.products.flatMap(p => p.variants).sort((a, b) => b.stock - a.stock)[0];
  assert(cfg.maxPerItem === 5, "the shop knows the limit");
  if (v && v.stock >= 6) {
    const r = await shop.request.post(BASE + "/api/checkout", { data: { email: `lim.${uniq}@example.com`, phone: "0612340000", name: "Lim It", postcode: "9742BD", house_no: "165",
      slot_id: cfg.slots[0] && cfg.slots[0].id, items: [{ variant_id: v.id, qty: 6 }], method: "tikkie" } });
    assert(r.status() === 422 && /up to 5/.test((await r.json()).error), "6 of one product is refused");
  }
  await shopCtx.close();
  // two devices logged in as the owner; a password change on one ends the other
  const a = await admin(), b = await admin();
  const [s1] = await adminPost(a.page, "/api/admin/password", { current: PASS, new: PASS + "-tmp" });
  assert(s1 === 200, "password changed");
  const [s2] = await adminPost(a.page, "/api/admin/password", { current: PASS + "-tmp", new: PASS });
  assert(s2 === 200, "and back, on the same device that is still logged in");
  assert((await b.page.request.get(BASE + "/api/admin/me")).status() === 401, "the other device is logged out");
  await b.ctx.close();
  // System → copy outside this server: save an address and password, send one now (test-all checks it arrives encrypted)
  const page = a.page; accept(page);
  await page.click(".nav button[data-s=more]"); await page.click(".menu [data-go=system]");
  await page.waitForSelector("#offForm");
  await page.fill("#offForm [name=email]", `offsite.${uniq}@example.com`); await page.fill("#offForm [name=password]", "backup-pass-123");
  await page.click("#offForm [type=submit]");
  await page.waitForSelector("#offsite [data-sys=offsite]");
  await page.click("#offsite [data-sys=offsite]");
  await page.waitForFunction(() => /Last copy sent/.test(document.querySelector("#offsite").textContent), null, { timeout: 15000 });
  await noHorizontalScroll(page, "admin system with off-site copy");
  await a.ctx.close();
});

async function layoutProblems(page, sel){
  return page.evaluate(sel => {
    const nav = document.getElementById("nav").getBoundingClientRect(), W = document.documentElement.clientWidth, out = [];
    const scrolls = el => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === "auto" || o === "scroll" || o === "hidden") return true; } return false; };
    for (const el of document.querySelectorAll(sel + " *")) {
      const r = el.getBoundingClientRect(), cs = getComputedStyle(el);
      if (!r.width || !r.height || cs.visibility === "hidden" || cs.position === "fixed" || el.closest(".leaflet-container") || scrolls(el)) continue;
      if (r.right > W + 1) out.push(`${el.tagName.toLowerCase()}.${el.className} sticks out ${Math.round(r.right - W)}px`);
      else if (r.left < nav.right - 1) out.push(`${el.tagName.toLowerCase()}.${el.className} under the sidebar`);
      if (out.length > 2) break;
    }
    return out;
  }, sel);
}
for (const W of [1100, 1440]) test(`desktop ${W} px: sidebar, every screen, sheets and confirm without layout breaks`, async () => {
  const { ctx, page } = await admin(DESKTOP(W)); accept(page);
  assert(await page.isVisible(".nav .nv-brand") && !(await page.isVisible(".nav button[data-s=more]")), "sidebar with brand, no More");
  const screens = ["home", "orders", "inbox", "delivery", "stock", "customers", "waitlist", "marketing", "money", "settings", "texts", "team", "system", "packing"];
  for (const s of screens) {
    const nav = page.locator(`.nav button[data-s=${s}]`);
    if (await nav.count() && await nav.isVisible()) await nav.click(); else await page.goto(BASE + "/admin/" + s);
    await page.waitForSelector(`#s-${s}.on > *`); await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(SHOTS, `desktop-${s}-${W}.png`) });
    await noHorizontalScroll(page, `desktop ${s}`);
    const bad = await layoutProblems(page, `#s-${s}`);
    assert(!bad.length, `${s} at ${W}px: ${bad.join("; ")}`);
  }
  // Money and Marketing sub-tabs
  for (const [s, t] of [["money", "prices"], ["money", "costs"], ["marketing", "codes"], ["marketing", "email"]]) {
    await page.goto(`${BASE}/admin/${s}#${t}`); await page.waitForSelector(`#s-${s}.on section`); await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(SHOTS, `desktop-${s}-${t}-${W}.png`) });
    const bad = await layoutProblems(page, `#s-${s}`);
    assert(!bad.length, `${s} ${t} at ${W}px: ${bad.join("; ")}`);
  }
  // the order sheet is a right-hand panel; the confirm sits centred above it
  await page.click(".nav button[data-s=orders]"); await page.waitForSelector("#orders .order");
  await page.locator("#orders .order").first().click(); await page.waitForSelector("#sheet.on #sheetBody .acts2");
  await page.waitForTimeout(400);
  const sh = await page.evaluate(() => { const r = document.getElementById("sheet").getBoundingClientRect(); return { right: r.right, w: r.width, top: r.top }; });
  assert(Math.abs(sh.right - W) < 2 && sh.w <= 561 && sh.top < 2, "sheet is a right-hand panel: " + JSON.stringify(sh));
  await page.screenshot({ path: path.join(SHOTS, `desktop-order-sheet-${W}.png`) });
  if (await page.$("#sheetBody [data-oact=cancel]")) {
    await page.click("#sheetBody [data-oact=cancel]"); await page.waitForSelector("#ask.on");
    await page.waitForTimeout(300);
    const box = await page.evaluate(() => { const r = document.querySelector("#ask .ask-c").getBoundingClientRect(); return { cx: r.left + r.width / 2, cy: r.top + r.height / 2 }; });
    assert(Math.abs(box.cx - W / 2) < 4 && Math.abs(box.cy - 450) < 40, "confirm centred on desktop: " + JSON.stringify(box));
    await page.screenshot({ path: path.join(SHOTS, `desktop-ask-${W}.png`) });
    await page.keyboard.press("Escape");
    assert(!(await page.$("#ask.on")) && await page.isVisible("#sheet.on"), "Escape closes only the confirm, the sheet stays");
  }
  await page.keyboard.press("Escape");
  // the folded sidebar (icons only) still leaves the content clear
  await page.click("#nvFold"); await page.waitForTimeout(300);
  for (const s of ["money", "inbox"]) {
    await page.click(`.nav button[data-s=${s}]`); await page.waitForSelector(`#s-${s}.on > *`); await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(SHOTS, `desktop-${s}-folded-${W}.png`) });
    const bad = await layoutProblems(page, `#s-${s}`);
    assert(!bad.length, `${s} folded at ${W}px: ${bad.join("; ")}`);
  }
  await page.click("#nvFold");
  assert(!page.errors.length, "JS errors: " + page.errors.join(" | "));
  await ctx.close();
});

// ---------------------------------------------------------------- run
let pass = 0, fail = 0;
for (const [name, fn] of tests) {
  const t0 = Date.now();
  try { await fn(); pass++; console.log(`  ✓ ${name} (${((Date.now() - t0) / 1000).toFixed(1)}s)`); }
  catch (e) { fail++; console.log(`  ✕ ${name}\n      ${e.message.split("\n")[0]}`); }
}
await browser.close();
console.log(`\n${pass} passed, ${fail} failed · screenshots in tools/e2e/shots`);
process.exit(fail ? 1 : 0);

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`drop01-build-kit/` is **Drop 01**: a waitlist + shop + CRM for a small reseller in Groningen (NL) that delivers its own orders. It ships as one zip that a non-technical owner extracts into GoDaddy cPanel `public_html` and installs via `/install.php`. It is **not** the GetYourDeposit cleaning company described in the global `~/.claude/CLAUDE.md`.

- `drop01-build-kit/CLAUDE.md` is the **product spec** (data model, order lifecycle, admin features, security checklist, phases, tests). Read the relevant section before changing behaviour, and don't rewrite it as dev notes.
- `PROMPTS.md` lists the phases. Phases 1–3 are done, and so is the marketing half of Phase 4. Beyond the spec there are also a packing list (with a one-order-at-a-time packing mode and shelf codes per variant), courier cash handover, customer self-reschedule, "order again", the **Inbox** (email + Telegram customer chats), **Money → margins** (unit economics per product) and **Texts** (every customer-facing word editable in the admin, plus a downloadable/uploadable texts file). Still open from Phase 4: the rest of Money (expenses entry, BTW quarter, exports), suppliers/purchase orders, and returns/refunds. `CHANGELOG.md` records what each step built.
- `prototypes/*.html` are the approved designs and the source of truth for look, copy and interactions. Port them; don't redesign them.
- `OWNER_INPUT.md` holds the owner's real details. Use placeholders where it is empty.
- Mollie payments are built and unit-tested but **switched off on purpose** (`mollie_enabled` in `data/config.php`). Payments are Tikkie, cash or bank transfer, marked paid in the CRM. Don't re-enable Mollie unless the owner asks.

## Commands (run from `drop01-build-kit/`)

There is no local PHP. Everything runs in the Docker image `drop01-php` (php:8.2-apache with gd/zip).

```bash
docker build -t drop01-php tools/docker          # once
./tools/php -l src/app/lib/Route.php             # lint one file (tools/php = php inside the image, repo at /repo)
./tools/php tools/tests/run.php                  # PHP unit tests (all test_* functions in tools/tests/*Test.php)
./tools/build.sh                                 # → dist/drop01-deploy.zip (+ dist/DEPLOY-GODADDY.md); fails on any php -l error
./tools/test-all.sh                              # the full gate: build → fresh Apache container from the zip → install → deny rules → cron → unit → browser → Mailpit email checks
(cd tools/e2e && npm install)                    # once, for Playwright
BASE=http://localhost:8092 node tools/e2e/run.mjs   # browser tests against an installed store (OFFLINE=1 skips the live PDOK check)
```

- The unit runner has no filter. To run a single test, temporarily rename the other `test_*` functions, or call one from a scratch script that requires `tools/tests/run.php`'s setup. The whole suite takes seconds.
- If the unit runner ends without its `N passed, M failed` line (exit 0 or 255, maybe a stray JSON line), a test reached `Http::json()`/`Http::error()` (for example via `Auth::require()`), which `exit`s the whole runner. `Auth::user()` caches the person per request; tests that switch `$_SESSION` call `Auth::forget()` first.
- `mk_fails()` (MarketingTest) only catches `RuntimeException`. Lib validation such as `Products::save` and `Margins::saveMaterial` throws `InvalidArgumentException`, so assert those with your own try/catch.
- Unit tests use a fresh temp SQLite DB. `fresh()` in `run.php` empties only some tables, and the shop/delivery fixtures (`shop_fixture()`, `route_fixture()`) empty the rest, so new tables need clearing in a fixture.
- The browser tests run in order and depend on earlier ones (signup → gift → checkout → CRM → delivery → courier → marketing → ops → money → inbox → texts → security, then the two desktop tests). For example, the cash-handover test needs the courier test's cash stop, and the packing test picks a day that still has orders to pack. After the browser tests, `test-all.sh` ages the consented left bag and runs cron, so the abandoned-cart email can be checked in Mailpit. They assume a **fresh install**. Rerunning `run.mjs` against a used container fails the waitlist tests because the store is in Shop mode by then. Use `test-all.sh` for a clean run. To run one browser test against `d01zip`, filter the `test()` registration into a throwaway copy inside `tools/e2e/` (so `playwright` resolves): `sed 's#^const test = (name, fn) => tests.push(\[name, fn\]);#const test = (name, fn) => /^money/.test(name) \&\& tests.push([name, fn]);#' run.mjs > _only.mjs && BASE=http://localhost:8092 node _only.mjs; rm _only.mjs`. This works only for tests that don't need a fresh install.
- Ports: `test-all.sh` uses 8092 (store), 8025 (Mailpit test inbox), 3025 (GreenMail SMTP: the store's IMAP mailbox, so the e2e can email the store) and 8094 (`tools/e2e/tg-mock.php`, the Bot API stand-in; 8093 is taken on this Mac). It points the store at them via `imap` settings and `telegram_api` in `data/config.php`. The Inbox e2e test needs `TG_MOCK` and/or `IMAP_SMTP` set. Port 8080 on this Mac is taken by another app. Test admin login: `owner@example.com` / `correct-horse-1`.
- The checkout e2e needs live PDOK. Postcode 9742BD has no house 22, so use 165. DNS inside the Docker test network occasionally fails transiently; just rerun. If it keeps failing, `OFFLINE=1 ./tools/test-all.sh` (the env passes through to `run.mjs`) types the street by hand instead.
- Headless Chromium sends no `Accept-Language` unless `locale` is set (the analytics country depends on it).
- The e2e suite runs at phone width (390 px), plus two `desktop …` tests at the end (1100 and 1440 px) that open every screen with the sidebar open and folded and fail on horizontal scroll or content sticking out right / under the sidebar (`layoutProblems()`). They save `shots/desktop-*.png`; still look at them after layout changes, since the check can't judge looks.
- The admin has no native `confirm()`/`prompt()`. `accept(page)` records any native dialog as a JS error; click through the in-app dialog with `askOk(page)` (or `#askNo`).
- Known flake: `admin: wrong password is refused` sometimes times out during a full run but doesn't reproduce on its own. Rerun before you go looking for a bug.
- Without Docker: `php -S localhost:8000 -t src src/index.php`, then open `/install.php`.
- Fast preview without a rebuild: `test-all.sh` leaves the `d01zip` container running, serving `tools/cache/zipcheck/html` (unminified sources are fine there). Copy a changed file in with `docker cp src/<path> d01zip:/var/www/html/<path>`. Its DB is `/var/www/html-drop01-data/app.sqlite`. Read `number` and `token` from `orders` to open a customer order page at `/order/<number>?t=<token>`.
- In e2e at 390 px, the bottom sheet covers `#scrim`, so `page.click("#scrim")` times out. Close it with `page.keyboard.press("Escape")`. After a save that re-renders and re-opens a sheet, wait for the toast before filling inputs. Otherwise you fill the old sheet's DOM.
- Some e2e assertions match UI copy (e.g. `/collect/i` on the delivery stops), so changing labels can break a test far from the change.
- `build.sh` copies `DEPLOY-GODADDY.md` into `dist/`, so rebuild after editing the guide. `test-all.sh` builds the zip before you write the changelog or guide.
- For quick screenshots against `d01zip`, a `page.goto` that only changes the `#hash` is a same-document navigation and doesn't re-render. Load the URL fresh, or click through the UI.
- Playwright `fullPage` screenshots draw `position:fixed` elements (the shop's bag bar) partway down the page. That's a screenshot artifact, so check with a viewport-only shot before "fixing" it.

## Architecture (`src/` = what ships = `public_html`)

**Request flow.** `index.php` is the single front controller (Apache rewrites everything to it). It bootstraps `app/bootstrap.php`, then each controller's `::routes(Router $r)` registers `{param}` paths, then it dispatches. A new controller must be added to the list in `index.php`. Classes autoload from `app/lib/` then `app/controllers/`. Every non-entry PHP file starts with `defined('DROP01') || exit;` and a one-line comment saying what it does. `install.php` locks itself after use. `cron.php` (CLI or secret token, every 5 min) runs all scheduled jobs: migrations, expired holds and unpaid orders, slot generation, back-in-stock, abandoned carts, the Inbox poll (IMAP + Telegram poll mode), the email queue, salt rotation, and the nightly backup + analytics roll-up.

**Adding an admin screen** touches four places: a `<div class="screen" id="s-<name>">` and a nav button in `app/views/admin.php`, `TITLES` + `AREA` + `RENDER.<name>` in `admin.js`, and the area in `Auth::ACCESS` (owner has `*`). On phones the bar holds six buttons; anything else goes under More (`openMore()`) with class `dk` on its nav button.

**Controllers** are static JSON APIs:
- `PublicController`: pages;
- `WaitlistApi`, `ShopApi`: public API;
- `AdminApi`: login, settings, products, team, system;
- `CrmApi`: orders, customers, stock;
- `DeliveryApi`: routes, courier stop actions; its day payload also carries the open courier cash;
- `MarketingApi`: the shop funnel (`Dashboard::shop()`), codes, referrals, broadcasts, back in stock, left bags;
- `OpsApi`: the packing list and cash handovers.
- `MoneyApi`: the margins report, cost settings (`costs`, saved here, not in `AdminApi`), packaging materials, what-if quotes and "use this price" (also needs `stock`).
- `InboxApi`: conversations, reply/retry, start a chat from a customer, inbox photos, "check now", Telegram status/disconnect, and the public `POST /api/telegram/hook` (checks the `X-Telegram-Bot-Api-Secret-Token` header).
- `TextsApi`: the Texts screen (list, save changes, `export` download, `import` upload, `reset`), area `texts` (owner only).
- `AdminController` only serves the `/admin` SPA shell. It isn't in the `index.php` list.

Admin endpoints follow one pattern: `Auth::require('<area>')` for reads; writes also call `Auth::checkCsrf()` (the `X-CSRF` header) and `Auth::audit(...)`. Endpoints that hand out or redirect all data (backup make/download, waitlist CSV, team save, off-site copy settings) also call `Auth::requireRecent()` (password typed in the last 15 min). Its 403 `{reauth:true}` makes `api()` in `admin.js` ask for the password and retry; plain download links get `data-sudo`. The session holds a password fingerprint (`pv`): any password change logs that person out elsewhere, so call `Auth::keepSession($newHash)` when someone changes their own. Sessions also end after 7 days (`born`). Role → area access is the `Auth::ACCESS` map. Couriers additionally pass `Route::mayWork()` / `slotFor()` checks so they only touch their own routes.

**Domain logic lives in `app/lib/`** as static classes:
- `Orders` holds the lifecycle. `Orders::checkLimits()` (shop orders only, not `admin`) enforces `shop.max_per_item` per product and one unpaid Tikkie/link order per email or phone. `markPaid()` is the single, idempotent paid flow: stock sale, slot booking with a capacity re-check, gift redemption, invoice, confirmation. `setStatus()` appends `order_events`, which become the order timeline.
- `Stock` handles reservations. Holds expire lazily on reads and by cron.
- `Delivery` covers zones, slots generated from the Settings template, and cut-off.
- `Route` builds routes: nearest neighbour + 2-opt from the depot, ETAs, Google Maps links chunked to 9 waypoints.
- `Courier` handles stop actions and their customer notifications.
- `Messages` / `EmailTemplates` / `Mailer`: email is queued, then sent after the response (`deliverLater`), with cron retries. Extra headers (In-Reply-To for Inbox replies) ride in the queued body's `headers`. WhatsApp is a logged click-to-chat link the owner taps; Telegram replies are logged with status `sent`/`failed`. Every message lands in `messages` and shows on the order timeline when it has an `order_id`. Queries that mean "emails" must filter `channel = 'email'`.
- Marketing:
  - `Discounts::resolve()` checks admin codes and friends' referral codes. A code is counted in `markPaid` and given back in `cancel`.
  - `Pricing::quote()` applies the code after the bundle. The free-delivery threshold ignores the code, and `totals()` in `shop.js` mirrors this.
  - `Referrals::reward()` runs from `Orders::setStatus('delivered')`.
  - `Restock::notify()` runs after `Stock::adjust`, after product saves, and from cron.
  - `Cart::remindAbandoned()` (cron) sends one reminder, only for carts with `consent = 1`. Without consent, no contact details are stored.
  - `Broadcasts`: recipients come from consent flags only. `Messages::flushQueue()` sends customer emails first and throttles broadcast emails to `marketing.per_hour`.
  - All marketing emails share one signed unsubscribe link (`Messages::unsubscribeEmailUrl`), which clears consent everywhere.
- Ops:
  - `Packing::day()` builds the packing list. "Packed" means status `packing` or later. Items and `pick` are sorted by `variants.shelf` (`Packing::byShelf()`, natural order, no shelf last). Packing mode (`pmOpen()`/`pmDraw()`, `#pm`, state `PM`) is a fixed full-screen layer over the admin; `go()` closes it.
  - `Cash`: `orders.cash_by` is set in `markPaid` when `method = cash` and an admin is known. Owners are excluded from `Cash::open()`.
  - `Orders::canReschedule()` / `customerReschedule()` handle the order-page window change: before cut-off, at most 3 times, and a toggle in Settings → Delivery.
- `Margins` (Money screen): `unit()` is the per-sale maths at a price (all excl. BTW; order costs spread over the real items per order once there are `MIN_ORDERS` paid orders in 90 days; the plan's delivery share never goes below 0). `actuals()` shares each paid order's discount and costs over its lines by value and uses `order_items.unit_cost_cents` (falls back to the product's current cost when 0); packaging and delivery use today's rates. `report()` adds speed, stock cover, verdicts and reorder hints. Materials are `pack_cents / pack_qty` each, `per` = `order` or `item`.
- Inbox:
  - `Inbox`: a conversation is one person on one channel (`conversations.channel` + `address` = email or Telegram chat id). `receive()` dedupes by `ext_id` (Message-ID / `tg:chat:msg`), `reply()` sends on the same channel and logs in `messages` (template `chat`); the thread merges the automatic emails to that address. `poll()` runs from cron and, at most once a minute, after the Inbox loads (`pollLater`).
  - `MailIn::senderCheck()` turns the top `Authentication-Results` header into `conversation_messages.auth` (`pass`/`fail`/NULL); `fail` shows *Sender not confirmed* on the bubble.
  - `MailIn`: own IMAP socket client (`ImapConn`, BODY.PEEK, UID position in setting `imap_state`, first run starts from now) and MIME parser + quote stripping. Empty IMAP login/password fall back to SMTP's. `MailIn::$fetcher` is the test hook.
  - `Telegram`: `connect()` (getMe, then webhook on HTTPS or poll mode), `handle()` one update, signed `/start o<number>_<sig>` links from the order page tie a chat to the customer. Inbox photos are re-encoded via `Images::store` and kept in `data/inbox/`.
- `Texts`: every customer-facing text (shop, waitlist, emails, WhatsApp texts, public error messages, Today/Tomorrow, page titles). Originals live in `app/texts.php` (`group => [page, title, url, items => [key => [original, label, opts]]]`; a list item such as the FAQ is `[[[q, a], …], label, opts]`); the owner's changes in the setting `texts`, only what differs. `t($key, $vars)` gives plain text, `th($key, $vars, $raw, $linkAttr)` safe HTML (`**bold**`, `[label](/link)`, line breaks; vars escaped unless named in `$raw`). A text may use only the `{words}` of its original plus `opts.vars`; `Texts::clean()` enforces this, the max length and the outer spaces of glued texts. The shop and waitlist get their keys (`shop.`/`wl.` + `common.`) in `window.SHOP.t` / `window.DROP_CONFIG.t`, with the same `t()`/`th()` in `shop.js`/`waitlist.js` (a var `{ h: "…" }` is raw HTML there).
- `Pdok` (Dutch address geocoder, cached 30 days), `Mollie` and `Telegram` each have a static `$http` hook that tests swap for mocks.

**Data.**
- The DB layer is SQLite by default, with MySQL by config. Migrations are `app/migrations/NNN_*.sql` with `{{PK}}`, `{{TEXT}}` and `{{ENGINE}}` placeholders.
- `DB::migrate()` runs on install, on every admin login and from System, copying the SQLite file to `data/backups/` first.
- Migrations are append-only and must never re-add an existing column (a duplicate `ALTER TABLE ADD COLUMN` breaks every fresh install).
- Money is integer cents and prices include BTW. Timestamps are ISO-8601 UTC text (`DB::now()`), shown in `TZ_LOCAL` (Europe/Amsterdam).
- `data/` (DB, `config.php`, backups, logs, sessions, delivery proof photos, Inbox photos) is never in the zip and may live outside `public_html`. `config.php` may also hold test-only overrides such as `telegram_api`.
- Anything new that stores personal data must be covered by `Customers::export()` and `Customers::delete()` (GDPR), as the Inbox is via `Inbox::forgetCustomer()`.

**Settings.**
- Links in emails and pages come from `Http::baseUrl()`, which is the setting `site_url` (Settings → Store; set on install and, when empty, on admin login). Never build absolute URLs from `HTTP_HOST`: cron has no request and the header can be forged.
- `Backup::offsite()` (nightly from cron, *System → Copy outside this server*) emails the SQLite file as an AES-256 zip locked with the secret `backup_pass`, never the app key.
- `Settings::defaults()` defines every key. `Settings::get()` shallow-merges new default sub-keys into stored objects, so adding a sub-key to defaults is enough for reads.
- The admin save handlers in `AdminApi` (the `GROUPS` map plus a per-group `case`) rebuild each object explicitly, so new fields must also be added there and in the Settings form in `admin.js`.
- Secrets (`Settings::setSecret()`/`secret()`: SMTP and IMAP passwords, Mollie keys, the Telegram token and webhook secret) are encrypted with the app key. `install.php` writes some settings directly (store, SMTP, IMAP host, Telegram via `Telegram::connect()`); a failing optional step there must not abort the install.

**Frontend.** Vanilla JS, no framework, no build step.
- The public pages are PHP views (`app/views/`) that output the prototype HTML with real data in `window.DROP_CONFIG`.
- The admin is one SPA, `assets/admin/admin.js`. `RENDER[screen]` renders each bottom-tab screen. Helpers are `api()` (adds CSRF, JSON or FormData), `sheet()` (bottom sheets), `toast(text, {act, fn}?)` (good news; with an action button such as Undo it stays 6 s), `toastErr()` (red, stays until closed; `busy()` sends every error there), `ask(msg, {title, ok, danger, input}?)` (the in-app confirm/prompt, a Promise of true/false or the typed text/null, above any sheet), `busy()`, `esc()` (escape all output) and `eur()` (Dutch format, `€34,95`). Never use `confirm()`/`prompt()`/`alert()`. Ask before final or customer-facing actions; for steps that tell the customer nothing, act at once and offer Undo in the toast. Deep links use `/admin/<screen>#<sub>`.
- Fonts are self-hosted. The CSP allows no external scripts.
- The CSP lives in `Http::securityHeaders()`. Its only external origin is `img-src https://service.pdok.nl`, for map tiles. Any new external resource needs that header changed.
- Front-end libraries are vendored in `src/assets/vendor/`. Leaflet 1.9.4 there has its sourcemap comment stripped. `build.sh` minifies all assets with esbuild when esbuild is available.
- The admin is mobile-first. The desktop layout is one `@media (min-width:1024px)` block at the end of `admin.css`, and phones must look unchanged.
  - On desktop the bottom `.nav` becomes a sidebar (brand, `.nv-h` group titles hidden by `drawSidebar()` when empty, `.nv-me` user card with logout, `#nvFold` folds it to icons via `html.nav-mini`, localStorage `d01navmini`). Labels are `<span class="nl">`; target the bar's wrapper as `.nav > div`, never `.nav div`. The `.dk` buttons (Stock, Waitlist, Marketing, Settings, Team, System) and the `.nv-*` parts show only there, and "More" is hidden. On phones Stock is reached through More (Inbox took its place in the bar).
  - Inbox screen (`RENDER.inbox`, state `IB`): `.ib` grid of `.ib-side` (list) and `.ib-main` (chat). On phones `.ib.open .ib-main` is a fixed full-screen layer; deep link `/admin/inbox#c<id>`. Channel icons are `.chb.email` / `.chb.telegram`.
  - Select tabs with `.nav button[data-s]`, because the logout and fold buttons have no `data-s`. E2e tests that open Stock on a phone must go through More.
  - `.sheet` becomes a right-hand panel on desktop.
- Settings screen (`RENDER.settings`): one template with a section per `<section id="set-<key>">`, listed in `SET_SECTIONS`. On desktop only the `.cur` section shows (left menu, `showSection()`); on phones every section shows and the menu chips scroll to them.
  - A new section must be added to `SET_SECTIONS`. A new form gets `data-track`, which drives the "unsaved" badge, the before-leave warning and Ctrl/⌘+S. Its submit button goes inside `<div class="set-save">`.
  - Save handlers must `delete f.dataset.dirty` on success. `RENDER.settings()` re-renders everything, and `keepDirty()`/`restoreDirty()` carry over unsaved edits in other sections.
- Delivery screen (`drawDelivery`): sticky `.dv-bar` (days and day totals), `upNext()` (today only, the next stop with big buttons), then one `routeCard()` per window with its `stopRow()`s.
  - Stop buttons use `data-sact`, resolved through `closest(".stop,.upnext")`.
  - The e2e tests depend on these hooks: `.route[data-slot] .head` (the "X of Y delivered" text), `.stops[data-slot] .stop`, `.stop.next [data-sact=onway]`, `[data-start]`, `[data-reset]` and `[data-courier]`.
  - Map visibility is the `d01map` localStorage key, and courier mode is `d01courier`.
- Delivery map:
  - `mapCard()` / `drawMap()` in `admin.js` draw it. Leaflet is lazy-loaded from `window.ADMIN.leaflet`. Tiles are the PDOK BRT `grijs` set, darkened with a CSS filter.
  - There is one `LMAP` instance, removed on every `drawDelivery()`.
  - Leaflet popups stop click propagation, so wire popup buttons in `popupopen`, not through delegation.
  - `.lmap` needs `z-index:0` so its panes don't cover the sticky header.

## Conventions and gotchas

- SQL inside PHP single-quoted strings needs `\'` for literals. Always use prepared statements.
- PHP arrow functions (`fn`) capture variables **by value**. Don't use them over data you mutate in a loop. This once silently broke 2-opt.
- `shop.css` and `waitlist.css` use short global class names (`.slot`, `.code`, `.track`, `.card`…). Scope new styles under the view (e.g. `#v-ok`) or pick a name that's unused, or they will pick up the checkout's styles.
- Every public CSS file kills all animation under `prefers-reduced-motion` (`animation:none!important`). Anything that becomes visible only through an animation's end state stays hidden there. Put the visible state in the base rule and animate *from* the hidden state with `animation-fill-mode: backwards`.
- **Customer-facing copy is never hard-coded.** Add the key to `app/texts.php` with the original wording and a plain label, then use `t()`/`th()` (PHP) or `t()`/`th()` (shop/waitlist JS). `test_texts_catalogue_is_complete` fails on a literal key that is missing; keys built from parts (`"shop.timer." + l`, `"email.$k.subject"`, `'page.' . $slug`, `'wa.' . $k`) are not caught, so check them by hand. Keep originals identical to the old copy (the e2e tests match some of it). In `shop.js`, don't name a local variable `t` where `t()` is used.
- What a customer sees about their order is exactly `ShopApi::publicOrder()` (behind the order token). To show more on the order page, add the field there.
- Never fake social proof: live-activity pills, counts and "on site now" must come from real data or stay hidden.
- Public UI and admin are in English. The owner reads Russian best: `DEPLOY-GODADDY.md` is bilingual (EN + RU), written for a non-developer, with exact menu names and a "If you see X, do Y" table.
- Printing (packing list and labels) uses `body.printing` + `#print` and an `@media print` block in `admin.css`. Everything except `#print` is hidden there.
- Each phase ends with: tests green (`test-all.sh`), zip rebuilt, `CHANGELOG.md` and `DEPLOY-GODADDY.md` (both languages) updated, and a short "what to click to verify" list for the owner.

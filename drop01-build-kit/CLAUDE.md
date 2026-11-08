# Drop 01 — Build Spec for Claude Code

You are building a **complete, working e-commerce system** for a small product-reselling business in **Groningen, the Netherlands**. Customers order on a mobile website and the business delivers the goods itself, by district, in time windows.

The system has four parts that share one database:

| Part | What it is | Design source (pixel reference) |
|---|---|---|
| **Waitlist** | Pre-launch page that collects emails and phones and lets people vote on products | `prototypes/waitlist.html` |
| **Shop** | The storefront: catalogue, product page, bag, checkout, order tracking | `prototypes/shop.html` |
| **Waitlist dashboard** | Analytics for the waitlist: visits, funnel, sources, votes, signups | `prototypes/waitlist-dashboard.html` |
| **CRM / back office** | Orders, delivery and courier mode, stock, customers, money | `prototypes/crm.html` |

The final deliverable is **one zip file** that the owner uploads to **GoDaddy cPanel shared hosting**, extracts into `public_html`, opens `/install.php` in a browser, and is live. No terminal, no Node, no Composer on the server.

The owner is **not a developer**, so every manual step must be written for a non-technical person.

---

## 0. Ground rules

1. **The prototypes are the source of truth for look and feel.**
   - They are finished, approved designs with fake data. Port them; do not redesign them.
   - Keep colours, fonts, spacing, copy, animations and micro-interactions as they are.
   - Replace the fake data with real data from the API.
   - Where a prototype hard-codes settings (store name, drop date, products, gift limit, marquee texts and so on), those become **admin settings stored in the database**.
2. **Mobile-first.** The public pages are designed for phones (360–430 px wide). They must also not break on desktop: keep the centered max-width layouts the prototypes use.
3. **The waitlist intro is the signature moment. Treat it with care.** The flow is:
   - a holographic booster pack falls in through WebGL smoke;
   - the visitor can only open it with a swipe;
   - the pack tears, the camera dives into it and through a foil tunnel while the words "SOMETHING / IS / DROPPING." fly past;
   - everything collapses into a single star, then a "big bang" shockwave reveals the site inside the ring.

   Keep all of it, including:
   - its safety nets: the frame loop is wrapped in `try/catch`, there is a timeout that always opens the site after the pack is torn, and a `prefers-reduced-motion` skip;
   - its performance tricks: shaders are compiled up front, sizes are adaptive, there are no GPU read-backs, and nothing animates off-screen.
4. **Keep the stack boring and portable:**
   - **PHP 8.1+** (target 8.2), **SQLite via PDO** (MySQL optional, see §3), vanilla JS, no frontend framework, no build step required on the server;
   - any third-party PHP library is **vendored** into the zip; prefer zero dependencies;
   - it must run on GoDaddy cPanel shared hosting with Apache and `.htaccess`.
5. **Never fake social proof.** The prototypes contain demo-only elements that must use real data or stay hidden:
   - the shop's "Someone in Helpman got Item 02 · 4 min ago" pill must come from **real paid orders** in the last 24 h, show district and product only, never names. If there are none, hide it;
   - "X on site now" in the dashboard must be real (active sessions in the last 5 min);
   - no invented counts anywhere.
6. **Everything user-facing on the public site is in English.** The admin UI is English too, with labels kept simple.
7. **Timezone:** `Europe/Amsterdam`. **Currency:** EUR, Dutch formatting in the shop (`€34,95`). **Prices include BTW (VAT).**
8. Write clean, commented code. Each PHP file starts with a one-line comment saying what it does.

---

## 1. What already exists and what must change in each prototype

### 1.1 `waitlist.html` (≈220 KB, single file)

- **Settings block** near the line `const PREVIEW_MODE = true;`, plus `STORE_NAME`, `DROP_DATE`, `GIFT`, `WAITLIST_COUNT`, `PRODUCTS`, `MOST_WANTED_MIN`, `MARQUEE` and `COUNTRIES`.
  - Replace these with values injected by PHP. The page fetches `/api/public/config` at boot, or PHP renders them into a `<script>window.DROP_CONFIG=…</script>`.
  - `PREVIEW_MODE` must be **false** in production.
- **Signup** currently saves to `localStorage` and invents a gift code client-side. Change it to:
  - `POST /api/waitlist/signup` with email, phone, country code, consent, UTM and referrer;
  - the server validates, de-duplicates by email and phone, and **issues the gift code** (unique, e.g. `D01-7KQ2`);
  - the server enforces the gift limit (`GIFT.limit`, e.g. 500). After that, signups still succeed but get no gift;
  - the response contains the code and the person's position number.
- **Product votes** (`var Votes = …`) have a branch for a hosted key-value store plus a `localStorage` fallback. Replace both:
  - `GET /api/votes` returns counts;
  - `POST /api/votes` takes `{productId, value: 1|-1|0}`;
  - votes are keyed by an anonymous visitor id (random UUID in `localStorage`, sent with the request) with server-side rate limiting.
  - Counts are shared by all visitors. Keep the optimistic UI and roll-number animation.
- **Counters:** the "already in line" proof line and the gift spots left come from the server.
- **Analytics events** (see §6) are sent with `navigator.sendBeacon('/api/e', …)`.
- **Product images:** the `image` field per product comes from admin uploads. Sealed/reveal logic stays: `reveal:false` shows packaging.
- **Mode switch:** when the admin switches the store from **Waitlist mode** to **Shop mode**, `/` serves the shop. The waitlist stays reachable at `/waitlist` for reference, or is redirected; this is an admin setting.

### 1.2 `shop.html`

Wire every interaction to the API: catalogue, stock, bag, checkout, payment and order tracking. Specifics:

- **Hero card fan:** shows featured products (admin flag `featured`).
- **Postcode check:** uses delivery zones from the database. Remember the visitor's postcode in `localStorage` (only on their device). The header "✓ Paddepoel" chip must keep working.
- **Delivery promise:** "Order within 7h 15m → tonight 18–21" uses the real cut-off time and real slot availability.
- **Stock and urgency:** "93% claimed", "Only 6 left" and "Only 2 spots left" use real stock and slot capacity.
- **Bundles:** the "The full drop" bundle is an admin-defined bundle (product set + % off).
- **Bag:** stored server-side as a session cart, mirrored to `localStorage` for speed.
- **Mystery gift line:** auto-applies only if the customer's email or phone matches a waitlist signup that holds a gift code that is not yet redeemed. Redeem on paid order.
- **"Notify me"** on sold-out items stores a back-in-stock request (email or phone) linked to the product.
- **Address lookup** uses the free Dutch government geocoder **PDOK Locatieserver**:
  - call it with postcode + house number to fill in street, city and lat/lon;
  - example: `https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q=9742BD%2022&fq=type:adres&rows=1`;
  - fields used: `straatnaam`, `woonplaatsnaam`, `centroide_ll`;
  - proxy it through `/api/address` and cache it. Verify the endpoint during the build.
- **Payments:**
  - "Express checkout" buttons (Apple Pay / iDEAL) and the main pay button create a **Mollie** payment (§5) and redirect to Mollie's hosted checkout.
  - On return, show the "It's yours." page with real order status.
  - **Tikkie** in v1 means: order created as *awaiting payment*, and the shop owner sends a Tikkie manually from the CRM, which shows a "Paste Tikkie link → send via WhatsApp" helper. Automatic Tikkie API is phase 5.
- **Referral block** "Give €5, get €5" on the success page: real referral codes (§7.7).
- **Live activity pill:** real data only (see rule 0.5).
- **Static pages:** Terms, Privacy, Returns, Contact. Generate sensible English drafts with clear `[[PLACEHOLDER]]` markers for company name, KvK and BTW number. Add a note in the admin telling the owner to have them checked.

### 1.3 `waitlist-dashboard.html`

This becomes the **Waitlist** tab of the admin. All numbers come from SQL aggregates:

- KPIs: visitors, unique visitors, conversion, pack-opened rate, average time on site;
- trend charts;
- funnel: landed → opened pack → typed email → added phone → joined;
- traffic sources, devices, countries;
- product votes and Most wanted;
- signups list with search, reveal-on-tap, and **CSV export** (UTF-8 with BOM so Excel opens it correctly).

The date-range control (Today / 7 days / 30 days / All) drives every query.

### 1.4 `crm.html`

This becomes the main admin app at `/admin`. Bottom tabs: **Today, Orders, Delivery, Stock, Customers**, plus a **More** menu for Waitlist, Marketing, Money, Settings and Team. Everything in the prototype must work with real data (§7).

---

## 2. Target hosting and deployment (GoDaddy cPanel)

- **Hosting:** GoDaddy *Web Hosting (cPanel)*, Linux, Apache with `.htaccess`, PHP selectable in cPanel (*Select PHP Version* or *MultiPHP Manager*), cron jobs available, AutoSSL available.
- **Required PHP extensions:** `pdo_sqlite`, `sqlite3`, `curl`, `gd`, `mbstring`, `openssl`, `json`, `fileinfo`, `zip`.
  - The installer checks every one of them and shows a friendly fix-it message, e.g. "Open cPanel → Select PHP Version → tick `pdo_sqlite`".
- **The zip extracts straight into `public_html`.** All private code and data live inside it but are locked down:
  - `.htaccess` `Require all denied` in `app/`, `data/` and `vendor/`, plus a PHP guard constant in every non-entry file;
  - the installer also tries to move `data/` one level above `public_html` if that is writable, and records the path in config.
- **No secrets in the zip.** The installer writes `data/config.php`: DB path, app key, Mollie key, SMTP and so on.
- **Updates:** the owner uploads a newer zip and extracts over the old one.
  - The zip **never contains `data/`**, so the database, uploads and config survive.
  - Pending DB migrations run automatically on the next admin login (§3.3).

### 2.1 Folder layout inside the zip

```
public_html/                 ← zip root = these contents
├── index.php                ← front controller (routes /, /p/{slug}, /checkout, /order/{id}, /waitlist, /pages/*)
├── install.php              ← first-run wizard; locks itself (renames to install.lock.php + refuses to run) when done
├── .htaccess                ← HTTPS redirect, pretty URLs → index.php, security headers, gzip, caching, deny dotfiles
├── robots.txt / sitemap.php / favicon + app icons + manifest.webmanifest (admin is installable as a PWA)
├── assets/
│   ├── waitlist/  (css, js split out of the prototype — or kept inline if splitting risks breaking it)
│   ├── shop/
│   ├── admin/
│   └── fonts/     (self-hosted woff2 of Bricolage Grotesque, Manrope, JetBrains Mono — no Google Fonts call, better privacy + speed)
├── uploads/        ← product images (public); .htaccess disables PHP execution here
├── api/
│   └── index.php  ← single API router: /api/...
├── admin/
│   └── index.php  ← admin SPA shell (session-protected)
├── app/            ← PHP source (denied to the web)
│   ├── bootstrap.php, Router.php, DB.php, Auth.php, Csrf.php, RateLimit.php, Mailer.php, Mollie.php, Pdok.php, Invoice.php, Analytics.php …
│   ├── controllers/  (Public*, Waitlist*, Shop*, Checkout*, Admin*)
│   ├── migrations/   (001_init.sql, 002_…sql)
│   └── views/        (PHP templates that output the prototype HTML with data)
├── vendor/         ← vendored libs only if truly needed (e.g. PHPMailer, a tiny PDF lib); denied to the web
├── cron.php        ← one entry for all scheduled jobs; refuses to run unless called from CLI or with the secret token
└── data/           ← NOT in the zip. Created by install.php: app.sqlite, config.php, backups/, logs/
```

### 2.2 The build script (you write it, you run it, you ship its output)

`tools/build.sh` (bash) or `tools/build.php` produces `dist/drop01-deploy.zip`. It must:

1. copy only the runtime files (no `tools/`, tests, `.git`, `data/`, `node_modules`, `prototypes/`);
2. self-host and subset the three font families into `assets/fonts/`;
3. minify CSS and JS if a minifier is available, otherwise ship unminified (correctness over size);
4. stamp a version (`app/version.php` = date + git short hash if available);
5. run `php -l` on every PHP file and fail the build on a syntax error;
6. print the zip size and the file count.

Also produce `dist/DEPLOY-GODADDY.md`, a step-by-step guide for a non-technical person (§10), and keep a copy in the repo root.

---

## 3. Data model

### 3.1 Database

- Default is **SQLite**: one file, zero setup, perfect for this scale. Turn on `PRAGMA journal_mode=WAL; foreign_keys=ON; busy_timeout=5000`.
- Write the DB layer so that **MySQL works by config** too. The installer offers "SQLite (recommended)" or "MySQL (advanced)"; GoDaddy cPanel has MySQL.
- Use portable SQL. Keep money as **integer cents**. Store timestamps as ISO-8601 UTC text and convert to Amsterdam time for display.

### 3.2 Tables (minimum; add what you need)

```
settings(key PK, value JSON)                          -- store name, mode, drop date, gift config, marquee, cut-off, free-delivery threshold, etc.
admins(id, name, email UNIQUE, pass_hash, role, totp_secret NULL, created_at, last_login_at)   -- roles: owner, manager, warehouse, courier, accountant
sessions / login_attempts                              -- brute-force lockout

products(id, slug UNIQUE, name, description, price_cents, cost_cents, vat_rate (21|9), category, featured, reveal, sort, active, run_size, low_stock_at, created_at)
product_images(id, product_id, path, sort)
variants(id, product_id, label, color_hex, sku, stock, active)   -- a product without real variants has one default variant
bundles(id, name, discount_pct, active) + bundle_items(bundle_id, product_id)

customers(id, name, email, phone, source, marketing_consent, consent_at, created_at, notes)
addresses(id, customer_id, postcode, house_no, addition, street, city, lat, lng, zone_id)
waitlist_signups(id, email, phone, country, gift_code UNIQUE NULL, gift_redeemed_order_id NULL, position, source, utm JSON, consent, created_at)
votes(visitor_id, product_id, value, updated_at, PK(visitor_id, product_id))
restock_requests(id, product_id, email, phone, created_at, notified_at)

delivery_zones(id, name, postcode_from, postcode_to, fee_cents, free_from_cents, active)   -- 4-digit postcode ranges, e.g. 9711–9712 Centrum; Haren 9750–9752 €5,95
delivery_slots(id, date, start, end, capacity, booked, closed)                           -- generated from a weekly template in Settings
couriers → admins with role courier

orders(id, number UNIQUE e.g. 1062, customer_id, address_id, slot_id, status, payment_status, payment_method, subtotal_cents, discount_cents, delivery_cents, total_cents, vat_cents, gift_code NULL, discount_code NULL, referral_code NULL, channel (web|whatsapp|instagram|phone|other), notes, created_at, paid_at, delivered_at)
order_items(id, order_id, product_id, variant_id, name_snapshot, unit_price_cents, qty, vat_rate)
order_events(id, order_id, type, data JSON, by_admin_id NULL, created_at)    -- status changes, messages sent, notes → the timeline in the order sheet
payments(id, order_id, provider (mollie|tikkie|cash|manual), provider_id, status, amount_cents, method, raw JSON, created_at, updated_at)
refunds / returns(id, order_id, reason, items JSON, resolution (refund|exchange|rejected), amount_cents, status, created_at)
invoices(id, order_id, number UNIQUE sequential per year e.g. 2026-0001, pdf_path, created_at)

suppliers(id, name, contact, email, phone, lead_days, notes)
purchase_orders(id, supplier_id, status (draft|ordered|received|cancelled), expected_at, total_cents, created_at) + po_items(po_id, variant_id, qty, unit_cost_cents)
stock_movements(id, variant_id, delta, reason (sale|return|receive|adjust|damage|reserve|release), ref, by_admin_id, created_at)

expenses(id, date, category (stock|delivery|ads|packaging|other), amount_cents, vat_cents, note)
discount_codes(id, code UNIQUE, type (pct|fixed|free_delivery), value, min_cents, max_uses, used, starts_at, ends_at, active)
referral_codes(id, customer_id, code UNIQUE, uses) 
abandoned_carts(id, session_id, email NULL, phone NULL, cart JSON, reminded_at, recovered_order_id)

events(id, ts, visitor_hash, session_id, type, path, ref_host, utm_source, utm_medium, utm_campaign, device, country, product_id NULL, value NULL)   -- analytics, see §6
messages(id, customer_id NULL, order_id NULL, channel (email|whatsapp|sms), template, to_addr, status, error, created_at)
audit_log(id, admin_id, action, entity, entity_id, data JSON, created_at)
schema_migrations(version PK, applied_at)
```

### 3.3 Migrations

- SQL files live in `app/migrations/NNN_name.sql`.
- `DB::migrate()` applies the pending ones inside a transaction. It runs:
  - from `install.php`;
  - on every admin login;
  - from a "Run updates" button in Settings → System.
- **Before migrating, copy the SQLite file** to `data/backups/pre-migrate-{version}.sqlite`.

---

## 4. Order lifecycle and stock

**Statuses:** `new` → `paid` → `packing` → `out_for_delivery` → `delivered`.

Side branches: `failed_delivery` (nobody home → reschedule), `cancelled`, `returned`.

**Payment status** is a separate field: `unpaid` | `paid` | `refunded` | `partially_refunded`.

1. **Checkout start:** variants are reserved (`stock_movements reserve`) for **15 minutes**. Expired reservations are released by cron and lazily on every read.
2. **Payment success** (Mollie webhook says `paid`):
   - the reservation becomes a sale;
   - the order becomes `paid`;
   - the slot's `booked` count goes up (capacity is checked again; if the slot filled up meanwhile, flag the order "needs new slot");
   - the gift code is redeemed;
   - an invoice is created;
   - the confirmation email and WhatsApp message are sent (§8).
3. **Unpaid timeout:** after 60 minutes (configurable) an unpaid order is cancelled and stock released. Tikkie orders get a longer window (configurable, default 24 h). Shop orders take at most 5 of one product (configurable) and one unpaid Tikkie/link order per customer, so nobody can hold a drop with unpaid orders.
4. **CRM transitions:** each status change from the CRM appends `order_events` and may send a message (configurable per status).
5. **Returns:**
   - "Back to stock" adds stock back;
   - "Damaged" logs a `damage` movement;
   - the refund goes through the Mollie Refunds API when the original payment was Mollie, otherwise it is marked as manual.
6. **Low-stock alerts** trigger when `stock ≤ low_stock_at`. They appear in "Needs your attention" and in an optional email to the owner.

---

## 5. Payments — Mollie

- Use Mollie (Dutch, supports **iDEAL, Apple Pay, cards, Bancontact**). Call the REST API directly with cURL, no SDK needed:
  - create: `POST https://api.mollie.com/v2/payments` with `amount{currency:"EUR", value:"59.90"}`, `description`, `redirectUrl` (`/order/{number}?t={token}`), `webhookUrl` (`/api/mollie/webhook`) and `metadata{order_id}`;
  - optionally pass `method` for the express buttons: `applepay` or `ideal`.
- **Webhook:**
  - Mollie posts only an `id`. **Always fetch the payment from the API** to get the real status; never trust the POST body.
  - Make it idempotent and respond `200` fast.
- **Return page:** if the webhook has not arrived yet, poll `/api/orders/{number}/status` for up to 20 s and show "Confirming payment…". Always show something sensible, never a blank page.
- **Keys and environment:**
  - test key and live key are entered in Settings, with a "Test mode" toggle;
  - the installer accepts a test key so the owner can try a full purchase before going live.
- **Apple Pay** on the web via Mollie hosted checkout needs domain verification. The build explains this step in the deploy guide; verify the current requirement in Mollie's docs during the build.

---

## 6. Analytics (cookieless, GDPR-friendly)

- **No cookies and no IP storage for analytics.** The visitor id is a **daily-rotating hash**: `sha256(daily_salt + ip + user_agent)`. The salt rotates at midnight and the old one is deleted. This gives unique-visitor counts per day without tracking people across days.
- **Session id:** random value in `sessionStorage`.
- **Endpoint:** `POST /api/e`, beacon, batched, rate-limited. **Event types:**
  - `pageview`;
  - `intro_shown`, `intro_opened` (swiped the pack), `intro_completed`;
  - `email_started`, `phone_added`, `signup`;
  - `vote`, `product_view`, `add_to_cart`, `checkout_started`, `payment_started`, `purchase`;
  - `postcode_check` (with result zone/none — this also gives demand from areas you don't serve yet);
  - `notify_me`.
- **Derived values:**
  - **source** from UTM, falling back to the referrer host, which is mapped to Instagram / TikTok / WhatsApp / Google / Direct / Other;
  - **device** from the user agent (iPhone / Android / Desktop);
  - **country** from the `Accept-Language` region or, better, a tiny bundled IP-to-country table if one fits. If neither is reliable, show "Country" only when known.
- **"On site now"** = distinct sessions with an event in the last 5 minutes.
- **Retention:** keep raw events for 13 months, then aggregate to daily rows.

---

## 7. Admin / CRM — features and acceptance criteria

The admin lives at `/admin`. Design is exactly `prototypes/crm.html` and `prototypes/waitlist-dashboard.html`: dark, mobile-first, bottom tabs, bottom sheets.

**Auth and security:**
- email + password (`password_hash`);
- optional TOTP 2FA for the owner;
- 10 failed logins → 15-minute lockout per account and per IP hash;
- secure session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`);
- CSRF token on every state-changing request;
- a new password ends that person's other sessions; sessions last at most 7 days;
- risky actions (backups, the contacts CSV, team, off-site copy settings) need the password typed in the last 15 minutes.

**Roles:**
- **owner:** everything;
- **manager:** orders, customers, stock, delivery, marketing;
- **warehouse:** orders (packing), stock;
- **courier:** only Delivery → own route;
- **accountant:** Money + exports.

Every write goes to `audit_log`.

### 7.1 Today

- KPI tiles: sales today, orders today, to deliver (done/left), profit today with margin %, each compared with the same weekday last week;
- **Needs your attention:** unpaid orders older than 30 min, failed deliveries, low and sold-out stock, slots ≥ 90% full, orders needing a new slot, unanswered restock requests. Each item deep-links;
- sales over the last 7 days, today's delivery windows with capacity;
- month to date: revenue, costs, profit, **BTW to set aside** (output VAT minus input VAT from expenses);
- best sellers this week.

### 7.2 Orders

- list with status chips and counts, search by number, name or street, infinite scroll;
- order sheet:
  - status stepper and the primary action for the next step;
  - customer and address with a map link, slot, payment state;
  - items with BTW;
  - message timeline;
  - WhatsApp and call buttons;
  - edit items or slot, cancel, refund/return, resend payment link, internal note, print or download invoice;
- **"+ New order"** for WhatsApp, Instagram or phone orders:
  - pick or create a customer, add products, pick a slot;
  - payment: Mollie payment link (sent by WhatsApp/email), Tikkie (paste link), cash on delivery or paid manually.

### 7.3 Delivery

- day picker;
- one **route per slot**:
  - ordered stops, ETA per stop, progress bar, distance and time estimate;
  - **"Open in Google Maps"** builds a directions URL with the waypoints, chunked to Google's waypoint limit;
  - route order is **nearest-neighbour from the depot address (Settings) using PDOK lat/lon, then a 2-opt improvement**, all computed in PHP;
  - drag to reorder manually;
- **courier mode** (role courier, also usable by the owner):
  - a big list with Delivered, Navigate, Call and Not home;
  - "collect payment" flag for unpaid or cash orders;
  - optional photo proof (upload, resized);
  - "Delivered" and "Not home" fire customer messages;
  - optional "On my way, ~10 min" button per stop that sends the heads-up message;
- zone overview for the week;
- **slot template** in Settings: weekday × windows × capacity, cut-off times (default: same-evening window closes at 16:00), blackout dates.

### 7.4 Stock

- stock value at cost, running-low count;
- product list with stock, margin and low/sold-out badges;
- product editor: name, slug, description, price, cost, BTW rate, category, featured, waitlist reveal, run size, low-stock threshold, variants with own stock, images (upload → server resizes to 1600px and 600px WebP/JPEG with GD, strips EXIF);
- stock adjustments with a reason; the movement history is the audit trail;
- **purchase orders:** suppliers → draft → ordered → **receive** (adds stock and updates the weighted-average cost);
- "What to reorder" hint from the 14-day sales rate × supplier lead time;
- bundles editor.

### 7.5 Customers

- segments: All, Regulars (≥3 orders), New (first order ≤30 days), Inactive 30d+, Waitlist-only (signed up, never ordered), plus search;
- customer sheet:
  - spent, orders, average order value;
  - contact details, source, **marketing consent with date**;
  - addresses, orders, notes;
  - actions: WhatsApp, new order, send discount code;
- **GDPR:** "Export customer data" (JSON) and "Delete customer" (anonymises orders, which are kept for tax law, and deletes personal data).

### 7.6 Waitlist

The real-data version of `waitlist-dashboard.html` (see §1.3), plus:
- gift settings (limit, title, text);
- the Waitlist ↔ Shop **mode switch** with a confirmation step;
- **"Launch broadcast":** email (and WhatsApp template, phase 5) to all consented waitlist members.

### 7.7 Marketing

- discount codes CRUD with usage stats;
- referral programme: each customer gets `NAME-D01`; the friend gets €X off the first order, the referrer gets €X credit as a discount code after the friend's order is delivered; amounts in Settings;
- abandoned-cart reminder: email after 1 h if contact details were entered and consent given;
- back-in-stock notifications when stock goes from 0 to >0;
- simple email broadcast to a segment, consented only, with an unsubscribe link.

### 7.8 Money

- revenue, refunds, costs (COGS from `order_items` × cost, plus expenses), profit by day, week and month;
- BTW summary per quarter for the bookkeeper (21% and 9% output VAT, input VAT from expenses);
- invoice list (PDF);
- **exports:** orders CSV, invoices ZIP, expenses CSV;
- expenses entry with optional receipt photo.

### 7.9 Settings

- store info: name, KvK, BTW-id, address, depot address, contact WhatsApp number, email;
- branding: logo text, store mode, drop date, marquee texts, countries list for the phone field;
- delivery zones and fees, free-delivery threshold, slot template, cut-off;
- payments: Mollie test/live keys and toggle, Tikkie instructions;
- email: SMTP host, port, user, password and from address, with a **"Send test email"** button;
- message templates per event, with variables such as `{first_name}`, `{order_number}`, `{eta}`;
- team and roles;
- legal pages editor;
- **System:**
  - version;
  - "Run updates";
  - **"Download backup"** (zip of the SQLite file + uploads);
  - cron status (last run time; turns red if older than 15 min);
  - PHP and extensions check.

---

## 8. Messaging

- **Email (v1, required):**
  - SMTP via vendored **PHPMailer**: GoDaddy email or any SMTP such as Brevo or Gmail app password;
  - templates are HTML in the same dark holographic style, with a plain-text fallback.
- **Events that send email:** waitlist signup (with gift code), order confirmation with invoice PDF, payment link, out for delivery with ETA window, delivered, failed delivery, refund, restock, abandoned cart, referral reward.
- **WhatsApp (v1):**
  - **click-to-chat links** (`https://wa.me/<number>?text=<prefilled>`) from the CRM, so the owner sends with one tap from their own WhatsApp;
  - the message is prefilled from the template.
- **WhatsApp Cloud API (phase 5, optional):** automatic sending via Meta's API once the owner has a verified business. Design the `Messenger` interface so it can be added without touching callers.
- Every message is logged in `messages` and visible on the order timeline.

---

## 9. Security, privacy, performance checklist (must pass)

- [ ] All SQL uses prepared statements; all output is escaped (`htmlspecialchars` / `textContent`).
- [ ] CSRF on admin writes; public forms have a honeypot field + time-to-submit check + rate limit per IP hash.
- [ ] `.htaccess` denies `app/`, `data/`, `vendor/`, `tools/`, dotfiles, `*.sqlite`, `*.log`, `*.md` (except the public pages), and disables PHP execution in `uploads/`.
- [ ] Security headers: `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and a sensible `Content-Security-Policy`. Fonts are self-hosted; only Mollie and PDOK are external.
- [ ] `install.php` cannot run twice; `cron.php` needs CLI or a secret token.
- [ ] Mollie webhook re-fetches the payment; idempotent.
- [ ] Uploads: image MIME check with `finfo`, re-encoded through GD, random file names.
- [ ] Privacy: consent checkbox for marketing (unticked by default), cookieless analytics, a privacy page that lists what is stored and why, data export and delete, and no third-party trackers by default.
- [ ] **Consumer law (EU/NL):** 14-day withdrawal right on the returns page and in the order email, total price incl. BTW and delivery shown before payment, company details in the footer. Flag in the admin that the legal texts are drafts to review.
- [ ] Performance targets on a mid-range phone over 4G:
  - shop home LCP < 2.5 s, CLS < 0.1;
  - images are lazy-loaded, `srcset`, WebP;
  - long-term cache headers on `/assets` with versioned file names;
  - the waitlist intro keeps its existing performance safeguards.
- [ ] Accessibility: visible focus states, labels on inputs, `prefers-reduced-motion` respected, colour never the only signal (status pills have text and icon).
- [ ] Nightly backup by cron to `data/backups/` (keep 14). The owner can download any of them from Settings → System.
- [ ] Optional nightly off-site copy: the database as an AES-256 zip emailed to an address outside the server, with its own backup password.

---

## 10. `DEPLOY-GODADDY.md` — what it must say (for a non-technical owner)

Write it in **simple English and also in Russian** (the owner reads Russian best). It needs short numbered steps with exact menu names, and a "If you see X, do Y" troubleshooting table.

1. **Before you start:** you need a GoDaddy **Web Hosting (cPanel)** plan (not "Website Builder"), your domain connected to it, and a Mollie account (test key is fine to start).
2. **SSL:** cPanel → *SSL/TLS Status* → run AutoSSL. Wait until the domain shows a green lock.
3. **PHP:** cPanel → *Select PHP Version* (or *MultiPHP Manager*) → choose PHP 8.2 or newer → in *Extensions* make sure the extensions listed in §2 are ticked.
4. **Upload:**
   - cPanel → *File Manager* → open `public_html`;
   - if there are old files (for example a GoDaddy placeholder `index.html`), delete them;
   - *Upload* → `drop01-deploy.zip` → back → right-click the zip → *Extract* → delete the zip.
5. **Install:**
   - open `https://yourdomain.com/install.php`;
   - the checker shows green or red per requirement;
   - fill in store name, your admin email and password, and choose SQLite;
   - paste your Mollie test key and (optionally) SMTP details;
   - click Install, then open `/admin`.
6. **Cron:** cPanel → *Cron Jobs* → add "Every 5 minutes" with the command shown on the last installer screen. The command is generated, with the correct PHP path and the file path, e.g. `/usr/local/bin/php /home/USER/public_html/cron.php`.
7. **Test a purchase** in Mollie test mode on your phone. Then switch to the live key in Settings → Payments.
8. **Go live:** set the store mode (Waitlist now, Shop on launch day).
9. **Updating later:** upload the new zip and extract over the old files. Your data is safe because it lives in `data/`, which the zip never touches. Then log in to `/admin` — updates apply automatically.
10. **Backups:** Settings → System → Download backup. Do this before every update.

Include screenshots placeholders, e.g. `![cPanel File Manager](…)` with a description, since you can't take real GoDaddy screenshots.

---

## 11. Build phases (do them in order; each ends in a working, testable zip)

Start every phase by re-reading the relevant prototype. End every phase by:
- running the tests (§12);
- building the zip;
- updating `CHANGELOG.md` and `DEPLOY-GODADDY.md`;
- writing a short summary of what to click to verify.

**Phase 1 — Foundation + Waitlist live**
1. Repo skeleton, router, DB layer, migrations, installer with requirement checks, admin auth, settings, `.htaccess`, build script producing the zip.
2. Port `waitlist.html` exactly; wire config, signup, gift codes, shared votes and analytics events.
3. Admin shell (the crm.html frame) with the **Waitlist** tab (real dashboard), Settings (store, waitlist, products with image upload, email), and the System page.
4. Emails: waitlist confirmation with gift code.
5. ✅ Done when: the owner can deploy to GoDaddy, install, add 4 products with photos, and share the link; people can sign up and vote from different phones and see shared counts; the dashboard shows real funnel numbers; CSV export works.

**Phase 2 — Shop + checkout + payments + core CRM**
1. Port `shop.html`: catalogue, product page, bag, bundles, postcode check, PDOK address, slots, checkout, Mollie, return page, order tracking page (`/order/{number}?t=token`).
2. CRM: Today, Orders (incl. new manual order), Customers, Stock (products, variants, adjustments).
3. Stock reservation, unpaid timeout, invoices (PDF), order emails, mode switch Waitlist→Shop, waitlist gift redemption.
4. ✅ Done when: a full test purchase with Mollie test mode goes from bag to "paid" in the CRM; stock drops; the invoice PDF is correct (BTW split, sequential number); emails arrive; the slot capacity counts down; the gift is applied only for matching waitlist members.

**Phase 3 — Delivery**
1. Zones, slot template, capacity, cut-off.
2. Route building (nearest neighbour + 2-opt), Google Maps link, drag reorder.
3. Courier role and courier mode, status messages, failed delivery and reschedule.
4. ✅ Done when: 10 test orders in different Groningen postcodes produce a sensible route, and marking stops delivered updates orders and notifies customers.

**Phase 4 — Money + marketing**
1. Expenses, profit, BTW quarter summary, exports.
2. Discount codes, referral programme, abandoned cart, back-in-stock, broadcasts.
3. Suppliers and purchase orders, reorder hints, returns and refunds (Mollie refunds API).
4. Real "live activity" pill in the shop.
5. ✅ Done when: month and quarter numbers reconcile with the order list, and every marketing flow works in test mode.

**Phase 5 — Optional extras (only when the owner asks)**
- WhatsApp Cloud API;
- Tikkie API (needs an ABN AMRO business account);
- Apple/Google Wallet passes for the gift (needs an Apple Developer account and a signing certificate, or a third-party pass service);
- Instagram Shop feed;
- Moneybird/e-Boekhouden export;
- multi-language (NL).

---

## 12. Testing (do it, don't just describe it)

- **Local run:** `php -S localhost:8000 -t public_html public_html/index.php` (router script), with a temp `data/` dir.
- **PHP unit-ish tests:** a tiny test runner in `tools/tests/` (no PHPUnit dependency needed), covering:
  - money math, BTW split, discount, bundle and gift logic;
  - slot capacity;
  - stock reservation and release;
  - route ordering;
  - postcode → zone mapping;
  - the Mollie webhook state machine (with a mocked HTTP client).
- **Browser tests:** Playwright (Chromium, mobile viewport 390×844, `isMobile`, `hasTouch`). Cover:
  - waitlist: swipe-only intro reaches the site, signup, vote counts shared across two browser contexts;
  - shop: add to bag → checkout → mocked Mollie redirect → paid;
  - admin: login, change order status, courier marks delivered.
- **Visual check:** screenshot each public page and admin tab at 390 px and compare by eye with the prototypes. There must be no horizontal scroll at 360 px.
- **Shared-hosting simulation:** run the built zip in a `php:8.2-apache` Docker container with `.htaccess` enabled (if Docker is available) to prove that the deny rules and rewrites work.

---

## 13. Things to ask the owner (collect answers in `OWNER_INPUT.md`; use placeholders until then)

- Brand/store name, logo, domain.
- Company name, KvK number, BTW-id, business address and depot address (where routes start).
- Products: names, photos, prices, cost prices, stock, variants, BTW rate (21% or 9%).
- Delivery: zones and fees, free-delivery threshold, windows, capacity, cut-off time, delivery days.
- Mollie account (test key first), SMTP or email account, WhatsApp business number.
- Gift details for waitlist members, referral amounts.
- Who else uses the admin (courier, helper) and with which role.

Never block the build on these. Use clearly marked placeholders and seed demo data that the owner can wipe with one button: Settings → System → "Remove demo data".

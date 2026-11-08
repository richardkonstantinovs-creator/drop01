# Changelog

## Packing mode and shelves (2026-10-13)

- **Start packing: one order at a time.** **Orders → Packing list** has a big **Start packing · N orders** button. It opens a full-screen carousel. Each order shows its number, window, name, address, notes, *Collect € cash* / *Not paid yet*, and what goes in the bag: shelf, photo, name and how many. Tap each item as it goes in (or press **Enter**). When all are ticked, **Packed · next order** marks it *Packing* and jumps to the next unpacked order. The strip at the top shows every order of the day (green = packed) and lets you jump. **‹ ›**, a swipe on the phone or the arrow keys move between orders, **Esc** closes. At the end: *All N orders packed* with **Print labels**, or a button for the orders you skipped. On a packed order, **Not packed after all** takes it back.
- **Shelves (A1, A2, B3…).** Every variant can have a shelf: in **Stock → product → Variants and stock** (new *Shelf* column) or straight from packing mode (tap the shelf box, type *A1*). Codes are tidied (*a 1* → *A1*). The *To pick* list, the items in each order and the printed list follow the shelves in walking order (A2 before A10, items without a shelf last). The Stock list shows the shelf next to each size.
- **Fix:** the packing list showed raw status codes (*out_for_delivery*) when the Orders list hadn't been opened first. It now says *Out for delivery*, *Delivered*.
- Under the hood: migration `009_shelf` (`variants.shelf`), `Products::shelf()` / `setShelf()` (the product editor keeps the shelf when a save doesn't send one), `POST /api/admin/shelf` (area `stock`), `Packing::byShelf()`; `Packing::day()` now returns `labels`, and `pick` per variant with `shelf`, `variant_id` and `image`; `pmOpen()` / `pmDraw()` in `admin.js`.

**Tests**: 1 new unit test (shelf order, tidying, the editor keeps or clears it), 62 in total. The packing browser test now also runs packing mode: set a shelf, tick by Enter, pack, take it back, Esc. 29 browser tests pass.

## Security pass (2026-10-13)

From a security review of the whole store. Nothing let anyone into the admin or the database; these close the gaps that were found.

- **Nobody can hold a whole drop with fake orders.** Before, one unpaid Tikkie order could reserve up to 800 of every size for 12 hours (the same size sent 40 times). Now a shop order takes at most **5 of one product** (all sizes together; **Settings → Shop → Most of one product per order**, 0 = no limit), a customer (same email or phone) can have **one unpaid Tikkie / payment-link order** at a time, and one network can place at most 10 orders a day. The shop's + buttons stop at the limit with *Max 5 per order*. Orders the team adds in the admin have no limits. Tikkie / payment-link orders now wait **24 hours** by default (was 12; existing stores keep their setting).
- **Sessions.** A new password (changed by the person, or set by the owner in Team) logs that person out on every other phone and computer. A login lasts at most 7 days (and still ends after 12 hours without use). Logging in with an email that has no account now takes as long as a wrong password, so timing doesn't reveal which emails exist.
- **Your password again for risky actions.** Downloading or making a backup, the waitlist CSV with every contact, Team changes and the off-site copy settings ask for the password if it wasn't typed in the last 15 minutes (*Confirm it's you*). Wrong tries count towards the login lockout.
- **Spoofed customer emails get a warning.** The Inbox reads what the store's mail server says about each email (SPF / DKIM / DMARC). When the sender can't be proven, the message shows **Sender not confirmed** with the advice not to change an order, address or payment because of it.
- **A copy outside the server, every night.** **System → Copy outside this server**: an email address (e.g. your Gmail) and a backup password. Each night the database goes there as an AES-256 password-protected zip (photos are not included; most mail servers take up to ~20 MB). **Send a copy now** tests it; the card shows when the last copy went out or why it failed.
- **The store's own address in every link.** **Settings → Store → Website address** (filled in on install, or on the next login for existing stores). Links in emails no longer come from the request, so a forged `Host` header can't change them, and emails sent by the cron job (left bag, back in stock) no longer link to `http://localhost`. That last one was a real bug: those links did not work.
- Under the hood: `Orders::checkLimits()`; `Auth` keeps a password fingerprint (`pv`), `born` and `sudo_at` in the session, `Auth::requireRecent()` / `reauth()` and `POST/GET /api/admin/reauth`, `api()` in `admin.js` asks for the password on a `reauth` error and retries, download links with `data-sudo` check first; `MailIn::senderCheck()` and migration `008_mail_auth` (`conversation_messages.auth`); `Backup::offsite()` (nightly from cron, `POST /api/admin/system/offsite`, `/offsite/send`, secret `backup_pass`, setting `offsite`); setting `site_url`, `Http::baseUrl()` / `requestBase()`.
- Not changed: 2FA stays optional; the gift check at checkout still says whether an email is on the waitlist (rate-limited); 10 wrong passwords still lock an account for 15 minutes.

**Tests**: 5 new unit tests (order limits, sessions and fresh password, spoofed sender, site address, off-site copy) — 61 in total. New browser test: 6 of one product is refused, a password change logs the other phone out, the off-site copy is set up and sent from System. `test-all.sh` now checks that the copy arrives as an AES-encrypted zip and that the cron's left-bag email links to the store. 29 browser tests pass.

## Admin polish pass (2026-10-13)

- **Orders list:** the payment line no longer repeats itself ("Paid · Paid manually" is now just *Paid*, cash shows *Paid · Cash*) and stays on one line; the delivery window (*Tomorrow 12:00–15:00*) no longer breaks in the middle. The order sheet says *✓ Paid manually* instead of *Paid with Paid manually*.
- **Waitlist KPIs:** numbers use Dutch formatting like the rest of the admin (*7,7%*, *1.234*); tiles without an earlier period say *nothing to compare yet*, and the Signups tile no longer claims there is no earlier data.
- **Stock:** the Demo / Waitlist / Sealed tags move to their own line as a group on a phone instead of splitting the product name (*Item / 02*).
- **Packing list:** the address and district sit on their own line under the customer's name.
- **Customers and Team:** no stray divider line above the first row (phone and desktop). The customer sheet's *Export data* button fills the row when it is alone.
- **Today:** the bar value in *Sales, last 7 days* no longer collides with the total in the card header.
- **Team:** removed the outdated note that Money "arrives with the next update".

**Tests**: 56 unit and 28 browser tests pass (`OFFLINE=1`; the live PDOK lookup failed once on the network).

## The courier's own screen, in iPhone style (2026-10-13)

- **Only for people with the courier role.** The owner's Delivery tab and its *Courier mode* chip look exactly as before.
- **Looks like an iPhone app.** It uses the phone's own font and follows the phone's light/dark setting, so it stays readable in sunlight. The top bar, bottom tabs and footer are hidden. A week strip like Calendar's (today in blue, stops per day under the date), a large title (*Today* / *Tomorrow*) with the date, and an account button (initials) with *Refresh my stops* and *Log out*.
- **Progress at a glance.** A ring (green = delivered, red = not home) shows *done of total*, next to how many stops are left, the last stop's ETA, how many were not home and the cash still to collect. *Cash with you* is a green Wallet-style card.
- **Up next (today):** the address in large type, ETA, collect badge, the customer's note, then round **Navigate · Call · On my way · Not home** buttons and one big green **Delivered** button under the thumb.
- **Each window** shows its progress bar, km and driving time, **Start route**, *Whole route in Google Maps* and the stops as an inset list. Tap a stop to slide its buttons open; only one stop per window is open at a time. On days other than today the next stop is already open. Stops show *Collect €…*, *Try 2* and *Note* tags. **Reorder** shows ≡ handles to drag stops; **Done** hides them. Delivered and not-home sheets, confirm dialogs, toasts and errors use the same style.
- Under the hood: `drawCourier()` and the `cv*` helpers in `admin.js` (used when the day payload has `courier_view`). `html.cv` (set at login for `role = courier`) changes the colour tokens and holds every style in one block of `admin.css`. No API changes.

**Tests**: the courier browser test now taps the cash stop open before *Delivered*. 56 unit and 28 browser tests pass.

## Texts: every customer-facing word editable in the admin (2026-10-13)

- **Admin → Texts** (owner only; on a phone under More): all 512 texts customers see, grouped by page and part — shop (top, postcode check, products and bundle, how it works and questions, product page, bag, checkout, order page), waitlist (intro, top, ticket and form, after signing up, countdown, the rest of the page), shared words and simple pages (Today/Tomorrow, page titles, unsubscribe pages), error messages, every customer email (subject, heading, paragraphs, buttons) and the ready-made WhatsApp messages. One group at a time with a menu on the left on a computer and chips on a phone; search across all texts; *changed* / *unsaved* markers per text and group; the original under each changed text with **↺ Original**; Save bar with the count, Discard, Ctrl/⌘+S and a warning before leaving with unsaved changes.
- **Words, bold, links.** `{store}`, `{cutoff}`, `{number}`… are filled in by the app; a text may only use the words of its original (plus a few documented extras), checked while typing and refused by the server with the list of allowed words. `**bold**`, `[label](/link)` (only `/`, `#`, `https://`, `mailto:`, `tel:` or a `{word}`) and line breaks become HTML; everything else is escaped. Empty = the original. Glued texts keep their outer space (" in Groningen"). Character limits where the design needs them (intro and headline words: 14).
- **Questions are lists** (shop and waitlist): add, remove, reorder; no questions hides the block.
- **The texts file**: **Download file** gives one JSON with every text in use, grouped by page with a how-to line; **Upload file** applies it (texts missing from the file stay, bad lines are listed in a sheet, the rest is saved). **Reset all** brings every original back. This is the quick way to dress the template up for a new client.
- Under the hood: originals in `app/texts.php`, the owner's changes in the setting `texts` (only what differs); `Texts` (`t()` plain / `th()` HTML helpers in `bootstrap.php`), the shop and waitlist get their texts in `window.SHOP.t` / `window.DROP_CONFIG.t` with matching `t()`/`th()` in the JS. `TextsApi` (`/api/admin/texts`, `/export`, `/import`, `/reset`), area `texts` (owner). Emails now build their text part from the same rows as the HTML. `Delivery::dayName()` uses the editable Today/Tomorrow, so the shop's same-day promise now compares dates instead of the label. The checkout's "Not in our area" link and the waitlist manifesto's foil/fading words are markup in the text (`[…](#list)`, `**gift**`, `~~gone.~~`).
- Not covered (on purpose): product names and descriptions (Stock), the logo and store name, gift, marquee, top bar, Tikkie text and legal pages (already in Settings), the invoice PDF, weekday names in delivery windows, and the admin itself.

**Tests**: 5 new unit tests (the catalogue has every key the code asks for, formatting and escaping, save checks and originals, emails/errors/WhatsApp/day names use the texts, the file round trip) — 56 in total. New browser test: the owner changes shop texts, a bad `{word}` is flagged and refused, a new question with a `{word}` appears on the shop, the downloaded file is edited and uploaded (one bad line reported, the rest live on the waitlist), Reset all brings the originals back. The desktop tests now also open Texts — 28 browser tests in total.

## Shop funnel, errors that stay, in-app confirms, desktop tests (2026-10-13)

- **Marketing → Shop funnel** (the tab Marketing opens on): visits that *viewed a product → added to the bag → opened checkout → placed the order*, then paid web orders, for 7/30/90 days or all time, with the loss per step and a **Biggest drop** card with a tip for that step. Built from the analytics events the shop already sends (`product_view`, `add_to_cart`, `checkout_started`, `payment_started`) plus `orders`; no new data. `Dashboard::shop()`, `GET /api/admin/marketing/funnel?days=`.
- **Errors stay on screen.** Every failed action (`busy()`, failed loads) shows a red message at the top (top right on a computer) until it is closed with ✕. The same error twice shows once with ×2; at most three at a time. Good news still uses the short toast.
- **No more browser `confirm()`/`prompt()`.** Final actions ask in the app's own dialog (`ask()`): above any open sheet, bottom card on the phone, centred on a computer, red button for things that can't be undone, Escape/tap outside cancels. Covers deleting customers, chats, photos, products, codes and packaging, marking paid, cancelling (with the reason typed in the same dialog), starting a route, sending a broadcast, switching the homepage, changing a price, disconnecting Telegram, turning off two-step login, removing demo data and leaving Settings with unsaved changes.
- **Undo instead of asking** for steps that tell the customer nothing: ticking *packed* on the packing list (the *not paid yet* question is gone; the toast says so instead) and *Start packing* on an order. A step that emails the customer (out for delivery, delivered, not home) can't be taken back, so its toast offers **WhatsApp** instead of the old "Send a WhatsApp update?" question.

**Tests**: 1 new unit test (shop funnel steps, only paid web orders in the period) — 51 in total. Browser tests: the funnel shows every step after the shop tests; an error stays after 3 s and closes; *Stay* keeps unsaved Settings; Undo on the packing list; every former `confirm()` now goes through the in-app dialog, and a native dialog counts as a failure. **New desktop tests at 1100 and 1440 px**: all 13 screens plus Money and Marketing sub-tabs, with the sidebar open and folded, checked for horizontal scroll and for content sticking out on the right or sliding under the sidebar; the order sheet is a right-hand panel and the confirm is centred above it — 27 browser tests in total.

## Money: margins per product (2026-10-13)

**Money tab** (owner and accountant; left menu on the computer, **More → Money** on the phone). Plain arithmetic, no AI.
- **Costs & packaging:** delivery cost per order, payment fee (fixed + %), fixed costs per month, target margin, reorder settings. Packaging materials entered as *pack price ÷ units in the pack*, used either once in every order or per product (how many per unit, set on the product).
- **Unit economics per product at today's price:** customer pays → BTW → average discount → what you keep → purchase cost, packaging, share of delivery (after the delivery fee customers pay), order packaging, payment fee → **profit**, margin and markup. **Lowest price** (break-even) and the price for the target margin (rounded up to ,95) with **Use this price**; **What if…** for another price or purchase cost.
- **Real results from paid orders** (7/30/90 days, year, all time): units, orders, profit, margin and profit per unit per product, each order's discount and costs shared over its lines; purchase cost from the order line. Period profit, profit per order, profit after fixed costs and **break-even** orders per month.
- **Verdict per product:** Best / Raise price / Slow / Rethink / Loss (margin vs target, speed vs the middle product). **Reorder soon:** products with stock for ≤ N days and how many to buy for M days. **Price calculator:** all products with price, costs, profit, lowest and target price.
- Assumptions come from the last 90 days (items per order, average discount, delivery fee per order) once there are 10 paid orders; before that 1 item, no discount, free delivery.

**Data**: migration `007_margins` (`materials`, `product_materials`), setting `costs`, `Margins` + `MoneyApi`. Deleting a product removes its packaging rows.

**Tests**: 3 new unit tests (cost breakdown, break-even and target price, real results, verdict, reorder, snapshot cost, materials) — 50 in total. New browser test: costs, a material, packaging on a product, the breakdown and what-if, the calculator at 390 px — 25 in total.

## A nicer sidebar on the computer (2026-10-13)

- Store logo with *Back office*, tabs grouped under **Daily** (Today, Orders, Inbox, Delivery, Stock, Customers), **Grow** (Waitlist, Marketing) and **Setup** (Settings, Team, System); a group title hides when your role has none of its tabs.
- The open tab has a holo bar and a lit icon; hover and keyboard focus are visible. Badges: amber *Needs your attention* count on Today, red unread chats on Inbox.
- At the bottom: who is logged in (initials, name, role; email on hover) with the log-out button.
- **Fold to icons** with the arrow at the top (or Ctrl/⌘ + \\): a 76 px rail with tooltips and the badges on the icons; remembered on this computer. Phones are unchanged.

## Inbox: email and Telegram chats in one place (2026-10-13)

**Inbox tab** (owner, manager; bottom bar on the phone — Stock moved under More there — and the left menu on the computer)
- Every conversation with a customer, newest first, with a channel icon on the avatar (purple envelope = email, blue plane = Telegram), unread count, search (name, address, subject, message text) and filters All / Unread / Email / Telegram. A red badge on the tab and an item in *Needs your attention* count chats waiting for a reply.
- The chat: bubbles per day, photos the customer sent, the team member who answered, *✓ sent / sending… / ✕ not sent* with **Send again**. Email chats also show the automatic emails sent to that address (order confirmed, on its way…). Buttons to the customer and their last order; mark unread; delete (spam). On the phone the chat opens full screen; on the computer next to the list. Ctrl/⌘+Enter sends. The list refreshes every 15 s.
- **Email / Telegram** buttons on the customer sheet and **Message** on the order sheet open (or start) the chat.

**Email in** (Settings → Email → *Incoming mail*)
- Reads new mail in the store mailbox over IMAP (own socket client, no php-imap needed) every cron run and right after the Inbox opens (at most once a minute), with BODY.PEEK so the mailbox looks untouched. Starts from now, no history import.
- Keeps the customer's own words: MIME parts, charsets, encoded headers, RFC 2231 file names; quoted history ("On … wrote:", "Op … schreef:", Russian Gmail, Outlook "From:" blocks) is cut off. Image attachments are re-encoded and kept outside the web root; other files are listed by name.
- Skips own mail, bounces, auto-replies and newsletters; optional *only customers, waitlist members and people you wrote to*. Linked to the customer by email address.
- Replies go out by SMTP with `Re: <subject>` and In-Reply-To/References, so they thread in the customer's mail app; logged in `messages` like every email. **Check incoming mail now** tests the login. A broken login shows in Settings and *Needs your attention*.

**Telegram** (installer step 6, Settings → Telegram)
- Paste the @BotFather token: the store checks it (getMe), then sets a webhook with a secret header on HTTPS sites, or polls from cron when there is no HTTPS. Welcome message editable. Connect again / Disconnect; webhook health from Telegram shown in Settings.
- The order page gets **Chat on Telegram** with a signed start link, so a chat started there is tied to the customer and says *Opened the chat from order #…*. Photos are downloaded; voice, video, stickers, locations and contacts show as notes. Group chats are ignored. A blocked bot shows as *The customer blocked the bot*.
- GDPR: customer export includes their chats; deleting a customer deletes their chats and photos.

**Data**: migration `006_inbox` (`conversations`, `conversation_messages`). `Mailer::send()` takes extra headers; the email-failure warning on Today counts emails only.

**Tests**
- 7 new unit tests (MIME/quotes/attachments, filters, mailbox position and errors, reply threading, Telegram connect/start link/photos/blocked bot/retry, poll mode, GDPR) — 47 in total. The IMAP client was also checked against a real IMAP server.
- New browser test: connect the bot in Settings, the order page's Telegram link, a Telegram chat and an email (sent to a GreenMail mailbox) both appear with their icons, replies go out on their channel. `test-all.sh` now runs GreenMail and a Bot API stand-in (`tools/e2e/tg-mock.php`), checks the webhook secret and that the email reply arrives. 24 browser tests pass.

## Delivery made for the phone, Settings made for the computer (2026-10-13)

**Delivery (phone first)**
- The day bar sticks to the top and sums up the day: delivered / to go / not home / cash to collect.
- **Up next** (today): the stop to drive to now, big address, ETA, cash to collect, note, and thumb-sized **Navigate · Delivered · Call · Not home** (+ **Start route** before leaving, **On my way** once out). It moves on after every stop.
- One card per window (route header, progress in the window's map colour, **Whole route in Maps**, courier, then the stops) instead of two cards.
- Every open stop has four big icon buttons (56 px); the next stop's **Delivered** is holo. ETA sits right of the address, *Collect €…* is a pill, delivered stops are compact.
- The map can be hidden (remembered per device), 280 px on phones. The screen refreshes when you come back from Google Maps or a call (after 20 s away). *Set your depot* links to Settings → Store.

**Settings (computer)**
- Left menu with every section and a hint; one section shown at a time; deep links (`/admin/settings#delivery`) still work. A yellow dot marks Store details missing, email not set up, legal texts not reviewed.
- Unsaved changes: **unsaved** badge in the menu, *Unsaved changes* + holo **Save** in a bar stuck to the bottom of the window, Ctrl/⌘+S saves the section, a warning before leaving Settings or closing the tab. Saving one section keeps unsaved edits in the others.
- Column labels above delivery windows and zones (also on the phone). Taller legal text boxes.
- Phone: the section menu is a row of chips that jump down the page; otherwise unchanged.

**Tests**
- All 23 Playwright tests and 40 unit tests pass; the delivery test now matches "Collect" case-insensitively.

## Daily operations: packing list, cash handover, customer reschedule, order again (2026-10-13)

**Packing list** (Orders → Packing list, also on Today; owner, manager, warehouse)
- Per delivery day: *To pick* totals for everything not packed yet (incl. waitlist gifts), then each window's orders in route order with items, notes, gift, *Collect €… cash* / *Not paid yet*.
- Tick a bag as packed (the order goes to *Packing*; untick puts it back). Unpaid non-cash orders ask before packing.
- **Print list** (checklist per window) and **Print labels** (one label per bag: number, window, name, address, phone, items, payment), printed black on white.

**Cash from couriers** (Delivery)
- Payments a courier (or manager) collects at the door are remembered per person. *Cash with couriers* shows each person's total and orders; **Received** records what the owner actually got, with any difference and a note; **History** lists the handovers. The courier sees *Cash with you*.
- Cash not handed over by the next day appears under Needs your attention. Cash the owner collects themselves is not listed.

**Customers change their delivery window**
- Order page: **Change time** shows the free windows; the move books the new window, frees the old one, emails the new time and shows on the timeline. Until the current window's cut-off, at most 3 times; not once the order is out for delivery.
- After *Not home*, or when the window filled up while paying, the page asks for a new time (the order goes back to packing). The confirmation email links to it, and the *We missed you* email's button is now **Pick a new time**.
- Settings → Delivery → *Customers can change their window* (on by default).

**Order again**
- The order page (paid orders) and the *delivered* email (**Order again** button, `/?reorder=…`) put the same items back in the bag, limited to what is in stock; sold-out items are named.

**Other**
- Fix: the confetti vibration logged a Chrome error when the order page opened without a tap.
- Migration `005_ops`.

**Tests**
- 40 PHP unit tests (+3: packing list and pick totals, customer reschedule incl. limits / not home / switched off, cash collected and handed over with a difference).
- 23 Playwright tests (+3): owner confirms courier cash with a difference; packing list day, tick packed, print labels in print media; customer changes the window, the CRM sees it, Order again from the page and from the email link on another phone.

## Phase 4a — Marketing (2026-10-13)

**Discount codes**
- Marketing → Codes: % off, € off or free delivery, with minimum bag, maximum uses, start/end dates, on/off switch and a note. Usage, paid orders and sales per code.
- Checkout: *Have a discount or friend’s code?* field; the summary and the pay button show the new total. The server checks the code again when the order is placed (expired, used up, minimum, personal codes).
- Shop links `/?code=…` and `/?ref=…` save the code and apply it at checkout. **Copy shop link** in each code.
- The discount comes after the bundle; the free-delivery threshold looks at the bag before the code, so a code never adds a delivery fee. BTW split, invoice and order page include it. A code counts as used on payment and the use comes back if a paid order is cancelled.

**Referrals**
- A friend’s code (`NAME-D01`) gives €5 off a first order (amounts in Settings → Shop). Your own code and second orders are refused with a clear message.
- When the friend’s order is delivered, the referrer gets a personal one-time code (`THANKS-XXXX`) by email; shown on the order timeline and the customer sheet.
- Marketing → Referrals: friends’ orders, sales, rewards sent and used, top referrers. The WhatsApp share text now contains the link with the code.

**Back in stock**
- When a sold-out product gets stock again (stock adjustment, product edit, a cancelled paid order, or cron), everyone who tapped *Notify me* is told once: by email, or as a WhatsApp message to send from Marketing → Back in stock.

**Left bags (abandoned cart)**
- Only with consent: the checkout box now reads *Tell me about the next drop first, and remind me if I leave my bag*. Without the tick, no contact details are kept on the bag.
- Cron sends one reminder 1–48 h after the bag was left (never twice to the same address within 7 days, not if they ordered meanwhile), with a link that puts the bag back on any device. Marketing → Left bags: on/off, reminders, bags won back and their sales.

**Email broadcasts**
- Marketing → Email: groups (everyone who said yes, customers, regulars, new, inactive, waitlist, next-drop list), subject, heading, message with `{first_name}`, optional button, **Send me a test**, then send with confirmation. One email per address, consent only.
- Hourly sending limit (default 200), customer emails always first. History with sent / waiting / failed.
- One unsubscribe link for all marketing emails: removes the address from every list and cancels queued broadcast emails. Order emails still arrive.

**Other**
- The Marketing screen is in the admin (More → Marketing on a phone, the sidebar on a computer; owners and managers).
- GDPR delete also removes the person’s bags and switches off their personal codes.
- Migration `004_marketing`.

**Tests**
- 37 PHP unit tests (+5: codes at checkout incl. BTW and use counts, referral discount and reward, back in stock, left-bag reminder, broadcast groups, hourly limit and unsubscribe).
- 20 Playwright tests (+3): the owner makes a code → its shop link applies it at checkout → the order carries it; a bad code is explained and a consented bag is kept; stock comes back, broadcast test + send, every Marketing tab at 390 px. `test-all.sh` also checks the broadcast, its test, the left-bag reminder and the back-in-stock email arrive.

## Nicer "It's yours." order page (2026-10-13)

- Delivery card at the top: the time window in large type, the delivery address, and "Add to calendar".
- "What you bought": product tiles, quantities, line prices, the delivery fee or discount, and the total paid.
- Status timeline: proper check icons, the line fills in as the order moves along, and a "Now" tag on the current step.
- Greets the customer by first name. The referral code is a button that copies it with one tap, and sharing is the main action.
- The cards fade in one after another (skipped with reduced motion). Fix: with reduced motion the check in the circle was invisible.
- The order page now also returns product id, line price and the delivery address. It only shows the customer their own order (token link).

## Real map in Delivery (2026-10-13)

- Delivery → **Map**: every stop of the chosen day on a real map of Groningen (Kadaster BRT grey map via PDOK, free, no key), darkened to match the admin. One colour per delivery window, numbered in driving order, the path from the depot, delivered stops green, not-home red, the next stop filled.
- Tap a point: order number, name, address, window, ETA, **Open order** and **Navigate**. Couriers see the map too (Navigate only).
- Replaces the schematic map in each route card. Stops whose address has no map position are listed under the map.
- Leaflet 1.9.4 is bundled (`assets/vendor/leaflet`, BSD licence) and loaded only when the map is shown. The page security policy now allows map images from `service.pdok.nl` (images only).

## Admin on a computer (2026-10-13)

- From 1024 px wide the admin gets a desktop layout; phones look exactly as before.
- Sidebar instead of the bottom tabs, with Waitlist, Settings, Team, System and Log out shown directly (no "More" menu).
- Today: four KPI tiles in a row, cards in two columns (three from 1440 px). Orders as a card grid; Customers, Stock and waitlist signups in two columns; Delivery routes side by side (courier mode stays one column); Waitlist hero + KPIs side by side; Settings and System forms in two columns.
- Order, customer and product details open as a panel on the right instead of a bottom sheet. Hover states for mouse users.

## Phase 3 — Delivery and courier mode (2026-10-12)

**Routes (Delivery tab, `prototypes/crm.html` design)**
- Day chips (today + days ahead), one route per window: stops in driving order, ETA per stop, progress bar, ≈ km and time, schematic map from real coordinates, zones this week with fees.
- Route order: nearest neighbour from the depot (Settings → Store → Depot address, geocoded with PDOK) + 2-opt, in PHP. Stops without coordinates are looked up again and otherwise go last. Finished stops come first, in the order they happened; ETAs then run from now.
- **Fixed:** the 2-opt step used a stale copy of the route (arrow function captured it by value) and could make routes longer. Now checked against the exact optimum: 10 real Groningen/Haren addresses → 20,2 km vs 19,7 km optimum (33,5 km in arrival order).
- **Open in Maps:** Google Maps directions links, chunked to 9 waypoints + destination, each part starting where the previous ended; car/bike/walking setting.
- Drag to reorder (touch handle, or arrow keys); new orders slot in where they cost the least; **Auto order** resets.
- Courier per window (with one courier, every route is theirs).

**Courier mode**
- Role *courier* sees only Delivery → own routes, as a big list: Delivered, Navigate, Call, Not home, On my way. The owner can switch it on with the *Courier mode* chip.
- **Start route:** stops go *Out for delivery*, customers get an email with a ±15 min arrival window.
- **Delivered:** optional photo proof (re-encoded, EXIF stripped, stored outside the web root, served only to the admin); *collect payment* for unpaid/cash orders marks the order paid and sends the invoice.
- **Not home:** reason, failed-attempt counter, "we missed you" email; listed under Needs your attention; **Reschedule** (Delivery tab or order sheet) moves the window booking, puts the order back to packing and emails the new time.
- Every event emails the customer (switchable per event in Settings → Delivery) and prepares a WhatsApp click-to-chat message; all on the order timeline. Status changes from the order sheet send the same messages.
- Settings → Delivery: average speed, minutes per stop, Maps travel mode, automatic emails.

**Fixes**
- Migration 003 re-added `orders.route_pos` (already in 001), which broke fresh installs and the unit tests.

**Tests**
- 32 PHP unit tests (+6: route on 10 real addresses vs the Held-Karp optimum, ETAs and Maps links, manual reorder, courier flow with messages, courier isolation, ETA windows).
- 17 Playwright tests (+2): 10 Groningen orders → route and Maps link, drag reorder, courier assignment; courier logs in, sees only Delivery, starts the route, delivers with photo and cash, marks not home; owner reschedules. `test-all.sh` also checks the delivery emails arrive.

## Phase 2 — Shop, checkout, core CRM (2026-10-11)

**Shop (`prototypes/shop.html`, design unchanged)**
- Served at `/` in Shop mode (Settings → Waitlist and launch, with a confirmation); the waitlist stays at `/waitlist` or redirects (setting).
- Catalogue, categories, search, product page (`/p/{slug}`) with sizes/colours per variant, photos with `srcset`, real stock, "x% claimed" from run size; hero fan shows `featured` products.
- Delivery promise and "Only N spots left" from the real cut-off and slot capacity; postcode check against the zones in the database (remembered on the device only).
- Bag in `localStorage`, mirrored to a server-side cart; admin-defined bundle (product set + % off); free-delivery progress; upsells.
- Checkout: details, PDOK address lookup via `/api/address` (exact postcode + house number, cached 30 days, manual street fallback), delivery windows, total incl. BTW and delivery before ordering, honeypot + rate limit.
- **Payments: Tikkie, cash and bank transfer.** The order is reserved and the owner sends the Tikkie from the CRM. Mollie (iDEAL/card/Apple Pay, webhook that re-fetches the payment, return-page polling) is built and unit-tested but switched off (`mollie_enabled` in `data/config.php`).
- Order page `/order/{number}?t=token`: "Almost yours." / "Confirming payment…" / "It's yours." with live status steps, referral code, calendar link, WhatsApp link.
- Waitlist gift: added only when the email or phone matches a signup with an unredeemed code; redeemed once, on payment.
- "Notify me" on sold-out items stores a restock request. Live activity pill is wired to real paid orders only (hidden when there are none).

**Orders and stock**
- Stock held for 15 min at checkout, turned into a sale on payment, released on cancel/timeout (cron and lazily). Unpaid orders cancel after 60 min, Tikkie orders after 12 h (Settings → Shop).
- Payment books the delivery window (re-checks capacity, flags "needs a new window"), redeems the gift, creates the invoice and sends the confirmation.
- Invoice PDF (own tiny PDF writer): sequential per year (`2026-0001`), BTW split per rate, company details from Settings, 14-day withdrawal notice; attached to the confirmation email.

**CRM**
- Today: sales/orders/to-deliver/profit vs same weekday last week, "Needs your attention" (unpaid > 30 min, new window needed, low/sold-out stock, restock requests), today's windows, month to date, best sellers.
- Orders: status chips, search, infinite scroll; order sheet with stepper and next action, map link, payment, items with BTW, timeline (events + messages), Tikkie "paste link → send via WhatsApp", mark as paid, change window, cancel, note, invoice, customer's page. **+ New order** for WhatsApp/Instagram/phone orders.
- Customers: segments (Regulars, New, Inactive 30d+, Waitlist only), customer sheet, notes, GDPR export (JSON) and delete (orders kept anonymised).
- Stock: variants with own stock, adjustments with reason and history, bundle editor.

**Tests**
- 26 PHP unit tests (pricing/BTW/bundle, zones, slots and cut-off, stock hold/release/sale, Mollie webhook state machine with mocked HTTP, gift matching, invoice numbers, PDOK, Tikkie flow).
- 15 Playwright mobile tests, new: mode switch, postcode check, notify me, gift only for waitlist members, **bag → checkout → reserved → paid in the CRM → packing** with stock, slot, invoice PDF and gift checks, admin Orders/Customers/Stock at 390 px. `test-all.sh` also checks the order confirmation email arrives with the invoice attached.

## Phase 1 — Foundation + Waitlist live (2026-10-11)

**Platform**
- PHP 8.2 + SQLite (MySQL by config), single front controller, `/api` and `/admin` routing, no build step on the server.
- Installer with plain-language requirement checks, owner account, demo data, self-lock; tries to keep `data/` outside `public_html`.
- Migrations (`app/migrations`) with automatic pre-migration database copy; run on install, on every admin login, and from System.
- `.htaccess`: HTTPS redirect, pretty URLs, deny for `app/ data/ vendor/ tools/`, dotfiles, `*.sqlite *.log *.md`, no PHP in `uploads/`; security headers + CSP with nonces; gzip; 1-year caching for versioned assets.
- `cron.php` (CLI or secret token): email retry queue, salt rotation, clean-up, nightly backup (keeps 14), 13-month analytics roll-up.
- Self-hosted fonts (Bricolage Grotesque, Manrope, JetBrains Mono; latin + latin-ext). No external requests.

**Waitlist (`prototypes/waitlist.html`, design unchanged)**
- Settings, products, counters and vote counts rendered from the database into `window.DROP_CONFIG`.
- Signup via `POST /api/waitlist/signup`: validation, de-duplication by email and phone (codes are only returned when both match), server-issued unique gift codes (`D01-XXXX`) with a limit, position number, honeypot, time-to-submit check, rate limit.
- When gifts run out, signups still succeed and the card says early access is saved.
- Shared votes via `/api/votes` (anonymous visitor id, rate limited), optimistic UI kept, 20 s refresh.
- Cookieless analytics via `sendBeacon` (`/api/e`): daily-rotating visitor hash, sources, devices, countries, intro and form funnel.
- Privacy link at the point of signup; Privacy, Terms, Returns and Contact drafts with `[[PLACEHOLDER]]` markers.
- Gift-code confirmation email (dark holographic HTML + plain text, unsubscribe link) via vendored PHPMailer.

**Admin (`prototypes/crm.html` + `prototypes/waitlist-dashboard.html`)**
- Login with lockout (10 fails → 15 min, per account and per IP hash), optional TOTP two-step login, CSRF on every write, audit log, roles.
- Today: real waitlist KPIs vs same weekday last week, "Needs your attention" (email, cron, legal drafts, company details, demo data, gifts running out).
- Waitlist: the full dashboard on SQL aggregates, Today/7/30/All range, signup list with search and reveal, CSV export (UTF-8 BOM).
- Stock: products with variants and stock log, photo upload (finfo check, GD re-encode, 1600/600 px WebP, EXIF stripped, random names), waitlist order.
- Settings: store and company, waitlist and launch (drop date, gift, marquee, countries, store mode with confirmation), email with test send, legal page editor, password, two-step login.
- Team (roles), System (version, updates, backups download, cron status + command, server check, demo data removal). Installable as a phone app.
- Orders, Delivery, Customers show honest empty states until Phases 2–3.

**Tests**
- 14 PHP unit tests, 11 Playwright mobile tests (incl. swipe-only intro, shared votes across two phones, admin, photo upload), full run from the built zip in `php:8.2-apache` with a test mail inbox: `tools/test-all.sh`.

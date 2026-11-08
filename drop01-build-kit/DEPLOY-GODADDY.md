# Put Drop 01 online on GoDaddy

**English** · [Русский ниже](#русский)

This takes about 20 minutes. You don't need any technical knowledge. Follow the steps in order.

---

## English

### 1. Before you start

You need:

- A GoDaddy **Web Hosting (cPanel)** plan. *Website Builder* plans will not work.
- Your domain connected to that hosting.
- The file **`drop01-deploy.zip`** (in the `dist` folder).
- Optional for now: a Mollie account (mollie.com) and an email box on your domain. You can add both later.

To open cPanel: log in at godaddy.com → **My Products** → next to *Web Hosting* click **Manage** → **cPanel Admin**.

### 2. Turn on HTTPS (the green lock)

1. In cPanel, search for **SSL/TLS Status** and open it.
2. Tick your domain and click **Run AutoSSL**.
3. Wait until your domain shows a green lock. This can take a few minutes, sometimes up to an hour.

![cPanel SSL/TLS Status with the Run AutoSSL button](screenshot-ssl.png)

### 3. Choose PHP 8.2

1. In cPanel, open **Select PHP Version**. (Some accounts call it **MultiPHP Manager**.)
2. Set **PHP 8.2** (or newer) and click **Set as current**.
3. Click the **Extensions** tab. Make sure these are ticked:
   `pdo_sqlite`, `sqlite3`, `curl`, `gd`, `mbstring`, `openssl`, `json`, `fileinfo`, `zip`.
   Each tick saves by itself.

![Select PHP Version, Extensions tab](screenshot-php.png)

### 4. Upload the store

1. In cPanel, open **File Manager** and double-click **public_html**.
2. If you see old files there (for example a GoDaddy `index.html` placeholder), select them and click **Delete**.
3. Click **Upload**, choose `drop01-deploy.zip`, and wait until the bar is green.
4. Click **Go Back to "/home/…/public_html"**.
5. Right-click `drop01-deploy.zip` → **Extract** → **Extract Files**.
6. Delete `drop01-deploy.zip`.

> Tip: File Manager hides files that start with a dot. To check that `.htaccess` is there: **Settings** (top right) → tick **Show Hidden Files**.

![File Manager with the extracted files](screenshot-filemanager.png)

### 5. Run the installer

1. Open **https://yourdomain.com/install.php** in your browser.
2. The checklist shows green ✓ or red ✕ for each requirement. If something is red, the text under it tells you exactly what to click. Fix it and reload the page.
3. Fill in:
   - your **store name**,
   - your **name, email and a password** (at least 10 characters). This is your admin login.
   - Database: keep **SQLite (recommended)**.
   - Email: optional now. If you fill it in, also type the **Incoming mail server (IMAP)** so customer emails show up in the admin **Inbox** (see step 7b).
   - **Telegram bot** (optional now): paste the token from @BotFather (step 7b explains how to get one). The last screen says *✓ Telegram bot @… connected*. If it says *not connected*, the store still installs; connect it later in Settings → Telegram.
   - Leave **demo products** ticked if you want to try everything first.
4. Click **Install**.
5. The last screen shows a **cron command**. Copy it, you need it in step 6.
6. Click **Open the admin** and log in.

The installer locks itself after this. It cannot be run a second time.

### 6. Add the cron job (background jobs)

This sends emails that failed the first time, makes a backup every night and cleans up.

1. In cPanel, open **Cron Jobs**.
2. Under *Add New Cron Job*, set **Common Settings** to **Once Per Five Minutes (*/5 * * * *)**.
3. Paste the command from the installer into **Command**. It looks like:
   `/usr/local/bin/php /home/YOURUSER/public_html/cron.php >/dev/null 2>&1`
4. Click **Add New Cron Job**.

After 5 minutes, **Admin → More → System** shows *Scheduled jobs: ✓ Running*. The same page shows the command again if you lose it.

![cPanel Cron Jobs](screenshot-cron.png)

### 7. Set up email (so people get their gift code)

1. Admin → **More → Settings → Email**.
2. Enter the outgoing mail server of your mailbox. You find these in GoDaddy under **Email → your mailbox → Connect devices / Server settings**. Common values:
   - GoDaddy cPanel email: server `mail.yourdomain.com`, port **465**, security **SSL**, login = your full email address.
   - GoDaddy Professional Email: server `smtpout.secureserver.net`, port **465**, **SSL**.
   - Microsoft 365 from GoDaddy: server `smtp.office365.com`, port **587**, **TLS**.
3. Click **Save email**, then **Send test**. You should get a test email within a minute.

### 7b. Chats with customers: email and Telegram in one Inbox

All conversations with customers are in the admin under **Inbox** (bottom bar on the phone, left menu on the computer). Each chat has a small icon on the photo circle: **purple envelope = email**, **blue paper plane = Telegram**. The red number on **Inbox** counts chats waiting for a reply. You answer in the chat, and the answer goes out the same way the customer wrote: email as email, Telegram in Telegram. Automatic emails (order confirmed, on its way…) to the same customer also show in their email chat, small and grey.

**Email in the Inbox**
1. Admin → **Settings → Email** → part *Incoming mail*.
2. Tick **Show customer emails in the Inbox** and fill in the incoming mail server of the same mailbox:
   - GoDaddy cPanel email: `mail.yourdomain.com`, port **993**, **SSL**;
   - GoDaddy Professional Email: `imap.secureserver.net`, port **993**, **SSL**.
   Login and password stay empty: the store uses the ones from the outgoing mail above.
3. **Save email**, then **Check incoming mail now**. It says *Connected*.
4. From now on, new emails in that mailbox appear in the Inbox within 5 minutes (straight away when the Inbox is open). Old emails are not imported. The store only reads: your mail app still shows everything, nothing is marked read or deleted. Newsletters, bounces and auto-replies are skipped. *Which emails → Only customers…* also skips strangers (e.g. sales offers).

**Telegram in the Inbox**
1. On your phone, open Telegram and search **@BotFather** (blue tick). Tap **Start**.
2. Send `/newbot`. Type a name customers will see (e.g. your store name), then a username that ends in `bot` (e.g. `drop01groningen_bot`).
3. BotFather answers with a **token** like `123456789:AAH…`. Copy the whole line.
4. Paste it in the installer (step 5), or later in Admin → **Settings → Telegram** → **Connect bot**. You see *Connected to @yourbot*.
5. Customers find the **Chat on Telegram** button on their order page. A chat started from there is linked to their orders, so you see *1 order · #1062* at the top. Anyone can also write to `t.me/yourbot`.
6. The bot sends the **welcome message** from Settings → Telegram when someone starts a chat. Change or empty it there.

Good to know: a bot can only answer people who wrote to it first. If your site has no HTTPS yet (step 2), messages arrive every few minutes instead of instantly; after HTTPS works, tap **Connect again** in Settings → Telegram. Photos customers send show in the chat; voice messages and videos show a note to open them in Telegram. On the phone, **Stock** moved into **More** to make room for **Inbox**.

### 8. Make it yours

On a computer the menu on the left groups the tabs (Daily, Grow, Setup). The arrow at its top folds it to icons for more room (Ctrl/⌘ + \\ does the same); the computer remembers it.

On a computer, **Settings** has a menu on the left: one section at a time. A yellow dot means something is missing there; **unsaved** means you changed something and haven't saved yet. The **Save** button stays at the bottom of the window, and **Ctrl+S** (⌘+S on a Mac) saves too. On a phone, the sections are buttons at the top that jump down the page.

1. **Settings → Store:** company name, KvK, BTW-id, address. These fill in the legal pages. Also fill in the **Depot address** (where you load the goods): every delivery route starts there.
2. **Stock:** edit the demo products into your real ones (name, price, cost, edition size, photos), or add new ones with **+ Add**. Photos straight from your phone are fine.
3. **Settings → Waitlist and launch:** drop date and time, gift title and limit, marquee texts.
4. **Settings → Legal pages:** these are drafts. Have them checked, then tick *The texts have been checked*.
5. **More → System → Remove demo data** when your real products are in.
6. Open your homepage on your phone, swipe the pack open, and sign up yourself to see the whole flow. Then share the link.

### 8b. Change any text on the site (Texts)

Every word customers see can be changed without a developer: the shop, the waitlist, the bag and checkout, the order page, the emails, the ready-made WhatsApp messages and the error messages.

1. Admin → **Texts** (on a phone: **More → Texts**).
2. Pick a part of the site in the menu (for example *Shop · Top of the page*), or type a word in **Search all texts**.
3. Change the text in its box. The grey line under a changed box shows the original.
4. Press **Save** at the bottom (or Ctrl/⌘+S). The site shows it at once; there is no need to upload anything.

Good to know:
- Words in braces like `{store}`, `{cutoff}`, `{number}` are filled in by the shop (store name, order time, order number…). Keep them. If you type one the shop does not know, the box turns red and **Save** explains which words you can use.
- `**word**` makes a word **bold**. `[Returns policy](/pages/returns)` makes a link.
- An empty box, or **↺ Original**, brings back the original text. **Reset all** puts every text back.
- Questions (Shop and Waitlist): **+ Add a question**, ↑ ↓ to change the order, × to remove. Remove all of them and the questions block disappears.
- Some texts have a character limit (shown as `7/14`), for example the big flying words in the intro, so the animation still fits on a phone.
- The store name and logo stay in **Settings → Store**; the gift, the marquee and the top bar of the shop in **Settings → Waitlist** and **Settings → Shop**; the legal pages in **Settings → Legal pages**.

**A version for a new client in a few minutes:** press **Download file**. You get one file with every text, grouped by page. Open it in any text editor (TextEdit, Notepad, VS Code), change the text after each colon and keep the quotes and commas. Then press **Upload file**. Texts you deleted from the file stay as they are; lines with a mistake are listed and the rest is saved.

### 9. Try a full order, then open the shop

Do this once before launch day, on your phone. It takes 5 minutes.

1. **Settings → Delivery:** check the delivery windows, how many orders fit in each window, the cut-off time and your delivery days. **Settings → Shop:** the free-delivery amount and how long unpaid orders wait.
2. **Settings → Waitlist and launch → Shop mode → Save**, and confirm. Your homepage is now the shop. (You can switch back the same way.)
3. On your phone, open your homepage, add something to the bag and check out with your own details and a real Groningen address. Choose **Tikkie** and tap **Place order**. You see *Almost yours.*
4. In the admin, open **Orders**. Your order says *Awaiting Tikkie*. Tap it:
   - make a Tikkie for the amount shown in the Tikkie app, paste the link and tap **Send via WhatsApp**;
   - when the money is in, tap **Mark as paid**. The customer gets the confirmation email with the invoice (PDF), the stock goes down and the delivery window fills up;
   - tap **Start packing**, and so on. The customer's order page follows every step.
5. Cancel the test order (**Cancel order**): the stock comes back.
6. If you want the waitlist to stay up until the drop, switch back to **Waitlist mode** now and switch to **Shop mode** on launch day.

**About payments:** right now customers pay by **Tikkie** (you send the link on WhatsApp), **cash** or **bank transfer** (tap *Mark as paid*). Unpaid Tikkie orders are cancelled by themselves after 12 hours (Settings → Shop), so stock is never stuck. Online card / iDEAL / Apple Pay checkout through Mollie is built in but switched off; ask your developer when you want it on.

### 9b. Deliveries and your courier

The **Delivery** tab shows one route per delivery window, for today and the next days.

1. Tap a day at the top. The bar with the days stays at the top while you scroll and shows how many stops are delivered, still to go, not home, and how much cash to collect. Each window is one card with its stops **in driving order** (the shortest way from your depot), the ETA per stop, the distance and time. The map above can be hidden with **Hide** (your phone remembers it).
2. **Today, *Up next*** at the top is the stop to drive to now, with big buttons: **Navigate**, **Delivered**, **Call**, **Not home** (and **Start route** before you leave). After each stop it moves on to the next one by itself, so you don't have to scroll while driving. When you come back from Google Maps, the screen refreshes.
3. **Whole route in Maps** opens the whole route in Google Maps on the phone. Long routes are split into parts of 10 stops (*Maps 1–10*, *Maps 11–15*): Google allows no more per link.
4. Want a different order? Hold the **⋮⋮** handle on a stop and drag it. **Auto order** puts it back to the shortest route.
5. **Start route** when you leave: all stops become *Out for delivery* and every customer gets an email with a half-hour arrival window.
6. At each door tap **Delivered** (add a photo if you like; for *cash on delivery* tick *I collected €…*, which marks it paid and emails the invoice) or **Not home**. Both email the customer. **On my way** sends a "10 minutes" heads-up, and the WhatsApp button next to it sends it on WhatsApp too.
7. A *Not home* order shows under **Today → Needs your attention**. Open it and tap **Reschedule delivery**, or tap **New window** on the Delivery tab. The customer gets the new time by email.

**A courier (helper):** **More → Team → + Add person**, role **courier**. They log in at `/admin` on their phone and only see their own Delivery screen. It is built like an iPhone app and follows the phone's light/dark setting. It has a week strip, a progress ring and the next stop with big buttons. They tap any stop to open its buttons (Navigate, Call, On my way, Not home, Delivered), and the account button (initials, top right) has *Log out*. If you have one courier, every route is theirs; with more, pick the courier per window on the Delivery tab. Your own **Courier mode** chip still shows the older big-button view.

**Settings → Delivery → Routes:** average speed and minutes per stop (for the ETAs), car or bike for Google Maps, and which emails go out automatically.

### 9c. Marketing: codes, friends, emails

**More → Marketing** (on a computer: *Marketing* in the sidebar). Six tabs:

0. **Shop funnel** (opens first). How many shop visits *viewed a product → added to the bag → opened checkout → placed the order → paid*, for 7, 30 or 90 days or all time, and how many were lost at each step. **Biggest drop** names the step where most people leave, with a tip what to check there. Counted without cookies; *Paid* counts web orders from that period that are paid now, also when you marked them paid later.
1. **Codes → + New code.** Type a name (e.g. `INSTA10`), choose *% off*, *€ off* or *Free delivery*, and optionally a minimum bag, a maximum number of uses and start/end dates. Customers type it at checkout under *Have a discount or friend’s code?*. Open a code and tap **Copy shop link**: whoever opens that link gets the code filled in by itself (good for Instagram stories). A code counts as used when the order is **paid**; if you cancel a paid order, the use comes back.
2. **Referrals.** Every customer gets a code like `LOTTE-D01` on their order page and a *Share on WhatsApp* button. A friend gets €5 off their first order with it. When the friend’s order is **delivered**, the customer automatically gets a personal one-time €5 code by email. Change the amounts in **Settings → Shop**; set the friend amount to 0 to switch the programme off.
3. **Email.** Write a message to a group (*everyone who said yes*, all customers, regulars, new, inactive, the waitlist or the next-drop list) and tap **Send me a test** first. Only people who agreed get it, and every email has an unsubscribe link. Emails go out in batches per hour (set the limit under *Sending limit*), so your email provider doesn’t block you; the cron job from step 6 sends the rest.
4. **Back in stock.** When a sold-out product gets stock again (Stock → adjust, or editing the product), everyone who tapped *Notify me* gets one email. People who left only a phone number appear here with a **WhatsApp** button: tap each one to send.
5. **Left bags.** If someone fills in checkout, ticks *Tell me about the next drop…* and leaves, they get **one** reminder email after an hour with a link that puts their bag back. Switch it off here if you don’t want it.

### 9d. Every day: packing, cash, customers changing their time

- **Packing list:** **Orders → Packing list** (also on **Today**, next to the delivery windows). Pick the day at the top. *To pick* adds up everything still to pack (e.g. *Item 01 · M × 4*). Below, every order per window with what goes in the bag, notes, 🎁 gifts and *Collect €… cash*. Tap the square when a bag is ready: the order becomes *Packing*. Tapped the wrong one? Tap **Undo** in the message at the bottom (or tap the square again). **Print list** prints a checklist; **Print labels** prints one label per bag (order number, window, name, address, phone) to stick on it. People with the *warehouse* role can use it too.
- **Packing mode (the fast way):** on the Packing list tap **Start packing**. You get one order at a time on the whole screen: the **shelf** (e.g. *A1*), the photo, the item and how many (*×2*). Take it from the shelf, put it in the bag, tap the item (on a computer: press **Enter**). When everything is ticked, tap **Packed · next order** (or Enter again): the next order opens. The strip at the top shows all orders of the day, green ones are packed; tap a number to jump. Arrows **‹ ›** (or swipe, or the arrow keys) skip an order. **Esc** or **×** closes. Packed the wrong one? Open it in the strip and tap **Not packed after all**.
- **Shelves:** give every size a place in the stock room, e.g. *A1*, *A2*, *B3*. In **Stock**, open a product: under *Variants and stock* there is a *Shelf* column; type the code and **Save**. Or, during packing, tap the dashed **+** box next to an item. *To pick* and packing mode then list items in shelf order, so you walk past the shelves once.
- **Cash from the courier:** when a courier ticks *I collected €…* at the door, the money is theirs to hand over. **Delivery → Cash with couriers** shows how much each courier has and for which orders. When you get it, tap **Received**, type what you actually got (a difference is shown and saved) and confirm. **History** lists every handover. Cash not handed over by the next day appears under **Today → Needs your attention**. The courier sees *Cash with you* on their screen.
- **Customers change their own delivery time:** on their order page (the link in every email) they tap **Change time** and pick another free window, until the cut-off of their current one, at most 3 times. You see it on the order timeline; they get the new time by email. After *Not home* or when a window filled up while they paid, the page asks them to pick a new time. Switch it off in **Settings → Delivery** (*Customers can change their window*).
- **Order again:** the *delivered* email and the order page have an **Order again** button that puts the same items back in the bag (what is still in stock).

### 9e. Money: what each product really earns

Open **Money** (left menu on the computer; **More → Money** on the phone). Fill in the costs once, then the numbers work by themselves. Everything is plain arithmetic, no guessing.

1. **Money → Costs & packaging**:
   - *Delivery cost per order*: what one delivery costs you (courier pay + fuel). What the customer pays for delivery is subtracted automatically.
   - *Payment fee*: the Tikkie fee per paid Tikkie (fixed €), or fixed + % for card payments. Cash and bank transfer: 0.
   - *Fixed costs per month*: storage, phone, hosting, subscriptions, ads. Used for the break-even.
   - *Target margin %* (e.g. 30), *Reorder at* (days of stock left) and *Buy for* (how many days the reorder should last).
   - Enter costs **without** the BTW you get back. If you can't reclaim BTW, enter what you really pay.
2. **+ Add** your packaging: the price of a pack and how many are in it (e.g. *Mailer box, €24 for 50*). Choose *Once in every order* (shipping bag, card) or *Per product*.
3. Make sure every product has its **purchase cost** (**Stock** → product → *Your cost*).
4. **Money → Products**: tap a product → **Packaging for one unit** → how many of each it uses → **Save packaging**.

What you see:
- **Products**: profit, profit per order, profit after fixed costs and **break-even** (orders a month that pay your fixed costs) for 7 / 30 / 90 days, a year or all time. Every product with units sold, profit and margin, and a label: **Best** (sells well, earns well), **Raise price** (sells well, margin under target), **Slow** (earns well, sells slowly), **Rethink**, **Loss**. **Reorder soon** lists what runs out within your *Reorder at* days and how many to buy.
- Tap a product: one sale step by step (price → BTW → discounts → purchase → packaging → delivery → payment fee → **profit**), margin and markup, the **lowest price** (below it you lose money) and the price for your target margin with **Use this price**, and **What if…**: type another price or purchase cost and see the profit right away.
- **Price calculator**: all products in one list: price, costs, profit, lowest price, target price.

Margin = profit ÷ what you keep after BTW and discounts. Markup = profit ÷ everything the product costs you. After 10 paid orders in 90 days, the plan uses your real items per order, average discount and delivery fees.

### 10. Updating later

1. **Admin → More → System → Make and download a backup.** Always do this first.
2. Upload the new `drop01-deploy.zip` to `public_html` and **Extract** it. Say **yes** to overwriting files.
3. Delete the zip. Log in to `/admin`. Database updates run by themselves when you log in.

Your data (database, settings, photos) is safe: it lives in a separate folder the zip never touches.

### 11. Backups

- A backup is made every night (the last 14 are kept), if the cron job from step 6 is running.
- **More → System** lists them. Tap one to download it. Keep a copy on your computer every week.
- The admin asks for your password again (**Confirm it's you**) before a backup, the waitlist CSV, Team changes and the copy settings below, if you haven't typed it in the last 15 minutes. That way a phone left logged in can't be used to take all your data.

**A copy outside this server (do this once, it takes 2 minutes).** If the hosting is ever hacked or lost, the backups above are lost with it.

1. **More → System → Copy outside this server.**
2. **Send the copy to:** an email address that is *not* the store's own mailbox, for example your private Gmail.
3. **Backup password:** make up a long password (at least 10 characters) and save it in your password manager or write it on paper at home. Without it nobody can open the copies, not even you. Don't use your admin password.
4. **Save**, then **Send a copy now**. Check that the email arrived (subject *"… backup 2026-…"*, with a zip attached).

From then on a copy arrives every night. Photos are not in it (they are in the backups you download).

**Restore a copy** (only if the database is lost or broken; ask the developer if you are unsure):

1. Open the zip from the email with **7-Zip** (Windows), **Keka** or **The Unarchiver** (Mac) and type the backup password. Inside is `app.sqlite`.
2. cPanel → **File Manager** → open the data folder (its path is under *Server check* in System, e.g. `public_html-drop01-data`).
3. Rename the old `app.sqlite` to `app-broken.sqlite`. If there are `app.sqlite-wal` and `app.sqlite-shm`, rename them too.
4. **Upload** the `app.sqlite` from the zip into that folder. Log in to `/admin`.

**Website address.** **Settings → Store → Website address** is used in every link in emails (for example `https://yourdomain.nl`). It is filled in when you install. Change it if you move to another domain.

### If you see X, do Y

| You see | Do this |
|---|---|
| "Index of /" or the GoDaddy placeholder page | The zip was not extracted in `public_html`, or the old `index.html` is still there. Delete it. |
| A red ✕ next to a PHP extension | cPanel → **Select PHP Version** → **Extensions** → tick it. Reload the installer. |
| A red ✕ "PHP 8.1 or newer" | cPanel → **Select PHP Version** → choose 8.2 → **Set as current**. |
| "The data folder can be written" is red | File Manager → `public_html` → create a folder `data` if missing → right-click → **Change Permissions** → 755. |
| **500 Internal Server Error** | File Manager → open `.htaccess` (Show Hidden Files) and check it exists. Make sure PHP is 8.2 (step 3). Then contact GoDaddy support and ask them to check the error log for `public_html`. |
| The page loads but "Not secure" | Step 2 (AutoSSL) is not finished yet. Wait and run it again. |
| "Scheduled jobs are not running" in the admin | Step 6. Check the command has your real user name in the path. |
| Signups don't get an email | Admin → Settings → Email → **Send test**. The error message says what is wrong (usually the password or the port). |
| Photo upload fails for big photos | The file is over the server limit. The store asks for 16 MB; if GoDaddy blocks this, ask support to raise `upload_max_filesize`. |
| "Too many attempts" at login | Wait 15 minutes. This protects your admin from password guessing. |
| You forgot your admin password | Ask another owner in **Team** to set a new one. If you are the only owner, contact the developer. |
| "We don't deliver to this postcode yet" | The postcode is outside your delivery zones. Add or widen a zone in **Settings → Delivery**. |
| "We couldn't find this address" at checkout | The house number doesn't exist at that postcode, or the address service is slow. The customer can type the street; the order still works. |
| An order cancelled itself | It was not paid in time (Settings → Shop: *Cancel unpaid* / *Tikkie wait*). If they paid after all, open the order and tap **Mark as paid**. |
| All delivery windows show "Full" | Raise the number per window in **Settings → Delivery**, or add delivery days. |
| No order confirmation email | Step 7. The order sheet's timeline shows whether the email was sent or why it failed. |
| Delivery says "set your depot in Settings" | **Settings → Store → Depot address**: type the full address (street, number, postcode, city). Routes start from the city centre until then. |
| A stop has no number on the map / sits at the end of the route | The address service could not find it. Check the house number on the order; the courier can still use **Navigate**. |
| The courier sees "No stops for you" | Give the window to that courier on the Delivery tab (or keep just one courier in Team). |
| A customer says their code doesn’t work | The message under the code field says why: ended, used up, minimum bag, a friend’s code on a second order, or someone else’s personal code. Check it in **Marketing → Codes**. |
| The Email tab says "Email is not set up yet" | Step 7. Broadcasts, reminders and back-in-stock emails all need it. |
| Print labels prints dark pages or the whole admin | Use Chrome or Safari and print from the Packing list buttons, not the browser menu. In the print dialog turn **Background graphics** off. |
| The courier's cash is not under "Cash with couriers" | Only payments the courier marked *I collected €…* at the door are listed. Cash you took yourself as owner is not. |
| A broadcast shows "waiting" for a long time | The hourly limit is reached, or the cron job (step 6) is not running. The rest goes out by itself. |
| Inbox: "Customer emails don't come in yet" | Step 7b: fill in the incoming mail server in Settings → Email and tick *Show customer emails in the Inbox*. |
| "The mail server refused the login" | The mailbox password changed or is wrong. Type it again in Settings → Email (outgoing password; or fill the incoming login/password if your provider uses different ones). |
| "Can't reach … (the secure connection failed)" | Check the server name and port 993 with SSL. GoDaddy Professional Email: `imap.secureserver.net`. Microsoft 365 mailboxes from GoDaddy often block this kind of login: use a cPanel mailbox for the store instead. |
| A customer's email is not in the Inbox | Wait 5 minutes (or tap **Check incoming mail now**). Emails that arrived before you switched the Inbox on are not imported. Newsletters and auto-replies are skipped on purpose. |
| Telegram: "That does not look like a bot token" | Copy the whole token from BotFather, including the numbers before the colon. Lost it? In BotFather send `/token` and pick your bot. |
| Telegram reports an error in Settings → Telegram | Tap **Connect again**. If it still says so, check that https://yourdomain.com opens with a green lock (step 2). |
| A Telegram reply shows "✕ not sent · The customer blocked the bot" | The customer stopped the bot. Write by email or WhatsApp instead. |
| No **Chat on Telegram** button on the order page | Telegram is not connected (Settings → Telegram). |
| Money says "Not filled in yet: …" | Step 9e: fill in the delivery cost, packaging and the purchase cost of the products it names. Until then profits look higher than they are. |
| A product shows **Loss** | Every sale costs you more than you keep. Tap it: the breakdown shows which cost is too high, and **Lowest price** shows the minimum price. |
| A red message at the top of the admin | Something did not work (for example an email could not be sent). It stays until you close it with ✕, so read it first; ×2 means the same thing failed twice. |
| A box asks "Are you sure?" / "Delete …?" | The admin asks before anything that can't be taken back (deleting, sending emails, marking paid). A red button means it is final. Steps you can take back (packed, start packing) don't ask: they show **Undo** at the bottom for a few seconds. |
| Shop funnel shows only zeros | Nobody looked at a product in that period yet, or the homepage is still the waitlist (Settings → Waitlist → store mode). |
| Texts: "{…} is not known here" | You typed a word in braces the shop can't fill in at that place. Use one of the words shown under the box, or remove the braces. |
| Texts: "This is not a texts file" when uploading | A quote or comma got lost while editing. Download the file again and change only the text between the quotes. |
| A text on the site shows something like `shop.hero.cta` | A text is missing from the update itself. Tell the developer which page shows it. |
| You changed a text but the site still shows the old one | Reload the page on your phone (pull down). Check you pressed **Save** in Texts: unsaved boxes say *unsaved*. |
| **Confirm it's you** asks for your password | Normal before backups, the CSV, Team and the off-site copy settings. Type your admin password; it won't ask again for 15 minutes. |
| You were logged out on your phone | Your password was changed (by you on another device, or by the owner in Team), or you logged in more than 7 days ago. Log in again. If nobody changed it, change your password now. |
| A customer sees "You already have an order waiting for payment" | They have an unpaid Tikkie order. Send them the Tikkie, or cancel the old order in **Orders**, then they can order again. |
| A customer sees "You can order up to 5 of …" | The limit per product per order. Change it in **Settings → Shop → Most of one product per order**, or add the order yourself in **Orders → New order** (no limit there). |
| An email in the Inbox says **Sender not confirmed** | It may be fake: someone pretending to be your customer. Don't change the address, order or payment because of it. Ask the customer on WhatsApp or by phone first. |
| System: *✕ Last try … Email not sent* under *Copy outside this server* | Email is not working (Settings → Email → **Send test**). If it says *too large*, the database is over ~18 MB: download backups from System instead. |
| Links in emails go to the wrong website | **Settings → Store → Website address**: type your real address, e.g. `https://yourdomain.nl`. |

---

## Русский

Займёт около 20 минут. Технические знания не нужны. Делай шаги по порядку.

### 1. Что нужно заранее

- Тариф GoDaddy **Web Hosting (cPanel)**. Тариф *Website Builder* не подойдёт.
- Твой домен подключён к этому хостингу.
- Файл **`drop01-deploy.zip`** (в папке `dist`).
- Можно позже: аккаунт Mollie (mollie.com) и почтовый ящик на твоём домене.

Как открыть cPanel: войди на godaddy.com → **My Products** → рядом с *Web Hosting* нажми **Manage** → **cPanel Admin**.

### 2. Включи HTTPS (зелёный замок)

1. В cPanel найди **SSL/TLS Status** и открой.
2. Отметь свой домен и нажми **Run AutoSSL**.
3. Дождись зелёного замка у домена. Обычно несколько минут, иногда до часа.

![cPanel SSL/TLS Status, кнопка Run AutoSSL](screenshot-ssl.png)

### 3. Выбери PHP 8.2

1. В cPanel открой **Select PHP Version** (иногда называется **MultiPHP Manager**).
2. Выбери **PHP 8.2** (или новее) → **Set as current**.
3. Вкладка **Extensions**: проверь, что отмечены
   `pdo_sqlite`, `sqlite3`, `curl`, `gd`, `mbstring`, `openssl`, `json`, `fileinfo`, `zip`.
   Каждая галочка сохраняется сама.

![Select PHP Version, вкладка Extensions](screenshot-php.png)

### 4. Загрузи магазин

1. В cPanel открой **File Manager** и зайди в **public_html** (двойной клик).
2. Если там есть старые файлы (например, заглушка GoDaddy `index.html`), выдели их и нажми **Delete**.
3. Нажми **Upload**, выбери `drop01-deploy.zip`, дождись зелёной полоски.
4. Нажми **Go Back to "/home/…/public_html"**.
5. Правый клик по `drop01-deploy.zip` → **Extract** → **Extract Files**.
6. Удали `drop01-deploy.zip`.

> Совет: File Manager скрывает файлы, которые начинаются с точки. Чтобы увидеть `.htaccess`: **Settings** (справа вверху) → **Show Hidden Files**.

![File Manager с распакованными файлами](screenshot-filemanager.png)

### 5. Запусти установщик

1. Открой в браузере **https://твойдомен.com/install.php**.
2. В списке у каждого пункта зелёная ✓ или красный ✕. Если что-то красное, под ним написано, что нажать. Исправь и обнови страницу.
3. Заполни:
   - **название магазина**,
   - **своё имя, email и пароль** (минимум 10 символов) — это вход в админку,
   - база данных: оставь **SQLite (recommended)**,
   - почту можно пропустить. Если заполняешь, впиши и **Incoming mail server (IMAP)** — тогда письма клиентов будут в админке во вкладке **Inbox** (см. шаг 7b),
   - **Telegram bot** (можно пропустить): вставь токен от @BotFather (как его получить — в шаге 7b). На последнем экране будет *✓ Telegram bot @… connected*. Если написано *not connected* — магазин всё равно установится, подключишь позже в Settings → Telegram,
   - оставь галочку **демо-товары**, если хочешь сначала всё попробовать.
4. Нажми **Install**.
5. На последнем экране будет **команда для cron**. Скопируй её, она нужна в шаге 6.
6. Нажми **Open the admin** и войди.

После этого установщик блокируется и второй раз не запустится.

### 6. Добавь cron (фоновые задачи)

Он повторно отправляет письма, если не ушли с первого раза, делает ночной бэкап и чистит старое.

1. В cPanel открой **Cron Jobs**.
2. В *Add New Cron Job* выбери **Common Settings** → **Once Per Five Minutes (*/5 * * * *)**.
3. Вставь команду из установщика в поле **Command**. Она выглядит так:
   `/usr/local/bin/php /home/ТВОЙ_ЮЗЕР/public_html/cron.php >/dev/null 2>&1`
4. Нажми **Add New Cron Job**.

Через 5 минут в **Админка → More → System** будет *Scheduled jobs: ✓ Running*. Там же можно снова посмотреть команду.

![cPanel Cron Jobs](screenshot-cron.png)

### 7. Настрой почту (чтобы люди получали код подарка)

1. Админка → **More → Settings → Email**.
2. Введи сервер исходящей почты своего ящика. Их видно в GoDaddy: **Email → твой ящик → Connect devices / Server settings**. Обычно:
   - почта cPanel от GoDaddy: сервер `mail.твойдомен.com`, порт **465**, **SSL**, логин = полный адрес почты;
   - GoDaddy Professional Email: `smtpout.secureserver.net`, порт **465**, **SSL**;
   - Microsoft 365 от GoDaddy: `smtp.office365.com`, порт **587**, **TLS**.
3. **Save email**, потом **Send test**. Тестовое письмо должно прийти в течение минуты.

### 7b. Переписка с клиентами: email и Telegram в одном Inbox

Вся переписка с клиентами — в админке во вкладке **Inbox** (нижняя панель на телефоне, меню слева на компьютере). У каждого чата на кружке с инициалами маленькая иконка канала: **фиолетовый конверт = email**, **голубой самолётик = Telegram**. Красная цифра на **Inbox** — сколько чатов ждут ответа. Отвечаешь прямо в чате, ответ уходит тем же путём, каким написал клиент: на email — письмом, в Telegram — в Telegram. Автоматические письма этому клиенту (заказ подтверждён, курьер в пути…) тоже видны в его email-чате, мелким серым шрифтом.

**Email в Inbox**
1. Админка → **Settings → Email** → блок *Incoming mail*.
2. Поставь галочку **Show customer emails in the Inbox** и впиши сервер входящей почты того же ящика:
   - почта cPanel от GoDaddy: `mail.твойдомен.com`, порт **993**, **SSL**;
   - GoDaddy Professional Email: `imap.secureserver.net`, порт **993**, **SSL**.
   Логин и пароль оставь пустыми: магазин возьмёт их из исходящей почты выше.
3. **Save email**, потом **Check incoming mail now**. Должно быть *Connected*.
4. Дальше новые письма в этом ящике появляются в Inbox в течение 5 минут (сразу, если Inbox открыт). Старые письма не загружаются. Магазин только читает: в твоей почтовой программе всё остаётся как было, ничего не помечается прочитанным и не удаляется. Рассылки, возвраты писем и автоответы пропускаются. Если выбрать *Which emails → Only customers…*, пропускаются и незнакомые отправители (например, рекламные предложения).

**Telegram в Inbox**
1. На телефоне открой Telegram и найди **@BotFather** (с синей галочкой). Нажми **Start**.
2. Отправь `/newbot`. Введи имя, которое увидят клиенты (например, название магазина), потом username, который заканчивается на `bot` (например, `drop01groningen_bot`).
3. BotFather пришлёт **токен** вида `123456789:AAH…`. Скопируй всю строку.
4. Вставь его в установщике (шаг 5) или позже: Админка → **Settings → Telegram** → **Connect bot**. Появится *Connected to @твойбот*.
5. Клиенты видят кнопку **Chat on Telegram** на странице своего заказа. Чат, начатый оттуда, сразу привязан к их заказам — сверху видно *1 order · #1062*. Любой может написать и просто на `t.me/твойбот`.
6. Когда кто-то начинает чат, бот отправляет **приветствие** из Settings → Telegram. Там его можно изменить или стереть.

Важно: бот может отвечать только тем, кто написал ему первым. Если на сайте ещё нет HTTPS (шаг 2), сообщения приходят раз в несколько минут, а не мгновенно; когда HTTPS заработает, нажми **Connect again** в Settings → Telegram. Фото от клиентов видны в чате; для голосовых и видео будет подсказка открыть их в Telegram. На телефоне вкладка **Stock** переехала в **More**, чтобы освободить место для **Inbox**.

### 8. Сделай магазин своим

На компьютере меню слева разбито на группы (Daily, Grow, Setup). Стрелка вверху сворачивает его до иконок, чтобы было больше места (то же делает Ctrl/⌘ + \\); компьютер это запоминает.

На компьютере в **Settings** слева меню: открыт один раздел за раз. Жёлтая точка — там чего-то не хватает; **unsaved** — ты что-то изменил и ещё не сохранил. Кнопка **Save** всегда внизу окна, сохранить можно и **Ctrl+S** (⌘+S на Mac). На телефоне разделы — кнопки сверху, они прокручивают страницу к нужному месту.

1. **Settings → Store:** название компании, KvK, BTW-id, адрес. Они подставятся в юридические страницы. Заполни и **Depot address** (откуда забираешь товар): с него начинается каждый маршрут.
2. **Stock:** переделай демо-товары в настоящие (название, цена, себестоимость, тираж, фото) или добавь новые через **+ Add**. Фото прямо с телефона подходят.
3. **Settings → Waitlist and launch:** дата и время дропа, название и лимит подарков, тексты бегущей строки.
4. **Settings → Legal pages:** это черновики. Покажи юристу, потом отметь *The texts have been checked*.
5. **More → System → Remove demo data**, когда настоящие товары готовы.
6. Открой сайт на телефоне, проведи пальцем по пачке, запишись сам и посмотри весь путь. Потом делись ссылкой.

### 8b. Поменять любой текст на сайте (Texts)

Любое слово, которое видят клиенты, можно поменять без разработчика: магазин, вейтлист, корзина и оформление, страница заказа, письма, готовые сообщения WhatsApp и сообщения об ошибках.

1. Админка → **Texts** (на телефоне: **More → Texts**).
2. Выбери часть сайта в меню (например *Shop · Top of the page*) или впиши слово в **Search all texts**.
3. Измени текст в его поле. Серая строка под изменённым полем показывает оригинал.
4. Нажми **Save** внизу (или Ctrl/⌘+S). Сайт сразу показывает новый текст, ничего загружать не нужно.

Полезно знать:
- Слова в фигурных скобках, например `{store}`, `{cutoff}`, `{number}`, магазин подставляет сам (название, время приёма заказов, номер заказа…). Оставляй их. Если впишешь слово, которого магазин не знает, поле станет красным, а **Save** напишет, какие слова можно использовать.
- `**слово**` делает слово **жирным**. `[Returns policy](/pages/returns)` делает ссылку.
- Пустое поле или кнопка **↺ Original** возвращает оригинальный текст. **Reset all** возвращает все тексты.
- Вопросы (в магазине и на вейтлисте): **+ Add a question** добавляет вопрос, ↑ ↓ меняют порядок, × удаляет. Если удалить все, блок вопросов исчезнет.
- У некоторых текстов есть лимит символов (видно как `7/14`), например у больших летящих слов в интро, чтобы анимация влезала на экран телефона.
- Название магазина и логотип остаются в **Settings → Store**; подарок, бегущая строка и верхняя плашка магазина в **Settings → Waitlist** и **Settings → Shop**; юридические страницы в **Settings → Legal pages**.

**Версия для нового клиента за несколько минут:** нажми **Download file**. Получишь один файл со всеми текстами, сгруппированными по страницам. Открой его в любом текстовом редакторе (TextEdit, Блокнот, VS Code), меняй текст после двоеточия, кавычки и запятые не трогай. Потом нажми **Upload file**. Тексты, которые ты удалил из файла, останутся как были; строки с ошибкой будут показаны списком, всё остальное сохранится.

### 9. Сделай пробный заказ и открой магазин

Сделай это один раз до дня запуска, с телефона. Займёт 5 минут.

1. **Settings → Delivery:** проверь окна доставки, сколько заказов влезает в одно окно, время cut-off и дни доставки. **Settings → Shop:** сумма бесплатной доставки и сколько ждут неоплаченные заказы.
2. **Settings → Waitlist and launch → Shop mode → Save** и подтверди. Теперь на главной — магазин. (Обратно переключается так же.)
3. На телефоне открой сайт, положи что-нибудь в корзину и оформи заказ на себя с настоящим адресом в Гронингене. Выбери **Tikkie** и нажми **Place order**. Увидишь *Almost yours.*
4. В админке открой **Orders**. У заказа статус *Awaiting Tikkie*. Нажми на него:
   - создай Tikkie на указанную сумму в приложении Tikkie, вставь ссылку и нажми **Send via WhatsApp**;
   - когда деньги пришли, нажми **Mark as paid**. Клиенту уходит письмо-подтверждение со счётом (PDF), остаток товара уменьшается, окно доставки заполняется;
   - дальше **Start packing** и т.д. Страница заказа у клиента показывает каждый шаг.
5. Отмени тестовый заказ (**Cancel order**): товар вернётся на склад.
6. Если хочешь, чтобы до дропа висел вейтлист, переключи обратно на **Waitlist mode**, а в день запуска — на **Shop mode**.

**Про оплату:** сейчас клиенты платят через **Tikkie** (ты отправляешь ссылку в WhatsApp), **наличными** или **переводом** (нажми *Mark as paid*). Неоплаченные Tikkie-заказы отменяются сами через 12 часов (Settings → Shop), так что товар не «зависает». Онлайн-оплата картой / iDEAL / Apple Pay через Mollie встроена, но выключена — скажи разработчику, когда захочешь её включить.

### 9b. Доставка и курьер

Во вкладке **Delivery** — один маршрут на каждое окно доставки, на сегодня и следующие дни.

1. Выбери день сверху. Полоса с днями остаётся наверху при прокрутке и показывает, сколько доставлено, сколько осталось, сколько «не было дома» и сколько наличных собрать. Каждое окно — одна карточка, остановки в ней идут **в порядке объезда** (самый короткий путь от склада), с ожидаемым временем на каждой, расстоянием и временем в пути. Карту сверху можно скрыть кнопкой **Hide** (телефон это запомнит).
2. **Сегодня, блок *Up next*** сверху — это остановка, куда ехать сейчас, с большими кнопками: **Navigate**, **Delivered**, **Call**, **Not home** (а перед выездом ещё **Start route**). После каждой остановки он сам переходит к следующей, так что за рулём не нужно листать. Когда возвращаешься из Google Maps, экран обновляется.
3. **Whole route in Maps** открывает весь маршрут в Google Maps на телефоне. Длинные маршруты делятся на части по 10 остановок (*Maps 1–10*, *Maps 11–15*): больше Google в одну ссылку не берёт.
4. Хочешь другой порядок? Зажми значок **⋮⋮** у остановки и перетащи. **Auto order** вернёт самый короткий маршрут.
5. Когда выезжаешь — **Start route**: все заказы становятся *Out for delivery*, каждому клиенту уходит письмо с получасовым окном прибытия.
6. У каждой двери нажми **Delivered** (можно добавить фото; для *оплаты наличными* отметь *I collected €…* — заказ станет оплаченным и клиенту уйдёт счёт) или **Not home**. В обоих случаях клиенту уходит письмо. **On my way** отправляет «буду через 10 минут», а кнопка WhatsApp рядом — то же самое в WhatsApp.
7. Заказ *Not home* появляется в **Today → Needs your attention**. Открой его и нажми **Reschedule delivery** или **New window** во вкладке Delivery. Клиент получит новое время по почте.

**Курьер (помощник):** **More → Team → + Add person**, роль **courier**. Он входит в `/admin` с телефона и видит только свой экран доставки. Экран сделан как приложение для iPhone и сам переключается на светлую или тёмную тему вместе с телефоном. На нём есть полоса дней недели, кольцо прогресса и следующая остановка с большими кнопками. Чтобы открыть кнопки любой остановки (Navigate, Call, On my way, Not home, Delivered), достаточно на неё нажать. Кнопка с инициалами справа вверху: там *Log out*. Если курьер один, все маршруты его; если несколько — выбери курьера для каждого окна во вкладке Delivery. Твоя кнопка **Courier mode** по-прежнему показывает старый вид с большими кнопками.

**Settings → Delivery → Routes:** средняя скорость и минуты на остановку (для расчёта времени), машина или велосипед для Google Maps и какие письма уходят автоматически.

### 9c. Маркетинг: коды, друзья, рассылки

**More → Marketing** (на компьютере — *Marketing* в боковом меню). Шесть вкладок:

0. **Shop funnel** (открывается первой). Сколько посещений магазина *посмотрели товар → положили в корзину → открыли оформление → оформили заказ → оплатили* за 7, 30, 90 дней или за всё время, и сколько людей ушло на каждом шаге. Блок **Biggest drop** называет шаг, где уходит больше всего людей, и подсказывает, что там проверить. Считается без cookies; *Paid* — это заказы с сайта за этот период, которые сейчас оплачены, в том числе те, что ты отметил оплаченными позже.
1. **Codes → + New code.** Впиши название (например, `INSTA10`), выбери *% off* (скидка в процентах), *€ off* (скидка в евро) или *Free delivery* (бесплатная доставка). По желанию: минимальная сумма корзины, сколько раз код можно использовать, даты начала и конца. Клиент вводит код при оформлении в поле *Have a discount or friend’s code?*. Открой код и нажми **Copy shop link**: у того, кто откроет эту ссылку, код подставится сам (удобно для сторис в Instagram). Код считается использованным, когда заказ **оплачен**; если отменишь оплаченный заказ, использование вернётся.
2. **Referrals.** Каждый покупатель получает на странице заказа код вида `LOTTE-D01` и кнопку *Share on WhatsApp*. Друг получает с этим кодом €5 скидки на первый заказ. Когда заказ друга **доставлен**, покупателю автоматически приходит на почту личный одноразовый код на €5. Суммы меняются в **Settings → Shop**; поставь сумму для друга 0, чтобы выключить программу.
3. **Email.** Напиши письмо группе (*все, кто согласился*, все покупатели, постоянные, новые, давно не заказывали, вейтлист или список на следующий дроп) и сначала нажми **Send me a test**. Письмо получают только те, кто дал согласие, и в каждом есть ссылка для отписки. Письма уходят порциями в час (лимит — в блоке *Sending limit*), чтобы почтовый сервис тебя не заблокировал; остальное отправит cron из шага 6.
4. **Back in stock.** Когда распроданный товар снова появляется (Stock → изменить остаток или правка товара), каждый, кто нажал *Notify me*, получает одно письмо. Те, кто оставил только телефон, появятся здесь с кнопкой **WhatsApp**: нажми на каждую, чтобы отправить.
5. **Left bags.** Если человек заполнил оформление, отметил *Tell me about the next drop…* и ушёл, через час ему придёт **одно** письмо-напоминание со ссылкой, которая вернёт его корзину. Здесь это можно выключить.

### 9d. Каждый день: сборка, наличные, клиенты сами меняют время

- **Лист сборки:** **Orders → Packing list** (кнопка есть и на **Today**, рядом с окнами доставки). Выбери день сверху. В блоке *To pick* сложено всё, что ещё надо собрать (например, *Item 01 · M × 4*). Ниже идут все заказы по окнам доставки: что положить в пакет, заметки, 🎁 подарки и *Collect €… cash* (взять наличные). Когда пакет готов, нажми квадратик: заказ станет *Packing*. Нажал не тот? Нажми **Undo** в сообщении внизу (или нажми квадратик ещё раз). **Print list** печатает список с галочками, **Print labels** печатает наклейку на каждый пакет (номер заказа, окно, имя, адрес, телефон). Этим разделом могут пользоваться и сотрудники с ролью *warehouse*.
- **Режим сборки (быстрый способ):** в Packing list нажми **Start packing**. На весь экран показывается один заказ: **полка** (например, *A1*), фото, товар и сколько штук (*×2*). Возьми с полки, положи в пакет, нажми на товар (на компьютере — клавиша **Enter**). Когда всё отмечено, нажми **Packed · next order** (или ещё раз Enter): откроется следующий заказ. Полоска сверху показывает все заказы дня, зелёные уже собраны; нажми на номер, чтобы перейти к нему. Стрелки **‹ ›** (или свайп, или стрелки на клавиатуре) пропускают заказ. **Esc** или **×** закрывает режим. Собрал не тот? Открой его в полоске и нажми **Not packed after all**.
- **Полки:** дай каждому размеру место на складе, например *A1*, *A2*, *B3*. В **Stock** открой товар: в блоке *Variants and stock* есть колонка *Shelf* (полка); впиши код и нажми **Save**. Или прямо во время сборки нажми на пунктирный квадрат **+** рядом с товаром. После этого *To pick* и режим сборки идут по порядку полок, и ты проходишь склад один раз.
- **Наличные от курьера:** когда курьер отмечает у двери *I collected €…*, эти деньги он должен передать тебе. **Delivery → Cash with couriers** показывает, сколько у каждого курьера и за какие заказы. Когда получишь деньги, нажми **Received**, впиши, сколько получил на самом деле (разница покажется и сохранится), и подтверди. В **History** все передачи. Наличные, не переданные до следующего дня, появляются в **Today → Needs your attention**. Курьер видит у себя блок *Cash with you*.
- **Клиент сам меняет время доставки:** на странице своего заказа (ссылка есть в каждом письме) он нажимает **Change time** и выбирает другое свободное окно. Это можно сделать до закрытия приёма заказов в текущем окне и не больше 3 раз. Ты видишь это в истории заказа, клиенту приходит письмо с новым временем. После *Not home* или если окно заполнилось, пока он платил, страница сама попросит выбрать новое время. Выключается в **Settings → Delivery** (*Customers can change their window*).
- **Заказать снова:** в письме «доставлено» и на странице заказа есть кнопка **Order again**. Она кладёт в корзину те же товары (те, что ещё есть в наличии).

### 9e. Money: сколько на самом деле приносит каждый товар

Открой **Money** (левое меню на компьютере; на телефоне **More → Money**). Один раз заполни расходы, дальше всё считается само. Тут только арифметика, никаких догадок.

1. **Money → Costs & packaging**:
   - *Delivery cost per order*: сколько тебе стоит одна доставка (оплата курьеру + бензин). То, что клиент платит за доставку, вычитается автоматически.
   - *Payment fee*: комиссия Tikkie за один оплаченный Tikkie (фикс. €) или фикс. + % для оплаты картой. Наличные и перевод: 0.
   - *Fixed costs per month*: склад, телефон, хостинг, подписки, реклама. Нужны для точки безубыточности.
   - *Target margin %* (например, 30), *Reorder at* (за сколько дней до конца запаса дозаказывать) и *Buy for* (на сколько дней хватит дозаказа).
   - Вписывай расходы **без** BTW, который тебе возвращают. Если не можешь вернуть BTW, вписывай, сколько реально платишь.
2. **+ Add** — добавь упаковку: цену упаковки и сколько в ней штук (например, *Mailer box, €24 за 50*). Выбери *Once in every order* (пакет, открытка в каждый заказ) или *Per product* (на конкретный товар).
3. Проверь, что у каждого товара есть **закупочная цена** (**Stock** → товар → *Your cost*).
4. **Money → Products**: нажми товар → **Packaging for one unit** → сколько каких материалов уходит на 1 штуку → **Save packaging**.

Что там видно:
- **Products**: прибыль, прибыль с заказа, прибыль после постоянных расходов и **break-even** (сколько заказов в месяц покрывают постоянные расходы) за 7 / 30 / 90 дней, год или всё время. По каждому товару: продано штук, прибыль, маржа и метка: **Best** (хорошо продаётся и хорошо зарабатывает), **Raise price** (продаётся, но маржа ниже цели — подними цену), **Slow** (зарабатывает, но продаётся медленно — продвигай), **Rethink** (мало маржи и медленно), **Loss** (в минус). **Reorder soon** — что закончится в ближайшие дни и сколько докупить.
- Нажми товар: одна продажа по шагам (цена → BTW → скидки → закупка → упаковка → доставка → комиссия → **прибыль**), маржа и наценка, **lowest price** (ниже — продаёшь в минус) и цена для твоей целевой маржи с кнопкой **Use this price**, и **What if…**: впиши другую цену или закупку и сразу увидишь прибыль.
- **Price calculator**: все товары одним списком: цена, расходы, прибыль, минимальная цена, цена для цели.

Маржа = прибыль ÷ то, что остаётся после BTW и скидок. Наценка = прибыль ÷ все расходы на товар. После 10 оплаченных заказов за 90 дней расчёт сам переходит на твои реальные цифры: штук в заказе, средняя скидка, оплата доставки.

### 10. Обновления

1. **Админка → More → System → Make and download a backup.** Всегда сначала это.
2. Загрузи новый `drop01-deploy.zip` в `public_html` и сделай **Extract**. На вопрос о замене файлов — **да**.
3. Удали zip. Войди в `/admin`. Обновления базы применятся сами при входе.

Твои данные (база, настройки, фото) в безопасности: они в отдельной папке, которую zip не трогает.

### 11. Бэкапы

- Каждую ночь делается бэкап (хранятся последние 14), если работает cron из шага 6.
- В **More → System** их список. Нажми, чтобы скачать. Раз в неделю сохраняй копию на компьютер.
- Перед бэкапом, выгрузкой CSV вейтлиста, изменениями в Team и настройками копии (ниже) админка ещё раз спрашивает пароль (**Confirm it's you**), если ты не вводил его последние 15 минут. Так с забытого залогиненного телефона нельзя унести все данные.

**Копия за пределами сервера (сделай один раз, 2 минуты).** Если хостинг когда-нибудь взломают или он пропадёт, бэкапы выше пропадут вместе с ним.

1. **More → System → Copy outside this server.**
2. **Send the copy to:** адрес почты, который *не* является почтой магазина, например твой личный Gmail.
3. **Backup password:** придумай длинный пароль (минимум 10 символов) и сохрани его в менеджере паролей или запиши на бумаге дома. Без него копии не откроет никто, даже ты. Не используй пароль от админки.
4. **Save**, потом **Send a copy now**. Проверь, что письмо пришло (тема *«… backup 2026-…»*, во вложении zip).

Дальше копия приходит каждую ночь. Фото в ней нет (они есть в бэкапах, которые ты скачиваешь).

**Восстановить из копии** (только если база потеряна или сломана; если не уверен — спроси разработчика):

1. Открой zip из письма программой **7-Zip** (Windows), **Keka** или **The Unarchiver** (Mac) и введи пароль бэкапа. Внутри файл `app.sqlite`.
2. cPanel → **File Manager** → открой папку данных (её путь написан под *Server check* в System, например `public_html-drop01-data`).
3. Переименуй старый `app.sqlite` в `app-broken.sqlite`. Если рядом есть `app.sqlite-wal` и `app.sqlite-shm`, переименуй и их.
4. **Upload** — загрузи `app.sqlite` из zip в эту папку. Войди в `/admin`.

**Адрес сайта.** **Settings → Store → Website address** используется во всех ссылках в письмах (например `https://yourdomain.nl`). Он заполняется при установке. Поменяй его, если переедешь на другой домен.

### Если видишь X — сделай Y

| Видишь | Что сделать |
|---|---|
| «Index of /» или заглушку GoDaddy | Zip не распакован в `public_html` или там остался старый `index.html`. Удали его. |
| Красный ✕ у расширения PHP | cPanel → **Select PHP Version** → **Extensions** → поставь галочку. Обнови установщик. |
| Красный ✕ «PHP 8.1 or newer» | cPanel → **Select PHP Version** → 8.2 → **Set as current**. |
| Красный «The data folder can be written» | File Manager → `public_html` → создай папку `data`, если её нет → правый клик → **Change Permissions** → 755. |
| **500 Internal Server Error** | Проверь, что `.htaccess` на месте (Show Hidden Files) и PHP 8.2 (шаг 3). Потом напиши в поддержку GoDaddy: попроси посмотреть error log для `public_html`. |
| Сайт открывается, но «Не защищено» | AutoSSL (шаг 2) ещё не готов. Подожди и запусти снова. |
| В админке «Scheduled jobs are not running» | Шаг 6. Проверь, что в пути твоё настоящее имя пользователя. |
| Письма не приходят | Админка → Settings → Email → **Send test**. В сообщении об ошибке будет причина (обычно пароль или порт). |
| Большие фото не загружаются | Файл больше лимита сервера. Магазин просит 16 МБ; если GoDaddy не даёт, попроси поддержку увеличить `upload_max_filesize`. |
| «Too many attempts» при входе | Подожди 15 минут. Это защита от подбора пароля. |
| Забыл пароль от админки | Попроси другого владельца в **Team** поставить новый. Если ты единственный владелец — напиши разработчику. |
| «We don't deliver to this postcode yet» | Индекс вне твоих зон доставки. Добавь или расширь зону в **Settings → Delivery**. |
| «We couldn't find this address» при оформлении | Такого номера дома нет на этом индексе, или сервис адресов медленный. Клиент может вписать улицу сам — заказ всё равно пройдёт. |
| Заказ отменился сам | Его не оплатили вовремя (Settings → Shop: *Cancel unpaid* / *Tikkie wait*). Если всё-таки заплатили — открой заказ и нажми **Mark as paid**. |
| Все окна доставки «Full» | Увеличь число заказов на окно в **Settings → Delivery** или добавь дни доставки. |
| Не пришло письмо-подтверждение заказа | Шаг 7. В истории заказа (Timeline) видно, ушло ли письмо и почему нет. |
| В Delivery написано «set your depot in Settings» | **Settings → Store → Depot address**: впиши полный адрес (улица, дом, индекс, город). До этого маршруты считаются от центра города. |
| Остановка без места на карте / стоит в конце маршрута | Сервис адресов её не нашёл. Проверь номер дома в заказе; курьер всё равно может нажать **Navigate**. |
| Курьер видит «No stops for you» | Назначь ему окно во вкладке Delivery (или оставь в Team одного курьера). |
| Клиент пишет, что код не работает | Под полем кода написана причина: срок кончился, код израсходован, мала сумма корзины, код друга на втором заказе или чужой личный код. Проверь в **Marketing → Codes**. |
| Во вкладке Email написано «Email is not set up yet» | Шаг 7. Без почты не работают рассылки, напоминания и письма «снова в наличии». |
| Печать наклеек даёт тёмные страницы или всю админку | Используй Chrome или Safari и печатай кнопками в Packing list, а не через меню браузера. В окне печати выключи **Background graphics** (фоновую графику). |
| Наличных курьера нет в «Cash with couriers» | Там только оплаты, которые курьер отметил у двери как *I collected €…*. Наличные, которые взял ты сам как владелец, там не показываются. |
| Рассылка долго висит в «waiting» | Достигнут лимит в час или не работает cron (шаг 6). Остальное уйдёт само. |
| В Inbox: «Customer emails don't come in yet» | Шаг 7b: впиши сервер входящей почты в Settings → Email и поставь галочку *Show customer emails in the Inbox*. |
| «The mail server refused the login» | Пароль ящика изменился или неверный. Впиши его снова в Settings → Email (пароль исходящей почты; или заполни отдельные логин/пароль входящей, если у провайдера они другие). |
| «Can't reach … (the secure connection failed)» | Проверь имя сервера и порт 993 с SSL. Для GoDaddy Professional Email: `imap.secureserver.net`. Ящики Microsoft 365 от GoDaddy часто блокируют такой вход — лучше заведи для магазина ящик в cPanel. |
| Письма клиента нет в Inbox | Подожди 5 минут (или нажми **Check incoming mail now**). Письма, пришедшие до включения Inbox, не загружаются. Рассылки и автоответы пропускаются специально. |
| Telegram: «That does not look like a bot token» | Скопируй токен из BotFather целиком, вместе с цифрами до двоеточия. Потерял? Отправь BotFather `/token` и выбери своего бота. |
| В Settings → Telegram написано, что Telegram сообщает об ошибке | Нажми **Connect again**. Если не помогло — проверь, что https://твойдомен.com открывается с зелёным замком (шаг 2). |
| Ответ в Telegram: «✕ not sent · The customer blocked the bot» | Клиент остановил бота. Напиши ему на email или в WhatsApp. |
| На странице заказа нет кнопки **Chat on Telegram** | Telegram не подключён (Settings → Telegram). |
| В Money написано «Not filled in yet: …» | Шаг 9e: впиши стоимость доставки, упаковку и закупочную цену товаров, которые там названы. Пока не впишешь, прибыль выглядит больше, чем есть. |
| У товара метка **Loss** | Каждая продажа стоит тебе больше, чем ты получаешь. Нажми товар: в разбивке видно, какой расход слишком большой, а **Lowest price** — минимальная цена без убытка. |
| Красное сообщение вверху админки | Что-то не получилось (например, не ушло письмо). Оно висит, пока не закроешь его ✕, так что сначала прочитай; ×2 значит, что одно и то же не получилось два раза. |
| Окно спрашивает «Are you sure?» / «Delete …?» | Админка переспрашивает перед тем, что нельзя отменить (удаление, отправка писем, отметка об оплате). Красная кнопка — действие окончательное. То, что можно вернуть (собрано, начать сборку), не переспрашивает: внизу на несколько секунд появляется **Undo**. |
| Shop funnel показывает одни нули | За этот период никто ещё не смотрел товары, или на главной всё ещё вейтлист (Settings → Waitlist → режим магазина). |
| Texts: «{…} is not known here» | Ты вписал в скобках слово, которое магазин не может подставить в этом месте. Возьми одно из слов под полем или убери скобки. |
| Texts: «This is not a texts file» при загрузке | При правке потерялась кавычка или запятая. Скачай файл заново и меняй только текст между кавычками. |
| На сайте вместо текста что-то вроде `shop.hero.cta` | Этого текста нет в самом обновлении. Напиши разработчику, на какой странице это видно. |
| Поменял текст, а на сайте старый | Обнови страницу на телефоне (потяни вниз). Проверь, что нажал **Save** в Texts: несохранённые поля помечены *unsaved*. |
| **Confirm it's you** просит пароль | Это нормально перед бэкапом, CSV, Team и настройками копии. Введи пароль от админки; 15 минут больше не спросит. |
| Тебя выкинуло из админки на телефоне | Пароль сменили (ты сам на другом устройстве или владелец в Team), или ты вошёл больше 7 дней назад. Войди снова. Если никто пароль не менял — смени его прямо сейчас. |
| Клиент видит «You already have an order waiting for payment» | У него есть неоплаченный заказ через Tikkie. Отправь ему Tikkie или отмени старый заказ в **Orders** — тогда он сможет заказать снова. |
| Клиент видит «You can order up to 5 of …» | Это лимит штук одного товара в заказе. Поменяй в **Settings → Shop → Most of one product per order** или оформи заказ сам в **Orders → New order** (там лимита нет). |
| Письмо в Inbox помечено **Sender not confirmed** | Возможно, это подделка: кто-то выдаёт себя за клиента. Не меняй из-за него адрес, заказ или оплату. Сначала спроси клиента в WhatsApp или по телефону. |
| В System под *Copy outside this server* написано *✕ Last try … Email not sent* | Не работает почта (Settings → Email → **Send test**). Если написано *too large*, база больше ~18 МБ — скачивай бэкапы из System. |
| Ссылки в письмах ведут не на тот сайт | **Settings → Store → Website address**: впиши свой настоящий адрес, например `https://yourdomain.nl`. |

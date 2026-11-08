# Prompts to paste into Claude Code, one phase at a time

Paste **one** prompt, let Claude Code finish, and check the result before moving on. The full spec is in `CLAUDE.md`; Claude Code reads it automatically.

---

## Phase 1 — Foundation + waitlist live

```
Read CLAUDE.md and every file in prototypes/ fully before writing code.
Build Phase 1 exactly as described in §11:
- project skeleton, installer, admin login, settings, migrations, .htaccess, build script;
- port prototypes/waitlist.html pixel-perfect and wire it to the API (signup with server gift codes, shared votes, counters, cookieless analytics);
- the admin shell from prototypes/crm.html with the Waitlist tab built from prototypes/waitlist-dashboard.html on real data, Settings, and System.
Run the tests from §12, including the Playwright mobile tests and the swipe-only intro test.
Then build dist/drop01-deploy.zip and dist/DEPLOY-GODADDY.md (English + Russian).
Finish with a short list of what I should click to verify it works.
```

## Phase 2 — Shop, checkout, payments, core CRM

```
Continue with Phase 2 from CLAUDE.md §11:
- port prototypes/shop.html and wire it fully: catalogue, product page, bag, bundles, postcode check, PDOK address lookup, delivery slots, Mollie checkout in test mode, return page, order tracking;
- CRM tabs Today, Orders (including manual "+ New order"), Customers and Stock;
- stock reservation, unpaid timeout, invoice PDF, order emails, the Waitlist→Shop mode switch and waitlist gift redemption.
Test a full purchase end-to-end with a mocked Mollie, run all tests, rebuild the zip, update DEPLOY-GODADDY.md and CHANGELOG.md.
```

## Phase 3 — Delivery and courier mode

```
Continue with Phase 3 from CLAUDE.md §11:
- zones, slot template and capacity, cut-off;
- route building (nearest neighbour + 2-opt) with the Google Maps link and drag-reorder;
- courier role and courier mode, customer notifications, failed delivery and reschedule.
Seed 10 test orders across Groningen postcodes, check the route makes sense, run all tests and rebuild the zip.
```

## Phase 4 — Money and marketing

```
Continue with Phase 4 from CLAUDE.md §11:
- expenses, profit, BTW quarter summary, exports;
- discount codes, referrals, abandoned cart, back-in-stock, broadcasts;
- suppliers and purchase orders, reorder hints, returns and refunds;
- the real "live activity" pill in the shop.
Check that the numbers reconcile, run all tests and rebuild the zip.
```

## Useful one-liners at any time

- `Run the full test suite and fix anything that fails.`
- `Compare every public page and admin tab with the prototypes at 390px wide and fix any visual differences.`
- `Do a security review against CLAUDE.md §9 and fix what you find.`
- `Rebuild dist/drop01-deploy.zip and tell me what changed since the last zip.`
- `My real details are in OWNER_INPUT.md — apply them everywhere and remove the demo data.`

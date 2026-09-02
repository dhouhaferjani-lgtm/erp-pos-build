# Handover — inventory counting A to Z (web · POS · mobile), for Dhouha — 2026-09-02

This is a focused wave on ONE flow: **inventory counting**, in both modes — **sales blocked** and **live counting (sales continue)** — across the web dashboard, the IziPOS desktop till, and the mobile app. It follows the working discipline in `README-DHOUHA-START-HERE-2026-09-02.md` and the bug-report shape in `docs/qa/MANUAL-TESTING-LOOP.md §3` (one report per bug, every field filled).

Read first (10 minutes):
1. `docs/superpowers/reviews/2026-09-02-inventory-counting-cross-layer-evidence.md` — what was verified on the API today, and the findings register (what is already known — do not re-report those).
2. `docs/handoff/CODEX-mobile-inventory-alignment-2026-09-02.md` — the mobile fixes Codex is doing; several mobile scenarios below are blocked until that lane lands.
3. `docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md` §3–§4 — the intended rules (block vs zone advisory, basket window).

## 0. How the feature is meant to work (the mental model you test against)
- A counting is created on the **web** (Inventory → Counting → Create). Only the web can tick **"Block sales during this count"**; mobile-created drafts are always live counts.
- **Sales blocked** = the POS tills at the counted location(s) refuse to add items to the cart (toast "Sales are paused while inventory count CNT-… is in progress"). The block lasts from activation **through review** and ends only at finalize or cancel. The till learns about it by polling every 60 s (up to 5 min if it is backing off), so allow up to a minute. The block is enforced on the till only: a cart that was already open when the block started can still be tendered; the server accepts that sale and flags it on the counting ("late sale") — this is by design, and the review page shows the banner.
- **Live counting** = sales continue; the system replays movements after the count instant so a sale that happened after you counted does not become a false variance. The "ambiguity window (minutes)" is the tolerance around the count instant.
- **Zone counts** (a shelf/aisle) can never block sales; tills show a soft warning "Zone X is being counted — sales continue".
- The counter (mobile or web) is **blind**: never sees the expected quantity. Once the last item is counted the counting moves to **pending review** and disappears from the counter's task list (expected). A reviewer on the web resolves variances (override with a mandatory note, or 3rd count), then **finalizes**. If any till at the location has not reported its sync status recently, finalize asks the reviewer to acknowledge the sync risk — that is expected on a fresh tenant; tick it.
- Finalize posts one `adjustment` stock movement per line with a variance (report shows "applied" / "not applied" with a reason). Where did it land? Stock level of the product at that location; and, if the country default has count-correction GL posting on, a journal entry.

## 1. Environment
- **Staging** web `https://erp.otospex.dev`, API `https://api.erp.otospex.dev`. Lane N-1 (this handover's ERP fixes) must be on staging before you start — check that the counting detail page shows a "Sales during count" row; if it does not, the deploy has not landed yet.
- **Fresh tenant** per `MANUAL-TESTING-LOOP.md §2`. Then: enable the Inventory module; import the cleaned products file (`856 / 3` with units mapped — see the start-here note) or receive a purchase order so at least 10 products have stock at the main shop; create a **second location** (shop 2) and give 3 products stock there too.
- **Users**: admin (you), `counter` with role manager (mobile drafts require manager/admin; counting needs `inventory.view` + `inventory.adjust` to create, only assignment to count), `cashier` (POS only).
- **POS**: current staging IziPOS Tauri build, one terminal claimed at shop 1 (and ideally a second at shop 2). Open a shift with a float before the counting scenarios.
- **Mobile**: Expo Go on a phone, repo `erp-mobile` (Codex branch once it lands; `main` today), `.env` `EXPO_PUBLIC_API_URL=https://api.erp.otospex.dev/api/v1`, log in as `counter`. If you test against a laptop API instead, use the LAN recipe in the Codex handover §3.
- Evidence per scenario: screenshots (web page, POS toast, phone screen) + the counting number + the stock level before/after + the movement line. Commit them under `docs/superpowers/reviews/2026-09-02-inventory-counting-*` as in previous waves.

## 2. Scenario matrix (run in this order)

### S1 — Sales-blocked count, the full A-to-Z (P0)
1. Web: create a counting — scope **Specific products at location**, 3 non-batch products at shop 1, tick **Block sales**, ambiguity window 15, count 2 = off, counter = `counter`. Expected: the review step shows "Block sales: Yes" and the window; after creation the detail page shows "Sales during count: Blocked" · ±15 min window.
2. Activate. Expected: status "Count 1 in progress".
3. POS (shop 1) within ~60 s: scan/add one of the counted products → toast "Sales are paused while inventory count CNT-… is in progress"; add a product NOT in the count → also refused (the block is per location, not per product). Take the screenshot. Shop 2 till keeps selling.
4. POS edge: before step 2, leave a cart open with one item; after the block, tender it → the sale goes through. Expected on the web review page later: banner "1 late sale recorded during the count".
5. Mobile as `counter`: Tasks shows the counting with progress 0/3 and the red banner "Ventes suspendues". Count item 1 by camera scan, item 2 by manual barcode entry, item 3 by tapping the row; use a quantity that differs from stock for one item (e.g. stock 14 → count 12) and a decimal-free value for the others. Expected: each submit returns to the list with a tick; after the third the task disappears from the list (pending review — expected, not a bug).
6. Web review page: status pending review; the variance line needs attention; the late-sale banner from step 4; "POS sync risk detected" with the acknowledgement checkbox if the till has not reported. Override the variance with a note (a note shorter than 10 characters must be refused). Finalize.
7. Where did it land: product stock at shop 1 = counted value; Stock movements shows `adjustment −2` referencing the counting; the discrepancy report shows items applied = 1, late sales corrections = the step-4 sale if it touched a counted product.
8. POS within ~60 s: selling resumes without restart.
Also try: cancel a blocked count instead of finalizing → the till resumes; activate a second blocking count on the same location while one is running → refused (overlap guard).

### S2 — Live count with a sale during the count (P0)
1. Web: same scope, **Block sales off**, window 15. Detail page shows "Sales during count: Live (±15 min window)".
2. Mobile: banner "Comptage en direct… ±15 min". Count product A exactly equal to stock (e.g. 14).
3. POS: sell 2 units of product A **after** the count was submitted.
4. Mobile: count the remaining items. Web review: product A shows expected-now 12, movements since count −2, adjustment 0, no flag; finalize.
5. Where did it land: stock = 12 (12 = 14 − 2, NOT corrected back to 14); no adjustment movement for A.
Variant: sell 2 units of product B **before** counting it, then count what is physically there → no variance either.

### S3 — Zone count, advisory only (P1)
1. Web: Inventory → Placements: create a zone node under shop 1 and place 2 products in it. Create a counting with scope **Zone**: the "Block sales" checkbox is disabled with the hint text (it is also disabled for the Product and Category scopes — a block only makes sense for a location-covering scope: Location, Full inventory, Specific products at location; the API refuses it otherwise); review step shows "Block sales: No".
2. POS: adding one of those products shows the soft warning "Zone … is being counted — sales continue" once, and the sale completes.
3. Mobile: the session header shows the zone name (blocked until Codex M-1 lands — before that, the label is empty; do not report).

### S4 — Mobile-created draft (P1, after Codex lane)
1. Mobile: Drafts → create a draft with scope **Specific products at location** — choose shop 1 — scan 3 barcodes, assign counter, sync, activate from the phone.
2. Web: the counting appears with the right scope and location; the counter-view has items ONLY at shop 1 (this is the N-1 fix; before it a draft without a location counted every location).
3. Also: a draft with scope Zone using the zone from S3; a draft that activates with zero products must be refused with a readable message ("At least one product must be added before activation").

### S5 — Offline mobile queue (P1)
1. During an active count put the phone in airplane mode, count 2 items → both stay "en attente"; reconnect → they sync within 30 s and the web progress moves.
2. Count an item offline, then have the web reviewer cancel the counting, then reconnect → the row parks in "Erreurs de synchronisation" with the server message. Today there is no "discard all for this counting" button (Codex M-4) — report only if it behaves worse than that.

### S6 — Receiving on mobile (P1)
1. Web: create + confirm a purchase order for 2 products (one batch-tracked) at shop 1.
2. Mobile: Réception → the PO → partial receipt (line 1: 5 of 10; line 2: with batch number + expiry) → "Réception partielle" 50 %. Web: the PO shows partially received, stock +5 and a lot with that expiry. Receive the rest → "Réception complète".
3. Barcode scan on the receiving screen is broken today (Codex M-6) — use manual line entry; do not report the scan.

### S7 — Permissions and second-of-everything (P1)
- `cashier` cannot open Inventory → Counting create (route guard) and gets 403 on the API; `counter` can count but cannot finalize.
- Repeat S1 steps 1–3 on **shop 2** with the shop-2 till; the shop-1 till must keep selling.
- Second company (if the tenant has one): a counting in company A must not appear in company B's list and must not block company B's till.

### S8 — Report page and history (P2)
Discrepancy report after S1/S2: expected/counted/variance/applied columns, "Late Sales Corrections" card, and — if you tender an offline POS sale that was authored before finalize but syncs after — the red "Sales arrived after this count was finalized" block.

## 3. Known, do not report (already filed)
- The counting disappears from the counter's list at pending review; mobile shows no "en vérification" state (M-4).
- Mobile: zone picker empty / zone label empty (M-1), `warehouse` scope in the picker (M-2), receiving barcode scan (M-6), Android manual barcode entry does nothing (M-7), raw "Request failed with status code 4xx" text on draft screens (M-5).
- POS: the block gates add-to-cart only, not checkout; no persistent banner, toast only; up to 60 s lag.
- Web: the create wizard has no "include zero-stock products" option (it follows the location's onboarding mode).
- API: `idempotency_key` from mobile is ignored (harmless).

## 4. What to report
Everything else that deviates from the expected outcomes above, with the bug-report shape from `docs/qa/MANUAL-TESTING-LOOP.md §3`, including: any 5xx, any console error in the web app, any stock or GL number that does not agree with the movement line, any counting that a till does not honour within 5 minutes, any late sale that does not appear on the review page.

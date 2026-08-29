# Staging tester checklist — 2026-08-30 (45 min, one tester)

**Staging build: `0cbaa1457` (+ batch 1b if listed below)** on `erp.otospex.dev`. Follow `docs/qa/MANUAL-TESTING-LOOP.md` §2 (fresh tenant, second company + second location first). Report shape: §4 of that doc — one line per step below: **PASS / FAIL + what you saw** (screenshot for any FAIL). Send to the orchestrator (Session J).

Legend: ☐ = tick when done · ⚠ = known, do not report (owner-ruled or owned by a lane).

## A. F-BUG-1 retest — company switching & imports (≈15 min)

Use a **fresh tenant** (`/register`). Then:

1. ☐ **Create a second company** (header → Select company → Add Company → 4-step wizard). Expected: after "Create Company" you land on the dashboard **already in the new company** (header shows its name).
2. ☐ **Switch company** in the header → **refresh the page** (F5). Expected: still on the company you picked; header unchanged; no red "Access denied" widget noise.
3. ☐ Switch back to company 1 → refresh → still company 1.
4. ☐ In company 2 (the one created in step 1): Settings → Locations. Expected: one "Main Location" with code **MAIN**.
5. ☐ **Products import with quantities** (company 2): Imports → Products → upload the tutorial products file (rows with `quantity` + `purchase_price`; leave `location_code` empty, or delete the column). Expected in the wizard:
   - an **Options** step with a **"Stock location"** select, **MAIN** preselected;
   - after execution, the completion screen shows Imported / Failed **and**, if any row was skipped, a yellow **warnings** panel naming why (e.g. "no stock was created…").
   - Inventory → Stock levels (scope = Main Location): the imported quantities are there.
6. ☐ Re-import the same file with one row's `location_code` set to a **shelf code** (e.g. `ZF12`) on purpose. Expected: that row appears in the warnings panel as "location unresolved — no stock created"; the others still land in MAIN. ⚠ A *validation-time* refusal of unknown location codes is Session G's — don't report it as missing.
7. ☐ Products import into company 2 with the **same SKUs** you imported into company 1 earlier. Expected: it **succeeds** (per-company SKU uniqueness, live since 3ac0de893). Tenant `019fc714` (Paradeals / synerivia) is the canonical case if you'd rather use it: import `model produits.xlsx` into **synerivia** — expected 0 duplicate-key failures. (The 3 rows with `quantity = -1` still fail validation — correct.)

## B. Units gate (≈5 min)

8. ☐ In the fresh tenant, Settings → Units **before touching anything**: expected 19 units visible (global set). ⚠ Imported products carry `unit` as free text and no `unit_id` — quantity-display oddities on imported products = known, lane R11.
9. ☐ Only if you can make a company with **no units** (deactivate all in Settings → Units): a products upload must be refused with HTTP 422 `units_not_seeded` — today surfaced as a **generic** error toast (⚠ friendly message is lane G-1; report only if the upload *succeeds*).

## C. Platform push gate — nothing must reach the platform (≈10 min)

Staging runs `SYNERIVA_PLATFORM_PUSH_ENABLED=false` (since 1326a96c1). With a logged-in user:

10. ☐ Purchase Hub → open a campaign → place an order. Expected: the UI shows an order **failure** (API returns **HTTP 502** `{ "error": { "code": "ORDER_FAILED" } }`), and **no order appears** in Purchase Hub → Orders afterwards (refresh the list). If you can, capture the network response of `POST /api/v1/purchase-hub/orders`.
11. ☐ Products → open a product → **Submit for enrichment**. Expected: the action **completes without an error** in the UI, and nothing changes on the platform side (the orchestrator will confirm platform-side; you just confirm no UI error and no enrichment result arriving within the session).

## D. Session G / H lane retests — **APPLY: 12 (G-3b), 15 (G-3c), 16 (H cleanup) — batch 1a `0cbaa1457`. SKIP 13 (G-6a) and 14 (G-4) unless Session J confirms batch 2 landed overnight.**

12. ☐ **G-3b** ✅ in build (import job pinned to its company + Composite Items entitlement): (a) create a 2nd company → products import into it succeeds end-to-end; (b) start an import in company A, switch company, try to execute → **409 "company mismatch"**; (c) import history lists only the current company's jobs; pre-existing jobs show an **"unattributed"** badge; (d) a company WITHOUT the Composite Items module: composite tile / template / upload → **403**, and wizard status/order never mention composite items.
13. ☐ **G-6a** ⏸ NOT in build unless confirmed (atomic claim / reaper / purge / cancel): (a) double-click "start import" → second attempt **409 already-started**, exactly one run; (b) DELETE a pending/validated job → gone; DELETE a completed job → **409 "has effects"**; (c) source-file download works on a fresh job; (d) informational, no UI: a job stuck "importing" > 90 min becomes failed **worker_lost**.
14. ☐ **G-4** ⏸ NOT in build unless confirmed (duplicate preview + resolvers): (a) re-import the same products file → preview shows **"N existing / M new"** once, with override / skip / cancel; **override** updates non-blank cells and NEVER erases a value from a blank cell; **skip** changes nothing; (b) `unit` column: exact code (`pc`, `kg`) resolves; a misspelling → row error listing the accepted codes; blank → `pc` with a warning; (c) two rows with the same name and no SKU/barcode → **one** product; (d) completion counts imported / skipped / failed match the file.
15. ☐ **G-3c** (IN batch 1): create a 2nd company → Treasury shows a **cash register + safe at MAIN** (payment repositories provisioned) → open a POS shift with cash on it.
16. ☐ **H h1-shape-neutral-cleanup** (IN batch 1 — `8d629ac89`): (a) customers / suppliers lists — the Tax ID column shows the VAT number instead of "-"; (b) inline "Add partner" from a quote — VAT + address persist on the created partner; (c) creating a customer from `/sales/customers/new` — Type offers only Customer / Both, and the record no longer vanishes from the list (suppliers likewise); (d) Nature (Particulier / Société) is required on the new-partner form; a legacy company with a VAT shows its B2B block; (e) Otospex: the vehicle-owner picker searches customers only; (f) `/crm/companies` redirects to `/sales/customers`; "Companies" is gone from the sidebar.

## E. Always-on (from the loop, 10 min)

17. ☐ Parties import with opening balances → open a customer → balance correct. ⚠ Same `vat_number` in two companies is allowed now (3ac0de893); a *duplicate within one company* must still be refused.
18. ☐ Re-run A5 and E17 with the same files → nothing doubles; the wizard says "skipped".

Known & owned — do **not** re-report: shelf codes accepted in `location_code` at validation time (G); `unit_id` NULL on imported products (R11); generic toast on `units_not_seeded` (G-1); empty warning list under a warnings heading right after a very fast import (F1 P3-R9, cosmetic); **second parties-with-balances import after the first AR/AP batch is posted → partners created but balances dropped with warning `balance_not_posted`** (I-1 finding, routed to G as G-14) — **workaround for tomorrow: put ALL opening balances (customers + suppliers) in ONE parties file per company.** Partner form: the **Tax Status / exemption block is hidden** (never persisted; Phase 2 wires it) and the **quick-create modal has no Nature field yet** (Phase 2).

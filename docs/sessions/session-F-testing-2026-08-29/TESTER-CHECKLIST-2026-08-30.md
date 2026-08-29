# Staging tester checklist — 2026-08-30 (45 min, one tester)

Build under test: `origin/dev` ≥ `1326a96c1` on `erp.otospex.dev`. Follow `docs/qa/MANUAL-TESTING-LOOP.md` §2 (fresh tenant, second company + second location first). Report shape: §4 of that doc — one line per step below: **PASS / FAIL + what you saw** (screenshot for any FAIL). Send to the orchestrator (Session J).

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

## D. Session G lane retests (lines forwarded by Session J — fill in on the day)

12. ☐ G-3b — *(line to come: import job pinned to the company it was started in; CompositeItems entitlement)*
13. ☐ G-6a — *(line to come: import execute claim/reaper/purge)*
14. ☐ G-4 — *(line to come: duplicate preview / "newer overrides" policy)*

## E. Always-on (from the loop, 10 min)

15. ☐ Parties import with opening balances → open a customer → balance correct. ⚠ Same `vat_number` in two companies is allowed now (3ac0de893); a *duplicate within one company* must still be refused.
16. ☐ Re-run A5 and E15 with the same files → nothing doubles; the wizard says "skipped".

Known & owned — do **not** re-report: shelf codes accepted in `location_code` at validation time (G); `unit_id` NULL on imported products (R11); generic toast on `units_not_seeded` (G-1); empty warning list under a warnings heading right after a very fast import (F1 P3-R9, cosmetic).

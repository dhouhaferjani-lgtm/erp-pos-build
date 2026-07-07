# Morning Brief — Live Inventory Counting (2026-07-07)

**Branch:** `feat/live-inventory-counting` (pushed, 44 commits, head `54a903f8e`) — worktree `apps/erp.live-counting`. NOT merged to dev; test first, then promote.

## What shipped overnight

The full spec (`docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`, Codex-hardened):

1. **Zones** — `location_zones` + `product_zone_assignments`, CRUD API + Settings→Locations UI, bulk assign, assign-as-you-count (first count of a product in a single-zone session auto-assigns it).
2. **`occurred_at` event time** on every stock-movement writer (POS device time, GRN/adjust business time), batched backfill, concurrent replay index.
3. **Timestamp-replay finalize** — `expected_now = counted + Σ signed deltas after count instant`; correct with sales during the count, offline devices, multi-day counts. Idempotent under queue retries (gate-verified). Guards: basket window (±configurable min), negative-at-apply, clock skew (>5 min), overlap between counts (with cancel escape hatch from pending_review).
4. **Blocking vs live mode** per count session — blocking enforced at the POS device via `stockGate`, late signed sales accepted + flagged + replay-corrected (fiscal invariants untouched, gate-verified); zone counts get advisory toasts, never hard blocks.
5. **Onboarding mode per location** — sell-below-zero allowed, first count posts as OPENING with cost (WAC basis set/blended correctly), pre-finalize cost gate (finalize refuses cost-less openings until backfilled on the review page), auto-exit on a qualifying full count, negative-stock worklist endpoint.
6. **Web**: wizard zone scope + block toggle + ambiguity window; review page with Expected-now / movements-since-count columns, flag chips, cost backfill, late-sales banner, unsynced-device warning in finalize dialog; location settings onboarding toggle + policy override. String quantities per precision contract.
7. **Mobile handover**: `docs/handoff/HANDOVER-live-counting-mobile.md` (API deltas verified against code; erp-mobile work items per screen; backward compatible — current mobile build keeps working, just without skew correction).

## Quality gates (all cleared)

- 16 tasks × implementer + independent reviewer; every Important/Critical finding fixed and re-reviewed.
- Domain gates: **inventory-costing** BLOCK→APPROVED (found the queue-retry double-apply; fixed `f6ec7b910` + atomic marker), **fiscal-pos** APPROVE-WITH-NOTES (invariants verified; advisory flag can't fail projection), **tenancy-authz** APPROVE-WITH-NOTES.
- Final whole-branch review found the pre-existing `parseInt`-on-uuid bug that would have killed the review page in the browser — fixed (`54a903f8e`).
- Preflight: PHPStan clean (1 pre-existing dev error untouched), Pint clean, web lint/typecheck/tanstack-gate clean, E2E scenario test green (`LiveCountingScenarioTest` proves the exact spec arithmetic: sell 2 → count 20 @ T → sell 3 → finalize ⇒ on-hand 17, opening +22, WAC set).

## Your test script (local stack)

**Setup:** `cd apps/api && php artisan tenants:migrate` (4 new tenant migrations). No permission reseed/cache-reset needed (no new permission keys). **Queue worker must be running** — finalize posts via a queued listener; without it stock never changes (most likely false alarm). POS picks up blocks/policy on its next terminal poll (~1 sync cycle).

1. Settings → Locations → **Zones** on the shop → create "Aisle 1"/A1 → bulk-assign 2-3 products.
2. Edit location → **Onboarding mode ON** → save.
3. POS (after a refresh cycle): **sell 2 of product P** — goes through despite zero stock; `stock_levels` shows −2.
4. Wizard → scope **Zone** → pick A1 → note block-toggle disabled → ambiguity window 0 (or keep 15 to see the basket flag when counting right after a sale) → create → activate.
5. **Count P = 20**. 6. **Sell 3 more** on POS. 
7. **Review page**: Expected now = 17, Movements since count = −3; if P has no cost, finalize is blocked until you enter one in the cost column.
8. **Finalize** → wait for queue → on-hand = **17**, `opening` movement +22 with your cost, WAC = cost.
9. **Blocking count** (location scope, Block sales ON) → POS refuses adds incl. during pending_review → cancel/finalize resumes selling.
10. **Auto-exit**: full-location count (includes zero-stock) finalize → onboarding toggle off.
11. **Overlap**: second count over same products → 422; cancel from pending_review works.

## Known cuts & follow-up tickets (full list in `.superpowers/sdd/progress.md`)

- **Cuts (documented):** import `zone` column; persistent zone banner (toast only); worklist API has no UI yet; mobile app changes (handover doc instead — owner pushes erp-mobile).
- **Top follow-ups:** unsynced-device status API (finalize dialog has static warning only); advisory lock on count activation (race is recoverable via cancel but exists); `COUNT_REPLAY` movement reference should embed counting number; multi-location full-count cartesian needs chunking; legacy counter routes lack `whereUuid`; `floatsEqual` float-casts (pre-existing rule-19 debt).

## Deploy notes (when promoting later)

`tenants:migrate` BEFORE code serves traffic (additive migrations, batched backfill — safe); no permission changes; no new Horizon queues (existing default queue).

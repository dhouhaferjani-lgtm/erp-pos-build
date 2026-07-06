# Live Inventory Counting — Count Without Stopping Sales

**Date:** 2026-07-06
**Status:** Approved design (owner-reviewed)
**Branch:** `feat/live-inventory-counting`
**Repos touched:** `apps/erp` (api + web), `erp-mobile` (separate repo, ships separately)

## Problem

A new client (parapharmacy) cannot close for 1–2 days to run a stocktake before going live. They must start selling from day one, with unrecorded stock, and count progressively while the shop trades — ending with correct on-hand quantities per location. The same machinery must also serve routine counts during trading hours for existing clients.

## Current state (audited 2026-07-06)

A complete blind counting subsystem already exists:

- **Backend** (`apps/api/app/Modules/Inventory/`): `InventoryCounting` sessions with scopes (`product_location`, `product`, `location`, `category`, `full_inventory`), up to 3 blind counters, `CountingReconciliationService` (variance thresholds 2/5/10%, auto-resolution, third-count, manual override), hash-chained audit events, counter metrics, fraud-triggered counts. Finalize dispatches `ApplyStockAdjustmentsOnCountingCompleted`, which applies `delta = final_qty − theoretical_qty` on top of **current** stock via `StockAdjustmentService::adjust()` (reason `count_correction`).
- **Web** (`apps/web/src/features/inventory-counting/`): dashboard, list, 5-step create wizard, detail, reconciliation review, third-count trigger, manual override, discrepancy report (PDF/xlsx), finalize.
- **Mobile** (`erp-mobile/`, sibling repo): blind count sessions, camera barcode scanning, drafts with counter assignment, offline queue + background sync (30s / foreground / network-restore), batch sync endpoints.

**Gaps this design fills:**

1. **Correctness during trading.** `theoretical_qty` is frozen at count creation; the delta-on-current application is only correct if the shelf is counted right after creation. For a multi-day progressive count, a sale occurring after creation but *before* the shelf is counted is double-deducted (once by POS, once by the variance).
2. **No event time on movements.** `stock_movements` has only `created_at` (processing time). POS projections stamp movements at projection time, and offline devices sync late — no reliable "when the sale physically happened."
3. **No shelf/zone structure.** Only free-text `products.shelf_location`. No zone-scoped counting.
4. **Stock policy is company-wide.** `companies.pos_stock_policy` (block/warn/off) has no per-location override; no onboarding state.
5. **No opening-balance semantics for first counts.** A first count posts as shrinkage variance against zero, with no cost capture.

## Industry grounding (researched 2026-07-06)

- SAP (freeze book inventory), NetSuite (snapshot + Recalculate Snapshot), D365 (counting-date on-hand + pending review) all use snapshot-at-start variance. Retail POSes (Lightspeed, Square, Shopify, Odoo, Erply) do **not** reconcile interim sales — they warn and tell the user to recount manually.
- The fully correct model for long-running counts during trading is **per-line count timestamps + movement replay**: `expected_now = counted_qty − outflows_after(T) + inflows_after(T)`; `adjustment = expected_now − on_hand_now`. No retail-tier system automates this — it is a genuine differentiator.
- Known residual: the **basket problem** (item taken off the shelf just before counting, sale rung up after) — mitigated by flagging lines with movements near the count timestamp for recount.
- Go-live-without-counting is enabled by permitting negative stock during an onboarding window and treating the first count of a SKU as an **opening balance set** (absolute, with cost), not a variance.

## Decisions (owner-approved 2026-07-06)

| Decision | Choice |
|---|---|
| Sub-location model | **Zones for counting/placement only** — stock stays at `(product, location)` grain; no bin dimension on movements |
| Reconciliation | **Timestamp replay at apply**, using new `occurred_at` on movements |
| Count session modes | **Blocking** (sales rejected at scoped locations) or **live** (replay reconciles) — per-session toggle |
| Onboarding | **First-class per-location onboarding mode** (negative sales allowed, first count = opening balance + cost) |
| Opening cost source | **`products.cost_price` + review-page backfill** (bulk-editable; mobile never asks for cost) |
| Zone mapping | **Assign-as-you-count AND bulk-assign UI AND import column**, all in v1 |

## Design

### 1. Zone management

**`location_zones`**: `id` uuid PK, `location_id` FK, `name`, `code`, `sort_order`, `is_active`, timestamps. Unique `(location_id, code)`. CRUD in web Settings → Locations. Zones are labels for count scoping and product placement; movements and stock levels never reference zones.

**`product_zone_assignments`**: `(product_id, location_id, zone_id)`, unique on `(product_id, location_id)` — one zone per product per location in v1 (many-to-many, e.g. promo endcaps, is a later extension).

Population paths (all v1):
- **Assign-as-you-count**: scanning a product in a zone-scoped count session upserts its assignment to that zone at that location. The first walk-through count builds the zone map.
- **Bulk assign**: zone detail page, multi-select products → assign.
- **Import**: `zone` column on product import (creates/matches zone by name per target location).
- Existing `products.shelf_location` free text is surfaced as a **suggestion** when creating zones (distinct values listed), never auto-trusted.

Counting integration: `CountingScopeType` gains `zone`; `scope_filters` carries `zone_ids`. Item generation pulls assigned products; `allow_unexpected_items` (existing) lets counters scan unassigned products in, which also zone-assigns them.

### 2. `occurred_at` on stock movements (foundation)

- Nullable `timestamptz occurred_at` on `stock_movements`; index `(product_id, location_id, occurred_at)`. Backfill existing rows from `created_at`; all replay queries use `COALESCE(occurred_at, created_at)`.
- Writers: POS projection (`PosCoreReceiptProjection::decrementStock`) and the synchronous `ReceiptCreationService` path set `occurred_at` from the **device-authored receipt/fiscal-event time**, not processing time. GRN, transfers, and manual adjustments set their business timestamp (defaults to now).
- Count-line timestamps: online submissions are server-stamped. Offline-queued submissions carry the device timestamp; each batch sync request also carries the device's current clock so the server computes skew, corrects queued timestamps, and flags lines whose corrected skew exceeds 5 minutes.

### 3. Count session modes: blocking vs live

New `inventory_countings.block_sales` boolean (default false), chosen at creation.

- **Blocking**: while the count is active (activated → finalized/cancelled), POS sale creation for scoped location(s) is rejected server-side with an explicit error; POS devices receive the block flag via config/heartbeat sync and refuse locally with a banner. Residual: a device already offline before the block can still sell; those sales sync later and are corrected by replay (replay runs in **both** modes — blocking just shrinks the reconciliation surface to ~zero).
- **Live**: sales proceed; correctness comes from replay (§4).
- Zone-scoped counts never hard-block: POS shows a **soft advisory banner** ("Zone X being counted") only, since sales don't declare shelves.

### 4. Timestamp-replay reconciliation (core)

At finalize, per item, with `T = final_qty_as_of` (new column on `inventory_counting_items`; set to the timestamp of the count that produced `final_qty` — for manual overrides, the timestamp of the count the override seeded from, else the review time):

```
expected_now = final_qty − outflows(T → now] + inflows(T → now]        # by COALESCE(occurred_at, created_at)
adjustment   = expected_now − on_hand_now
```

Posted through the existing `StockAdjustmentService` path with reason `count_correction` (or as an opening movement, §5). Replaces the current `final_qty − theoretical_qty` delta in `ApplyStockAdjustmentsOnCountingCompleted`. Correct for multi-day counts: sales before `T` are already reflected in the counted shelf quantity and excluded from replay; sales after `T` replay on top. Late-syncing sales carry their true `occurred_at`, so they land on the correct side of `T` as long as they sync before finalize.

**Multi-counter normalization**: `CountingReconciliationService` currently compares raw `count_1/2/3` values — invalid during trading when counts happen hours apart. Before comparison, each count is normalized to a common instant via the same replay (e.g. `count_1` at `T1` projected forward by movements in `(T1 → T2]` before comparing to `count_2`). Blind semantics are untouched; normalization is server-side only. Epsilon comparison (existing 0.0001) applies to normalized values.

**Guards:**

- **Basket window**: a line with any movement whose `occurred_at` is within ±N minutes of `T` (session setting `ambiguity_window_minutes`, default 15) is flagged for recount rather than auto-applied. This is the one ambiguity timestamps cannot resolve (item in a customer's basket while the shelf is counted).
- **Negative-at-apply**: outside onboarding mode, an adjustment that would drive on-hand below zero flags the line into review instead of posting.
- **Unsynced-device warning**: the review page warns when POS devices at the scoped location report pending unsynced receipts; finalize requires acknowledging the warning. Movements arriving after finalize remain a residual in every model (industry-wide) and surface in the next count.

### 5. Onboarding mode per location

New per-location fields: `locations.onboarding_mode` (bool, default false) and `locations.pos_stock_policy_override` (nullable enum block/warn/off; when set, wins over `companies.pos_stock_policy` — useful beyond onboarding).

While onboarding mode is active at a location:

- Effective stock policy is `off` — sales below zero allowed; negative on-hand is expected, and the **negative-stock report doubles as the "count these next" worklist**.
- **First count of a product** at that location — defined as: no prior `opening` or `receipt` movement for `(product, location)` — posts the replay-computed adjustment as `MovementType::Opening` with `unit_cost`, i.e. an opening balance, not shrinkage. Subsequent counts post normal `count_correction`. (A product received via GRN mid-onboarding and then counted is a normal `count_correction`.)
- **Cost**: taken from `products.cost_price` when set. The web review page flags cost-less opening lines with a bulk-editable cost column; finalize requires each flagged line to have a cost entered or explicitly zeroed. Mobile never asks for cost. When prior on-hand ≤ 0, the opening sets the absolute WAC basis for the product; otherwise it blends through the existing WAC seam (`WeightedAverageCostService`).
- Mode ends **manually** (location settings toggle) or **automatically** when a `full_inventory`/whole-location-scoped count at that location finalizes. Effective policy then reverts to the override/company setting.
- **Known limitation (v1)**: sales during the window carry COGS at whatever `cost_price` existed at sale time (possibly zero). No retroactive COGS restatement — fiscal receipts are immutable and GL go-live is separate work (`project_accounting_gl_roadmap`).

### 6. Surfaces

**Web** (`apps/web`):
- Settings → Locations: zone CRUD per location; onboarding-mode toggle; `pos_stock_policy_override` selector.
- Zone detail: bulk product assignment (multi-select).
- Product import: `zone` column.
- Counting create wizard: zone scope step; `block_sales` toggle; `ambiguity_window_minutes`.
- Review page: replay columns (movements-after-count, expected-now), basket/negative/skew flags, opening-cost backfill column, unsynced-device warning.

**Mobile** (`erp-mobile`, separate repo/release):
- Draft creation: zone picker (per selected location).
- Session header shows zone; scanning assigns zone (via existing unexpected-item path + new assignment side-effect server-side — no mobile logic needed beyond display).
- Blocking-mode and zone-advisory banners.
- Offline count submissions: include device clock in batch sync payloads (skew correction is server-side).

**POS** (`apps/pos` / web POS):
- Resolve effective stock policy per location (override → company).
- Hard block + banner during blocking counts; soft advisory for zone counts.

**Permissions**: existing `inventory.view` / `inventory.adjust` gate counting as today; onboarding toggle and policy override sit behind the existing company/location settings permission. No new permission keys.

### 7. Data changes summary

| Change | Table |
|---|---|
| New | `location_zones` |
| New | `product_zone_assignments` |
| Add `occurred_at` + index, backfill | `stock_movements` |
| Add `block_sales`, `ambiguity_window_minutes` | `inventory_countings` |
| Add `final_qty_as_of`, replay/flag fields (`expected_qty_at_apply`, `replayed_out_qty`, `replayed_in_qty`, flag reasons) | `inventory_counting_items` |
| Add `onboarding_mode`, `pos_stock_policy_override` | `locations` |
| Add `zone` case | `CountingScopeType` enum |

All quantity math via `QuantityScale`/bcmath at scale 4 (precision contract); no floats. New enums for flag reasons. DTOs for all new JSONB/payload shapes; `php artisan typescript:transform` after DTO changes.

### 8. Testing (TDD)

Backend PHPUnit (run by path):
- Table-driven replay cases: sold before/after `T`; late-syncing sale on each side of `T`; receipt during count; basket-window flag; negative-at-apply flag; multi-counter normalization (counts hours apart with interleaved sales); onboarding first-count → opening vs recount → correction; opening cost basis with negative prior on-hand.
- Policy resolution matrix: company × location override × onboarding mode.
- Blocking enforcement: sale rejected at scoped location, allowed elsewhere; block lifts at finalize/cancel.
- Zone assignment: assign-as-you-count upsert; import column; uniqueness per (product, location).
- Projection tests clear `CompanyContext` before `apply()` (rule 20); explicit currency to scale resolution in queued contexts (rule 19).

Web vitest: review-page flag rendering, cost backfill validation, zone CRUD/bulk-assign. E2E critical path (local stack): create zone count → sell mid-count (before and after counting the item) → finalize → assert on-hand equals hand-computed truth.

### 9. Out of scope (v1)

- Bin-level stock (movements/stock_levels never carry zones)
- Many-to-many product↔zone
- Retroactive COGS restatement for onboarding-window sales
- GL postings for count corrections/openings (GL roadmap owns this)
- Hard zone-level sale locks
- Meilisearch zone indexing
- Mobile standalone stock-lookup screen (separate backlog item)

### 10. Rollout notes

- `occurred_at` migration is additive and backfilled — safe on existing tenants (`tenants:migrate`).
- Replay changes activate only for counts created after deploy (existing in-flight counts finalize with the legacy delta path, keyed on presence of `final_qty_as_of`).
- Mobile app changes ship on the `erp-mobile` release train; server changes are backward-compatible with older mobile builds (missing device-clock field ⇒ no skew correction, submissions server-stamped as today).

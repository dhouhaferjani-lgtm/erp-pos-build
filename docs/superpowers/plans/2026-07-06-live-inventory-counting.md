# Live Inventory Counting Implementation Plan (v2 — post Codex plan review)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Count inventory (by location or zone/shelf) while sales continue, with timestamp-replay reconciliation, per-session sales blocking, and a per-location onboarding mode (sell-before-count, first count = opening balance).

**Architecture:** Extends the existing blind-counting module (`apps/api/app/Modules/Inventory/`). New: `location_zones` + `product_zone_assignments` (labels only — stock stays at `(product, location[, variant])` grain), `occurred_at` event time on `stock_movements`, a `MovementReplayService` summing signed per-row deltas, a replay-based finalize path inside `StockAdjustmentService`'s existing lock discipline, per-location stock-policy resolution, and web UI. Spec: [`docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`](../specs/2026-07-06-live-inventory-counting-design.md) (§4/§5 math normative). Reviews: spec review + THIS PLAN's review with dispositions live in `docs/superpowers/specs/reviews/`.

**Tech Stack:** Laravel 12 / PHP 8.2 strict / PostgreSQL (db-per-tenant), React 19 + TS strict + TanStack Query 5, bcmath via `InventoryScale::QUANTITY_SCALE`.

## Global Constraints

- **Workspace:** worktree `/Users/houssamr/Projects/syneriva/apps/erp.live-counting`, branch `feat/live-inventory-counting`. ALL work happens there. Never touch `/Users/houssamr/Projects/syneriva/apps/erp` (main checkout, shared).
- **NEVER run the full PHPUnit suite** (crashes the machine). Run tests BY PATH: `cd apps/api && ./vendor/bin/phpunit tests/Feature/<file> --filter=<TestName>`. Vitest: `cd apps/web && pnpm vitest run <path>` — never watch mode; if it hangs, kill workers (`ps aux | grep 'node (vitest'`).
- **Precision contract** (`docs/architecture/precision-contract.md`): quantities are scale-4 bcmath strings. No float casts, no `parseFloat`/`Number()` on quantities. Use `InventoryScale::QUANTITY_SCALE`; never hardcode scale ints (PHPStan guard).
- **Constructor injection ONLY** (`private readonly`), never `app()`. Enums for every status/type. Strict types; no `mixed`.
- **Tenant migrations** in `apps/api/database/migrations/tenant/` with prefix `2026_07_06_2NNNNN_` (the branch ALREADY HAS `2026_07_06_100000`–`180000` from other work — the 2NNNNN block sorts after them; do not reuse lower numbers).
- **Module boundaries:** cross-module references by id (Inventory already stores `location_id` without importing Company models — follow that).
- **DTO + types:** new/changed API payload shapes get PHP DTOs; run `php artisan typescript:transform` (may need `CACHE_STORE=array`) and commit regenerated `packages/shared/types/`.
- **Frontend:** all text via `t()` (namespace `inventory`); TanStack keys via `tenantScopedKey([...])`; design tokens for color classes in touched code; `apiGet`/`apiPost` already unwrap.
- **Routes:** module `routes.php` middleware `['api', 'auth:sanctum', SetPermissionsTeam::class]`. Counting mutations `can:inventory.adjust`, reads `can:inventory.view`.
- **Backend tests:** `RefreshDatabase`, real models, `RolesAndPermissionsSeeder`, valid UUIDs for FKs. Read sibling tests in `apps/api/tests/Feature/Inventory/` first. Validation errors use the `{error:{errors}}` envelope.
- **Regression sentinels (run BY PATH after B3):** `tests/Feature/Inventory/CountingReasonTest.php`, `tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php` — they assert the LEGACY listener behavior and must stay green (legacy path preserved for items with `final_qty_as_of IS NULL`).
- **Commit after every task** (conventional commits). **TDD:** failing test first, always.

## Verified paths you will touch

| What | Path |
|---|---|
| Count session model (fillable ends at `cancellation_reason`) | `apps/api/app/Modules/Inventory/Domain/InventoryCounting.php` |
| Count item model (`count_N_qty/at`, `final_qty`, `resolution_method`, `is_flagged`, scalar `flag_reason`, `variant_id`) | `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php` (submitCount stamps `now()` ~L213) |
| Orchestration (`submitCount` ~L302, `manualOverride` ~L524, `finalize` ~L546, item generation ~L84-160 with `quantity > 0` filter at ~L160) | `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` |
| Reconciliation (`reconcileItem` ~L78-113, thresholds ~L232-251) | `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php` |
| Finalize listener (legacy `delta = final − theoretical` at L64, posts at L85) | `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php` |
| Stock service (`adjust()` ~L603-689; `ProductCostLock` acquired at ~L617) | `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` |
| Lock (product-grain `(tenant, company, product)`; NOT taken by pure sales) | `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php` |
| Movement model / enums | `apps/api/app/Modules/Inventory/Domain/StockMovement.php`, `Domain/Enums/{MovementType,MovementReason}.php` |
| Scope enum (5 cases) + CHECK `chk_valid_scope_type` | `Domain/Enums/CountingScopeType.php`; `database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php:104` |
| Counting routes (batch draft routes ~L181 are DRAFT-ONLY; single submit ~L233) | `apps/api/app/Modules/Inventory/Presentation/routes.php` |
| Controllers | `Presentation/Controllers/InventoryCountingController.php` (batchAddProducts ~L829, updates only `scope_filters.product_ids` ~L904), `Presentation/Controllers/CountingItemController.php` (`submitCount` ~L78-98) |
| Requests | `Presentation/Requests/{SubmitCountRequest,CreateCountingRequest,ManualOverrideRequest}.php` |
| **All `StockMovement::create` writers (A1 must cover EVERY one — re-grep to confirm):** | `StockAdjustmentService.php`; `Application/Services/OpeningBalancePostingService.php:117`; `Application/Services/WeightedAverageCostService.php:258` (receipt rows with NULL reason!); `POS/Application/Projections/PosCoreReceiptProjection.php` (~L1009 sale, ~L1097-1214 return); `POS/Application/Services/ReceiptCreationService.php` (~L883-960); `POS/Application/Services/ReceiptReturnService.php:1237`; `POS/Application/Services/ReceiptVoidService.php:181`; `Procurement/Application/SupplierCreditNotePostingService.php:668` |
| Terminal payload | `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (~L29-33) |
| Location / policy enum | `apps/api/app/Modules/Company/Domain/Location.php`, `Domain/Enums/PosStockPolicy.php` |
| **POS stock gate — THE single cart-ingress seam (do not hunt page actions)** | `apps/pos/src/lib/stock/stockGate.ts` (`gateStockForAdd()`, reads policy ~L62); `apps/pos/src/stores/terminalStore.ts` (refresh ~L948-987) |
| Web counting | `apps/web/src/features/inventory-counting/` (`types.ts` [stale `'warehouse'` in union L6-12; `number` quantities L120/L133/L231], `pages/CreateCountingPage.tsx` ~L22-28, `pages/CountingReviewPage.tsx`, `components/ReconciliationTable.tsx` ~L312-324, `components/ManualOverrideDialog.tsx` parseFloat ~L27-31, `api/countingApi.ts` manualOverride ~L95) |
| Web locations settings (single file — D1 and D4 BOTH touch it → sequential) | `apps/web/src/features/settings/LocationsPage.tsx` (`LocationFormData` ~L36) |
| Migration patterns to copy | `2026_06_02_100006_add_variant_id_to_stock_movements.php` (`$withinTransaction = false` + `CREATE INDEX CONCURRENTLY`), `2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php` (quantities ALREADY 15,4 — do NOT re-widen) |

## Execution graph

```
Wave A (parallel): A1  A2  A3  A4        ← file-disjoint (A4 does NOT touch ReceiptCreationService)
Wave B: B1(A1) B2(A3) → B3(A1,A3,A4,B1,B2) ; B4(B1,B2) B5(A3) may run alongside B3 (different files)
Wave C: C1(A2,A3) C2(A3,A4) C3(A4,B3,C1)   ← C1/C2 parallel; C3 after
Wave D: chain1 D1→D4 (LocationsPage) ∥ chain2 D2→D3 (counting feature files)
Wave E: E1 → E2 (reviews) → E3 (preflight/push) → E4 (mobile handover)
```

---

## Wave A

### Task A1: `occurred_at` on stock movements — migration + ALL writers

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_200001_add_occurred_at_to_stock_movements.php`
- Modify: `apps/api/app/Modules/Inventory/Domain/StockMovement.php` (fillable + `datetime` cast)
- Modify (every writer stamps `occurred_at`): `StockAdjustmentService.php` (all its `StockMovement::create` sites; methods gain `?CarbonInterface $occurredAt = null` defaulting `now()`), `OpeningBalancePostingService.php`, `WeightedAverageCostService.php`, `PosCoreReceiptProjection.php` (sale AND return sites ← device event time, already read ~L195), `ReceiptCreationService.php` (device/receipt time), `ReceiptReturnService.php` (device event time), `ReceiptVoidService.php` (device event time), `SupplierCreditNotePostingService.php` (`now()`)
- Test: `apps/api/tests/Feature/Inventory/StockMovementOccurredAtTest.php`

**Interfaces:**
- Produces: `stock_movements.occurred_at` nullable timestamptz, backfilled = `created_at`; index `idx_stock_movements_replay (product_id, location_id, variant_id, occurred_at)` built `CONCURRENTLY`. Every post-deploy movement row has non-null `occurred_at`. NO scale changes (quantities are already `decimal(15,4)` since `2026_05_29_120000`).
- Consumes: nothing.

Migration requirements (copy the `2026_06_02_100006` pattern): `public $withinTransaction = false`; add column plain `ALTER TABLE ADD COLUMN` (instant on PG); **batched backfill** — loop `UPDATE stock_movements SET occurred_at = created_at WHERE id IN (SELECT id FROM stock_movements WHERE occurred_at IS NULL LIMIT 10000)` until 0 rows; then `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_movements_replay ...`. Down: drop index + column. `stock_movements` is an append-only audit log — the backfill writes only the new column, nothing else.

- [ ] **Step 1: failing test** — (a) pre-existing row backfilled to `created_at`; (b) POS projection sale movement `occurred_at` == fiscal event device time (projection test: clear `CompanyContext` before `apply()`, explicit currency — rules 19/20); (c) return path likewise; (d) `StockAdjustmentService::adjust()` stamps `now()` by default and honors an explicit value.
- [ ] **Step 2: run → FAIL** (`./vendor/bin/phpunit tests/Feature/Inventory/StockMovementOccurredAtTest.php`)
- [ ] **Step 3: implement** (grep `StockMovement::create` repo-wide FIRST; patch every site listed above plus any the grep reveals)
- [ ] **Step 4: run → green**
- [ ] **Step 5: commit** `feat(inventory): occurred_at event time on all stock movement writers`

### Task A2: zones schema + CRUD API + DTOs

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_200002_create_location_zones_tables.php`
- Create: `apps/api/app/Modules/Inventory/Domain/{LocationZone,ProductZoneAssignment}.php`
- Create: `apps/api/app/Modules/Inventory/Application/Services/ZoneService.php`
- Create: `apps/api/app/Modules/Inventory/Application/DTO/{ZoneDto,ZoneProductAssignmentDto}.php` (typescript-transformable, matching existing DTO conventions in the module)
- Create: `Presentation/Controllers/ZoneController.php`, `Presentation/Requests/{CreateZoneRequest,UpdateZoneRequest,BulkAssignZoneRequest}.php`
- Modify: `Presentation/routes.php`
- Test: `apps/api/tests/Feature/Inventory/ZoneManagementTest.php`

**Interfaces:**
- Produces: `location_zones (id uuid pk, tenant_id, location_id uuid idx, name, code, sort_order int, is_active bool, timestamps, UNIQUE(location_id, code))`; `product_zone_assignments (id uuid pk, tenant_id, product_id, location_id, zone_id fk→location_zones cascadeOnDelete, timestamps, UNIQUE(product_id, location_id))`. `ZoneService::assignProduct(string $productId, string $locationId, string $zoneId): void` (upsert; guards zone.location_id === locationId). Routes: `GET/POST /inventory/zones?location_id=`, `PATCH/DELETE /inventory/zones/{id}`, `POST /inventory/zones/{id}/assign-products {product_ids: uuid[]}`, `GET /inventory/zones/{id}/products`. Run `php artisan typescript:transform`; generated zone types land in `packages/shared/types/` — D1/D2 consume THESE, not hand-written ones.
- Consumes: nothing.

- [ ] **Step 1: failing test** — CRUD; duplicate code same location → 422; bulk assign upsert (reassign moves, no dup); cascade delete; cross-location assignment → 422; `Str::isUuid()` guard on lookups.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green + typescript:transform + commit generated types**
- [ ] **Step 5: commit** `feat(inventory): location zones + product assignments`

### Task A3: counting schema additions + Zone scope case

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_200003_add_live_counting_columns.php`
- Modify: `Domain/Enums/CountingScopeType.php` (+`case Zone = 'zone';` + every closed `match` in the enum)
- Modify: `Domain/InventoryCounting.php`, `Domain/InventoryCountingItem.php` (fillable/casts for every new column)
- Create: `Domain/Enums/CountingItemFlagReason.php` (`basket_window`, `negative_at_apply`, `clock_skew`, `normalized_agreement`)
- Create: `Application/DTO/ReplayAuditDto.php` (`windowFrom`, `windowTo`, `replayedDelta`, `onHandAtApply`, `expectedAtApply`)
- Test: `apps/api/tests/Feature/Inventory/LiveCountingSchemaTest.php`

**Interfaces:**
- Produces — `inventory_countings` += `block_sales bool default false`, `ambiguity_window_minutes int default 15`, `late_sales_flags jsonb null` (array of `{receipt_id, occurred_at}` — C2 writes), `includes_zero_stock bool default false` (C1 sets at generation; C3 reads for auto-exit); scope CHECK `chk_valid_scope_type` DROPped and re-ADDed with 6 values. `inventory_counting_items` += `count_N_device_at` / `count_N_at_estimate` timestamptz null (N=1..3), `final_qty_as_of timestamptz null`, `expected_qty_at_apply decimal(15,4) null`, `opening_unit_cost decimal(15,6) null` (D3 writes, B3 reads), `replay_audit jsonb null`, `flag_reasons jsonb null` (array of `CountingItemFlagReason` string values; legacy scalar `flag_reason` untouched).
- **Flag semantics (normative for B2/B3/B4/D3):** `is_flagged=true` ⟺ `flag_reasons` contains a BLOCKING reason (`basket_window`, `negative_at_apply`, `clock_skew`). `normalized_agreement` is informational: appended to `flag_reasons`, NEVER sets `is_flagged`.
- Consumes: nothing.

- [ ] **Step 1: failing test** — `scope_type='zone'` row insert OK post-migration; all new columns writable via model; enum case + label.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** `feat(inventory): live-counting schema (zone scope, block/replay/flag columns)`

### Task A4: per-location stock policy + onboarding fields

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_200004_add_onboarding_policy_to_locations.php`
- Modify: `apps/api/app/Modules/Company/Domain/Location.php`
- Create: `apps/api/app/Modules/Company/Application/Services/LocationStockPolicyResolver.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (`pos_stock_policy` key now carries the RESOLVED per-location value — same key, old POS builds keep working)
- Modify: the locations settings controller/request (locate via the CRUD used by `LocationsPage.tsx`) to accept both fields
- Test: `apps/api/tests/Feature/Company/LocationStockPolicyResolverTest.php`

**Interfaces:**
- Produces: `locations.onboarding_mode bool default false`, `locations.pos_stock_policy_override varchar null`. `LocationStockPolicyResolver::resolve(Location $location): PosStockPolicy` = `onboarding_mode ? Off : (override ?? company)`. TerminalResource resolved.
- **Deliberate cut:** does NOT touch `ReceiptCreationService` (retired path; keeps company policy — avoids file collision with A1; noted for a follow-up ticket).
- Consumes: nothing.

- [ ] **Step 1: failing test** — resolution matrix incl. onboarding-forces-off; TerminalResource reflects resolved value; settings update roundtrip.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** `feat(company): per-location stock policy + onboarding mode`

---

## Wave B

### Task B1: MovementReplayService (needs A1)

**Files:** Create `apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php`; Test `apps/api/tests/Feature/Inventory/MovementReplayServiceTest.php`

**Interfaces — produces (exact):**
```php
final class MovementReplayService
{
    /** Σ(quantity_after − quantity_before) over the stock line's movements,
     *  COALESCE(occurred_at, created_at) ∈ (from, to]. Scale-4 string, may be negative. */
    public function signedDelta(string $productId, string $locationId, ?string $variantId,
        CarbonInterface $from, CarbonInterface $to): string;

    /** Any movement for the stock line with event time within ±$windowMinutes of $instant. */
    public function hasMovementNear(string $productId, string $locationId, ?string $variantId,
        CarbonInterface $instant, int $windowMinutes): bool;
}
```
Variant predicate: `variant_id IS NULL` when `$variantId === null`, else `variant_id = :v`. Classify by signed per-row delta ONLY — never by `MovementType` (Adjustment is bidirectional; reversal rows self-cancel through their own before/after).

- [ ] **Step 1: failing table-driven test** — sale after T negative; sale before T excluded; receipt after T positive; adjustment-DOWN after T negative (type-mapping's failure case); reversal pair nets zero; boundary `(from, to]` exact; variant isolation (movements of variant B invisible to variant A's replay); `hasMovementNear` in/out.
- [ ] **Steps 2-4: FAIL → implement (single SUM query + `bcadd($sum,'0',InventoryScale::QUANTITY_SCALE)`) → green**
- [ ] **Step 5: commit** `feat(inventory): MovementReplayService (signed-delta replay)`

### Task B2: device timestamps on count submission (needs A3)

**Files:**
- Modify: `Presentation/Requests/SubmitCountRequest.php` (+`counted_at_device` nullable ISO-8601 UTC, +`device_now` nullable ISO-8601 UTC)
- Modify: `Presentation/Controllers/CountingItemController.php` `submitCount` (~L78-98) — the ONLY API boundary; passes both fields through (**there are NO batch count-submission endpoints — the batch routes ~L181 are draft-management only; mobile's offline queue drains through this single route**)
- Modify: `Application/Services/InventoryCountingService.php::submitCount` (~L302; signature gains `?CarbonInterface $countedAtDevice = null, ?CarbonInterface $deviceNow = null`)
- Modify: `Domain/InventoryCountingItem.php` count-stamping (~L213)
- Test: `apps/api/tests/Feature/Inventory/CountTimestampSkewTest.php`

**Interfaces:**
- Produces per count N: `count_N_at` = server receive (unchanged); `count_N_device_at` = raw claim; `count_N_at_estimate` = both fields present ? `count_N_device_at + (server_now − device_now)` : `count_N_at`. Skew = `|server_now − device_now|` > 5 min → append `clock_skew` to `flag_reasons` + `is_flagged=true` (A3 semantics). **B3/B4 use `count_N_at_estimate` exclusively.**
- Consumes: A3 columns/enum.

- [ ] **Step 1: failing test** — no device fields → estimate = server time, no flag; device clock 2h off with consistent `device_now` → corrected estimate ≈ server-true instant, no flag; `counted_at_device` without `device_now` → estimate = server receive + `clock_skew` flag; 6-min skew → flag.
- [ ] **Steps 2-5:** FAIL → implement → green → commit `feat(inventory): skew-corrected device count timestamps`

### Task B3: replay-based finalize (needs A1, A3, A4, B1, B2)

**Files:**
- Modify: `Domain/Services/StockAdjustmentService.php` — NEW method (see contract) so replay-read + post happen under the SAME lock order as `adjust()` (`ProductCostLock` → stock-level row `FOR UPDATE`). **Lock-order rule: never row-lock before ProductCostLock — that inverts `adjust()`'s order and can deadlock.** POS projection serializes with us via the stock-level row lock (it doesn't take ProductCostLock).
- Modify: `Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php`
- Create: `Domain/Services/FirstCountDetector.php`
- Modify: `Application/Services/InventoryCountingService.php` (`finalize` sets `final_qty_as_of` per item; `manualOverride` path documents its as-of)
- Test: `tests/Feature/Inventory/ReplayFinalizeTest.php`, `tests/Feature/Inventory/OnboardingFirstCountTest.php`; regression: run `CountingReasonTest.php` + `InventoryCountingDefaultBatchTest.php` by path — MUST stay green.

**Interfaces:**
- Consumes: B1 signatures verbatim; A4 resolver; A3 columns/flag semantics; B2 estimates.
- Produces:
```php
// StockAdjustmentService
/** Applies a finalized count line under ProductCostLock + row lock:
 *  replays (finalQtyAsOf, now], computes expected/adjustment, posts opening|count_correction,
 *  returns the audit DTO. Returns null when a guard flagged the item (nothing posted). */
public function applyCountResult(
    string $productId, string $locationId, ?string $variantId,
    string $finalQty, CarbonInterface $finalQtyAsOf,
    int $ambiguityWindowMinutes, bool $onboarding, ?string $openingUnitCost,
): ?ReplayAuditDto;
```
```php
// FirstCountDetector
/** True iff NO prior baseline movement exists for the stock line.
 *  Baseline = movement_type IN ('opening','transfer_in')
 *          OR (movement_type = 'receipt' AND (reason IS NULL OR reason NOT IN ('pos_return','customer_return'))).
 *  NULL-reason receipts ARE baseline (WeightedAverageCostService::recordPurchase L258 writes reason-less receipts).
 *  POS sales/returns never block opening. */
public function isFirstCount(string $productId, string $locationId, ?string $variantId): bool;
```
- Behavior contract (normative):
  1. `final_qty_as_of` per item at resolution: count-N-resolved → `count_N_at_estimate` of the count that supplied `final_qty`; **`manual_override` → `resolved_at`** (fixed rule — no other interpretation; the override request has no timestamp field).
  2. Inside `applyCountResult`: `expected_now = bcadd(finalQty, signedDelta(product, location, variant, finalQtyAsOf, now()), 4-const)`; `adjustment = bcsub(expected_now, on_hand_now, 4-const)`.
  3. Guards (flag + skip posting, item left for review): basket window via `hasMovementNear(…, finalQtyAsOf, window)`; negative-at-apply `bccomp(expected_now,'0',4-const) < 0 && !$onboarding`.
  4. `$onboarding && FirstCountDetector::isFirstCount(...)` → post adjustment additively as `MovementType::Opening` reason `MovementReason::OpeningBalance`, `unit_cost = openingUnitCost` (listener passes `opening_unit_cost ?? products.cost_price`; if still null → post with null cost + keep item flagged pending-cost, do NOT block the count). Prior on-hand ≤ 0 with cost → SET absolute WAC basis; > 0 → blend via `WeightedAverageCostService`. Else → `count_correction` as today.
  5. Listener writes `expected_qty_at_apply` + `replay_audit` on the item; posted movement's `occurred_at` = posting time.
  6. **Legacy path:** `final_qty_as_of IS NULL` → EXACT old behavior (`final − theoretical` via `adjust()`), keeping the two sentinel test files green.
  7. Queued context: no CompanyContext; explicit currency for scale resolution.

- [ ] **Step 1: failing tests** — (a) count 20 @ T, 3 post-T sales, on-hand −5 → adjustment +22, ends 17; (b) pre-T sale not double-deducted; (c) movement 5 min from T (window 15) → flagged, nothing posted; (d) non-onboarding negative-at-apply → flagged; (e) onboarding first count → `opening` movement + WAC set; second count → `count_correction`; (f) prior `transfer_in` → not first; (g) prior NULL-reason `receipt` → NOT first (WAC-purchase case); (h) only `pos_sale`/`pos_return` history → IS first; (i) `final_qty_as_of` null → legacy delta; (j) manual override → as-of `resolved_at`.
- [ ] **Steps 2-4: FAIL → implement → green (incl. the two regression sentinel files by path)**
- [ ] **Step 5: commit** `feat(inventory): replay-based count finalize (opening semantics, guards, lock-ordered)`

### Task B4: multi-counter normalization (needs B1, B2 — file-disjoint from B3 except reconciliation service; run after B3 starts only if touching different files, else after B3)

**Files:** Modify `Application/Services/CountingReconciliationService.php` (~L78-113); Test `tests/Feature/Inventory/NormalizedReconciliationTest.php`

**Interfaces:** consumes B1 + `count_N_at_estimate`. Produces: normalize each submitted count to the LATEST estimate among them: `normalized_N = bcadd(count_N_qty, signedDelta(…, count_N_at_estimate, T_latest), 4-const)`; compare normalized with existing epsilon. Raw-disagree/normalized-agree → resolve + append `normalized_agreement` (informational — does NOT set `is_flagged`, per A3 semantics). Single-count and legacy (no estimates) → unchanged behavior.

- [ ] Steps: failing test (10 @ 10:00, sale 2 @ 11:00, 8 @ 12:00 → auto-match + informational flag; same without sale → genuine mismatch as today) → FAIL → implement → green → commit `feat(inventory): normalize blind counts to common instant`

### Task B5: overlap guard — variant-aware (needs A3)

**Files:** Modify `Application/Services/InventoryCountingService.php` (`activate`, `activateDraft`, `finalize` pre-check); Test `tests/Feature/Inventory/CountingOverlapGuardTest.php`

**Interfaces:** activation throws domain exception (→ 422) when the activating count's items intersect another ACTIVE counting's items on **`(product_id, location_id, variant_id)` — null-variant-aware** (`variant_id IS NOT DISTINCT FROM`), active = statuses from `count_1_in_progress` through `pending_review`. `finalize` re-validates.

- [ ] Steps: failing test (same product+location+variant → 422; same product different variants → both OK; disjoint → OK; cancelled → OK) → FAIL → implement → green → commit `feat(inventory): variant-aware overlapping count guard`

---

## Wave C

### Task C1: zone scope + assign-on-submit + zero-stock inclusion (needs A2, A3)

**Files:**
- Modify: `Application/Services/InventoryCountingService.php` — scope switch (~L121-158): `Zone` case generates items from `product_zone_assignments` for `scope_filters.zone_ids`; item generation for onboarding-location counts AND `full_inventory`/`location` counts sets `includes_zero_stock=true` and sources from the product catalog (active products of the company) LEFT JOIN stock (theoretical = on-hand or `'0.0000'` when absent) instead of the `quantity > 0` filter (~L160)
- Modify: `Application/Services/InventoryCountingService.php::submitCount` — **assign-as-you-count happens HERE**: when the session's scope is `zone` (single zone id), upsert `ZoneService::assignProduct($item->product_id, $item->location_id, $zoneId)` on first count submission for the item. (NOT in `batchAddProducts` — that draft path only edits `scope_filters.product_ids` and creates no items.) Unexpected-item creation during an active zone count (scan-in path, `allow_unexpected_items`) also assigns.
- Modify: `Presentation/Requests/CreateCountingRequest.php` (~L83-115): `zone` scope requires `scope_filters.zone_ids` uuid[] + `location_id`; **`block_sales=true` is REJECTED for `zone` scope** (soft advisory only, per spec §3).
- Test: `tests/Feature/Inventory/ZoneScopedCountingTest.php`

**Interfaces:** consumes `ZoneService::assignProduct` (A2), `CountingScopeType::Zone` + `includes_zero_stock` (A3). Produces zone sessions, assignment-on-submit, zero-stock inclusion.

- [ ] Steps: failing test (zone count generates assigned products; submit for unassigned scanned-in product creates assignment; onboarding full count includes product with no stock row [theoretical `0.0000`] and one at −3; zone+block_sales → 422) → FAIL → implement → green → commit `feat(inventory): zone-scoped counting + assign-on-submit + zero-stock inclusion`

### Task C2: blocking mode end-to-end (needs A3, A4)

**Files:**
- Create: `Application/Services/CountingBlockService.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (+`active_counting_block: {counting_id, counting_number, started_at} | null`, +`counting_zone_advisories: [{zone_name, counting_number}]`)
- Modify: `PosCoreReceiptProjection.php` sale path — late signed sale whose device event time falls inside an active block window → append `{receipt_id, occurred_at}` to the counting's `late_sales_flags` (accepted, stock still moves)
- Modify POS: `apps/pos/src/stores/terminalStore.ts` (persist both new fields from terminal refresh) + `apps/pos/src/lib/stock/stockGate.ts` — **enforce inside `gateStockForAdd()`, the single cart-ingress seam** (hard refuse on `active_counting_block`; advisory toast for zone advisories); i18n via POS translation namespace.
- Test: `tests/Feature/Inventory/CountingBlockTest.php`; POS store/gate vitest if `apps/pos` has test infra (check `package.json`; skip with a note if none).

**Interfaces:** consumes A3 (`block_sales`, `late_sales_flags`), A4 resolver. Produces `CountingBlockService::activeBlockFor(string $locationId): ?InventoryCounting` — `block_sales=true` counting in statuses `count_1_in_progress`..`pending_review` (**explicit list — NOT `scopeActive()`, which excludes `pending_review`**) whose scope is location-covering (`location`, `full_inventory`, `product_location` on that location — zone counts can never have `block_sales`, enforced by C1). Also `zoneAdvisoriesFor(string $locationId): array`.

- [ ] Steps: failing test (block visible in TerminalResource; `pending_review` still blocked; finalized → null; late sale flagged + accepted; zone count → advisory not block) → FAIL → implement → green → commit `feat(pos+inventory): device-enforced sales blocking + late-sale flags + zone advisories`

### Task C3: onboarding lifecycle (needs A4, B3, C1)

**Files:**
- Create: `Application/Listeners/ExitOnboardingOnFullCountFinalized.php` (listens `InventoryCountingCompleted`)
- Create: worklist endpoint on `InventoryCountingController`: `GET /inventory/onboarding-worklist?location_id=` — products at location with negative on-hand OR no stock row, minus products already counted (any finalized/active count item since `onboarding_mode` was enabled); paginated, `can:inventory.view`
- Test: `tests/Feature/Inventory/OnboardingLifecycleTest.php`

**Interfaces:** consumes A4 fields, C1's `includes_zero_stock`. Produces: finalized count with scope `full_inventory` or `location` covering an onboarding location AND `includes_zero_stock=true` → `onboarding_mode=false`. Worklist shape `{data:[{product_id, name, sku, on_hand, last_sold_at}], meta}` (paginated → web uses `api.get` + `response.data`, NOT `apiGet`).

- [ ] Steps: failing test (qualifying finalize flips mode off; zone count doesn't; `includes_zero_stock=false` full count doesn't; worklist includes negative-stock product, excludes counted) → FAIL → implement → green → commit `feat(inventory): onboarding auto-exit + worklist`

---

## Wave D (two sequential chains; chains parallel to each other)

**Chain 1 (settings files): D1 → D4. Chain 2 (counting feature files): D2 → D3.**

### Task D1: zones management UI

**Files:** Create `apps/web/src/features/settings/zones/{ZonesPanel.tsx,ZoneFormDialog.tsx,BulkAssignDialog.tsx,api.ts}`; Modify `apps/web/src/features/settings/LocationsPage.tsx` (per-location "Zones" entry only — D4 adds its fields after); Test `.../zones/__tests__/ZonesPanel.test.tsx`

**Interfaces:** consumes A2 routes + A2's GENERATED types from `packages/shared/types/` (no hand-written zone types). **Deliberate cut (documented):** product-import `zone` column is OUT of v1 — the import pipeline (`ImportService.php:631` → `ProductService::upsert():63`) has no assignment seam and bulk-assign covers the need; note as follow-up.

- [ ] Steps: failing vitest (zones list per location; create validates name; bulk assign posts product_ids) → FAIL → implement (t() keys, tokens, tenantScopedKey) → green + `pnpm typecheck` → commit `feat(web): zone management + bulk assignment UI`

### Task D2: create-wizard additions + type precision migration

**Files:** Modify `features/inventory-counting/types.ts` (scope union += `'zone'`, drop stale `'warehouse'`; **ALL quantity fields → `string` in this task — D3 depends on it**), `pages/CreateCountingPage.tsx` (~L22-28: zone scope option → zone multi-select for the chosen location; `block_sales` toggle DISABLED for zone scope with explainer; `ambiguity_window_minutes` input default 15), `api/countingApi.ts` (create payload). Test: `__tests__/CreateCountingZoneScope.test.tsx`

**Interfaces:** consumes C1's request contract (`scope_type:'zone'`, `scope_filters.zone_ids`, `location_id`, `block_sales`, `ambiguity_window_minutes`) + A2 zone list route.

- [ ] Steps: failing test (zone option lists zones of selected location; payload carries zone_ids; block toggle disabled under zone scope) → FAIL → implement → green (`pnpm typecheck` — the string migration will surface every numeric usage; fix them all here) → commit `feat(web): zone scope + blocking toggle in wizard (string quantities)`

### Task D3: review page replay columns + opening-cost backfill (needs D2, B3)

**Files:**
- Backend (small, same task): `Presentation/Controllers/CountingItemController.php` + route `PATCH /inventory/countings/{id}/items/{itemId}/opening-cost` + `Presentation/Requests/SetOpeningCostRequest.php` (`unit_cost` string, money-regex ceiling per precision contract) — **writes `inventory_counting_items.opening_unit_cost`** (A3 column; B3 reads it with fallback `products.cost_price`). Gated `can:inventory.adjust`.
- Web: `pages/CountingReviewPage.tsx`, `components/ReconciliationTable.tsx` (~L312-324 string comparisons), `components/ManualOverrideDialog.tsx` (drop parseFloat ~L27-31 → string state), `api/countingApi.ts`.
- Test: `__tests__/ReviewReplayColumns.test.tsx` + backend `tests/Feature/Inventory/OpeningCostEndpointTest.php`

**Behavior:** columns "Expected now" (`expected_qty_at_apply`) + "Movements since count" (`replay_audit.replayedDelta`); flag chips from `flag_reasons` (blocking ones with recount CTA reusing third-count/override actions; `normalized_agreement` informational style); opening items missing cost → bulk-editable cost column hitting the PATCH; **finalize disabled while any item has a BLOCKING flag unresolved, is `pending`, or is an opening line with null cost**; banner rendering `late_sales_flags` count.

- [ ] Steps: failing tests (chip renders; finalize disabled on missing cost; override emits string; PATCH writes column) → FAIL → implement → green → commit `feat(web+api): replay review columns, flags, opening-cost backfill`

### Task D4: location settings — onboarding + policy override (needs D1, A4)

**Files:** Modify `apps/web/src/features/settings/LocationsPage.tsx` (`LocationFormData` ~L36 += both fields; switch + select [inherit/block/warn/off] with explainer copy). Test: `__tests__/LocationOnboarding.test.tsx`

- [ ] Steps: failing test → implement → green → commit `feat(web): location onboarding mode + stock policy override`

---

## Wave E

### Task E1: cross-layer scenario test

**Files:** Create `apps/api/tests/Feature/Inventory/LiveCountingScenarioTest.php`

Story, hand-computed inline: onboarding location; sell 2 of P (on-hand → −2); zone count (live); count 20 @ T (pre-T sales already off shelf); sell 3 with post-T `occurred_at` (on-hand → −5); finalize. Assert: `expected_now = 20 + (−3) = 17`; `adjustment = 17 − (−5) = +22` posted as `opening`; final on-hand **17**; WAC = entered cost; `replay_audit` populated; no flags; onboarding auto-exits after a qualifying full count.

- [ ] Steps: write → run → green (fix integration seams it exposes) → commit `test(inventory): live counting end-to-end scenario`

### Task E2: reviewer gates (do NOT skip)

- [ ] `inventory-costing-reviewer` on full branch diff (`git diff origin/dev...HEAD` in the worktree) — WAC/opening/replay.
- [ ] `fiscal-pos-reviewer` on POS-touching files (TerminalResource, projection, apps/pos gate/store) — fiscal immutability, projection contracts, rule 20.
- [ ] `tenancy-authz-reviewer` on routes/permissions/migrations (tenant_id, middleware, module gating).
- [ ] Fix findings; re-gate BLOCKERs. Reviews → `docs/superpowers/specs/reviews/`.

### Task E3: preflight + push

- [ ] `cd apps/api && ./vendor/bin/phpstan analyse` on new/changed paths; `./vendor/bin/pint --dirty`
- [ ] `cd apps/web && pnpm lint && pnpm typecheck`
- [ ] Re-run every test file created by this plan BY PATH (never the full suite)
- [ ] `git push -u origin feat/live-inventory-counting` — feature branch ONLY; no dev merge tonight (owner tests in the morning)

### Task E4: mobile handover

**Files:** Create `docs/handoff/HANDOVER-live-counting-mobile.md` — API deltas with example payloads (create-draft `scope_type:'zone'`/`zone_ids`/`block_sales`; submit-count `counted_at_device`+`device_now` ISO-8601 UTC through the SINGLE submit endpoint — offline queue in `useSubmitCount.ts` must attach both when draining); zone picker source (`GET /inventory/zones?location_id=`); blocking/advisory banners from session payload; backward-compat note (missing fields ⇒ server-stamped, no skew correction); screens (`create-draft.tsx`, `[id]/index.tsx` header, `draftSyncService.ts`); test cases (offline count with skewed clock e2e). Owner pushes erp-mobile personally.

- [ ] Write → commit `docs(handoff): mobile handover for live counting`

---

## Self-review record (v2)

- All 6 plan-review BLOCKERs addressed: A1/A4 collision (A4 drops ReceiptCreationService), A3 now produces `late_sales_flags`/`includes_zero_stock`/`opening_unit_cost`, B2 corrected to the single real endpoint + controller, A1 writer sweep covers all 8 call-site files + repo grep, FirstCountDetector treats NULL-reason receipts as baseline, A1 migration batched/concurrent/no-rewiden.
- MAJORs: D-waves serialized into two chains; B5 variant-aware; manual-override as-of = `resolved_at` (fixed rule); C2 uses `stockGate.ts` + zone advisories + `late_sales_flags` producer defined; import-zone column CUT with rationale; legacy sentinel tests named in B3; replay index includes `variant_id`; lock order specified (`ProductCostLock` → row lock, via new `applyCountResult`); flag semantics centralized in A3; D3 opening-cost persistence pinned to `opening_unit_cost`; C1 assign-on-submit (not batchAddProducts); migration prefixes moved to `2026_07_06_2NNNNN`.
- Type consistency re-checked: `signedDelta`/`hasMovementNear`/`applyCountResult`/`isFirstCount` signatures quoted once and referenced; flag enum values identical in A3/B2/B3/B4/D3; `count_N_at_estimate` consistent.

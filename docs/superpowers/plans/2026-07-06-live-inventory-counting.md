# Live Inventory Counting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Count inventory (by location or zone/shelf) while sales continue, with timestamp-replay reconciliation, per-session sales blocking, and a per-location onboarding mode (sell-before-count, first count = opening balance).

**Architecture:** Extends the existing blind-counting module (`apps/api/app/Modules/Inventory/`). New: `location_zones` + `product_zone_assignments` (labels only — stock stays at `(product, location)` grain), `occurred_at` event time on `stock_movements`, a `MovementReplayService` that sums signed per-row deltas, a replay-based finalize listener, per-location stock-policy resolution, and web UI. Spec: [`docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`](../specs/2026-07-06-live-inventory-counting-design.md) (read it first; its §4/§5 math is normative). Codex review dispositions: [`docs/superpowers/specs/reviews/2026-07-06-live-inventory-counting-spec-review.md`](../specs/reviews/2026-07-06-live-inventory-counting-spec-review.md).

**Tech Stack:** Laravel 12 / PHP 8.2 strict / PostgreSQL (db-per-tenant), React 19 + TS strict + TanStack Query 5, bcmath via `QuantityScale`.

## Global Constraints

- **Workspace:** worktree `/Users/houssamr/Projects/syneriva/apps/erp.live-counting`, branch `feat/live-inventory-counting`. ALL work happens there. Never touch `/Users/houssamr/Projects/syneriva/apps/erp` (main checkout, shared).
- **NEVER run the full PHPUnit suite** (crashes the machine). Run tests BY PATH: `cd apps/api && ./vendor/bin/phpunit tests/Feature/<area> --filter=<TestName>`. Same for vitest: `cd apps/web && pnpm vitest run <path>` — never watch mode. If a vitest run hangs, kill the worker pool (`ps aux | grep 'node (vitest'`).
- **Precision contract** (`docs/architecture/precision-contract.md`): quantities are scale-4 bcmath strings. No float casts, no `parseFloat`/`Number()` on quantities. PHP: `InventoryScale::QUANTITY_SCALE` (4) with `bcadd/bcsub/bccomp`. Never hardcode bcmath scale integers inline (PHPStan `ForbidHardcodedBcmathScale`) — use the class constants.
- **Constructor injection ONLY** (`private readonly`), never `app()`. Enums for every status/type. No magic strings. Strict types everywhere; no `mixed`.
- **Tenant migrations** go in `apps/api/database/migrations/tenant/` with a `2026_07_06_1NNNNN_` prefix. Run with `php artisan tenants:migrate` (local verification only if a local stack exists — otherwise migration tests via RefreshDatabase cover it).
- **Module boundaries:** cross-module references by id (the Inventory module already stores `location_id` without importing Company models — follow that). No direct model imports across modules.
- **DTO + types:** new/changed API payload shapes get PHP DTOs; run `php artisan typescript:transform` (needs `CACHE_STORE=array` in some worktree envs) and commit regenerated `packages/shared/types/`.
- **Frontend:** all user-facing text via `t()` (namespace `inventory`); TanStack keys via `tenantScopedKey([...])`; design tokens (`@/lib/designTokens`) for any color classes in touched code; `apiGet`/`apiPost` already unwrap — no double-unwrap.
- **Routes:** module `routes.php` middleware `['api', 'auth:sanctum', SetPermissionsTeam::class]`. Counting mutations stay behind `can:inventory.adjust`, reads behind `can:inventory.view`.
- **Backend tests:** `RefreshDatabase`, real models, `RolesAndPermissionsSeeder`, valid UUIDs for all FKs. Look at `apps/api/tests/Feature/Inventory/` siblings for conventions before writing.
- **Commit after every task** (conventional commits, scoped to the task's files).
- **TDD:** every task writes its failing test first, sees it fail, then implements.

## Existing code you will touch (verified paths)

| What | Path |
|---|---|
| Count session model | `apps/api/app/Modules/Inventory/Domain/InventoryCounting.php` |
| Count item model (has `count_1_qty`, `count_1_at`, …, `final_qty`, `resolution_method`, `is_flagged`, `flag_reason`) | `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php` |
| Orchestration (`create`, `generateCountingItems`, `activate`, `submitCount` ~L302-361, `finalize` ~L546-588) | `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` |
| Variance resolution (`reconcileItem` ~L78-113, thresholds ~L232-251) | `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php` |
| Adjustment posting listener (queued, `delta = final − theoretical`) | `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php` |
| Stock adjust/issue/transfer (`adjust()` ~L603-689 row-locks under per-product advisory lock) | `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` |
| Movement model (`directionForRow()` ~L170-180, `reverses_movement_id`) | `apps/api/app/Modules/Inventory/Domain/StockMovement.php` |
| Movement enums | `apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php`, `MovementReason.php` |
| Scope enum (5 cases: `product_location, product, location, category, full_inventory`) | `apps/api/app/Modules/Inventory/Domain/Enums/CountingScopeType.php` |
| Count routes (~L130-231) | `apps/api/app/Modules/Inventory/Presentation/routes.php` |
| Submit request (quantity canonical-string helper) | `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php` |
| Create request (~L83-115 per-scope validation) | `apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php` |
| WAC | `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` (has `recordCostAdjustment`) |
| Opening-balance posting (ADDITIVE — do not reuse blindly) | `apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php` |
| POS projection (`decrementStock` ~L1009-1094, return restock ~L1097-1214, reads device event time ~L195) | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` |
| Terminal payload (serializes company `pos_stock_policy` ~L29-33) | `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` |
| Location model / policy enum | `apps/api/app/Modules/Company/Domain/Location.php`, `.../Enums/PosStockPolicy.php` |
| POS terminal store (refresh ~L948-987 reads `pos_stock_policy`) | `apps/pos/src/stores/terminalStore.ts`; scheduler `apps/pos/src/lib/sync/syncScheduler.ts` ~L96-105 |
| Web counting feature | `apps/web/src/features/inventory-counting/` (`types.ts`, `pages/CreateCountingPage.tsx`, `pages/CountingReviewPage.tsx`, `components/ReconciliationTable.tsx`, `components/ManualOverrideDialog.tsx`, `api/countingApi.ts`) |
| Web locations settings | `apps/web/src/features/settings/LocationsPage.tsx` |
| Count session CHECK constraints (scope_type at L104-105, status at L117) | `apps/api/database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php` |
| Movements table (`quantity_before/after` are `decimal(15,2)` — MUST widen) | `apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php` |

---

## Wave A — foundations (A1–A4 are file-disjoint; safe to implement in parallel)

### Task A1: `occurred_at` on stock movements + scale widening + writers

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100001_add_occurred_at_to_stock_movements.php`
- Modify: `apps/api/app/Modules/Inventory/Domain/StockMovement.php` (fillable + casts)
- Modify: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (every `StockMovement::create` gains `occurred_at`, default `now()`, overridable param)
- Modify: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (sale decrement AND return restock set `occurred_at` from the receipt's device event time — the projection already reads it ~L195; thread it into both movement-creation sites)
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (sync path `decrementStock` ~L883-960: same)
- Test: `apps/api/tests/Feature/Inventory/StockMovementOccurredAtTest.php`

**Interfaces:**
- Produces: `stock_movements.occurred_at` (nullable timestamptz, backfilled = `created_at`), index `idx_stock_movements_replay (product_id, location_id, occurred_at)`. `StockMovement::create([... 'occurred_at' => CarbonInterface])`. Widened `quantity`, `quantity_before`, `quantity_after` to `decimal(20,4)` (current 15,2 truncates scale-4 replay deltas).
- Consumes: nothing.

- [ ] **Step 1: failing test** — assert (a) migration adds column+index and backfills equal to `created_at` for a pre-existing row; (b) a POS projection movement's `occurred_at` equals the fiscal event device time, not `now()`; (c) `StockAdjustmentService::adjust()` stamps `occurred_at`. For (b) follow an existing projection test in `apps/api/tests/**/POS/**` (clear `CompanyContext` before `apply()` — rule 20; pass explicit currency to scale resolvers — rule 19).
- [ ] **Step 2: run, see FAIL** — `./vendor/bin/phpunit tests/Feature/Inventory/StockMovementOccurredAtTest.php`
- [ ] **Step 3: migration** — `ALTER TABLE stock_movements ALTER COLUMN quantity TYPE decimal(20,4), ALTER COLUMN quantity_before TYPE decimal(20,4), ALTER COLUMN quantity_after TYPE decimal(20,4)` (raw statement; Laravel change() needs doctrine/dbal — prefer `DB::statement`), add `occurred_at` nullable timestamptz, `UPDATE stock_movements SET occurred_at = created_at WHERE occurred_at IS NULL`, add index. Down: drop column+index only (don't narrow scales back).
- [ ] **Step 4: writers** — add `occurred_at` to `$fillable`/casts; projection passes device event time to its two `StockMovement::create` sites; `StockAdjustmentService` methods accept optional `?CarbonInterface $occurredAt = null` and default `now()`.
- [ ] **Step 5: run to green, commit** — `feat(inventory): occurred_at event time on stock movements + scale-4 widening`

### Task A2: zones schema + CRUD API

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100002_create_location_zones_tables.php` (both tables)
- Create: `apps/api/app/Modules/Inventory/Domain/LocationZone.php`, `apps/api/app/Modules/Inventory/Domain/ProductZoneAssignment.php`
- Create: `apps/api/app/Modules/Inventory/Application/Services/ZoneService.php`
- Create: `apps/api/app/Modules/Inventory/Presentation/Controllers/ZoneController.php`, `apps/api/app/Modules/Inventory/Presentation/Requests/{CreateZoneRequest,UpdateZoneRequest,BulkAssignZoneRequest}.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/routes.php` (zone routes under the existing group)
- Test: `apps/api/tests/Feature/Inventory/ZoneManagementTest.php`

**Interfaces:**
- Produces: tables `location_zones (id uuid pk, tenant_id, location_id uuid indexed, name string, code string, sort_order int, is_active bool, timestamps, UNIQUE(location_id, code))` and `product_zone_assignments (id uuid pk, tenant_id, product_id uuid, location_id uuid, zone_id uuid FK->location_zones cascadeOnDelete, timestamps, UNIQUE(product_id, location_id))`. `ZoneService::assignProduct(string $productId, string $locationId, string $zoneId): void` (upsert on the unique key — this is the seam C1 uses for assign-as-you-count). Routes: `GET/POST /inventory/zones?location_id=`, `PATCH/DELETE /inventory/zones/{id}`, `POST /inventory/zones/{id}/assign-products {product_ids: uuid[]}`, `GET /inventory/zones/{id}/products` (paginated). Reads `can:inventory.view`, writes `can:inventory.adjust`.
- Consumes: nothing.

- [ ] **Step 1: failing test** — CRUD happy path; duplicate code in same location → 422 (`{error:{errors}}` envelope — use `Tests\Traits\AssertsApiValidation` if present); bulk assign upserts (re-assign moves product to new zone, no duplicate row); delete zone cascades assignments; cross-location zone reuse rejected (assignment's `location_id` must equal zone's `location_id` — service-level guard).
- [ ] **Step 2: FAIL run**
- [ ] **Step 3: implement** (models `HasUuids`; service constructor-injected; validate `Str::isUuid()` before any `where('id', $val)` on uuid columns)
- [ ] **Step 4: green run**
- [ ] **Step 5: commit** — `feat(inventory): location zones + product assignments (schema, service, CRUD API)`

### Task A3: counting schema additions + Zone scope case

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100003_add_live_counting_columns.php`
- Modify: `apps/api/app/Modules/Inventory/Domain/Enums/CountingScopeType.php` (+`case Zone = 'zone';` and every closed `match` in the enum)
- Modify: `apps/api/app/Modules/Inventory/Domain/InventoryCounting.php`, `InventoryCountingItem.php` (fillable/casts)
- Create: `apps/api/app/Modules/Inventory/Domain/Enums/CountingItemFlagReason.php` (`basket_window`, `negative_at_apply`, `clock_skew`, `normalized_agreement`)
- Create: `apps/api/app/Modules/Inventory/Application/DTO/ReplayAuditDto.php` (jsonb shape: `windowFrom`, `windowTo`, `replayedDelta`, `excludedReversalIds: string[]`, `onHandAtApply`, `expectedAtApply`)
- Test: `apps/api/tests/Feature/Inventory/LiveCountingSchemaTest.php`

**Interfaces:**
- Produces: `inventory_countings` += `block_sales bool default false`, `ambiguity_window_minutes int default 15`; **scope_type CHECK constraint dropped & re-added including `'zone'`** (constraint name `chk_valid_scope_type`; status CHECK untouched). `inventory_counting_items` += `count_1_device_at timestamptz null`, `count_1_at_estimate timestamptz null` (same pair for 2/3), `final_qty_as_of timestamptz null`, `expected_qty_at_apply decimal(20,4) null`, `replay_audit jsonb null`, `flag_reasons jsonb null` (array of `CountingItemFlagReason` values — existing scalar `flag_reason` stays for legacy variance labels). `CountingScopeType::Zone`.
- Consumes: nothing.

- [ ] **Step 1: failing test** — creating a counting row with `scope_type='zone'` succeeds post-migration (fails against old CHECK); new item columns accept writes; enum `::Zone` case exists with label.
- [ ] **Step 2: FAIL** → **Step 3: implement** (CHECK: `ALTER TABLE inventory_countings DROP CONSTRAINT chk_valid_scope_type;` then re-ADD with 6 values) → **Step 4: green**
- [ ] **Step 5: commit** — `feat(inventory): live-counting schema (zone scope, block_sales, replay/audit columns)`

### Task A4: per-location stock policy + onboarding fields

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_06_100004_add_onboarding_policy_to_locations.php`
- Modify: `apps/api/app/Modules/Company/Domain/Location.php`
- Create: `apps/api/app/Modules/Company/Application/Services/LocationStockPolicyResolver.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (serve resolved per-location policy instead of raw company value; keep the same payload key `pos_stock_policy` so old POS builds keep working)
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (its `Block` throw ~L912-933 resolves via the resolver — retired path but keep consistent)
- Modify: Location settings controller/request (find via `routes` for the existing locations CRUD used by `apps/web/src/features/settings/LocationsPage.tsx`) to accept the two new fields
- Test: `apps/api/tests/Feature/Company/LocationStockPolicyResolverTest.php`

**Interfaces:**
- Produces: `locations.onboarding_mode bool default false`, `locations.pos_stock_policy_override varchar null` (values of `PosStockPolicy`). `LocationStockPolicyResolver::resolve(Location $location): PosStockPolicy` — `onboarding_mode ? Off : (override ?? company policy)`. TerminalResource `pos_stock_policy` = resolved value for the terminal's location.
- Consumes: nothing.

- [ ] **Step 1: failing test** — resolution matrix: (no override, company block) → block; (override warn) → warn; (onboarding on + override block) → off; TerminalResource reflects resolved value.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green**
- [ ] **Step 5: commit** — `feat(company): per-location stock policy override + onboarding mode + resolved terminal policy`

---

## Wave B — core engine (B1 → B2 → B3; B4/B5 after B1/B2)

### Task B1: MovementReplayService (needs A1)

**Files:**
- Create: `apps/api/app/Modules/Inventory/Domain/Services/MovementReplayService.php`
- Test: `apps/api/tests/Feature/Inventory/MovementReplayServiceTest.php`

**Interfaces:**
- Produces:
```php
final class MovementReplayService
{
    /** Σ(quantity_after − quantity_before) over movements for the stock line,
     *  COALESCE(occurred_at, created_at) ∈ (from, to]. Scale-4 string (may be negative). */
    public function signedDelta(string $productId, string $locationId, ?string $variantId,
        CarbonInterface $from, CarbonInterface $to): string;

    /** True when any movement for the stock line has event time within ±$windowMinutes of $instant. */
    public function hasMovementNear(string $productId, string $locationId, ?string $variantId,
        CarbonInterface $instant, int $windowMinutes): bool;
}
```
- Consumes: A1's `occurred_at`.
- **Normative math (spec §4):** classify by signed per-row delta ONLY — never by `MovementType`. Reversal rows carry their own before/after so summing handles them; do NOT special-case `reverses_movement_id`, but record excluded/irregular rows in nothing here (B3 owns audit). Variant handling: `variant_id IS NULL` when null, `= :variant` otherwise (partial unique indexes on stock_levels follow this pattern).

- [ ] **Step 1: failing test, table-driven** — seed movements with controlled `occurred_at`/before/after and assert: sale after T counts negative; sale before T excluded; receipt after T counts positive; adjustment DOWN after T counts negative (this is the case type-mapping gets wrong); reversal pair after T nets to zero; boundary semantics `(from, to]` (movement exactly at `from` excluded, exactly at `to` included); `hasMovementNear` inside/outside window.
- [ ] **Step 2: FAIL** → **Step 3: implement** — single SQL `SUM(quantity_after - quantity_before)` cast through `bcadd($sum, '0', InventoryScale::QUANTITY_SCALE)`; **Step 4: green**
- [ ] **Step 5: commit** — `feat(inventory): MovementReplayService (signed-delta timestamp replay)`

### Task B2: device timestamps on count submission (needs A3)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php` (+`counted_at_device` nullable ISO-8601; +`device_now` nullable ISO-8601)
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` `submitCount` (~L302-361) and `InventoryCountingItem` count-stamping (~L213-222)
- Modify: the mobile batch-count endpoints' requests (find `batch` routes in `Presentation/routes.php` ~L130-231 — mobile `batchAddProducts`/`submitCount` paths) to accept the same pair
- Test: `apps/api/tests/Feature/Inventory/CountTimestampSkewTest.php`

**Interfaces:**
- Produces: on each count N submission: `count_N_at` = server receive time (unchanged), `count_N_device_at` = raw device claim, `count_N_at_estimate` = `count_N_device_at + (server_now − device_now)` when both present, else `count_N_at`. Skew = `|server_now − device_now|`; if > 5 min → append `CountingItemFlagReason::ClockSkew` to `flag_reasons`. **Replay boundary consumers (B3/B4) MUST use `count_N_at_estimate`.**
- Consumes: A3 columns.

- [ ] **Step 1: failing test** — online submit (no device fields) → estimate = server time, no flag; offline-style submit with device clock 2 h behind → estimate corrected to ~server-true instant, no flag when correction consistent; device_now missing but counted_at_device present → estimate = server receive time + ClockSkew flag; skew 6 min → flag.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): skew-corrected device count timestamps`

### Task B3: replay-based finalize (needs A1, A3, A4, B1)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php` (full rewrite of the per-item body)
- Create: `apps/api/app/Modules/Inventory/Domain/Services/FirstCountDetector.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` (`finalize` sets `final_qty_as_of` per item at resolution; pre-finalize validation described below)
- Test: `apps/api/tests/Feature/Inventory/ReplayFinalizeTest.php`, `apps/api/tests/Feature/Inventory/OnboardingFirstCountTest.php`

**Interfaces:**
- Consumes: `MovementReplayService` (B1 signatures), `LocationStockPolicyResolver` (A4), `WeightedAverageCostService::recordCostAdjustment`, A3 columns/enums/DTO.
- Produces (behavior contract, normative):
  1. `final_qty_as_of` = `count_N_at_estimate` of the count that supplied `final_qty` (`resolution_method` → count number; `manual_override` → the seeded count's estimate, else `resolved_at`).
  2. Per item, inside `DB::transaction`, acquire the SAME per-product advisory lock `StockAdjustmentService::adjust()` uses (extract its lock helper into a shared protected/public seam if private — do NOT invent a second lock key). Then:
     `delta = MovementReplayService::signedDelta(product, location, variant, final_qty_as_of, now())`
     `expected_now = bcadd(final_qty, delta_inverted…)` — careful: signedDelta returns net stock change since T, so `expected_now = bcadd(final_qty, signedDelta, QUANTITY_SCALE)`. *(A sale after T has negative row delta, lowering expectation — matches spec formula.)*
     `adjustment = bcsub(expected_now, on_hand_now, QUANTITY_SCALE)`.
  3. Guards, evaluated before posting; each appends `CountingItemFlagReason` to `flag_reasons`, sets `is_flagged`, and SKIPS posting for that item (item stays unresolved for review):
     - basket window: `hasMovementNear(…, final_qty_as_of, counting->ambiguity_window_minutes)`
     - negative at apply: `bccomp(expected_now, '0', 4) < 0 && !onboarding` (via resolver on the location)
  4. Opening vs correction: `FirstCountDetector::isFirstCount(string $productId, string $locationId, ?string $variantId): bool` — true iff NO prior movement rows with (`movement_type = 'opening'`) OR (`movement_type = 'receipt'` AND `reason IN ('goods_receipt','opening_balance')`) OR (`movement_type = 'transfer_in'`). POS sales/returns (`reason IN ('pos_sale','pos_return','customer_return','delivery',…)`) never block opening.
     - First count + onboarding location → post adjustment quantity ADDITIVELY as `MovementType::Opening`, reason `MovementReason::OpeningBalance`, with `unit_cost = products.cost_price` (nullable OK — D3 backfills before finalize; if still null at posting, post with null cost and keep the item flagged `pending cost` — do NOT block the whole count). When prior on-hand ≤ 0 and cost present, call `WeightedAverageCostService::recordCostAdjustment`-equivalent to SET the absolute WAC basis; when on-hand > 0, blend.
     - Otherwise → `count_correction` exactly as today.
  5. Write `expected_qty_at_apply` + `replay_audit` (ReplayAuditDto) on the item. `occurred_at` of the posted movement = posting time (it happens "now").
  6. Legacy path: items with `final_qty_as_of IS NULL` (counts created pre-deploy) use the OLD `final − theoretical` delta unchanged.
  7. Queued context: NO CompanyContext — pass explicit currency for any scale resolution (rule 19/20).

- [ ] **Step 1: failing tests** — the money cases, hand-computed:
  (a) count 20 @ T, 3 sales after T (replay −3) → expected 17; on-hand −5 → adjustment +22, on-hand ends 17.
  (b) sale BEFORE T not double-deducted (on-hand −2 pre-T, count 20 @ T, 1 sale post-T → ends 19).
  (c) movement 5 min from T with window 15 → flagged, nothing posted.
  (d) non-onboarding count that would land negative → flagged.
  (e) onboarding first count posts `opening` movement w/ cost, WAC set; second count same product posts `count_correction`.
  (f) product with prior `transfer_in` → NOT first count.
  (g) product with only prior `pos_sale`/`pos_return` rows → IS first count.
  (h) pre-deploy item (`final_qty_as_of` null) → legacy delta path.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): replay-based count finalize (opening semantics, basket/negative guards, advisory lock)`

### Task B4: multi-counter normalization (needs B1, B2)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php` (`reconcileItem` ~L78-113)
- Test: `apps/api/tests/Feature/Inventory/NormalizedReconciliationTest.php`

**Interfaces:**
- Consumes: `MovementReplayService::signedDelta`, `count_N_at_estimate` (B2).
- Produces: before double/triple comparison, normalize each count to the LATEST estimate among submitted counts: `normalized_N = bcadd(count_N_qty, signedDelta(product, location, variant, count_N_at_estimate, T_latest), 4)`. Compare normalized values with the existing epsilon. If raw values disagree but normalized agree → resolution proceeds AND `CountingItemFlagReason::NormalizedAgreement` appended (informational, does not block). Single-count mode unchanged. Legacy items without estimates → raw comparison as today.

- [ ] **Step 1: failing test** — counter 1 counts 10 @ 10:00, sale of 2 @ 11:00, counter 2 counts 8 @ 12:00 → normalized both 8 → `auto_all_match` + `NormalizedAgreement` flag; same scenario without the sale → genuine mismatch handled as today (counters disagree).
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): normalize blind counts to common instant before comparison`

### Task B5: overlap guard (needs A3)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` (`activate`, `activateDraft`, and `finalize` pre-check)
- Test: `apps/api/tests/Feature/Inventory/CountingOverlapGuardTest.php`

**Interfaces:**
- Produces: activation throws a domain exception (→ 422) when any `(product_id, location_id)` of the activating count's items intersects items of another counting in an ACTIVE status (activated → not finalized/cancelled — i.e., statuses between `count_1_in_progress` and `pending_review` inclusive). `finalize` re-validates and aborts with the same error. Exact SQL: join `inventory_counting_items` on product+location where `counting_id != :self` and counting status in the active list.
- Consumes: A3 (nothing else).

- [ ] **Step 1: failing test** — two counts over same product+location: second activation → 422; disjoint products → both activate; cancelled first count → second activates.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): overlapping active count guard`

---

## Wave C — flows (needs Wave A + relevant B)

### Task C1: zone scope + assign-as-you-count + zero-stock inclusion (needs A2, A3)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` (`generateCountingItems` scope switch ~L121-160)
- Modify: `apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php` (~L83-115: `zone` scope requires `scope_filters.zone_ids` uuid[] + single `location_id`)
- Modify: the barcode `lookup` + unexpected-item add path (routes ~L130-231, `CountingItemController`) — when session scope is `zone`, scanning/adding a product calls `ZoneService::assignProduct`
- Modify: item generation query — for counts on onboarding-mode locations AND all `full_inventory`/`location` counts used for onboarding exit, include products with stock `quantity <= 0` and products with NO stock_levels row (current filter is `quantity > 0` at ~L160): source products from the product catalog scoped to location's company + active, LEFT JOIN stock (theoretical 0 when absent)
- Test: `apps/api/tests/Feature/Inventory/ZoneScopedCountingTest.php`

**Interfaces:**
- Consumes: `ZoneService::assignProduct` (A2), `CountingScopeType::Zone` (A3).
- Produces: zone-scoped counting sessions generate items from `product_zone_assignments` for the zone(s); `allow_unexpected_items` scan-in works and zone-assigns. Onboarding/full counts include zero/negative/absent stock products with `theoretical_qty = current on-hand (may be negative/0)`.

- [ ] **Step 1: failing test** — zone count generates exactly the assigned products; scan of unassigned product (allow_unexpected_items=true) creates item AND assignment; onboarding full count includes a product with no stock row (theoretical 0) and one at −3 (theoretical −3).
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): zone-scoped counting + assign-as-you-count + zero-stock inclusion`

### Task C2: blocking mode end-to-end (needs A3, A4)

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/CountingBlockService.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` (+`active_counting_block: {counting_id, counting_number, started_at} | null` for the terminal's location)
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php` (activation of a `block_sales` count → nothing extra server-side beyond block-service visibility; finalize/cancel end it implicitly via status)
- Modify: POS app — `apps/pos/src/stores/terminalStore.ts` (persist `active_counting_block` from terminal refresh ~L948-987) + the sale-line/cart entry point (locate the add-to-cart/create-sale action in `apps/pos/src/` and refuse with a banner/toast when block active; i18n `t()` keys in the POS translation namespace)
- Modify: fiscal ingestion flag — in `PosCoreReceiptProjection` sale path, if `CountingBlockService::activeBlockFor(location_id)` at projection time AND receipt device time falls inside the block window: append a flag onto the counting session (jsonb `late_sales_flags` on `inventory_countings` — add the column in A3's migration; A3 owner: include `late_sales_flags jsonb null`)
- Test: `apps/api/tests/Feature/Inventory/CountingBlockTest.php`; `apps/pos` vitest for the store change if POS has test setup (check `apps/pos/package.json` — if no test infra, skip FE test and note it)

**Interfaces:**
- Consumes: A3 `block_sales` (+`late_sales_flags`), A4 resolver.
- Produces: `CountingBlockService::activeBlockFor(string $locationId): ?InventoryCounting` — a `block_sales=true` counting in ANY status from activation through `pending_review` (explicit status list — NOT the model's `scopeActive()`, which excludes `pending_review`) whose scope covers the location. TerminalResource carries it; POS refuses new sale lines locally; late signed sales are accepted + flagged on the session.

- [ ] **Step 1: failing test** — block active → TerminalResource payload contains block; count in `pending_review` → still blocked; finalized → null; late sale projected during block window → session gains a late-sale flag and stock still moves (accepted).
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(pos+inventory): sales blocking during counts (device-enforced, late sales flagged)`

### Task C3: onboarding lifecycle (needs A4, B3)

**Files:**
- Modify: location settings controller/request (same one A4 touched) — toggling handled there already; this task adds the AUTO-EXIT
- Create: `apps/api/app/Modules/Inventory/Application/Listeners/ExitOnboardingOnFullCountFinalized.php` (listens to the existing `InventoryCountingCompleted` event)
- Create: `GET /inventory/onboarding-worklist?location_id=` endpoint (Controller method): products at the location with negative on-hand OR no stock row, not yet counted in any active/finalized count since onboarding started — the "count these next" list (paginated, `can:inventory.view`)
- Test: `apps/api/tests/Feature/Inventory/OnboardingLifecycleTest.php`

**Interfaces:**
- Consumes: A4 fields, B3's finalize flow, C1's zero-stock generation.
- Produces: when a finalized count has scope `full_inventory` or `location` covering an onboarding-mode location AND was generated with zero-stock inclusion (C1 marks this — add bool `includes_zero_stock` set at generation time; A3 owner: include this column too), set `onboarding_mode = false` on that location. Worklist endpoint shape: `{data: [{product_id, name, sku, on_hand, last_sold_at}], meta}`.

- [ ] **Step 1: failing test** — finalizing a qualifying full count flips `onboarding_mode` off; a zone-scoped count does NOT; worklist returns the negative-stock product and excludes counted ones.
- [ ] **Step 2: FAIL** → **Step 3: implement** → **Step 4: green** → **Step 5: commit** — `feat(inventory): onboarding auto-exit + negative-stock worklist`

---

## Wave D — web UI (D1–D4 parallel after C; all: i18n `t()`, design tokens, `tenantScopedKey`, string quantities)

### Task D1: zones management UI

**Files:**
- Create: `apps/web/src/features/settings/zones/` (`ZonesPanel.tsx`, `ZoneFormDialog.tsx`, `BulkAssignDialog.tsx`, `api.ts`, `types.ts` [zone types re-exported from generated types if transform emits them; else local matching DTO])
- Modify: `apps/web/src/features/settings/LocationsPage.tsx` (per-location "Zones" drawer/section entry)
- Modify: product import mapping — find the import column-mapping config used by `features/import/` and add optional `zone` column mapped to assignment (server side: extend the product import handler to upsert assignment when column present — locate via `rg "shelf_location" apps/api` import pipeline)
- Test: `apps/web/src/features/settings/zones/__tests__/ZonesPanel.test.tsx` (vitest, mock hooks per convention)

**Interfaces:** consumes A2 routes verbatim.

- [ ] Steps: failing vitest (list renders zones; create validates required name; bulk assign posts product_ids) → FAIL → implement → green → `pnpm typecheck` → commit `feat(web): zone management + bulk assignment UI + import column`

### Task D2: create-wizard additions

**Files:**
- Modify: `apps/web/src/features/inventory-counting/types.ts` (scope union += `'zone'`; remove stale `'warehouse'` if present after checking the backend never accepted it; **quantities in these types become `string`**)
- Modify: `apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx` (~L16-64 wizard: zone scope option → zone multi-select for chosen location; `block_sales` toggle with explanation copy; `ambiguity_window_minutes` numeric input default 15)
- Modify: `apps/web/src/features/inventory-counting/api/countingApi.ts` (create payload)
- Test: extend existing wizard test or add `__tests__/CreateCountingZoneScope.test.tsx`

**Interfaces:** consumes C1's request contract (`scope_type: 'zone'`, `scope_filters.zone_ids`, `location_id`, `block_sales`, `ambiguity_window_minutes`).

- [ ] Steps: failing test (zone option renders zones of selected location; payload carries zone_ids + block_sales) → FAIL → implement → green → commit `feat(web): zone scope + blocking toggle in counting wizard`

### Task D3: review page replay columns + cost backfill + precision

**Files:**
- Modify: `apps/web/src/features/inventory-counting/pages/CountingReviewPage.tsx`, `components/ReconciliationTable.tsx` (~L312-324 numeric comparisons → string-based), `components/ManualOverrideDialog.tsx` (drop `parseFloat` ~L27-31 → string state + `<QuantityInput>`), `types.ts` (reconciliation/override quantities → `string`)
- Modify: `apps/web/src/features/inventory-counting/api/countingApi.ts` (+`PATCH /inventory/countings/{id}/items/{itemId}/opening-cost {unit_cost: string}` — backend: add this small endpoint+request in the same task, file `CountingItemController` + route, gated `can:inventory.adjust`)
- Test: `__tests__/ReviewReplayColumns.test.tsx`

**Interfaces:** consumes B3's item fields (`expected_qty_at_apply`, `replay_audit`, `flag_reasons`), A3 enum values; produces the opening-cost endpoint used pre-finalize.

Behavior: new columns "Expected now" + "Movements since count" (from `replay_audit.replayedDelta`); flag chips for `basket_window` / `negative_at_apply` / `clock_skew` / `normalized_agreement` with recount CTA (reuses existing third-count/override actions); opening items with null cost show a bulk-editable cost column; **finalize button disabled while flagged-unresolved or cost-missing opening lines exist**; unsynced-device warning banner when API exposes it (B3/C2 flags — render `late_sales_flags` count too).

- [ ] Steps: failing tests (flag chip renders from flag_reasons; finalize disabled when cost missing; override dialog emits string) → FAIL → implement → green → commit `feat(web): replay review columns, flags, opening-cost backfill (precision-safe)`

### Task D4: location settings — onboarding + policy override

**Files:**
- Modify: `apps/web/src/features/settings/LocationsPage.tsx` (form: `onboarding_mode` switch with explainer, `pos_stock_policy_override` select [inherit/block/warn/off])
- Test: extend the page's existing test file (or create `__tests__/LocationOnboarding.test.tsx`)

**Interfaces:** consumes A4's location settings fields.

- [ ] Steps: failing test → implement → green → commit `feat(web): location onboarding mode + stock policy override`

---

## Wave E — verification, review gates, handover

### Task E1: cross-layer scenario test (the morning-demo path)

**Files:**
- Create: `apps/api/tests/Feature/Inventory/LiveCountingScenarioTest.php`

One test telling the whole story, hand-computed at every step: onboarding location; sell 2 units of P (on-hand → −2); create zone count (live mode); counter counts 20 @ T (the 2 pre-T sales are already off the shelf, so 20 is what remains); sell 3 more with `occurred_at` after T (on-hand → −5); finalize. Arithmetic the test must assert inline: `expected_now = 20 + signedDelta(T→now) = 20 + (−3) = 17`; `adjustment = 17 − (−5) = +22` posted as an `opening` movement; **final on-hand = 17**; WAC = entered cost; onboarding auto-exits after the qualifying full count. Assert movement rows, `replay_audit` contents, and that no flags were raised.

- [ ] Steps: write → run → green (fix integration seams it exposes) → commit `test(inventory): live counting end-to-end scenario`

### Task E2: reviewer gates (do NOT skip)

- [ ] Dispatch `inventory-costing-reviewer` on the full branch diff (`git diff origin/dev...HEAD`) — WAC/opening/replay focus.
- [ ] Dispatch `fiscal-pos-reviewer` on POS-touching files (TerminalResource, projection, apps/pos) — fiscal immutability, projection contracts.
- [ ] Dispatch `tenancy-authz-reviewer` on routes/permissions/migrations (tenant_id columns, route middleware).
- [ ] Fix findings; re-gate BLOCKER-level items. Reviews saved under `docs/superpowers/specs/reviews/`.

### Task E3: preflight + branch push

- [ ] `cd apps/api && ./vendor/bin/phpstan analyse <new/changed paths only>` + `./vendor/bin/pint --dirty`
- [ ] `cd apps/web && pnpm lint && pnpm typecheck` (scoped if slow)
- [ ] Targeted test re-run: every test file created by this plan, BY PATH.
- [ ] `git push -u origin feat/live-inventory-counting` (feature branch only — NO merge to dev tonight; owner tests in the morning).

### Task E4: mobile handover document

**Files:**
- Create: `docs/handoff/HANDOVER-live-counting-mobile.md`

Contents (complete, for the erp-mobile session): API contract deltas with example payloads — create-draft accepts `scope_type: 'zone'` + `zone_ids` + `block_sales`; submit-count accepts `counted_at_device` + `device_now` (ISO-8601 UTC) and batch endpoints likewise; terminal-block advisory (mobile counting app: show blocking banner from session payload); zone picker data source (`GET /inventory/zones?location_id=`); explicit note that server is backward-compatible with current mobile build (missing fields ⇒ server-stamped times, no skew correction); screens to touch (`create-draft.tsx` zone picker, `[id]/index.tsx` zone header, sync payload in `draftSyncService.ts`/`useSubmitCount.ts`); recommended test cases (offline count with skewed clock end-to-end). Owner pushes erp-mobile personally.

- [ ] Write → commit `docs(handoff): mobile handover for live counting`

---

## Self-review record

- **Spec coverage:** §1 zones → A2/C1/D1; §2 occurred_at + skew → A1/B2; §3 modes → C2; §4 replay/normalization/guards/locks/overlap → B1/B3/B4/B5; §5 onboarding/opening/cost/auto-exit/zero-stock → A4/B3/C1/C3/D3; §6 surfaces → D1-D4 (+POS in C2; mobile = E4 handover); §7 schema → A1/A2/A3/A4; §8 testing → per-task + E1; §10 rollout → B3 legacy path + A1 backfill.
- **Deliberate scope cut (documented):** product-import `zone` column ships in D1 only if the import pipeline exposes a clean mapping seam; otherwise D1 notes it as follow-up — bulk-assign UI covers the need for morning testing.
- **Type consistency:** `signedDelta`/`hasMovementNear` signatures quoted identically in B1/B3/B4; flag enum values identical in A3/B2/B3/D3; `count_N_at_estimate` name identical in A3/B2/B3/B4.

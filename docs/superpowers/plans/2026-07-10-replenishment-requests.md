# Replenishment Requests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Executor note:** this plan is written for a long-running autonomous executor (Codex desktop). Every interface referenced here was verified against the codebase on 2026-07-10. If reality disagrees with the plan, STOP and report — do not improvise a replacement component or contract.

**Goal:** Shop staff request product refills from POS (offline-capable) or web; back office reviews per-shop / per-product-matrix queues and fulfills via stock transfer or draft PO; transfers auto-settle matching requests.

**Architecture:** New un-gated backend module `Replenishment` (hexagonal; line-grain `replenishment_requests` table, no header doc), a POS offline outbox + pull feed cloned from the pending-customer template, and a web feature `features/replenishment` (capture page + review queue). Fulfillment wires into existing `StockTransferService::initiate()` and draft-PO creation; settlement is event-driven (`StockTransferInitiated` / `StockTransferCancelled`) via a new `Shared/Contracts` `TransferLineReader`.

**Tech Stack:** Laravel 12 / PHP 8.2 strict / PostgreSQL partial unique indexes; React 19 + TS strict + TanStack Query 5; Tauri POS + SQLite outbox; Vitest + PHPUnit; spatie/laravel-data `#[TypeScript]`.

**Spec:** `docs/superpowers/specs/2026-07-10-replenishment-requests-design.md` (Rev 2). Review: `docs/superpowers/specs/reviews/2026-07-10-replenishment-requests-adversarial-review.md`.

## Global Constraints

**Milestone review gates (owner-mandated — top-tier adversarial review at every milestone):**
The executor MUST HARD-STOP at four gates and wait for explicit approval before continuing. At each gate: commit everything, run the listed verification, and output a gate report (tasks completed, verification commands + actual output, any deviations from the plan, files touched). The owner runs an external Opus adversarial review of the diff at each gate; findings come back as a fix list to apply before the next wave.
- **Gate A** — after Task 8 (backend complete: schema, capture, endpoints, contract, listeners, actions, types). Verify: `php artisan test tests/Feature/Replenishment tests/Feature/POS/PosReplenishmentControllerTest.php tests/Feature/Inventory/TransferLineQueryServiceTest.php` + `./vendor/bin/phpstan analyse app/Modules/Replenishment app/Shared --level=8`.
- **Gate B** — after Task 11 (POS complete). Verify: targeted vitest runs for the three POS test files + `pnpm typecheck` in apps/pos.
- **Gate C** — after Task 15 (web complete). Verify: `pnpm vitest run src/features/replenishment` + `pnpm typecheck` + `pnpm lint` in apps/web.
- **Gate D** — after Task 16 (E2E + preflight), before any merge. Nothing merges to dev without Gate D approval.

**Process:**
- Work in a git worktree off local `dev` on branch `feat/replenishment-requests`. Never commit to shared `dev` directly; never push `dev`.
- TDD every task: failing test → minimal code → green → commit. Commit after every task (small commits within tasks are fine).
- NEVER run the full PHPUnit suite (crashes the machine). Run by path: `php artisan test tests/Feature/Replenishment` etc. Same for vitest: run specific files. If a vitest run hangs: `ps aux | grep 'node (vitest'` and kill workers.
- Preflight before finishing: `./scripts/preflight.sh` from repo root (PHPStan L8 zero errors on new code, Pint, TS strict, ESLint).
- After changing/adding PHP DTOs with `#[TypeScript]`: `cd apps/api && CACHE_STORE=array php artisan typescript:transform`. Never hand-edit `packages/shared/types/`.

**Backend rules:**
- Constructor injection with `private readonly` ONLY — never `app()`.
- Enums for status/type columns. No `mixed`. Strict types everywhere.
- `tenant_id`/`company_id` from `CompanyContext` (`requireTenantId()`/`requireCompanyId()`) — never from payload.
- Cross-module access ONLY via events, public service classes, or `app/Shared/Contracts` — never import another module's Eloquent models (importing DTOs from `App\Modules\X\Application\DTOs` for a public service call is the established exception; model imports are not).
- Quantities: `decimal(15,4)`, validation `['numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/']`. No money fields in this module; no float ever touches a quantity (strings end-to-end).
- Routes: `Route::prefix('api/v1')->middleware(['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class])` + per-route `->middleware('can:...')`. POS endpoints instead gate in-controller: `Gate::authorize('pos.operate_terminal')`.
- Permissions seeded in `database/seeders/RolesAndPermissionsSeeder.php` ONLY (NOT `PermissionSeeder.php` — it never runs for tenants).

**Wire & runtime contracts locked by the adversarial reviews (do not weaken):**
- **All read-endpoint responses emit snake_case keys** (`ReplenishmentRequestResource::toArray` → `product_id`, `requested_qty`, `request_count`, `last_requested_at`, …), matching the POS house style (`PosStockLevelController.php:97-110`) and the TS client types in Tasks 10/12. The `#[TypeScript]` DTO (camelCase props) is NOT the wire contract for these endpoints — this exact camelCase-resource-vs-snake_case-client mismatch shipped a dead feature once already (pricing verdict endpoint).
- **Settlement/re-open listeners are plain synchronous classes — do NOT implement `ShouldQueue`.** A queued listener runs with no CompanyContext on the central connection under db-per-tenant and silently writes to the wrong DB.
- `documents`/`document_lines` money columns are `decimal(15,3)` (widened by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php`); make NO schema changes. Rule-19 math is unchanged: resolve `$scale` from the document currency through constructor-injected `CurrencyScaleResolverInterface`, use `$scale + 1` intermediates, and round once at the boundary with `CurrencyScale::bcformatStrict`.
- POS capture: `client_request_uuid` replay → 200 with existing row. Natural-key dedupe collision → **perform the bump, return 200** with the bumped line. NEVER 409/4xx for a collision (it would wedge the POS outbox retry loop). Only cross-tenant/company contract violations return 4xx.
- Web capture: submitted `location_id` validated via `LocationContext::validateLocationAccess()`; picker constrained to `getAllowedLocationIds()`.
- Review actions must `Gate::authorize('inventory.transfers.create')` / `Gate::authorize('purchase-orders.create')` at the point of creating the downstream doc — `replenishment.process` alone is NOT sufficient.

**Frontend guardrails (owner-mandated — reuse, never reinvent):**

| Need | MUST use (verified export + path) | Never |
|---|---|---|
| Pagination | `OffsetPagination` `@/components/ui/OffsetPagination` (props: currentPage/lastPage/total/perPage/from/to/onPageChange/onPerPageChange — **no `hidePerPage` prop exists**) | hand-rolled pagers |
| Empty states | `EmptyState` `@/components/molecules/EmptyState/EmptyState` (`{title, description?, icon?}`) | ad-hoc "no data" divs |
| Query errors | `QueryError` `@/components/QueryError` (`{error, onRetry?}`) | inline error strings |
| Status pills | `StatusBadge` `@/components/atoms/StatusBadge/StatusBadge` (`tone: 'neutral'\|'info'\|'success'\|'warning'\|'danger'\|'pending'`, children = translated label); feature-local `ReplenishmentStatusBadge` maps status→tone like `StockTransferStatusBadge` | new pill components, raw color classes |
| Product search + scan entry | `LineItemEntryBar` `@/components/molecules/line-items/LineItemEntryBar` (`onAddProduct(product, meta)`, `onBeforeAdd?`, `onNotFound?`) | new product pickers |
| Location multi-select | `LocationSelectorMulti` `@/features/locations/components/LocationSelectorMulti` (`{value: string[], onChange(ids)}`) | new location dropdowns |
| Date filters | `DateRangeFilter` `@/components/ui/filters/DateRangeFilter` | raw date inputs |
| Product×shop matrix | copy the dynamic-column CSS-grid pattern from `features/purchases/quote-requests/QuoteRequestComparisonPage.tsx:97-169` (`gridTemplateColumns: minmax(220px,1fr) repeat(N, minmax(180px,1fr))`, rows via `className="contents"`, cells looked up by `row.cells[columnId]`) | `<table>` pivots, new grid frameworks |
| Colors/spacing | `tokens`, `textColors`, `borderColors` from `@/lib/designTokens` (new feature dir will be ESLint-`error` for hardcoded Tailwind colors) | `bg-blue-600` etc. |
| Quantity input | `QuantityInput` from `@/components/atoms` (emits strings) | `parseFloat`, `<input type=number>` on quantities |
| POS buttons/dialogs | `Button` from `@/components/ui` (`variant: 'primary'\|'confirm'\|'secondary'\|'ghost'\|'destructive'`); `Modal` from `@/components/pos/Modal` (`{isOpen,onClose,title,children,footer?,size?}`) | hand-rolled overlays (LineDiscountModal's hand-rolled dialog is legacy — use `Modal`) |
| Atomic placement | web: cross-feature UI → `src/components/{atoms,molecules,organisms}`; feature-specific → `src/features/replenishment/components/`. POS: organism = `src/components/organisms/<Name>/<Name>.tsx` + `index.ts` re-export + `__tests__/` | shared components dumped in feature dirs, feature one-offs in the shared tree |
| Data fetching | paginated `{data,meta}` → `api.get` + return `response.data`; single-resource → `apiGet` (already unwraps). ALL query keys via `tenantScopedKey([...])` | double-unwrapping; raw query keys |
| Text | every string through `t()`; new namespace `replenishment` (en/fr/ar), FR uses "réassort" | hardcoded strings |
| POS timestamps | outbox columns `TEXT NOT NULL` with JS-supplied ISO (`nowIso()`), **no `DEFAULT (datetime('now'))`**; comparisons via `toSqliteUtc()` | mixing ISO and `datetime('now')` |

---

## File Structure

**Backend (new module `apps/api/app/Modules/Replenishment/`):**
```
Providers/ReplenishmentServiceProvider.php     — routes, event listeners, (no bindings yet)
Domain/ReplenishmentRequest.php                — Eloquent model
Domain/ReplenishmentCaptureReceipt.php         — idempotency-ledger model (Rev 3)
Domain/Enums/ReplenishmentStatus.php           — pending|in_progress|fulfilled|rejected|cancelled
Domain/Enums/ReplenishmentChannel.php          — pos|web
Domain/Enums/ReplenishmentFulfillmentType.php  — transfer|purchase_order
Domain/Events/{ReplenishmentRequested,ReplenishmentRequestBumped,ReplenishmentSourced,ReplenishmentFulfilled,ReplenishmentReopened,ReplenishmentRejected}.php
Application/Services/ReplenishmentCaptureService.php    — atomic insert-or-bump
Application/Services/ReplenishmentFulfillmentService.php — transfer/PO/reject actions
Application/Listeners/SettleRequestsOnTransferInitiated.php
Application/Listeners/ReopenRequestsOnTransferCancelled.php
Application/DTOs/{CaptureRequestData,ReplenishmentRequestData}.php   — #[TypeScript]
Presentation/routes.php
Presentation/Controllers/{ReplenishmentRequestController,ReplenishmentActionController}.php
Presentation/Requests/{CaptureReplenishmentRequest,CreateTransferFromRequestsRequest,CreatePoFromRequestsRequest,RejectRequestsRequest}.php
Presentation/Resources/ReplenishmentRequestResource.php
```
**Backend (touch existing):**
```
apps/api/bootstrap/providers.php                          — register provider
apps/api/app/Shared/Contracts/TransferLineReader.php      — NEW contract
apps/api/app/Shared/DTOs/TransferLineDTO.php              — NEW DTO
apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php — NEW impl
apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php — bind contract
apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php — NEW (POS capture + pull)
apps/api/app/Modules/POS/routes.php                       — 2 routes
apps/api/database/seeders/RolesAndPermissionsSeeder.php   — permissions + role grants
apps/api/database/migrations/tenant/2026_07_10_100000_create_replenishment_requests_table.php
```
**POS (`apps/pos/src/`):**
```
lib/db/migrations.ts                                — append version 60 (outbox + open-requests cache)
lib/db/repositories/replenishmentOutboxRepository.ts — clone of pendingCustomerRepository
lib/db/repositories/openReplenishmentRepository.ts   — pull-feed cache
lib/replenishment/replenishmentSyncService.ts        — push driver (clone pendingCustomerSyncService)
lib/sync/syncService.ts                              — wire push + pull steps
api/replenishmentApi.ts                              — POST + paged GET clients
lib/audit/eventTypes.ts                              — add 'pos.replenishment_requested'
components/organisms/RequestRefillSheet/{RequestRefillSheet.tsx,index.ts,__tests__/}
components/pos/ProductDetailDrawer.tsx               — mount button + sheet
```
**Web (`apps/web/src/`):**
```
features/replenishment/{index.ts,types/index.ts}
features/replenishment/api/{replenishmentApi.ts,queries.ts}
features/replenishment/components/{ReplenishmentStatusBadge.tsx,RequestContextPanel.tsx,CreateTransferDialog.tsx,AddToPoDialog.tsx,RejectDialog.tsx,index.ts}
features/replenishment/pages/{ReplenishmentQueuePage.tsx,ReplenishmentCapturePage.tsx,index.ts}
features/replenishment/__tests__/
routes/index.tsx                                     — 2 routes under Inventory section
lib/i18n.ts + locales/{en,fr,ar}/replenishment.json
```

---

### Task 0: Worktree + baseline commit

**Files:** none (git only)

- [ ] **Step 1:** From `/Users/houssamr/Projects/syneriva/apps/erp`: `git worktree add ../erp.replenishment -b feat/replenishment-requests dev && cd ../erp.replenishment`
- [ ] **Step 2:** Verify the worktree's `apps/api/vendor` and `apps/web/node_modules` resolve (run `cd apps/api && php artisan --version` and `cd apps/web && pnpm typecheck --help`); if vendor is a symlink to the main repo, STOP and report (known landmine: stale main-repo code runs instead of branch code).
- [ ] **Step 3:** Commit the spec, plan, and review docs (they currently sit untracked):
```bash
git add docs/superpowers/specs/2026-07-10-replenishment-requests-design.md docs/superpowers/specs/reviews/2026-07-10-replenishment-requests-adversarial-review.md docs/superpowers/plans/2026-07-10-replenishment-requests.md
git commit -m "docs(replenishment): spec Rev 2 + adversarial review + implementation plan"
```

### Task 1: Migration, model, enums, module scaffold

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_10_100000_create_replenishment_requests_table.php`
- Create: `apps/api/app/Modules/Replenishment/Domain/Enums/{ReplenishmentStatus,ReplenishmentChannel,ReplenishmentFulfillmentType}.php`
- Create: `apps/api/app/Modules/Replenishment/Domain/ReplenishmentRequest.php`
- Create: `apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php` + empty `Presentation/routes.php`
- Modify: `apps/api/bootstrap/providers.php` (append `ReplenishmentServiceProvider::class,` after `ProcurementServiceProvider::class,`)
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentSchemaTest.php`

**Interfaces:**
- Produces: model `App\Modules\Replenishment\Domain\ReplenishmentRequest` (table `replenishment_requests`), enums `ReplenishmentStatus::{Pending,InProgress,Fulfilled,Rejected,Cancelled}` (string-backed: `pending|in_progress|fulfilled|rejected|cancelled`) with helpers `isOpen(): bool` (Pending|InProgress) and `isTerminal(): bool`; `ReplenishmentChannel::{Pos,Web}`; `ReplenishmentFulfillmentType::{Transfer,PurchaseOrder}` (`transfer|purchase_order`).

- [ ] **Step 1: Failing test** — `ReplenishmentSchemaTest` (uses `RefreshDatabase`): asserts table exists with expected columns; asserts the two partial unique indexes exist by inserting duplicate open rows and expecting `QueryException` (23505), while a duplicate against a `fulfilled` row succeeds:
```php
public function test_open_rows_unique_per_product_location(): void
{
    $base = ['tenant_id'=>$t=Str::uuid()->toString(),'company_id'=>$c=Str::uuid()->toString(),
        'location_id'=>$l=Str::uuid()->toString(),'product_id'=>$p=Str::uuid()->toString(),
        'status'=>'pending','source_channel'=>'web','requested_by_user_id'=>Str::uuid()->toString(),
        'first_requested_at'=>now(),'last_requested_at'=>now()];
    ReplenishmentRequest::create($base);
    $this->expectException(QueryException::class);
    ReplenishmentRequest::create($base);
}
public function test_closed_rows_do_not_block_new_open_row(): void { /* create fulfilled row, then pending same key → succeeds, 2 rows */ }
public function test_variant_rows_unique_separately(): void { /* same product different variant_id both insert; same variant twice throws */ }
```
- [ ] **Step 2:** Run: `cd apps/api && php artisan test tests/Feature/Replenishment/ReplenishmentSchemaTest.php` → FAIL (table missing).
- [ ] **Step 3: Migration** — key content (uuid PK; FKs to locations/products/product_variants/users nullable-appropriate; all timestamps `timestampTz`):
```php
Schema::create('replenishment_requests', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->index();
    $table->uuid('company_id');
    $table->uuid('location_id');
    $table->uuid('product_id');
    $table->uuid('variant_id')->nullable();
    $table->decimal('requested_qty', 15, 4)->nullable();
    $table->text('note')->nullable();
    $table->unsignedInteger('request_count')->default(1);
    $table->string('status', 20)->default('pending');
    $table->string('source_channel', 10);
    $table->uuid('requested_by_user_id');
    $table->timestampTz('first_requested_at');
    $table->timestampTz('last_requested_at');
    $table->uuid('client_request_uuid')->nullable();
    $table->uuid('sourcing_document_id')->nullable();
    $table->string('fulfillment_type', 20)->nullable();
    $table->uuid('fulfillment_id')->nullable();
    $table->uuid('processed_by_user_id')->nullable();
    $table->timestampTz('processed_at')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->timestampsTz();
    $table->foreign('location_id')->references('id')->on('locations');
    $table->foreign('product_id')->references('id')->on('products');
    $table->index(['company_id', 'status', 'location_id']);
    $table->unique(['tenant_id', 'company_id', 'client_request_uuid'], 'replenishment_client_uuid_unique');
});
DB::statement("CREATE UNIQUE INDEX replenishment_open_non_variant ON replenishment_requests (company_id, location_id, product_id) WHERE variant_id IS NULL AND status IN ('pending','in_progress')");
DB::statement("CREATE UNIQUE INDEX replenishment_open_with_variant ON replenishment_requests (company_id, location_id, product_id, variant_id) WHERE variant_id IS NOT NULL AND status IN ('pending','in_progress')");

Schema::create('replenishment_capture_receipts', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');
    $table->uuid('client_request_uuid');
    $table->uuid('request_id');
    $table->timestampTz('applied_at');
    $table->unique(['tenant_id', 'client_request_uuid'], 'replenishment_receipt_uuid_unique'); // tenant-wide: enables the cross-company 409 probe
    $table->foreign('request_id')->references('id')->on('replenishment_requests');
});
```
(`client_request_uuid` on `replenishment_requests` stays as the creator's uuid — PG treats NULLs as distinct so the plain composite unique works with nullable uuid. The **receipts ledger** is the idempotency authority: every applied capture, insert or bump, records its uuid there; note the receipt unique is tenant-scoped (not per-company) so a same-tenant/other-company uuid reuse collides and can be answered with 409.)
- [ ] **Step 4:** Model: `HasUuids`, `$table='replenishment_requests'`, guarded `[]`→use explicit `$fillable` (all columns above), casts: `status => ReplenishmentStatus::class`, `source_channel => ReplenishmentChannel::class`, `fulfillment_type => ReplenishmentFulfillmentType::class`, `requested_qty => 'decimal:4'` **(string cast — never float)**, datetimes `immutable_datetime`. Scope `scopeOpen($q)` → `whereIn('status', [Pending, InProgress])`.
- [ ] **Step 5:** Provider (clone `ProcurementServiceProvider` shape: `boot()` → `loadRoutesFrom(__DIR__.'/../Presentation/routes.php')`); register in `bootstrap/providers.php`. `routes.php` = empty group for now (header per Global Constraints).
- [ ] **Step 6:** Run test → PASS. Run `./vendor/bin/phpstan analyse app/Modules/Replenishment --level=8` → 0 errors.
- [ ] **Step 7:** Commit: `feat(replenishment): schema, model, enums, module scaffold`

### Task 2: Capture service — atomic insert-or-bump + events

**Files:**
- Create: `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentCaptureService.php`
- Create: `apps/api/app/Modules/Replenishment/Application/DTOs/CaptureRequestData.php`
- Create: the 6 event classes under `Domain/Events/` (plain readonly-property classes with `Dispatchable`, mirroring `StockTransferInitiated`'s shape)
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentCaptureServiceTest.php`

**Interfaces:**
- Produces: `ReplenishmentCaptureService::capture(CaptureRequestData $data): ReplenishmentRequest` where
```php
final class CaptureRequestData {
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $requestedQty,   // numeric-string or null
        public readonly ?string $note,
        public readonly string $requestedByUserId,
        public readonly ReplenishmentChannel $channel,
        public readonly ?string $clientRequestUuid = null,
    ) {}
}
```
- Behavior contract: (a) `clientRequestUuid` replay (row already exists with that uuid) → return existing row unchanged, no bump; (b) no open row for the natural key → insert `pending`, emit `ReplenishmentRequested`; (c) open row exists → bump: `requested_qty` = both-non-null ? bcadd(scale 4) : whichever is non-null; `note` appended (`"\n---\n"` separator) when new note present; `request_count + 1`; `last_requested_at = now()`; emit `ReplenishmentRequestBumped`. Return value exposes `wasRecentlyCreated` to let controllers pick 201 vs 200.

- [ ] **Step 1: Failing tests** (RefreshDatabase; real Location/Product factories per Testing Conventions; valid UUIDs for all FKs):
```php
test_insert_creates_pending_row_and_emits_requested()
test_second_request_same_key_bumps_not_forks()      // qty 2 + 3 → '5.0000', request_count 2, still 1 row
test_qty_null_merge_keeps_existing_value()          // null + 3 → 3; 3 + null → 3
test_client_uuid_replay_returns_existing_without_bump()   // insert-path uuid
test_bump_path_uuid_replay_is_idempotent()                 // B4 pin: create with U1, bump with U2, RE-SEND U2 → request_count/requested_qty UNCHANGED
test_cross_company_uuid_replay_throws()                    // uuid recorded under company A, replayed under company B (same tenant) → CrossCompanyReplayException
test_closed_line_gets_fresh_row()                   // fulfilled row for key, capture → new pending row
test_variant_scoped_dedupe()                        // same product, variants A/B → 2 rows
test_concurrent_insert_race_resolved_by_retry()     // pre-insert open row inside a listener hooked before service insert path? — implement as: force unique violation by seeding row between existence-check and insert using DB::listen; simpler deterministic version: call private upsert twice and assert single row (documented as race-approximation)
```
- [ ] **Step 2:** Run → FAIL (service missing).
- [ ] **Step 3: Implementation** — the atomic core (variant-null branches to match each partial index; retry-once on 23505):
```php
public function capture(CaptureRequestData $data): ReplenishmentRequest
{
    if ($data->clientRequestUuid !== null) {
        $receipt = ReplenishmentCaptureReceipt::query()
            ->where('tenant_id', $data->tenantId)
            ->where('client_request_uuid', $data->clientRequestUuid)->first();
        if ($receipt !== null) {
            if ($receipt->company_id !== $data->companyId) {
                throw new CrossCompanyReplayException($data->clientRequestUuid); // controller maps → 409 (permanent)
            }
            return ReplenishmentRequest::query()->findOrFail($receipt->request_id); // replay: insert OR bump uuid → no-op
        }
    }
    try {
        return $this->insertOrBump($data);
    } catch (QueryException $e) {
        if ($this->isUniqueViolation($e)) { return $this->insertOrBump($data); } // concurrent insert → second pass bumps
        throw $e;
    }
}
// insertOrBump additionally records the receipt INSIDE the same DB::transaction, for both branches:
//   if ($data->clientRequestUuid !== null) {
//       ReplenishmentCaptureReceipt::create(['tenant_id'=>..., 'company_id'=>..., 'client_request_uuid'=>$data->clientRequestUuid, 'request_id'=>$row->id, 'applied_at'=>now()]);
//   }
// `isUniqueViolation` must ALSO match 'replenishment_receipt_uuid_unique' (a receipt race replays cleanly on retry).
// Define `CrossCompanyReplayException` in Domain/Exceptions/.
private function insertOrBump(CaptureRequestData $data): ReplenishmentRequest
{
    return DB::transaction(function () use ($data) {
        $open = ReplenishmentRequest::query()
            ->where('company_id', $data->companyId)->where('location_id', $data->locationId)
            ->where('product_id', $data->productId)
            ->when($data->variantId === null, fn ($q) => $q->whereNull('variant_id'),
                   fn ($q) => $q->where('variant_id', $data->variantId))
            ->whereIn('status', [ReplenishmentStatus::Pending, ReplenishmentStatus::InProgress])
            ->lockForUpdate()->first();
        if ($open === null) {
            $row = ReplenishmentRequest::create([...]);           // full column set; status pending
            ReplenishmentRequested::dispatch(...);                 // ids + key fields
            return $row;
        }
        $open->requested_qty = match (true) {
            $open->requested_qty !== null && $data->requestedQty !== null => bcadd($open->requested_qty, $data->requestedQty, 4),
            $data->requestedQty !== null => $data->requestedQty,
            default => $open->requested_qty,
        };
        if ($data->note !== null && $data->note !== '') {
            $open->note = $open->note === null ? $data->note : $open->note."\n---\n".$data->note;
        }
        $open->request_count += 1;
        $open->last_requested_at = now();
        $open->save();
        ReplenishmentRequestBumped::dispatch(...);
        return $open;
    });
}
private function isUniqueViolation(QueryException $e): bool
{
    return in_array($e->errorInfo[0] ?? null, ['23000', '23505'], true)
        && str_contains($e->getMessage(), 'replenishment_open_');
}
```
`lockForUpdate` + partial-unique backstop + retry-once = the concurrency answer from the review (do NOT attempt `ON CONFLICT` against the status-predicated partial index — Eloquent upsert can't express it reliably).
- [ ] **Step 4:** Run tests → PASS. PHPStan module path → 0. Commit: `feat(replenishment): capture service with atomic dedupe-bump`

### Task 3: Web capture + list + cancel endpoints

**Files:**
- Create: `Presentation/Controllers/ReplenishmentRequestController.php`, `Presentation/Requests/CaptureReplenishmentRequest.php`, `Presentation/Resources/ReplenishmentRequestResource.php`, `Application/DTOs/ReplenishmentRequestData.php` (`#[TypeScript]`, extends `Data` — fields mirror the resource: id, locationId, locationName, productId, productName, variantId, variantName, requestedQty, note, requestCount, status, sourceChannel, firstRequestedAt, lastRequestedAt, sourcingDocumentId, fulfillmentType, fulfillmentId, rejectionReason)
- Modify: `Presentation/routes.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentRequestEndpointsTest.php`

**Interfaces:**
- Produces routes: `GET /api/v1/replenishment-requests` (`can:replenishment.view`; filters `status` = `open|pending|in_progress|fulfilled|rejected|cancelled`, `location_ids[]`, `product_id`, `from`/`to` dates; `status=open` returns ALL open lines unpaginated ordered `last_requested_at desc` — capped 500 with `meta.truncated=true` beyond; other statuses paginated `{data,meta}` 25/page), `POST /api/v1/replenishment-requests` (`can:replenishment.create`), `POST /api/v1/replenishment-requests/{id}/cancel` (requester cancels own pending line; also allowed for `replenishment.process` holders).
- Permissions: add to `createPermissions()` after the `pos.` block: `'replenishment.view', 'replenishment.create', 'replenishment.process',`. Role grants: append all three to `$manager->syncPermissions([...])`; append `'replenishment.view','replenishment.create'` to `operator`'s list. Cashier unchanged.

- [ ] **Step 1: Failing tests** (seed `RolesAndPermissionsSeeder`; create users with roles; `actingAs`):
```php
test_capture_creates_pending_line_201()
test_capture_bump_returns_200_with_bumped_line()
test_capture_rejects_location_outside_allowed_location_ids()   // membership with allowed_location_ids=[A]; submit B → 403
test_capture_rejects_location_of_other_company()               // 422
test_qty_regex_rejects_5dp()                                    // 422 on '1.00001'
test_list_open_groups_all_open_lines()                          // returns pending + in_progress
test_list_fulfilled_is_paginated_with_meta()
test_cancel_own_pending_line_ok_other_users_line_403_unless_processor()
test_view_requires_permission_403_for_cashier_role()
```
- [ ] **Step 2:** FAIL. **Step 3:** Implement. FormRequest rules:
```php
'location_id' => ['required','uuid'],
'product_id' => ['required','uuid', ScopedExists::tenantAndCompany('products', $tenantId, $companyId)],
'variant_id' => ['nullable','uuid'],
'requested_qty' => ['nullable','numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/'],
'note' => ['nullable','string','max:2000'],
```
Controller `store()`: `$companyId = $this->companyContext->requireCompanyId();` then `$this->locationContext->validateLocationAccess($validated['location_id'], $companyId);` (catch `RuntimeException` → 403 JSON) + assert the location row belongs to `$companyId` (422 otherwise); build `CaptureRequestData` with `channel: ReplenishmentChannel::Web`; respond `$row->wasRecentlyCreated ? 201 : 200`.
`index()` location scoping — `getAllowedLocationIds()` is **three-valued** (`LocationContext.php:194-207`); branch explicitly:
```php
if (! $request->user()->can('replenishment.process')) {
    $allowed = $this->locationContext->getAllowedLocationIds($companyId);
    if ($allowed === null)      { /* unrestricted membership: no location filter */ }
    elseif ($allowed === [])    { return ...empty result...; }        // no membership: fail-closed
    else                        { $query->whereIn('location_id', $allowed); }
    // intersect with any client-supplied location_ids filter (never widen)
}
// Processors intentionally bypass membership location limits (spec §6 "reviewers see all by design") — comment this in code.
```
`cancel` route MUST carry `->whereUuid('id')` (Procurement convention — a non-UUID id otherwise 500s against the PG uuid column). Cancel authorization (in-controller):
```php
abort_unless($row->company_id === $companyId, 404);
abort_unless($row->status === ReplenishmentStatus::Pending, 422);
abort_unless($row->requested_by_user_id === $request->user()->id || $request->user()->can('replenishment.process'), 403);
```
- [ ] **Step 4:** PASS → PHPStan → Commit: `feat(replenishment): web capture/list/cancel endpoints + permissions`

### Task 4: POS capture endpoint + pull feed

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php`
- Create: `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentQueryService.php` — public module seam for the POS pull feed; `feedForLocation(tenantId, companyId, locationId, closedWithinDays: 14, cap: 200)` returns `{rows, truncated}` without exposing the Eloquent model to POS code.
- Modify: `apps/api/app/Modules/POS/routes.php` — add:
```php
Route::post('/pos/replenishment-requests', [PosReplenishmentController::class, 'store'])->name('pos.replenishment.store');
Route::get('/pos/replenishment-requests', [PosReplenishmentController::class, 'index'])->name('pos.replenishment.index');
```
- Test: `apps/api/tests/Feature/POS/PosReplenishmentControllerTest.php`

**Interfaces:**
- `store()` body: `{ client_request_uuid: uuid (required), terminal_id: uuid (required), product_id: uuid (required), variant_id?: uuid, requested_qty?: numeric-string ≤4dp, note?: string }`. Terminal looked up `Terminal::query()->where('company_id',$companyId)->where('id',$terminalId)->first()` → 404 `TERMINAL_NOT_FOUND` if absent (exact `PosStockLevelController` pattern); `location_id` = `$terminal->location_id`; **any payload `location_id` is ignored** (not in validation rules). Replay (via receipts ledger — covers bump-path retries) → 200; new → 201; natural-key collision with fresh uuid → bump → 200. `CrossCompanyReplayException` → 409 `{error:{code:'REPLENISHMENT_UUID_COMPANY_CONFLICT'}}` (permanent — client marks failed). Response resource = same `ReplenishmentRequestResource`, which **emits snake_case keys** (see Global Constraints wire contract).
- `index()` query: `{ terminal_id: uuid (required) }` → open lines + lines closed within the last 14 days for the terminal's location, ordered `last_requested_at desc`, capped 200 with a `truncated: bool` flag in the response (`{ data: [...], as_of: ISO, truncated }`) so the device chip stays honest for busy shops. Gate: `Gate::authorize('pos.operate_terminal')` on both.

- [ ] **Step 1: Failing tests:** create+replay+bump trio (assert bump returns **200 and never 409**); `test_payload_location_id_is_ignored()` (send a bogus `location_id`, assert row uses terminal's location); `test_unknown_terminal_404()`; `test_terminal_of_other_company_404()`; `test_pull_feed_scoped_to_terminal_location()`; `test_cross_company_uuid_replay_conflict_409()` (client_request_uuid exists under another company → 409 permanent, mirroring alias conflict).
- [ ] **Step 2:** FAIL → **Step 3:** implement (constructor: `CompanyContext` + `ReplenishmentCaptureService` + public `ReplenishmentQueryService`; `requested_by_user_id` = `$request->user()->id`; channel `Pos`; POS never imports the Replenishment Eloquent model). → **Step 4:** PASS, PHPStan, commit: `feat(replenishment): POS capture + pull endpoints`

### Task 5: TransferLineReader contract + Inventory implementation

**Files:**
- Create: `apps/api/app/Shared/Contracts/TransferLineReader.php`, `apps/api/app/Shared/DTOs/TransferLineDTO.php`
- Create: `apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php`
- Modify: `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php` `register()`: `$this->app->bind(TransferLineReader::class, TransferLineQueryService::class);`
- Test: `apps/api/tests/Feature/Inventory/TransferLineQueryServiceTest.php`

**Interfaces:**
```php
interface TransferLineReader
{
    /** @return list<TransferLineDTO> */
    public function linesForTransfer(string $tenantId, string $companyId, string $transferId): array;
}
final class TransferLineDTO {
    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly string $quantity,   // numeric-string
    ) {}
}
```
- [ ] Steps: failing test (initiate a real transfer via `StockTransferService`, read lines back; wrong tenant/company → empty array) → implement (query `StockTransferLine` internally — legal, it lives in Inventory) → PASS → commit: `feat(inventory): TransferLineReader shared contract`

### Task 6: Settlement + re-open listeners

**Files:**
- Create: `Application/Listeners/SettleRequestsOnTransferInitiated.php`, `Application/Listeners/ReopenRequestsOnTransferCancelled.php`
- Modify: `ReplenishmentServiceProvider::boot()` — `Event::listen(StockTransferInitiated::class, SettleRequestsOnTransferInitiated::class); Event::listen(StockTransferCancelled::class, ReopenRequestsOnTransferCancelled::class);` (cross-module listener registration mirrors `InventoryServiceProvider::registerEventListeners()`)
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentSettlementTest.php`

**Behavior (locked by spec Rev 2):**
- Both listeners are **plain synchronous classes (never `ShouldQueue`)** — they fire inside `DB::afterCommit` of the initiating request (`StockTransferService.php:476-489`); a queued variant would run without CompanyContext on the wrong connection. Because the transfer is already committed when they run, **wrap each per-line unit of work in try/catch (log + continue)** — a settlement failure must never bubble a 500 to the transfer caller or abort the remaining lines.
- Settle: for each `TransferLineDTO` of the initiated transfer, match open lines on `(company_id = event->companyId, location_id = event->destinationLocationId, product_id, variant_id)` (variant-null matches variant-null only) → set `status=Fulfilled, fulfillment_type=Transfer, fulfillment_id=transferId, processed_at=now()`; `processed_by_user_id = event->initiatedByUserId`; emit `ReplenishmentFulfilled`. Transfers with no matches = no-op. Constructor-inject `TransferLineReader`.
- Re-open: lines `where fulfillment_type=Transfer and fulfillment_id=event->transferId and status=Fulfilled` → back to `Pending`, clear fulfillment fields, append note `"transfer {transferNumber} cancelled"`, emit `ReplenishmentReopened`. **Collision edge (from review):** if an open line for the same natural key was created after fulfillment, the flip to Pending violates the partial index → catch unique violation per line, merge instead: bump the existing open line (`request_count += reopened line's request_count`, append its note) and mark the old line `Cancelled` with `rejection_reason='superseded_after_transfer_cancelled'`.

- [ ] **Step 1: Failing tests:**
```php
test_initiated_transfer_settles_matching_open_lines()           // pending + in_progress both settle
test_settlement_matches_variant_grain_exactly()                 // variant line doesn't settle product-grain line
test_settlement_ignores_other_company_and_other_destination()
test_unrelated_transfer_is_noop()
test_cancelled_transfer_reopens_line()
test_cancelled_transfer_reopen_collision_merges_into_new_open_line()
```
- [ ] **Step 2-4:** FAIL → implement → PASS (these tests run the REAL `StockTransferService::initiate/cancel` — no faked events), PHPStan, commit: `feat(replenishment): transfer settlement + compensating re-open`

### Task 7: Review actions — transfer / PO / reject

**Files:**
- Create: `Application/Services/ReplenishmentFulfillmentService.php`, `Presentation/Controllers/ReplenishmentActionController.php`, `Presentation/Requests/{CreateTransferFromRequestsRequest,CreatePoFromRequestsRequest,RejectRequestsRequest}.php`
- Modify: `Presentation/routes.php` — `POST /replenishment-requests/actions/create-transfer|create-po|reject`, all `can:replenishment.process`
- Test: `apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php`

**Interfaces:**
- `create-transfer` body: `{ source_location_id: uuid, lines: [{ request_id: uuid, quantity: numeric-string >0 ≤4dp }] }`. Controller: `Gate::authorize('inventory.transfers.create')` **in addition to** route `can:replenishment.process`. Service groups request lines by their `location_id` (destination) → one `InitiateTransferData` per (source→destination) with `idempotencyKey = 'replenishment:'.sha1($sourceLocationId.':'.$destinationLocationId.':'.implode(',', sortedRequestIdsOfTHISgroup))` — **the key MUST be computed per destination group** (a selection-wide key would make `StockTransferService`'s idempotency short-circuit return the first transfer for every subsequent group, silently never creating the others), `initiatedByUserId = auth user`, lines `InitiateTransferLineData(productId, quantity, variantId)`. FormRequest rules: `source_location_id => ['required','uuid']` + controller-side `LocationContext::validateLocationAccess` is NOT required (reviewer acts company-wide) but the location must belong to the company (422); `lines.*.request_id => ['required','uuid']`, `lines.*.quantity => ['required','numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/']`. Requests are settled by the Task-6 listener (assert in tests — do NOT double-write status here). Validation: every request must be open and belong to the company; 422 listing offending ids otherwise; `source_location_id != destination` enforced by `StockTransferService` (surface its exception as 422).
- `create-po` body: `{ supplier_id: uuid, destination_location_id: uuid, existing_document_id?: uuid, lines: [{ request_id: uuid, quantity: numeric-string }] }`. Controller: `Gate::authorize('purchase-orders.create')`. **Module boundary is strict here: Replenishment NEVER imports `Product`, `Document`, or `DocumentLine` models.** All PO writing lives in a new Document-module public service; Replenishment passes IDs only:
```php
// apps/api/app/Modules/Document/Application/DTOs/DraftPurchaseOrderData.php
final class DraftPurchaseOrderData {
    /** @param list<DraftPurchaseOrderLineData> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $supplierId,          // partner_id
        public readonly string $destinationLocationId,
        public readonly string $createdByUserId,
        public readonly array $lines,
    ) {}
}
final class DraftPurchaseOrderLineData {
    public function __construct(
        public readonly string $productId,
        public readonly string $quantity,            // numeric-string ≤4dp
        public readonly ?string $variantId,
        public readonly ?string $lineLocationId,     // stamped onto document_lines.location_id (column exists: migration 2025_12_27_150000; effective-location accessor DocumentLine.php:231-240 falls back to document)
    ) {}
}
// apps/api/app/Modules/Document/Application/Services/DraftPurchaseOrderService.php
public function createDraft(DraftPurchaseOrderData $data): Document;
public function appendLines(string $documentId, string $companyId, string $supplierId, array $lines): Document;
```
Inside `DraftPurchaseOrderService` (Document module — model access legal): resolve `unit_price` from `Product::purchase_price ?? '0'`, resolve line tax via the same `DocumentLineTaxResolver` the PO controller uses, and create with the **full NOT-NULL field set** (verified against migrations — omit any and the insert throws):
  - `Document::create` MUST include: `tenant_id, company_id, type=DocumentType::PurchaseOrder, status=DocumentStatus::Draft, fiscal_status=FiscalStatus::Draft, fiscal_category=FiscalCategory::fromDocumentType(...), partner_id, location_id=$destinationLocationId, document_number=$this->numberingService->generateNumber($tenantId,$companyId,DocumentType::PurchaseOrder),` **`document_date => now()->toDateString()` (NOT NULL, no default — `create_documents_table.php:21`)**, `currency` (company currency), `subtotal/tax_amount/total` computed from lines.
  - each `DocumentLine::create` MUST include **`line_number` (NOT NULL) and `description` (NOT NULL — product-name snapshot)** plus `document_id, product_id, quantity, unit_price, line_total, tax fields, location_id=$line->lineLocationId`. Money per rule 19: `$lineTotal = CurrencyScale::bcformatStrict(bcmul($qty, $unitPrice, $scale + 1), $scale)`, `$scale = $this->scaleResolver->getScale($currency)` (`CurrencyScaleResolverInterface` constructor-injected). `documents`/`document_lines` money columns are `decimal(15,3)`; make NO schema changes. For TND, scale-3 values fit exactly.
  - **`appendLines` MUST continue numbering from `max(line_number)+1`** (`UNIQUE(document_id, line_number)` — `create_document_lines_table.php:29`; starting at 1 collides) **and recompute + persist `subtotal/tax_amount/total` from the full line set** after appending. Guards: document exists AND `company_id` matches AND `type === PurchaseOrder` AND `status === Draft` AND `partner_id === $supplierId` — 422 otherwise (an unscoped `existing_document_id` is a cross-company write vector).
Replenishment-side `CreatePoFromRequestsRequest` rules: `supplier_id => ['required','uuid', ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)]`; `destination_location_id => ['required','uuid']` + controller asserts it belongs to the active company (422); `existing_document_id => ['nullable','uuid']`; `lines.*.request_id => ['required','uuid']`, `lines.*.quantity => ['required','numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/']`.
Then per request line: destination == request's shop → `Fulfilled` + `fulfillment_type=PurchaseOrder` + `fulfillment_id=$document->id`; else `InProgress` + `sourcing_document_id=$document->id`; emit `ReplenishmentSourced`/`ReplenishmentFulfilled`.
- `reject` body: `{ request_ids: [uuid...], reason: string (required, max 500) }` → status `Rejected`, `rejection_reason`, `processed_by/at`, emit `ReplenishmentRejected`.

- [ ] **Step 1: Failing tests:**
```php
test_create_transfer_groups_by_destination_and_settles_via_listener()
test_create_transfer_requires_inventory_permission()   // user with only replenishment.process → 403
test_create_po_to_warehouse_marks_lines_in_progress_with_sourcing_link()
test_create_po_direct_to_shop_marks_fulfilled()
test_create_po_appends_to_existing_draft_only_same_supplier()
test_create_po_requires_purchase_orders_create()        // 403
test_po_lines_stamped_with_request_location()
test_po_append_continues_line_numbering_and_recomputes_totals()   // B3 pin: append to draft with existing lines → no 23505, header totals == sum of ALL lines
test_po_append_rejects_other_company_or_non_draft_or_other_supplier_422()
test_create_transfer_multi_destination_creates_one_transfer_per_group()  // M1 pin: 2 destinations → 2 distinct stock_transfers rows
test_reject_requires_reason_and_closes_lines()
test_actions_reject_non_open_or_foreign_company_lines_422()
```
- [ ] **Step 2-4:** FAIL → implement (including `DraftPurchaseOrderService` in Document module) → PASS, PHPStan, commit: `feat(replenishment): review actions (transfer/PO/reject) + Document DraftPurchaseOrderService`

### Task 8: TypeScript types + DTO transform

- [ ] Run `cd apps/api && CACHE_STORE=array php artisan typescript:transform`; verify `packages/shared/types/` gains `ReplenishmentRequestData` (and `CaptureRequestData` if annotated). Commit: `chore(types): transform replenishment DTOs`

### Task 9: POS SQLite migration + outbox repository

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` — append **version 60**:
```ts
{
  version: 60,
  name: 'create_replenishment_tables',
  sql: `
    CREATE TABLE IF NOT EXISTS replenishment_outbox (
      client_request_uuid TEXT NOT NULL,
      tenant_id TEXT NOT NULL,
      company_id TEXT NOT NULL,
      terminal_id TEXT NOT NULL,
      product_id TEXT NOT NULL,
      variant_id TEXT,
      requested_qty TEXT,
      note TEXT,
      status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'resolved', 'failed')),
      sync_error TEXT,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL,
      PRIMARY KEY (tenant_id, company_id, client_request_uuid)
    );
    CREATE INDEX IF NOT EXISTS idx_replenishment_outbox_status
      ON replenishment_outbox(tenant_id, company_id, status, updated_at);

    CREATE TABLE IF NOT EXISTS open_replenishment_cache (
      tenant_id TEXT NOT NULL,
      company_id TEXT NOT NULL,
      request_id TEXT NOT NULL,
      product_id TEXT NOT NULL,
      variant_id TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL,
      requested_qty TEXT,
      request_count INTEGER NOT NULL DEFAULT 1,
      last_requested_at TEXT NOT NULL,
      fetched_at TEXT NOT NULL,
      PRIMARY KEY (tenant_id, company_id, request_id)
    );
    CREATE INDEX IF NOT EXISTS idx_open_replenishment_product
      ON open_replenishment_cache(tenant_id, company_id, product_id, variant_id);
  `,
},
```
(NO `datetime('now')` defaults anywhere — JS supplies ISO timestamps; rule 20.)
- Create: `apps/pos/src/lib/db/repositories/replenishmentOutboxRepository.ts` — clone `pendingCustomerRepository` function-for-function: `enqueueReplenishmentRequest(db, input)` (same `ON CONFLICT(tenant_id, company_id, client_request_uuid) DO UPDATE` reset-to-pending shape), `getPendingReplenishmentRequests(db, tenantId, companyId)`, `markReplenishmentResolved(...)`, `markReplenishmentFailed(..., syncError)`; reuse `execute/queryAll/queryOne` from `@/lib/db` and the `nowIso()/assertPresent/assertScope` helper shapes.
- Create: `apps/pos/src/lib/db/repositories/openReplenishmentRepository.ts` — `replaceOpenRequests(db, tenantId, companyId, rows, fetchedAt)` using the **upsert-first / delete-absent-after** convention (clone `locationStockRepository.ts:145-181` `replaceAllStock` — the repo has NO transaction primitive and the established pattern deliberately avoids an empty-table window for concurrent readers; do NOT delete-then-insert) + `getOpenRequestForProduct(db, tenantId, companyId, productId, variantId | '')` — **filters `status IN ('pending','in_progress')`** (the cache also holds recently-closed lines; a fulfilled line must not render as "already requested") + `getAllOpenRequests(db, tenantId, companyId)` (returns all cached rows incl. recently-closed, for the read-only feedback list).
- Test: `apps/pos/src/lib/db/repositories/__tests__/replenishmentOutboxRepository.test.ts` (mirror the pending-customer repo tests: enqueue/idempotent re-enqueue resets failed→pending, ordering, scope assertions).

- [ ] Steps: failing tests → implement → `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/replenishmentOutboxRepository.test.ts` → PASS → commit: `feat(pos): replenishment outbox + open-requests cache (sqlite v60)`

### Task 10: POS push/pull sync services + API client + audit type

> Wire guardrail: POS must use the hand-written snake_case `ServerReplenishmentRow` below, never the generated camelCase `ReplenishmentRequestData` DTO.

**Files:**
- Create: `apps/pos/src/api/replenishmentApi.ts`:
```ts
import { apiPost, apiGetRaw, type ApiRequestOptions } from '@/lib/api';
export interface ReplenishmentPushBody {
  client_request_uuid: string; terminal_id: string; product_id: string;
  variant_id?: string | null; requested_qty?: string | null; note?: string | null;
}
export interface ServerReplenishmentRow {
  id: string; product_id: string; variant_id: string | null; status: string;
  requested_qty: string | null; request_count: number; last_requested_at: string;
}
export async function pushReplenishmentRequest(body: ReplenishmentPushBody, opts?: ApiRequestOptions): Promise<ServerReplenishmentRow>
export async function fetchOpenReplenishment(terminalId: string, opts?: ApiRequestOptions): Promise<{ data: ServerReplenishmentRow[]; as_of: string }>
```
- Create: `apps/pos/src/lib/replenishment/replenishmentSyncService.ts` — clone `pendingCustomerSyncService`: typed `ReplenishmentSyncScopeError` / `ReplenishmentSyncResponseError`; `pushReplenishmentRequests(db, tenantId, companyId): Promise<number>` draining `getPendingReplenishmentRequests`, POSTing each, `markReplenishmentResolved` on success; permanent-vs-transient classification — **explicit status-based rules (this deliberately EXTENDS the template, which only classifies by error type and never inspects status; a literal clone would rethrow a 409/422 forever and wedge the outbox):**
```ts
} catch (error) {
  if (error instanceof ReplenishmentSyncResponseError || error instanceof ReplenishmentSyncScopeError) {
    await markReplenishmentFailed(db, tenantId, companyId, row.client_request_uuid, error.message);
    continue; // permanent: contract violation
  }
  if (error instanceof ApiRequestError && error.status !== 401 && error.status < 500) {
    await markReplenishmentFailed(db, tenantId, companyId, row.client_request_uuid, `${error.status}: ${error.apiMessage}`);
    continue; // permanent: 404/409/422/etc — retrying cannot succeed
  }
  throw error; // transient: 401, 5xx, network, timeout → retry next tick
}
``` Also `pullOpenReplenishment(db, tenantId, companyId, terminalId)` → `replaceOpenRequests`.
- Modify: `apps/pos/src/lib/sync/syncService.ts` — in the push phase adjacent to `pushPendingCustomers` (lines ~2035-2065), add the same guarded block: push replenishment (errors → `errors.push('Replenishment push failed: …')`, isolated), then in the pull section next to `pullLocationStock` (~2084) add swallow-and-log `pullOpenReplenishment` — NOTE: `tenantId`/`companyId` are NOT in scope at that site (they're scoped inside the customer block); re-read them from `useAuthStore.getState()` locally, skipping the pull when absent.
- Modify: `apps/pos/src/lib/audit/eventTypes.ts` — add `'pos.replenishment_requested'` to the `PosAuditEventType` union.
- Test: `apps/pos/src/lib/replenishment/__tests__/replenishmentSyncService.test.ts` — success drains + resolves; 422 marks failed and continues to next row; network error rethrows leaving row pending; scope mismatch marks failed.

- [ ] Steps: failing tests → implement → targeted vitest run → PASS → commit: `feat(pos): replenishment sync push/pull wired into sync cycle`

### Task 11: POS UI — RequestRefillSheet + drawer integration

**Files:**
- Create: `apps/pos/src/components/organisms/RequestRefillSheet/RequestRefillSheet.tsx` + `index.ts` + `__tests__/RequestRefillSheet.test.tsx`
- Modify: `apps/pos/src/components/pos/ProductDetailDrawer.tsx` — mount a "Request refill" `Button` (variant `secondary`, `lucide-react` `PackagePlus` icon) in the left `<aside>` under the add-to-cart controls, and render the sheet.
- Modify: `apps/pos/src/components/pos/ProductCard.tsx` + `ProductGrid.tsx` — the out-of-stock entry point (spec §4): `ProductCard` already computes `isOutOfStock` (`ProductCard.tsx:22`); when out of stock, render a small `PackagePlus` icon button in the stock-label area that calls a new optional prop `onRequestRefill?: (product: POSProduct) => void` (stopPropagation so it doesn't trigger the tile's add-to-cart tap). `ProductGrid` (renders `ProductCard` at `ProductGrid.tsx:~213`) threads the prop; the page owning the grid opens `RequestRefillSheet` with that product.
- Modify: POS i18n `pos` namespace files — keys `replenishment.request_refill`, `replenishment.quantity_optional`, `replenishment.note`, `replenishment.submit`, `replenishment.already_requested` (`"Requested {{date}}"`), `replenishment.queued_offline`, `replenishment.request_recorded`.

**Interfaces:**
```ts
export interface RequestRefillSheetProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct;
  variantId?: string | null;
}
```
Behavior: uses `Modal` from `@/components/pos/Modal` (size `sm`, title = t('replenishment.request_refill')); optional quantity (numeric string input accepting ≤4dp — reuse the POS quantity input atom if present, else a text input with the same regex guard), optional note; submit → `enqueueReplenishmentRequest` (uuid via `crypto.randomUUID()`, tenant/company from `useAuthStore.getState()`, terminal from `useTerminalStore`) → fire-and-forget `void recordAuditEvent({ type: 'pos.replenishment_requested', aggregateType: 'ReplenishmentRequest', aggregateId: clientUuid, payload: { product_id, variant_id, requested_qty } }).catch(() => {})` → toast t('replenishment.queued_offline') when offline / t('replenishment.request_recorded') otherwise → close. "Already requested" chip: `getOpenRequestForProduct` on open; when found render `StatusPill` (from `@/components/ui`) with `t('replenishment.already_requested', { date: formatted })` — submitting anyway is allowed (server bumps).
- Tests: renders gate-free (no permission gate — capture rides the terminal), submits enqueue with terminal scope, shows already-requested chip when cache row exists, qty regex rejects `1.00001`.

- [ ] Steps: failing component tests → implement → targeted vitest → PASS → commit: `feat(pos): request-refill sheet in product drawer`

### Task 12: Web feature scaffold — types, api, queries, i18n, routes

> Wire guardrail: web must use the hand-written snake_case `ReplenishmentLine` below, never the generated camelCase `ReplenishmentRequestData` DTO.

**Files:**
- Create: `apps/web/src/features/replenishment/types/index.ts`:
```ts
export type ReplenishmentStatus = 'pending' | 'in_progress' | 'fulfilled' | 'rejected' | 'cancelled'
export interface ReplenishmentLine {
  id: string; location_id: string; location_name: string
  product_id: string; product_name: string; variant_id: string | null; variant_name: string | null
  requested_qty: string | null; note: string | null; request_count: number
  status: ReplenishmentStatus; source_channel: 'pos' | 'web'
  first_requested_at: string; last_requested_at: string
  sourcing_document_id: string | null
  fulfillment_type: 'transfer' | 'purchase_order' | null; fulfillment_id: string | null
  rejection_reason: string | null
}
export interface ReplenishmentListResponse { data: ReplenishmentLine[]; meta?: PaginationMeta; truncated?: boolean }
export interface CaptureReplenishmentInput { location_id: string; product_id: string; variant_id?: string | null; requested_qty?: string | null; note?: string | null }
export interface CreateTransferActionInput { source_location_id: string; lines: { request_id: string; quantity: string }[] }
export interface CreatePoActionInput { supplier_id: string; destination_location_id: string; existing_document_id?: string; lines: { request_id: string; quantity: string }[] }
export interface RejectActionInput { request_ids: string[]; reason: string }
```
- Create: `api/replenishmentApi.ts` (open list + paginated history via `api.get` preserving meta; capture/cancel/actions via `apiPost`), `api/queries.ts` (`namespace = 'replenishment'`; `useOpenReplenishment(filters)`, `useReplenishmentHistory(filters)`, `useCaptureReplenishment()`, `useCreateTransferAction()`, `useCreatePoAction()`, `useRejectAction()` — every mutation invalidates `tenantScopedKey([namespace])`, and the transfer action ALSO invalidates `tenantScopedKey(['stock-transfers'])` + `tenantScopedKey(['stock-levels'])`, mirroring `useCreateStockTransfer`).
- Create: `src/locales/{en,fr,ar}/replenishment.json` (ar may alias en initially like `stock-transfers` does) — keys: `title`, `queue.by_shop`, `queue.by_product`, `queue.empty_title`, `queue.empty_description`, `status.{pending,in_progress,fulfilled,rejected,cancelled}`, `capture.title`, `capture.submit`, `actions.{create_transfer,add_to_po,reject}`, `dialog.*` (labels below), `matrix.requested_no_qty` etc. FR: "Demandes de réassort", statuses "En attente / En cours / Servie / Rejetée / Annulée".
- Modify: `src/lib/i18n.ts` — import en/fr/ar files, register in all three `resources` blocks, append `'replenishment'` to `ns` array.
- Modify: `src/routes/index.tsx` — lazy imports + routes registered **directly adjacent to the existing `stock-transfers` route block (~line 1268) at the same nesting level** (do not invent an `inventory/` path prefix the router doesn't use):
```tsx
<Route path="replenishment" element={
  <RequirePermission permission="replenishment.view"><SuspenseWrapper><ReplenishmentQueuePage /></SuspenseWrapper></RequirePermission>} />
<Route path="replenishment/new" element={
  <RequirePermission permission="replenishment.create"><SuspenseWrapper><ReplenishmentCapturePage /></SuspenseWrapper></RequirePermission>} />
```
Also add the sidebar nav item under the Inventory group (follow how `stock-transfers` is registered in the nav config — same file pattern, `t('replenishment:title')`).
- Test: `features/replenishment/__tests__/queries.test.ts` — query keys are tenant-scoped; list uses `api.get` (mock axios instance per Testing Conventions — no fake payload assertions beyond shape).

- [ ] Steps: failing tests → implement → `pnpm vitest run src/features/replenishment` + `pnpm typecheck` → PASS → commit: `feat(web): replenishment feature scaffold (api/types/i18n/routes)`

### Task 13: Web capture page

**Files:** `pages/ReplenishmentCapturePage.tsx` + test.

Composition (reuse only): `PageHeader` molecule; location select limited to allowed locations (fetch via existing `useLocations()` and filter by the memberships-aware endpoint the backend enforces anyway — UI shows all active locations user may access; server is the authority); `LineItemEntryBar` with `onAddProduct={(product, meta) => submit capture for product/meta.variantId}` and `onBeforeAdd={() => locationChosen}`; after each submit show a toast + append to a local "submitted this session" list rendered with `StatusBadge`; `QuantityInput` + note field in an inline row before submit (quantity optional). Phone-usable: single column, no fixed widths.
- Tests: veto add without location; capture called with string qty; 403 from server surfaces `QueryError`.

- [ ] Steps: failing tests → implement → targeted vitest + typecheck → commit: `feat(web): replenishment capture page`

### Task 14: Web review queue — by-shop list + by-product matrix + context panel

**Files:** `pages/ReplenishmentQueuePage.tsx`, `components/{ReplenishmentStatusBadge,RequestContextPanel}.tsx` + tests.

- `ReplenishmentStatusBadge`: map `{pending:'pending', in_progress:'info', fulfilled:'success', rejected:'danger', cancelled:'neutral'}` → `StatusBadge` tone; label `t('replenishment:status.'+status)` (clone `StockTransferStatusBadge` shape).
- Queue page: `Tabs`-style toggle (existing `FilterTabs` molecule) between **By shop** and **By product**; filters row = `LocationSelectorMulti` + `DateRangeFilter` + status chips (open default). Data = `useOpenReplenishment`.
  - **By shop:** group lines client-side by `location_id`; each shop section = header (shop name + line count + "select all") and rows (product, variant, qty-or-✓, `request_count`, age, note icon, checkbox). Selection state = `Set<requestId>`.
  - **By product (matrix):** pivot client-side: rows = distinct (product_id, variant_id), columns = distinct shops among open lines; copy the `QuoteRequestComparisonPage` CSS-grid technique verbatim (`gridTemplateColumns: minmax(220px,1fr) repeat(${shopCount}, minmax(140px,1fr))`, row wrapper `className="contents"`, cell lookup `row.cells[locationId]`); cell content = requested qty (or ✓) + `request_count>1` superscript; clicking a cell toggles that line's selection.
  - Row/product click opens `RequestContextPanel` (right-side panel): wraps `getProductStock(productId)` in `useQuery({queryKey: tenantScopedKey(['product-stock', productId])})` and renders the per-location vector (`StockLocation[]`: available/min/max/is_below_minimum) — highlight the requesting shop and surplus locations.
  - Footer action bar when selection non-empty: buttons `actions.create_transfer` / `actions.add_to_po` / `actions.reject` (Button atoms; `RequirePermission permission="replenishment.process"` around the bar).
  - `EmptyState` for no open lines; `QueryError` + retry; history tab (status filter ≠ open) renders the paginated list with `OffsetPagination`.
- Tests: pivot groups correctly (2 shops × 2 products fixture); matrix renders one column per shop; selection toggles; badge tones; empty + error states.

- [ ] Steps: failing tests → implement → targeted vitest + typecheck + lint (`pnpm lint --filter … new dir is token-strict`) → commit: `feat(web): replenishment review queue (by-shop + product matrix)`

### Task 15: Web action dialogs

**Files:** `components/{CreateTransferDialog,AddToPoDialog,RejectDialog}.tsx` + tests.

- `CreateTransferDialog({selected: ReplenishmentLine[], isOpen, onClose})`: source location single-select (exclude any destination in selection); per-line qty inputs (`QuantityInput`, default = `requested_qty ?? min/max suggestion ?? '1'`, all required >0); per-destination grouping preview ("2 transfers will be created: Warehouse→Shop A (3 lines), Warehouse→Shop B (1 line)"); submit → `useCreateTransferAction` → toast with links to created transfers (`/inventory/stock-transfers/{id}` route family). Guard: disable submit if the source equals any line's destination.
- `AddToPoDialog`: supplier picker (reuse the partner picker used by purchases — `PartnerSearchSelect`/`PartnerPicker`, whichever `features/purchases/quote-requests/QuoteRequestCreatePage.tsx` uses — copy that exact import); destination select defaulting to the company's warehouse-type location; optional "append to existing draft PO" select (fetch draft POs for supplier via existing purchase-orders list API `GET /purchase-orders?status=draft&partner_id=…`); per-line qty inputs; submit → `useCreatePoAction` → toast distinguishes in_progress vs fulfilled outcomes.
- `RejectDialog`: textarea reason (required), submits `useRejectAction`.
- Tests per dialog: required-field gating, payload shape (string quantities), permission-gated rendering.

- [ ] Steps: failing tests → implement → targeted vitest + typecheck → commit: `feat(web): replenishment fulfillment dialogs`

### Task 16: E2E critical path + preflight + handoff

- [ ] **Step 1 (backend E2E, PHPUnit by path):** one feature test walking: POS capture (terminal) → line visible in web list → `create-transfer` action → transfer initiated → listener settles line → POS pull feed shows `fulfilled`. Run: `php artisan test tests/Feature/Replenishment tests/Feature/POS/PosReplenishmentControllerTest.php tests/Feature/Inventory/TransferLineQueryServiceTest.php`.
- [ ] **Step 2 (browser):** with the local stack running (see `reference_local_db_per_tenant_demo_launch` recipe; demo tenant login `owner@pharmabio.tn`), Playwright-drive: open `/inventory/replenishment/new`, capture a request, open `/inventory/replenishment`, select the line, create a transfer from the warehouse, verify the line flips to Servie/fulfilled. Screenshot the matrix view.
- [ ] **Step 3:** `./scripts/preflight.sh` → all green (PHPStan L8, Pint, PHPUnit targeted, tsc, ESLint). Fix anything it surfaces.
- [ ] **Step 4:** Final commit + summary of deploy obligations, **named commands in order** (there is no `tenants:seed`): (1) `php artisan tenants:migrate` (prefer the rolling/per-tenant-isolated variant if available in the repo) — new tables; (2) `php artisan tenants:run "db:seed --class=RolesAndPermissionsSeeder"` — new permissions + role grants (seeder is idempotent: firstOrCreate + syncPermissions); (3) `php artisan permission:cache-reset` **per tenant** (Spatie cache is tenant-blind — without this the three new permissions 403 silently). No Horizon change. POS devices need an app update (sqlite v60).

---

## Self-review checklist (run before handoff)
1. Spec Rev 2 coverage: §3 dedupe/two-index/atomic-bump → Tasks 1-2; §4 POS contract (200-never-409, terminal_id, outbox rules, pull feed) → Tasks 4, 9-11; §4 web authorization → Task 3; §5 pivots/actions/downstream-gates/settlement/re-open → Tasks 5-7, 14-15; §6 scoping → Tasks 3-4 (index restrictions); §7 roles/seeder/provider → Tasks 1, 3; §8 precision/i18n/types → Tasks 2-3, 8, 12; §9 tests → distributed + Task 16.
2. No placeholders: every step names files, code, commands.
3. Type consistency: `ReplenishmentStatus` string values identical across PHP enum, SQLite CHECK, TS union; `TransferLineDTO` field names match Task 6 listener usage; action payload keys match Task 7 FormRequests ↔ Task 12 TS inputs.
4. **Wire-casing symmetry (both directions):** every read endpoint's Resource emits snake_case keys matching the TS client types (`ServerReplenishmentRow`, `ReplenishmentLine`) field-for-field; request payload keys match FormRequest rules. Verify with one round-trip test per endpoint, not by inspection.

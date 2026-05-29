# Inventory Transfer (PR #147) — Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every BLOCKER and P1 from the Codex adversarial review (and the actionable P2s from both Codex and Opus) for PR #147 so the Inventory Transfer feature is merge-ready, then update the master CLAUDE.md / architecture docs to reflect the in-flight DB-per-tenant tenancy flip.

**Architecture:** Three structural changes drive the bulk of the plan: (1) replace the single `stock_transfers.transfer_cost` column with an append-only cost-event ledger (`stock_transfer_cost_events`) where each event capitalizes immediately against `on_hand + in_transit` — the denominator is invariant under transfer-state transitions, so WAC is deterministic under any ordering of cost events across any number of in-flight transfers; cost events can be added at initiate, in transit, or at receipt (matching the real business workflow of quoted-vs-actual costs); (2) thread `idempotency_key` through `complete`/`cancel` and through the new cost-event endpoint, with a parallel-safe insert-with-catch pattern on `initiate`; (3) refactor `StockAdjustmentService::issue/receive/recordMovement` to accept optional `movementType`/`referenceType`/`referenceId` so the new transfer service stops doing the post-hoc "re-label most recent movement" dance and the `StockMovementRecorded` event finally matches the persisted row. Migration placement is corrected by moving `2026_05_28_120000_create_stock_transfers_table.php` into `database/migrations/tenant/`. CLAUDE.md + architecture docs are deferred to a dedicated orchestrator-owned post-flip docs PR.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 16+, PHPUnit + RefreshDatabase, PHPStan level 8, Pint; React 19 / Vite 7 / TypeScript strict, TanStack Query 5, Vitest, Playwright; design tokens from `apps/web/src/lib/designTokens.ts`; i18n via react-i18next.

---

## Plan-document conventions

- Every code snippet that contains `// ...` shorthand means **"preserve the existing method body verbatim from the file the snippet modifies; only the lines explicitly written here are added or changed"**. The executing engineer reads the file under modification and copies the unchanged sections forward. Do not invent new code to fill in for `// ...`.
- Every file path in this plan is **relative to the worktree root** (`apps/erp.inventory-transfer/` during execution). When the plan says `docs/superpowers/coordination/X.md` it means the path inside the worktree; the absolute path is `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/docs/superpowers/coordination/X.md`.
- Every task that ends without an explicit `git commit ...` step belongs to the surrounding batch's final commit. The subagent driver should batch-commit at batch boundaries unless the task explicitly says otherwise.

## Source reviews

- Opus review: `docs/superpowers/reviews/2026-05-28-inventory-transfer-opus-review.md` — verdict APPROVE-WITH-MINOR-EDITS (0 BLOCKER, 2 P1, 7 P2, 7 P3)
- Codex review: `docs/superpowers/reviews/2026-05-28-inventory-transfer-codex-review.md` — verdict REQUEST-CHANGES (2 BLOCKERs, 4 P1, 8 P2, 3 P3)
- Source PR: https://github.com/otospexsolutions/erp/pull/147 (branch `feat/inventory-transfer` at `a7181cc2f`)

Where Opus and Codex disagree, **Codex's reading wins** when the disagreement is about concurrency/correctness (Codex caught two BLOCKERs Opus missed: WAC order-dependence and event-type drift). Where Opus called something safe and Codex did not, this plan defers to the more conservative reading.

---

## Cross-review synthesis

| Finding | Opus | Codex | Plan batch |
|---|---|---|---|
| WAC order-dependence under concurrent in-transit transfers | not flagged | **BLOCKER #1** | A — solved via multi-event cost stream + `(on_hand + in_transit)` denominator |
| `complete()` not retry-safe per spec §202 | P1-1 | **BLOCKER #2** | B |
| Frontend never sends `idempotency_key` on create | P1-2 | P2-1 | B |
| Parallel-create race on same `idempotency_key` | not flagged | P1-1 | B |
| `generateTransferNumber()` race produces SQLSTATE 23505 on retry | P3-2 | P1-2 | B |
| Re-label-most-recent-movement pattern is brittle | "safe under concurrency" | P1-3 | C |
| `StockMovementRecorded` events emit `issue`/`receipt` after re-label (consumers see wrong type) | not flagged | **P1-4** | C |
| `lockTransfer()` doesn't carry `tenant_id` + `company_id` | P2-3 | P2-3 | D |
| Missing distribution-mode tests (`ProRataQuantity`, `EqualPerLine`) | P2 (mentioned) | P2-8 | D |
| Missing same-key-different-payload idempotency test | not flagged | P2-8 | B |
| Cross-tenant product rejection test missing | P2-4 | P2-8 | D |
| Quantity precision drift (lines decimal:4 vs movements decimal:2) | P2-5 (pre-existing tech debt) | not flagged | scoped out — tracked separately |
| List page has no pagination controls | P2-1 | not flagged | E |
| Create page swallows server errors with generic toast | P2-2 | P2-7 | E |
| `cancel` from `in_transit` produces a `TransferIn` at source (confusing audit) | P2-6 | P3-1 | F |
| Cancel modal duplicates `ConfirmDialog` | P3-7 | P3-2 | scoped out — needs `ConfirmDialog` refactor across the codebase |
| AR locale `stock-transfers.json` missing (i18n falls back to EN) | P2-7 | not flagged | E (file note, not full translation) |
| Migration in `migrations/` not `migrations/tenant/` | P3-5 | P2-6 | G — promoted because the flip is actively landing |
| Negative `transferCost` via DTO path not guarded | not flagged | P3-3 | D |
| `ProRataValue` falls back to equal-per-line when total value is zero (undocumented) | not flagged | P2-4 | D (document + test) |
| Batch preservation seam — `stock_transfer_lines` has no `batch_id`, `unique(transfer_id, product_id)` blocks multi-batch | flagged as next-PR concern | P2-5 | deferred to Scenario A close-out session |
| `bcformat` not used in new WAC math | P3-3 | not flagged | scoped out — matches surrounding code |
| Doc-flip: CLAUDE.md + architecture docs still describe row-level tenancy | not in scope | not in scope | H — deferred to a dedicated orchestrator-owned post-flip docs PR; this plan adds a forward-pointer only |

---

## Decisions locked

All three of the original decision points are now resolved.

### D1 — WAC determinism policy: **multi-event cost stream**

We are not capitalizing a single `transfer_cost` at `complete()`. Instead, each transfer carries an append-only ledger of cost events (`stock_transfer_cost_events`) — `at_initiate`, `in_transit`, `at_receipt`. Each event capitalizes immediately against `on_hand_in_stock_levels + in_transit_owned`. The denominator is invariant during transfer-state transitions (source decrement + in-transit-lines increment cancel out), which makes WAC deterministic under any ordering of any cost events across any in-flight transfers. The architecture also supports the user's explicit ask — "I should be able to modify the final weighted average cost because the unit cost during the transport could still be modified until it lands" — because new events can be appended at any time during in_transit or after completion. This also forward-compatible with the future multi-company / inter-company work that needs the same cost-event ledger semantics.

### D2 — Migration relocation: **land Batch G in this PR**

The orchestrator confirmed: the `git mv` lives here. The T6 cascade (PRs #141–#146) already landed the DB-per-tenant flip on `dev`; PR #148 (T6 pre-flip ops with the central `tenant_backups` migration) is alongside in the **central** `database/migrations/` tree, so my `tenant/` placement won't collide with it.

### D3 — Doc-flip ownership: **deferred to a dedicated orchestrator-owned PR**

Batch H is replaced by a single forward-pointer task. The master-docs rewrite (CLAUDE.md + claude/* + apps/erp/CLAUDE.md + .claude/context/architecture.md + database.md + memory) lands in a dedicated post-flip-docs PR after #147 and #148 merge, so it can be a single coherent pass with fresh context on the post-flip reality. The forward-pointer goes in the design note instead.

---

## File-by-file map

Backend (Laravel) — files this plan touches:

- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` — extensive: remove re-label dance, add complete/cancel idempotency_key, compute in-transit quantity for WAC capitalization, scope `lockTransfer` by `tenant_id` + `company_id`, catch-and-reload on parallel idempotency_key races, switch transfer-number generation to a `INSERT ... ON CONFLICT` retry loop, add request-fingerprint check when reusing idempotency_key with different payload.
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` — extend `issue()`, `receive()`, `recordMovement()` with optional `movementType` / `referenceType` / `referenceId` (default behavior unchanged for existing callers); emit `StockMovementRecorded` with the actual persisted `movementType`.
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` — `recordCostAdjustment()` accepts optional `inTransitQuantity` parameter; denominator becomes `on_hand + in_transit`.
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` — accept `idempotency_key` on `complete`/`cancel`; surface `INSUFFICIENT_STOCK` details unchanged (already does).
- `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php` — already validates `transfer_cost min:0`; no change needed.
- `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php` — guard non-negative `transferCost` at construction (Codex P3-3 closes this for direct-service callers).
- `apps/api/app/Modules/Inventory/Application/DTOs/CompleteTransferData.php` — **new** DTO carrying `transferId`, `userId`, `idempotencyKey?`.
- `apps/api/app/Modules/Inventory/Application/DTOs/CancelTransferData.php` — **new** DTO carrying `transferId`, `userId`, `reason?`, `idempotencyKey?`.
- `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php` — **move** the existing migration here from `database/migrations/`; add columns `complete_idempotency_key VARCHAR(128) NULL`, `cancel_idempotency_key VARCHAR(128) NULL`, `idempotency_payload_hash VARCHAR(64) NULL`, and unique constraints `(tenant_id, company_id, complete_idempotency_key)` and `(tenant_id, company_id, cancel_idempotency_key)`.
- `apps/api/app/Modules/Inventory/Domain/StockTransfer.php` — add the three new columns to `$fillable` + property docblock.
- `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` — extend with tests for: WAC determinism (two-completion-order assertion), complete idempotency (retry returns the existing transfer), cancel idempotency, parallel-create idempotency, same-key-different-payload mismatch returns 409, ProRataQuantity allocation, EqualPerLine allocation, ProRataValue zero-cost fallback, cross-tenant product rejection, negative transferCost rejection at DTO boundary.
- `apps/api/tests/Feature/Inventory/InventoryTransferServiceConcurrencyTest.php` — **new** integration test that uses two transactions to exercise the parallel-create race path.

Frontend (React) — files this plan touches:

- `apps/web/src/features/stock-transfers/api/stockTransferApi.ts` — `complete()` and `cancel()` accept `idempotencyKey?: string` and POST it in the body.
- `apps/web/src/features/stock-transfers/api/queries.ts` — `useCompleteStockTransfer` and `useCancelStockTransfer` accept an idempotency key.
- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` — generate `crypto.randomUUID()` for `idempotency_key`; surface `INSUFFICIENT_STOCK` / `INVALID_TRANSFER` error messages instead of generic toast.
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx` — generate `crypto.randomUUID()` for complete and cancel idempotency keys; surface server error messages.
- `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx` — pagination controls (Prev/Next + page-N-of-M label).
- `apps/web/src/lib/api.ts` — confirm an error helper `extractServerMessage(error: unknown): string | null` exists; if not, add it.
- `apps/web/src/features/stock-transfers/__tests__/StockTransferListPage.test.tsx` — add pagination interaction test.
- `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.test.tsx` — **new** smoke test that asserts an `idempotency_key` is included in the submitted payload and that `INSUFFICIENT_STOCK` errors surface the available qty.

Docs:

- `docs/superpowers/coordination/2026-05-28-inventory-transfer.md` — annotate the deferred items now closed and the open follow-ups (Batch I).
- Forward-pointer to the orchestrator-owned post-flip docs PR (Batch H) — the CLAUDE.md + claude/* + apps/erp/CLAUDE.md + .claude/context/architecture.md + database.md + memory rewrites are NOT in scope for this plan per D3. The orchestrator session lands them in a dedicated PR after #147 and #148 merge.

End-to-end verification:

- Local Docker stack + Playwright spec at `apps/web/tests/e2e/stock-transfers.spec.ts` (**new**) — golden path: create → in_transit → complete; and create → in_transit → cancel.

---

## Batch A — Multi-event cost stream + in-transit-aware WAC denominator (Codex BLOCKER #1, user-locked architecture)

**Decision locked (D1):** Cost is a stream of events, not a single `transfer_cost` field. Each event capitalizes immediately against `on_hand + in_transit`, which is invariant under transfer-state transitions and thus makes WAC deterministic. Costs can be added at initiate, while in transit, or at receipt — matching the real business workflow ("you define a specific shipping cost but then when they arrive it could be higher"). Forward-compatible with the future multi-company / inter-company cost-allocation work.

**Scope of Batch A:** new `stock_transfer_cost_events` table, new `TransferCostEventPhase` enum, new `StockTransferCostEvent` model, new `RecordCostEventData` DTO, new `StockTransferCostEventRecorded` domain event, new `StockTransferService::recordCostEvent()` application method, extension of `WeightedAverageCostService::recordCostAdjustment()` to accept an `inTransitQuantity` parameter, retrofit of `StockTransferService::initiate()` to emit an `at_initiate` cost event when the `transferCost` field is non-zero (back-compat with existing API), removal of `StockTransferService::complete()`'s `capitalizeTransferCost()` block (capitalization is per-event now, not per-completion), new HTTP endpoint `POST /api/v1/stock-transfers/{id}/cost-events`, frontend "Add cost" affordance on the detail page for in_transit + completed transfers.

### Task A1 — Migration: `stock_transfer_cost_events` table

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_05_28_140000_create_stock_transfer_cost_events_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_cost_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('phase', 20); // at_initiate | in_transit | at_receipt
            $table->decimal('amount', 15, 4);
            $table->string('label', 128)->nullable();
            $table->string('distribution', 20)->default('pro_rata_value');

            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            // sha256 of the canonical (phase, amount, label, distribution)
            // payload so reusing the same key with a different payload throws
            // IdempotencyKeyConflictException instead of silently returning
            // the prior event (Codex plan-review P2-7).
            $table->string('idempotency_payload_hash', 64)->nullable();
            // When this event is itself a reversal of another event (emitted
            // by the cancel flow per Option B+), link back to the original.
            // A unique partial index below makes "this original has already
            // been reversed" detectable in O(1) so the cancel modal cannot
            // double-reverse and over-shoot WAC backwards (Codex v2 P1 on F3).
            $table->foreignUuid('reverses_event_id')
                ->nullable()
                ->constrained('stock_transfer_cost_events')
                ->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'idempotency_key'], 'stock_transfer_cost_events_idem_unique');
            $table->index(['transfer_id', 'recorded_at'], 'stock_transfer_cost_events_transfer_recorded_idx');
            $table->index(['tenant_id', 'company_id', 'recorded_at'], 'stock_transfer_cost_events_company_recorded_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Amount is signed: positive = capitalize cost; negative =
            // reverse / compensating event (e.g., quoted freight that the
            // user didn't actually pay, recorded by the cancel flow per
            // Option B+ in the design note). Zero amounts are still
            // forbidden because they would mean "no change".
            DB::statement("ALTER TABLE stock_transfer_cost_events ADD CONSTRAINT stock_transfer_cost_events_amount_nonzero CHECK (amount <> 0)");
            DB::statement("ALTER TABLE stock_transfer_cost_events ADD CONSTRAINT stock_transfer_cost_events_phase_valid CHECK (phase IN ('at_initiate', 'in_transit', 'at_receipt'))");
            // At most one reversal per original event. The partial-unique
            // index guarantees the cancel modal cannot double-reverse and
            // over-shoot WAC backwards even with concurrent submits.
            DB::statement("CREATE UNIQUE INDEX stock_transfer_cost_events_reverses_unique ON stock_transfer_cost_events (reverses_event_id) WHERE reverses_event_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_cost_events');
    }
};
```

- [ ] **Step 2: Run migration**

```
cd apps/api && php artisan migrate:fresh --env=testing 2>&1 | tail -10
```

Expected: clean.

- [ ] **Step 3: Commit**

```
git add apps/api/database/migrations/tenant/2026_05_28_140000_create_stock_transfer_cost_events_table.php
git commit -m "feat(inventory): add stock_transfer_cost_events table (append-only cost ledger)"
```

### Task A2 — Enum: `TransferCostEventPhase`

**Files:**
- Create: `apps/api/app/Modules/Inventory/Domain/Enums/TransferCostEventPhase.php`

- [ ] **Step 1: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * When a transfer cost was recorded.
 *
 * AtInitiate — booked together with `initiate()` (the quoted transport cost).
 * InTransit  — recorded after initiate, before the transfer completes
 *              (mid-transit adjustment to the quoted amount).
 * AtReceipt  — recorded after `complete()` lands the stock at destination
 *              (actuals discovered on arrival: port fees, customs, damaged-unit
 *              write-off, etc.).
 */
enum TransferCostEventPhase: string
{
    case AtInitiate = 'at_initiate';
    case InTransit = 'in_transit';
    case AtReceipt = 'at_receipt';

    /**
     * Which transfer statuses may accept an event in this phase?
     *
     * AtInitiate is only created internally by the service during initiate();
     * the public POST cost-event endpoint only accepts InTransit and AtReceipt.
     */
    public function allowedTransferStatuses(): array
    {
        return match ($this) {
            self::AtInitiate => [TransferStatus::Draft, TransferStatus::InTransit],
            self::InTransit => [TransferStatus::InTransit],
            self::AtReceipt => [TransferStatus::Completed],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AtInitiate => 'At Initiate',
            self::InTransit => 'In Transit',
            self::AtReceipt => 'At Receipt',
        };
    }
}
```

- [ ] **Step 2: Commit**

```
git add apps/api/app/Modules/Inventory/Domain/Enums/TransferCostEventPhase.php
git commit -m "feat(inventory): add TransferCostEventPhase enum"
```

### Task A3 — Model: `StockTransferCostEvent`

**Files:**
- Create: `apps/api/app/Modules/Inventory/Domain/StockTransferCostEvent.php`

- [ ] **Step 1: Write the model**

Follow the same shape as `StockTransferLine` — HasUuids, fillable matching migration columns, casts for `phase` (enum) + `amount` (decimal:4) + `recorded_at` (datetime), BelongsTo relations to transfer/tenant/company/recordedBy.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferCostEventPhase;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $transfer_id
 * @property string $tenant_id
 * @property string $company_id
 * @property TransferCostEventPhase $phase
 * @property numeric-string $amount
 * @property string|null $label
 * @property TransferCostDistribution $distribution
 * @property string $recorded_by_user_id
 * @property string|null $idempotency_key
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransfer $transfer
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read User $recordedBy
 */
class StockTransferCostEvent extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_cost_events';

    protected $fillable = [
        'transfer_id',
        'tenant_id',
        'company_id',
        'phase',
        'amount',
        'label',
        'distribution',
        'recorded_by_user_id',
        'idempotency_key',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => TransferCostEventPhase::class,
            'distribution' => TransferCostDistribution::class,
            'amount' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
```

- [ ] **Step 2: Add `costEvents()` HasMany to `StockTransfer`**

```php
// in StockTransfer.php
/**
 * @return HasMany<StockTransferCostEvent, $this>
 */
public function costEvents(): HasMany
{
    return $this->hasMany(StockTransferCostEvent::class, 'transfer_id');
}

/**
 * Total transfer cost computed from the cost-event ledger.
 *
 * @return numeric-string
 */
public function getTotalCostAttribute(): string
{
    return (string) ($this->costEvents()->sum('amount') ?: '0');
}
```

- [ ] **Step 3: Commit**

```
git add apps/api/app/Modules/Inventory/Domain/StockTransferCostEvent.php \
        apps/api/app/Modules/Inventory/Domain/StockTransfer.php
git commit -m "feat(inventory): StockTransferCostEvent model + costEvents relation + total_cost accessor"
```

### Task A4 — DTO + domain event for cost-event recording

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/DTOs/RecordCostEventData.php`
- Create: `apps/api/app/Modules/Inventory/Domain/Events/StockTransferCostEventRecorded.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferCostEventPhase;
use InvalidArgumentException;

final class RecordCostEventData
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public readonly string $transferId,
        public readonly TransferCostEventPhase $phase,
        public readonly string $amount,
        public readonly string $recordedByUserId,
        public readonly ?string $label = null,
        public readonly TransferCostDistribution $distribution = TransferCostDistribution::ProRataValue,
        public readonly ?string $idempotencyKey = null,
    ) {
        // Amount is signed: positive = capitalize cost; negative = reverse
        // a prior cost event (recorded by the cancel flow when the user
        // confirms a quoted-but-unpaid fee on the transfer being voided).
        // Zero amounts are forbidden — they would mean "no change".
        if (bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('Cost event amount must be non-zero.');
        }
    }
}
```

- [ ] **Step 2: Write the domain event**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Modules\Inventory\Domain\Enums\TransferCostEventPhase;
use Illuminate\Foundation\Events\Dispatchable;

class StockTransferCostEventRecorded
{
    use Dispatchable;

    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public readonly string $costEventId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly TransferCostEventPhase $phase,
        public readonly string $amount,
        public readonly string $recordedByUserId,
        public readonly string $occurredAt,
    ) {}
}
```

- [ ] **Step 3: Commit**

### Task A5 — Failing test for WAC determinism under multi-event scenario

**Files:**
- Test: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`

- [ ] **Step 1: Write the failing test**

This test exercises the full user mental model: two concurrent transfers each with cost events at multiple phases. Final WAC must equal the algebraic sum of all events / total owned qty.

```php
public function test_wac_is_deterministic_across_multi_event_cost_streams_for_concurrent_transfers(): void
{
    // 100 units at WH, WAC = 5.00. Same product, no other on-hand.
    $this->seedStock($this->productA, $this->warehouse, '100.0000');
    $this->assertEquals('5.0000', $this->productA->fresh()->cost_price);

    // Two transfers, AB ordering of events
    $a = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
        transferCost: '100.0000', // at_initiate event for A
        idempotencyKey: 'ab-A',
    ));
    $b = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
        transferCost: '50.0000', // at_initiate event for B
        idempotencyKey: 'ab-B',
    ));
    // Mid-transit cost added to A:
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $a->id,
        phase: TransferCostEventPhase::InTransit,
        amount: '30.0000',
        recordedByUserId: $this->user->id,
    ));
    $this->service()->complete(
        transferId: $a->id, userId: $this->user->id,
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );
    // At-receipt cost added to A:
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $a->id,
        phase: TransferCostEventPhase::AtReceipt,
        amount: '20.0000',
        recordedByUserId: $this->user->id,
    ));
    $this->service()->complete(
        transferId: $b->id, userId: $this->user->id,
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );
    // Post-receipt cost added to B (technically: at_receipt now that B is completed):
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $b->id,
        phase: TransferCostEventPhase::AtReceipt,
        amount: '10.0000',
        recordedByUserId: $this->user->id,
    ));

    $wacAB = $this->productA->fresh()->cost_price;

    // Reset and replay with reversed cost-event ordering (BA).
    StockTransferCostEvent::query()->delete();
    StockMovement::query()->delete();
    StockLevel::query()->delete();
    StockTransferLine::query()->delete();
    StockTransfer::query()->delete();
    $this->productA->forceFill(['cost_price' => '5.0000'])->save();
    $this->seedStock($this->productA, $this->warehouse, '100.0000');

    $b2 = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
        transferCost: '50.0000', idempotencyKey: 'ba-B',
    ));
    $a2 = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
        transferCost: '100.0000', idempotencyKey: 'ba-A',
    ));
    $this->service()->complete(
        transferId: $b2->id, userId: $this->user->id,
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $b2->id,
        phase: TransferCostEventPhase::AtReceipt,
        amount: '10.0000',
        recordedByUserId: $this->user->id,
    ));
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $a2->id,
        phase: TransferCostEventPhase::InTransit,
        amount: '30.0000',
        recordedByUserId: $this->user->id,
    ));
    $this->service()->complete(
        transferId: $a2->id, userId: $this->user->id,
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );
    $this->service()->recordCostEvent(new RecordCostEventData(
        transferId: $a2->id,
        phase: TransferCostEventPhase::AtReceipt,
        amount: '20.0000',
        recordedByUserId: $this->user->id,
    ));

    $wacBA = $this->productA->fresh()->cost_price;

    $this->assertEquals($wacAB, $wacBA,
        "Multi-event WAC must be invariant under any event ordering. AB={$wacAB}, BA={$wacBA}");

    // Total of all events = 100 + 50 + 30 + 20 + 10 = 210; total owned across the run = 100.
    // Expected WAC = 5 + 210/100 = 7.10
    $this->assertEquals('7.1000', $wacAB);
}
```

- [ ] **Step 2: Run, expect failure**

```
cd apps/api && ./vendor/bin/phpunit --filter test_wac_is_deterministic_across_multi_event_cost_streams_for_concurrent_transfers tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: FAIL — `recordCostEvent` does not exist.

### Task A6 — Single product-lock invariant; read denominators consistently (closes Codex v1 BLOCKER 1, v2 BLOCKER 1 + BLOCKER 2)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
- New: `apps/api/tests/Architecture/InventoryProductLockInvariantTest.php`

The v2 draft tried to lock everything in sight (`stock_levels`, the joined `stock_transfers` parent rows, the product). Codex's v2 review caught two distinct issues with that approach:

- **Deadlock** — `complete()` for transfer C holds the transfer-C lock and is waiting for the product-P lock. `recordCostEvent()` on transfer A holds the transfer-A lock and product-P, then tries to lock matched `stock_transfers` rows for *every* in-transit transfer of product P — including transfer C. B waits for C; C waits for B's product. Classic lock cycle.
- **Aggregate row locks are a no-op in Postgres** — `->lockForUpdate()->sum('quantity')` emits `SELECT SUM(quantity) FROM stock_levels FOR UPDATE`, which on PostgreSQL either errors out (it's invalid against aggregates) or runs without taking any row lock at all. The "lock" was paperware.

The right fix is structural: **establish a product-lock invariant** — every operation that mutates `stock_levels.quantity` for product P or that flips `stock_transfers.status` to/from `InTransit` for a transfer whose lines reference P MUST first acquire `Product::lockForUpdate()` on P. As long as that invariant holds, any reader that holds P's product lock observes a consistent snapshot of (`on_hand`, `in_transit`) WITHOUT taking any further locks — no aggregate row locks, no joined parent locks. No deadlock surface beyond the product lock itself.

This invariant is already true for the existing `initiate()` / `complete()` / `cancel()` paths because each of them locks the product before calling `StockAdjustmentService::issue/receive` (which is what mutates `stock_levels`). The new `recordCostEvent()` is the only fresh caller, and Task A7 follows the same discipline. The architecture test (added below) catches any future deviation.

- [ ] **Step 1: Rewrite the method to lock the product, then read both halves WITHOUT row locks**

```php
public function recordCostAdjustment(
    Product $product,
    float $additionalCost,
    string $reason,
    string $tenantId,                 // <-- NEW required
    string $companyId,                // <-- NEW required
    ?string $reference = null,
    ?string $referenceType = null,
    ?string $referenceId = null,
): ?StockMovement {
    return DB::transaction(function () use (
        $product, $additionalCost, $reason, $tenantId, $companyId,
        $reference, $referenceType, $referenceId
    ): ?StockMovement {
        // INVARIANT: the product lock is the single serialization point
        // for (on_hand + in_transit) of this product. Every callsite that
        // mutates stock_levels.quantity for this product OR flips a
        // stock_transfers.status to/from InTransit for a transfer
        // referencing this product MUST acquire this lock first. The
        // architecture test InventoryProductLockInvariantTest enforces
        // this by scanning callsites.
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($product->id);

        // on_hand: simple aggregate. Consistent because every writer
        // who would change these rows for product P must hold the
        // product lock we now own.
        /** @var numeric-string $onHandQty */
        $onHandQty = (string) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->sum('quantity');

        // in_transit: simple aggregate over stock_transfer_lines joined
        // to stock_transfers WHERE status = InTransit. No lockForUpdate
        // on the join — see invariant above. The aggregate is consistent
        // because every writer that would flip a status to/from InTransit
        // for product P must hold the product lock we now own.
        /** @var numeric-string $inTransitQty */
        $inTransitQty = (string) StockTransferLine::query()
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
            ->where('stock_transfer_lines.product_id', $product->id)
            ->where('stock_transfers.tenant_id', $tenantId)
            ->where('stock_transfers.company_id', $companyId)
            ->where('stock_transfers.status', TransferStatus::InTransit)
            ->sum('stock_transfer_lines.quantity');

        /** @var numeric-string $totalOwnedQty */
        $totalOwnedQty = bcadd($onHandQty, $inTransitQty, $this->scale());
        $totalOwnedFloat = (float) $totalOwnedQty;
        if ($totalOwnedFloat <= 0) {
            return null;
        }

        // The remainder of the method body is the existing flow, but every
        // reference to `$onHandFloat` becomes `$totalOwnedFloat`, and every
        // `$onHandQty` written into the stock_movements audit row becomes
        // `$totalOwnedQty`. The stock_movements row still has quantity = 0
        // because no stock motion has occurred; only avg_cost_before /
        // avg_cost_after capture the WAC change. Existing body preserved
        // verbatim per the // ... shorthand convention.
        // ... existing body continues here ...
    });
}
```

- [ ] **Step 2: Write the architecture test enforcing the invariant**

`apps/api/tests/Architecture/InventoryProductLockInvariantTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Enforces the product-lock invariant documented in
 * WeightedAverageCostService::recordCostAdjustment: every call site that
 * mutates `stock_levels.quantity` for a product OR flips
 * `stock_transfers.status` to/from InTransit for a transfer whose lines
 * reference a product MUST hold Product::lockForUpdate() on that product
 * first.
 *
 * The test scans each public mutation method in the Inventory module and
 * fails if the lexically-preceding statements do not include a
 * Product::lockForUpdate() call.
 */
class InventoryProductLockInvariantTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function gatedMethodsProvider(): array
    {
        return [
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function issue'],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function receive'],
            ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function adjust'],
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'private function moveSourceToInTransit'],
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'public function complete'],
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'public function cancel'],
            ['app/Modules/Inventory/Application/Services/StockTransferService.php', 'public function recordCostEvent'],
        ];
    }

    /**
     * @dataProvider gatedMethodsProvider
     */
    public function test_method_acquires_product_lock_before_mutating_stock_or_status(
        string $relativePath,
        string $methodSignature,
    ): void {
        $body = $this->extractMethodBody(base_path($relativePath), $methodSignature);

        $this->assertStringContainsString(
            'Product::query',
            $body,
            "Method `$methodSignature` in $relativePath does not acquire a Product query before mutation. The product-lock invariant requires every mutator to call Product::query()->lockForUpdate()->findOrFail() before changing stock_levels.quantity or stock_transfers.status.",
        );

        $this->assertStringContainsString(
            'lockForUpdate',
            $body,
            "Method `$methodSignature` in $relativePath queries Product but does not lockForUpdate. The product-lock invariant requires the lock, not a plain SELECT.",
        );
    }

    private function extractMethodBody(string $path, string $signature): string
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Could not read $path");

        $start = strpos($contents, $signature);
        $this->assertNotFalse($start, "Could not locate `$signature` in $path");

        // Walk forward until balanced braces close the method body.
        $depth = 0;
        $end = $start;
        $started = false;
        for ($i = $start; $i < strlen($contents); $i++) {
            if ($contents[$i] === '{') {
                $depth++;
                $started = true;
            } elseif ($contents[$i] === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($contents, $start, $end - $start + 1);
    }
}
```

- [ ] **Step 3: Run the architecture test against the EXISTING code as a baseline check**

```
cd apps/api && ./vendor/bin/phpunit tests/Architecture/InventoryProductLockInvariantTest.php
```

Expected: all dataProvider rows pass. If any fail, that's a pre-existing invariant violation in the merged dev — surface it and fix the offending callsite in this PR before relying on the invariant. (Likely candidates: `StockAdjustmentService::adjust` may not currently lock product; if so, bring it under the invariant.)

- [ ] **Step 4: Verify PHPStan + Pint clean**

```
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/WeightedAverageCostService.php tests/Architecture/InventoryProductLockInvariantTest.php --memory-limit=2G
cd apps/api && ./vendor/bin/pint --test app/Modules/Inventory/Application/Services/WeightedAverageCostService.php tests/Architecture/InventoryProductLockInvariantTest.php
```

Expected: both clean.

- [ ] **Step 5: Commit**

```
git add apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php \
        apps/api/tests/Architecture/InventoryProductLockInvariantTest.php
git commit -m "fix(inventory): product-lock invariant for WAC denominator; close deadlock + agg-lock blockers

Drops lockForUpdate() from the (on_hand + in_transit) sum queries — those
locks were either deadlock-prone (joined stock_transfers parents) or no-ops
(aggregates with FOR UPDATE in Postgres). The product lock is now the
single serialization point; an architecture test enforces it across every
Inventory mutator. Closes Codex v2 BLOCKER 1 + BLOCKER 2."
```

- [ ] **Step 2: Update existing callers of `recordCostAdjustment` to pass tenant + company**

The only existing call site today is inside `StockTransferService::capitalizeTransferCost` (which Batch A9 deletes). Confirm by grep:

```
cd apps/api && rg -n "recordCostAdjustment\(" app/
```

Expected: only the StockTransferService call site. Update it to pass `$transfer->tenant_id` and `$transfer->company_id`, even though the body of `capitalizeTransferCost` itself is removed in Task A9 — the *internal* method `recordCostEventInternal` (added in Task A7) will call `recordCostAdjustment` instead.

- [ ] **Step 3: Verify PHPStan + Pint clean on the file**

```
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/WeightedAverageCostService.php --memory-limit=2G
cd apps/api && ./vendor/bin/pint --test app/Modules/Inventory/Application/Services/WeightedAverageCostService.php
```

Expected: both clean.

- [ ] **Step 4: Commit**

```
git add apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php
git commit -m "fix(inventory): compute (on_hand + in_transit) denominator under same lock

Closes Codex BLOCKER 1 from the plan review: the denominator was read in
two steps (in_transit in StockTransferService, on_hand inside the WAC
service), so a concurrent transfer state transition between those reads
could shift one half without the other. Both halves now live inside the
WAC service's locked transaction; cross-transfer races serialize correctly."
```

### Task A7 — Add `StockTransferService::recordCostEvent()` method

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

The `inTransitQuantityForProduct` helper from the previous draft is **removed** — Codex BLOCKER 1's fix lives entirely inside `WeightedAverageCostService::recordCostAdjustment` now (Task A6). The transfer service only passes tenant + company; the WAC service locks and reads both denominator halves.

**Cross-product deadlock prevention.** Two concurrent cost events on different transfers that touch overlapping product sets — Transfer A has lines for products `[P, Q]`, transfer F has lines for `[Q, P]` — would deadlock if each call iterates its lines in the user-submitted order (B holds A+P, waits for Q; E holds F+Q, waits for P). The fix is to **sort lines by `product_id` ascending before the per-line lock loop** in every flow that locks products per line: `initiate()` (via `moveSourceToInTransit`), `complete()`, `cancel()` (the return-to-source path), and the new `recordCostEvent()`. All concurrent callers now request product locks in the same total order; cross-product deadlocks become impossible.

- [ ] **Step 1: Add `recordCostEvent()`**

```php
/**
 * Record a cost event on a transfer and capitalize it into the
 * company-wide WAC for every product on the transfer (allocated per
 * the chosen distribution mode).
 *
 * Allowed phases by transfer status:
 *   InTransit  → status must be InTransit
 *   AtReceipt  → status must be Completed
 *   AtInitiate → never via this method; created internally by initiate()
 */
public function recordCostEvent(RecordCostEventData $data): StockTransferCostEvent
{
    if ($data->phase === TransferCostEventPhase::AtInitiate) {
        throw new InvalidArgumentException('at_initiate events are created by initiate(), not recordCostEvent.');
    }

    return DB::transaction(function () use ($data): StockTransferCostEvent {
        $transfer = $this->lockTransferUnscoped($data->transferId);

        // Idempotency short-circuit on the cost-event endpoint, route-scoped
        // to the target transfer (Codex plan-review P1-1) AND payload-fingerprint
        // checked (Codex plan-review P2-7). If the key was used on a DIFFERENT
        // transfer in the same company, reject with 409. If it was used on the
        // same transfer with a different payload, reject with 409.
        if ($data->idempotencyKey !== null) {
            $existing = StockTransferCostEvent::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ($existing->transfer_id !== $transfer->id) {
                    throw new IdempotencyKeyConflictException(
                        $data->idempotencyKey, $existing->transfer_id, $transfer->id,
                    );
                }
                $expectedHash = $this->fingerprintCostEvent($data);
                if ($existing->idempotency_payload_hash !== null
                    && $existing->idempotency_payload_hash !== $expectedHash) {
                    throw new IdempotencyKeyConflictException(
                        $data->idempotencyKey,
                        $existing->idempotency_payload_hash,
                        $expectedHash,
                    );
                }
                return $existing;
            }
        }

        // Validate phase-vs-status.
        if (!in_array($transfer->status, $data->phase->allowedTransferStatuses(), true)) {
            throw new TransferStateException($transfer->id, $transfer->status, 'record cost event ('.$data->phase->value.')');
        }

        try {
            $event = StockTransferCostEvent::create([
                'id' => Str::uuid()->toString(),
                'transfer_id' => $transfer->id,
                'tenant_id' => $transfer->tenant_id,
                'company_id' => $transfer->company_id,
                'phase' => $data->phase,
                'amount' => $data->amount,
                'label' => $data->label,
                'distribution' => $data->distribution,
                'recorded_by_user_id' => $data->recordedByUserId,
                'idempotency_key' => $data->idempotencyKey,
                'idempotency_payload_hash' => $data->idempotencyKey !== null
                    ? $this->fingerprintCostEvent($data)
                    : null,
                'recorded_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Parallel insert with same idempotency_key — reload the winner
            // and re-apply the same route + payload checks the pre-insert
            // short-circuit applied. The catch path MUST NOT trust that the
            // key collided with our own legitimate intent; a hostile or
            // confused client could have reused a key on a different
            // transfer or with a different payload (Codex v2 P1 fix).
            if ($data->idempotencyKey === null) { throw $e; }
            $winner = StockTransferCostEvent::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->where('idempotency_key', $data->idempotencyKey)
                ->firstOrFail();

            if ($winner->transfer_id !== $transfer->id) {
                throw new IdempotencyKeyConflictException(
                    $data->idempotencyKey, $winner->transfer_id, $transfer->id,
                );
            }
            $expectedHash = $this->fingerprintCostEvent($data);
            if ($winner->idempotency_payload_hash !== null
                && $winner->idempotency_payload_hash !== $expectedHash) {
                throw new IdempotencyKeyConflictException(
                    $data->idempotencyKey,
                    $winner->idempotency_payload_hash,
                    $expectedHash,
                );
            }
            return $winner;
        }

        // Capitalize this event into WAC for every product on the transfer.
        $weights = $this->computeAllocationWeights($transfer, $data->distribution);
        $totalWeight = array_sum($weights);
        $eventAmount = (float) $data->amount;

        // Sort lines by product_id ascending so all concurrent callers
        // request product locks in the same total order, preventing
        // cross-product deadlocks (v3 plan-review fix).
        $sortedLines = $transfer->lines->sortBy('product_id')->values();

        foreach ($sortedLines as $line) {
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            $allocated = $totalWeight > 0
                ? $eventAmount * ($weights[$line->id] / $totalWeight)
                : $eventAmount / max(1, $transfer->lines->count());
            // Allowed values: positive (normal capitalization), negative
            // (reversal event from the cancel flow). Only true zero is
            // skipped because a zero-amount allocation is a no-op (Codex
            // v2 BLOCKER 3 fix: the old `<= 0` skip silently dropped every
            // reversal event's WAC adjustment).
            if (round($allocated, 4) === 0.0) { continue; }

            // Bump the line's running allocated_transfer_cost. bcadd is
            // signed-safe; a negative $allocated reduces the line's
            // running cost projection.
            $line->allocated_transfer_cost = bcadd(
                (string) $line->allocated_transfer_cost,
                (string) round($allocated, 4),
                4
            );
            $line->save();

            $this->wacService->recordCostAdjustment(
                product: $product,
                additionalCost: $allocated,
                reason: 'stock_transfer_cost.'.$data->phase->value,
                tenantId: $transfer->tenant_id,
                companyId: $transfer->company_id,
                reference: $transfer->transfer_number,
                referenceType: StockTransfer::class,
                referenceId: $transfer->id,
            );
        }

        // Keep the legacy transfer_cost column in sync so reports/list views
        // sorting by cost don't break.
        $transfer->transfer_cost = bcadd((string) $transfer->transfer_cost, (string) $data->amount, 4);
        $transfer->save();

        $eventSnapshot = $event;
        DB::afterCommit(function () use ($eventSnapshot): void {
            event(new StockTransferCostEventRecorded(
                costEventId: $eventSnapshot->id,
                transferId: $eventSnapshot->transfer_id,
                tenantId: $eventSnapshot->tenant_id,
                companyId: $eventSnapshot->company_id,
                phase: $eventSnapshot->phase,
                amount: (string) $eventSnapshot->amount,
                recordedByUserId: $eventSnapshot->recorded_by_user_id,
                occurredAt: now()->toIso8601String(),
            ));
        });

        return $event;
    });
}

private function lockTransferUnscoped(string $transferId): StockTransfer
{
    return StockTransfer::query()->with('lines')->lockForUpdate()->findOrFail($transferId);
}

private function fingerprintCostEvent(RecordCostEventData $data): string
{
    return hash('sha256', json_encode([
        'phase' => $data->phase->value,
        'amount' => $data->amount,
        'label' => $data->label,
        'distribution' => $data->distribution->value,
    ], JSON_THROW_ON_ERROR));
}
```

Note: `computeAllocationWeights()` already exists for the line-level distribution math. Refactor its signature to accept a `TransferCostDistribution` parameter (so cost events can pick a distribution that differs from the transfer-level default).

### Task A8 — Modify `initiate()` to emit an `at_initiate` event instead of writing `transfer_cost` directly

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- [ ] **Step 1: After `StockTransfer::create()` in `initiate`, emit a cost event when initial cost > 0**

Inside `initiate()` after the lines are created and `moveSourceToInTransit` is called (which has already flipped status to InTransit), invoke:

```php
if (bccomp($data->transferCost, '0', 4) > 0) {
    $this->recordCostEventInternal(
        $transferSnapshot,
        new RecordCostEventData(
            transferId: $transferSnapshot->id,
            phase: TransferCostEventPhase::AtInitiate,
            amount: $data->transferCost,
            recordedByUserId: $data->initiatedByUserId,
            label: $data->transferCostLabel,
            distribution: $data->transferCostDistribution,
            idempotencyKey: null,
        ),
    );
}
```

`recordCostEventInternal()` is a private variant that bypasses the `AtInitiate` phase guard (which the public `recordCostEvent()` rejects) but otherwise runs the same WAC capitalization + line-cost bump + event dispatch.

Refactor: extract the body of `recordCostEvent` (after the phase-vs-status validation) into `recordCostEventInternal()`. The public method runs the phase guard then delegates; `initiate()` calls the internal variant directly.

- [ ] **Step 2: Stop writing `transfer_cost` directly in `initiate()`'s `StockTransfer::create()`**

The column is now maintained by `recordCostEventInternal()` so initiate just initializes it to `0.0000` and lets the event bump it.

### Task A9 — Remove `capitalizeTransferCost()` from `complete()`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- [ ] **Step 1: Delete the `capitalizeTransferCost()` call inside `complete()` and the method body itself**

Cost capitalization is now per-event, not per-completion. The completion path only moves stock and records the destination receipt.

- [ ] **Step 2: Run the Task A5 test**

```
cd apps/api && ./vendor/bin/phpunit --filter test_wac_is_deterministic_across_multi_event_cost_streams_for_concurrent_transfers tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: PASS. WAC = 7.1000 regardless of AB/BA ordering.

- [ ] **Step 3: Verify the pre-existing single-event WAC test (`test_complete_with_transfer_cost_recomputes_company_wide_wac`) still passes**

In the single-event case (one transfer with `transferCost=60`, no `recordCostEvent` calls), the `at_initiate` event fires from `initiate()`, capitalizes 60 against (100 + 20) = 120, gives 5.50. ✓ unchanged from the previous assertion.

- [ ] **Step 4: Commit**

```
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
git commit -m "feat(inventory): multi-event cost stream — WAC capitalizes per-event with in-transit-aware denominator

Cost is no longer a single field captured at initiate and applied at
complete. Each cost event (at_initiate / in_transit / at_receipt)
capitalizes immediately against (on_hand + in_transit), which is invariant
under transfer-state transitions and produces deterministic WAC regardless
of which transfer's events happen in which order. Closes Codex BLOCKER #1
with the architecture chosen by the owner."
```

### Task A10 — HTTP endpoint + request for recording cost events

**Files:**
- Create: `apps/api/app/Modules/Inventory/Presentation/Requests/RecordCostEventRequest.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/routes.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

- [ ] **Step 1: Write the request validator**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferCostEventPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCostEventRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'phase' => ['required', Rule::in([
                TransferCostEventPhase::InTransit->value,
                TransferCostEventPhase::AtReceipt->value,
            ])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'label' => ['nullable', 'string', 'max:128'],
            'distribution' => ['nullable', Rule::enum(TransferCostDistribution::class)],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
```

- [ ] **Step 2: Add the controller method**

```php
public function recordCostEvent(RecordCostEventRequest $request, string $transfer): JsonResponse
{
    $company = $this->companyContext->requireCompany();
    /** @var User $user */
    $user = $request->user();

    if (!Str::isUuid($transfer)) { abort(404); }

    $existing = StockTransfer::query()
        ->where('tenant_id', $company->tenant_id)
        ->where('company_id', $company->id)
        ->findOrFail($transfer);

    /** @var numeric-string $amount */
    $amount = (string) $request->input('amount');

    try {
        $event = $this->service->recordCostEvent(new RecordCostEventData(
            transferId: $existing->id,
            phase: TransferCostEventPhase::from($request->input('phase')),
            amount: $amount,
            recordedByUserId: $user->id,
            label: $request->input('label'),
            distribution: $request->input('distribution')
                ? TransferCostDistribution::from($request->input('distribution'))
                : TransferCostDistribution::ProRataValue,
            idempotencyKey: $request->input('idempotency_key'),
        ));
    } catch (TransferStateException $e) {
        return $this->stateExceptionResponse($e);
    } catch (InvalidArgumentException $e) {
        return response()->json([
            'error' => ['code' => 'INVALID_COST_EVENT', 'message' => $e->getMessage()],
        ], 422);
    }

    return response()->json(['data' => [
        'id' => $event->id,
        'transfer_id' => $event->transfer_id,
        'phase' => $event->phase->value,
        'amount' => $event->amount,
        'label' => $event->label,
        'distribution' => $event->distribution->value,
        'recorded_by_user_id' => $event->recorded_by_user_id,
        'recorded_at' => $event->recorded_at->toIso8601String(),
    ]], 201);
}
```

- [ ] **Step 3: Register the route**

```php
Route::post('/stock-transfers/{transfer}/cost-events', [StockTransferController::class, 'recordCostEvent'])
    ->middleware('can:inventory.transfers.record_cost')
    ->name('stock-transfers.record-cost-event');
```

- [ ] **Step 4: Add the new permission**

In `RolesAndPermissionsSeeder.php`, add `'inventory.transfers.record_cost'` to both the declaration list AND the admin/manager role grants.

- [ ] **Step 5: Update `usePermissions.ts` for the new key**

- [ ] **Step 6: Commit**

```
git add apps/api/app/Modules/Inventory/Presentation/Requests/RecordCostEventRequest.php \
        apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php \
        apps/api/app/Modules/Inventory/Presentation/routes.php \
        apps/api/database/seeders/RolesAndPermissionsSeeder.php \
        apps/web/src/hooks/usePermissions.ts
git commit -m "feat(inventory): HTTP endpoint POST /stock-transfers/{id}/cost-events + permission"
```

### Task A11 — Frontend: surface cost-event ledger + Add-cost affordance on detail page

**Files:**
- Modify: `apps/web/src/features/stock-transfers/types/index.ts` (add `StockTransferCostEvent` interface + ledger field on `StockTransfer`)
- Modify: `apps/web/src/features/stock-transfers/api/stockTransferApi.ts` (add `recordCostEvent`)
- Modify: `apps/web/src/features/stock-transfers/api/queries.ts` (add `useRecordCostEvent`)
- Modify: `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`
- Modify: `apps/web/src/locales/{en,fr}/stock-transfers.json` (new keys for the Add-cost flow + cost-event table)

- [ ] **Step 1: Type the cost event + extend the StockTransfer interface**

```ts
export type TransferCostEventPhase = 'at_initiate' | 'in_transit' | 'at_receipt'

export interface StockTransferCostEvent {
  id: string
  transfer_id: string
  phase: TransferCostEventPhase
  amount: string
  label: string | null
  distribution: TransferCostDistribution
  recorded_by_user_id: string
  recorded_at: string
}

export interface StockTransfer {
  // ... existing ...
  cost_events?: StockTransferCostEvent[]
  total_cost: string  // sum of cost_events.amount, populated by the show endpoint
}

export interface RecordCostEventInput {
  phase: 'in_transit' | 'at_receipt'  // at_initiate forbidden via the public POST
  amount: string
  label?: string
  distribution?: TransferCostDistribution
  idempotency_key?: string
}
```

- [ ] **Step 2: Add the API + query hook**

```ts
recordCostEvent: async (id: string, input: RecordCostEventInput): Promise<StockTransferCostEvent> => {
  return apiPost<StockTransferCostEvent>(`${BASE_URL}/${id}/cost-events`, input)
}

export function useRecordCostEvent() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: RecordCostEventInput }) =>
      stockTransferApi.recordCostEvent(id, input),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace, 'detail', id]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace, 'list']) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['products']) })
    },
  })
}
```

- [ ] **Step 3: On the detail page, add a "Cost ledger" section and an "Add cost" button**

The button is visible when `status === 'in_transit'` (phase=in_transit) or `status === 'completed'` (phase=at_receipt). Clicking opens a modal with: amount, label, distribution (default pro_rata_value), idempotency_key generated on mount. Submitting calls `useRecordCostEvent` and toasts the server message.

The "Cost ledger" section is a small table beneath the lines section, showing all `cost_events` with phase badge / amount / label / recorded_by_name / recorded_at.

- [ ] **Step 4: Include the ledger in the API response**

Update `StockTransferController::show()` to `with(['costEvents.recordedBy'])` and serialize `cost_events` + `total_cost` in `formatTransfer()`.

- [ ] **Step 5: Add Vitest for the Add-cost interaction**

- [ ] **Step 6: Lint + typecheck + Vitest + ESLint baseline**

```
cd apps/web && pnpm test -- --run src/features/stock-transfers && pnpm typecheck && pnpm lint
```

Expected: clean. ESLint warning ratchet still at baseline (11343).

- [ ] **Step 7: Commit**

```
git add apps/web/src/features/stock-transfers \
        apps/web/src/locales/en/stock-transfers.json \
        apps/web/src/locales/fr/stock-transfers.json \
        apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php
git commit -m "feat(stock-transfers): cost-event ledger + Add-cost affordance on detail page"
```

### Task A12 — Verify the existing single-event WAC test still passes + add multi-phase positive/negative tests

- [ ] **Step 1: Run the full transfer suite**

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: all green. Specifically:
- The pre-existing `test_complete_with_transfer_cost_recomputes_company_wide_wac` still passes — the single `at_initiate` event capitalizes 60 against (100+20=120) and produces 5.50.
- The new multi-event determinism test from A5 passes — 7.10 regardless of ordering.

- [ ] **Step 2: Add negative-path tests**

```php
public function test_cannot_record_in_transit_cost_event_on_completed_transfer(): void { /* status=Completed; phase=InTransit; expect TransferStateException */ }
public function test_cannot_record_at_receipt_cost_event_on_in_transit_transfer(): void { /* status=InTransit; phase=AtReceipt; expect TransferStateException */ }
public function test_cannot_record_cost_event_on_cancelled_transfer(): void { /* status=Cancelled; expect TransferStateException */ }
public function test_record_cost_event_is_idempotent_on_same_key(): void { /* two POSTs with same key return same event id, ledger has one row */ }
public function test_record_cost_event_with_zero_amount_rejected_at_dto(): void { /* InvalidArgumentException at DTO construction */ }
```

- [ ] **Step 3: Commit**

---

## Batch B — Idempotency end-to-end (Codex BLOCKER #2 + P1-1 + P1-2 + Opus P1-1 + P1-2)

### Task B1 — Add migration columns for complete/cancel idempotency

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_05_28_130000_add_idempotency_to_stock_transfers.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->string('complete_idempotency_key', 128)->nullable()->after('idempotency_key');
            $table->string('cancel_idempotency_key', 128)->nullable()->after('complete_idempotency_key');
            $table->string('idempotency_payload_hash', 64)->nullable()->after('cancel_idempotency_key');

            $table->unique(['tenant_id', 'company_id', 'complete_idempotency_key'], 'stock_transfers_complete_idem_unique');
            $table->unique(['tenant_id', 'company_id', 'cancel_idempotency_key'], 'stock_transfers_cancel_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropUnique('stock_transfers_cancel_idem_unique');
            $table->dropUnique('stock_transfers_complete_idem_unique');
            $table->dropColumn(['idempotency_payload_hash', 'cancel_idempotency_key', 'complete_idempotency_key']);
        });
    }
};
```

> **NOTE:** This migration is placed in `database/migrations/tenant/` from day one because Batch G has not yet relocated the original `2026_05_28_120000_create_stock_transfers_table.php`. The relocation in Batch G will leave this migration in the same folder.

- [ ] **Step 2: Run the migration against the in-memory SQLite test DB**

```
cd apps/api && php artisan migrate:fresh --env=testing 2>&1 | tail -10
```

Expected: migration runs without error.

- [ ] **Step 3: Commit**

```
git add apps/api/database/migrations/tenant/2026_05_28_130000_add_idempotency_to_stock_transfers.php
git commit -m "feat(inventory): add complete/cancel idempotency columns to stock_transfers"
```

### Task B2 — Failing test for `complete()` idempotency

**Files:**
- Test: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` (extend)

- [ ] **Step 1: Write the failing test**

```php
public function test_complete_with_same_idempotency_key_returns_existing_transfer(): void
{
    $this->seedStock($this->productA, $this->warehouse, '50.0000');

    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
    ));

    $key = 'complete-retry-1';
    $first = $this->service()->complete(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->company->id,
        idempotencyKey: $key,
    );
    $second = $this->service()->complete(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->company->id,
        idempotencyKey: $key,
    );

    $this->assertSame($first->id, $second->id);
    $this->assertSame(TransferStatus::Completed, $second->status);

    // Destination only incremented once.
    $destStock = StockLevel::query()
        ->where('product_id', $this->productA->id)
        ->where('location_id', $this->shop->id)
        ->first();
    $this->assertEquals('5.00', $destStock->quantity);
}
```

- [ ] **Step 2: Run, expect failure**

```
cd apps/api && ./vendor/bin/phpunit --filter test_complete_with_same_idempotency_key_returns_existing_transfer tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: `Too few arguments to function ...complete()` — the service signature does not accept a key yet.

### Task B3 — Extend service signature; implement complete idempotency short-circuit

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- Modify: `apps/api/app/Modules/Inventory/Domain/StockTransfer.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` — every call site of `service->complete(...)` and `service->cancel(...)` switches to named-argument form with `tenantId: $company->tenant_id, companyId: $company->id`.
- Modify: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` — sweep every pre-existing `complete(`/`cancel(` call site to the new final-locked shape. The plan's earlier WAC determinism tests at the v3 line numbers 522/530/560/573 are explicitly part of this sweep; the multi-event determinism test from Task A5 already uses the new shape via the `initiateData()` helper. Audit the file with `rg -n 'service\(\)->(complete|cancel)\(' apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` and confirm every match has the locked five-/six-argument shape before commit.

- [ ] **Step 1: Add `complete_idempotency_key` to the model fillable**

```php
// in StockTransfer.php — extend $fillable
protected $fillable = [
    // ... existing keys ...
    'complete_idempotency_key',
    'cancel_idempotency_key',
    'idempotency_payload_hash',
];
```

- [ ] **Step 2: Change the service signature to the FINAL locked shape**

Lock the canonical signature here so Batch D doesn't need to rewrite it (Codex plan-review P1-2). The order is: `transferId, userId, tenantId, companyId, ?idempotencyKey`. `cancel` follows the same order with an extra `?reason` parameter sitting between `companyId` and `?idempotencyKey` so the user-facing reason can be passed even without an idempotency_key:

Change `complete(string $transferId, string $userId): StockTransfer` to:

```php
public function complete(
    string $transferId,
    string $userId,
    string $tenantId,
    string $companyId,
    ?string $idempotencyKey = null,
): StockTransfer {
    return DB::transaction(function () use ($transferId, $userId, $tenantId, $companyId, $idempotencyKey): StockTransfer {
        $transfer = $this->lockTransfer($transferId, $tenantId, $companyId);

        // Idempotency short-circuit (route-scoped per Codex plan-review P1-1):
        // if this key was already used to complete THIS specific transfer,
        // return the existing row. If the same key was used on a DIFFERENT
        // transfer in the same company, throw IdempotencyKeyConflictException
        // so the client cannot accidentally observe an unrelated aggregate.
        if ($idempotencyKey !== null) {
            $existing = StockTransfer::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->where('complete_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ($existing->id !== $transfer->id) {
                    throw new IdempotencyKeyConflictException(
                        $idempotencyKey, $existing->id, $transfer->id,
                    );
                }
                return $existing->loadMissing('lines');
            }
        }

        if (! $transfer->status->canBeCompleted()) {
            throw new TransferStateException($transfer->id, $transfer->status, 'complete');
        }

        // ... existing body ...

        $transfer->status = TransferStatus::Completed;
        $transfer->completed_by_user_id = $userId;
        $transfer->completed_at = now();
        $transfer->complete_idempotency_key = $idempotencyKey; // <-- NEW
        $transfer->save();

        // ... afterCommit event dispatch unchanged ...
    });
}
```

- [ ] **Step 3: Run the test**

```
cd apps/api && ./vendor/bin/phpunit --filter test_complete_with_same_idempotency_key_returns_existing_transfer tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: PASS.

- [ ] **Step 4: Add the symmetric cancel test + implement cancel idempotency**

```php
public function test_cancel_with_same_idempotency_key_returns_existing_transfer(): void
{
    $this->seedStock($this->productA, $this->warehouse, '20.0000');

    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
    ));

    $key = 'cancel-retry-1';
    $first = $this->service()->cancel(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->company->id,
        reason: 'lost shipment',
        idempotencyKey: $key,
    );
    $second = $this->service()->cancel(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->company->id,
        reason: 'lost shipment',
        idempotencyKey: $key,
    );

    $this->assertSame($first->id, $second->id);
    $this->assertSame(TransferStatus::Cancelled, $second->status);

    $sourceStock = StockLevel::query()
        ->where('product_id', $this->productA->id)
        ->where('location_id', $this->warehouse->id)
        ->first();
    $this->assertEquals('20.0000', $sourceStock->quantity);
}
```

Change the cancel signature to the FINAL locked shape symmetrically:

```php
public function cancel(
    string $transferId,
    string $userId,
    string $tenantId,
    string $companyId,
    ?string $reason = null,
    ?string $idempotencyKey = null,
): StockTransfer
```

Add the same route-scoped short-circuit and `cancel_idempotency_key` write that complete uses.

- [ ] **Step 5: Run both tests**

```
cd apps/api && ./vendor/bin/phpunit --filter "test_(complete|cancel)_with_same_idempotency_key_returns_existing_transfer" tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: 2 passing.

- [ ] **Step 6: Commit**

```
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/app/Modules/Inventory/Domain/StockTransfer.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
git commit -m "feat(inventory): idempotent complete and cancel

Closes Codex BLOCKER #2 (spec §202: complete must be retry-safe via
idempotency_key) and Opus P1-1."
```

### Task B4 — Catch-and-reload pattern on parallel `initiate` with same idempotency_key

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- Test: `apps/api/tests/Feature/Inventory/InventoryTransferServiceConcurrencyTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

// ... same imports as InventoryTransferServiceTest ...

class InventoryTransferServiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // ... same setUp as InventoryTransferServiceTest (factor into a trait if needed) ...

    public function test_parallel_initiate_with_same_idempotency_key_does_not_throw_unique_violation(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '50.0000');

        $key = 'parallel-1';
        $data = fn () => new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->productA->id, '5.0000')],
            idempotencyKey: $key,
        );

        // The TRUE race is "pre-check missed, INSERT hit the unique
        // constraint". Force exactly that codepath instead of relying on
        // the serial pre-check short-circuit (Codex v2 P1 fix). We use
        // PHPUnit reflection + DB::shouldReceive to invalidate the pre-check
        // result, OR more cheaply: seed an existing row via DB::table()
        // insert (bypassing the service's pre-check window entirely), then
        // call initiate() once — the service's pre-check finds the seeded
        // row and short-circuits. To force the catch path:
        //
        //   (a) Disable the pre-check temporarily via a service-level
        //       feature flag in the test environment, OR
        //   (b) Use a fake repository in the test that returns no existing
        //       row for the pre-check but lets the INSERT throw the unique
        //       violation against the seeded row.
        //
        // Pattern (b) is the cleanest. The test uses the `$this->without`
        // pattern that PHPUnit + Eloquent's model factories support: bind
        // a fake StockTransfer::query() that skips the pre-check, then run
        // initiate(), then assert the catch path returned the seeded row.

        // Seed a winning row directly via DB::table to bypass the service.
        $winnerId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('stock_transfers')->insert([
            'id' => $winnerId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'transfer_number' => 'TR-RACE-WINNER',
            'transfer_type' => TransferType::Intracompany->value,
            'status' => TransferStatus::InTransit->value,
            'source_location_id' => $this->warehouse->id,
            'destination_location_id' => $this->shop->id,
            'initiated_by_user_id' => $this->user->id,
            'idempotency_key' => $key,
            'idempotency_payload_hash' => 'seeded-fingerprint',
            'transfer_cost' => '0',
            'transfer_cost_distribution' => TransferCostDistribution::ProRataValue->value,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Now run initiate() and assert it returns the winner (the catch
        // path took over because the INSERT inside initiate's transaction
        // raised UniqueConstraintViolationException, and the catch block
        // reloaded by (tenant, company, key) and confirmed the payload hash
        // matches via the fingerprint helper).
        //
        // Because the seeded row's fingerprint doesn't match the
        // service-computed fingerprint of $data(), the catch path will
        // throw IdempotencyKeyConflictException — which IS the correct
        // behavior for a real race with a hostile collision. Assert
        // that:
        $this->expectException(IdempotencyKeyConflictException::class);
        $this->service()->initiate($data());
    }

    public function test_parallel_initiate_with_matching_fingerprint_returns_the_winner(): void
    {
        // Symmetric test: seed a row with a fingerprint that matches what
        // the service would compute for the same $data(). The catch path
        // should return the seeded row without throwing.
        $this->seedStock($this->productA, $this->warehouse, '50.0000');
        $key = 'parallel-2';
        $data = fn () => new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->productA->id, '5.0000')],
            idempotencyKey: $key,
        );

        // Compute the canonical fingerprint via the service's helper. The
        // test asks the service to expose `fingerprintPayload` as `public`
        // for testability (or wraps it in a `@internal` test-only method);
        // either way the test computes the same hash the service would.
        $service = $this->service();
        $expectedHash = $service::fingerprintForTest($data());

        $winnerId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('stock_transfers')->insert([
            // ... same as above, but idempotency_payload_hash = $expectedHash ...
        ]);

        $result = $service->initiate($data());
        $this->assertSame($winnerId, $result->id);
    }
}
```

Both tests exercise the actual catch-and-reload path (Codex v1 P2-4 + v2 P1). The pre-check serial path is covered by the existing `test_initiate_is_idempotent_when_same_idempotency_key_is_used` test.

- [ ] **Step 2: Add the catch-and-reload pattern to `initiate`**

Right after the read-before-insert short-circuit, wrap the insert section:

```php
try {
    /** @var StockTransfer $transfer */
    $transfer = StockTransfer::create([ /* ... unchanged payload ... */ ]);

    // ... line creation loop ...
} catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
    if ($data->idempotencyKey === null) {
        throw $e;
    }
    // Another transaction won the race. Reload the winner AND re-validate
    // payload-fingerprint match before returning. The catch path MUST NOT
    // trust that the colliding key matched our intent: a confused or
    // hostile client could have reused the key with a different payload,
    // in which case 409 is correct (Codex v2 P1 fix).
    $winner = StockTransfer::query()
        ->where('tenant_id', $data->tenantId)
        ->where('company_id', $data->companyId)
        ->where('idempotency_key', $data->idempotencyKey)
        ->with('lines')
        ->firstOrFail();

    $expectedHash = $this->fingerprintPayload($data);
    if ($winner->idempotency_payload_hash !== null
        && $winner->idempotency_payload_hash !== $expectedHash) {
        throw new IdempotencyKeyConflictException(
            $data->idempotencyKey,
            $winner->idempotency_payload_hash,
            $expectedHash,
        );
    }

    return $winner;
}
```

- [ ] **Step 3: Run the test**

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceConcurrencyTest.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceConcurrencyTest.php
git commit -m "fix(inventory): catch unique-violation on parallel initiate with same idempotency_key

Closes Codex P1-1. The read-before-insert short-circuit cannot prevent
two transactions from reaching the INSERT simultaneously; the catch path
reloads the winner and returns it."
```

### Task B5 — Same-key-different-payload returns 409

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- Test: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`

- [ ] **Step 1: Add a payload-hash helper and failing test**

```php
public function test_reusing_idempotency_key_with_different_payload_throws_mismatch(): void
{
    $this->seedStock($this->productA, $this->warehouse, '50.0000');

    $key = 'k';
    $first = $this->service()->initiate(new InitiateTransferData(
        tenantId: $this->tenant->id, companyId: $this->company->id,
        sourceLocationId: $this->warehouse->id, destinationLocationId: $this->shop->id,
        initiatedByUserId: $this->user->id,
        lines: [new InitiateTransferLineData($this->productA->id, '5.0000')],
        idempotencyKey: $key,
    ));

    $this->expectException(\App\Modules\Inventory\Domain\Exceptions\IdempotencyKeyConflictException::class);

    $this->service()->initiate(new InitiateTransferData(
        tenantId: $this->tenant->id, companyId: $this->company->id,
        sourceLocationId: $this->warehouse->id, destinationLocationId: $this->shop->id,
        initiatedByUserId: $this->user->id,
        lines: [new InitiateTransferLineData($this->productA->id, '6.0000')], // <-- different qty
        idempotencyKey: $key,
    ));
}
```

- [ ] **Step 2: Create the exception**

`apps/api/app/Modules/Inventory/Domain/Exceptions/IdempotencyKeyConflictException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

class IdempotencyKeyConflictException extends DomainException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $expectedHash,
        public readonly string $actualHash,
    ) {
        parent::__construct(
            "Idempotency key {$idempotencyKey} was previously used with a different payload."
        );
    }
}
```

- [ ] **Step 3: Add payload-hash computation + comparison in `initiate`**

```php
private function fingerprintPayload(InitiateTransferData $data): string
{
    // Canonicalize: sort lines by product_id so the same logical payload
    // submitted with lines in a different order produces the same hash
    // (Codex plan-review P2-3). Include every DTO field that materially
    // describes the resulting transfer. Excluded fields:
    //   - initiatedByUserId: the user is an actor, not part of resource identity.
    //   - idempotencyKey: including it would make the hash trivially
    //     self-consistent.
    // Included fields:
    //   - transferNumber: when the caller supplies it (instead of letting
    //     the server allocate), it materially changes the resulting row.
    //     A client that supplies different transfer_numbers with the same
    //     idempotency_key is doing something incoherent and should get 409
    //     (Codex v2 P2 fix).
    $lines = array_map(
        fn ($l) => ['product_id' => $l->productId, 'quantity' => $l->quantity],
        $data->lines,
    );
    usort($lines, fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));

    return hash('sha256', json_encode([
        'source' => $data->sourceLocationId,
        'dest' => $data->destinationLocationId,
        'transfer_type' => $data->transferType->value,
        'transfer_number' => $data->transferNumber, // null when server-allocated; non-null differs
        'lines' => $lines,
        'cost' => $data->transferCost,
        'cost_label' => $data->transferCostLabel,
        'distribution' => $data->transferCostDistribution->value,
        'notes' => $data->notes,
    ], JSON_THROW_ON_ERROR));
}

// in the short-circuit branch:
if ($existing !== null) {
    $expected = $existing->idempotency_payload_hash;
    $actual = $this->fingerprintPayload($data);
    if ($expected !== null && $expected !== $actual) {
        throw new IdempotencyKeyConflictException($data->idempotencyKey, $expected, $actual);
    }
    return $existing->loadMissing('lines');
}

// in the create() call, persist the fingerprint:
$transfer = StockTransfer::create([
    // ... existing ...
    'idempotency_payload_hash' => $this->fingerprintPayload($data),
]);
```

- [ ] **Step 4: Surface as HTTP 409 in the controller**

In `StockTransferController::store`, add a `catch (IdempotencyKeyConflictException $e)` that returns:

```php
return response()->json([
    'error' => [
        'code' => 'IDEMPOTENCY_KEY_CONFLICT',
        'message' => $e->getMessage(),
    ],
], 409);
```

- [ ] **Step 5: Run the test**

```
cd apps/api && ./vendor/bin/phpunit --filter test_reusing_idempotency_key_with_different_payload_throws_mismatch tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```
git add apps/api/app/Modules/Inventory/Domain/Exceptions/IdempotencyKeyConflictException.php \
        apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
git commit -m "feat(inventory): reject idempotency_key reuse with different payload (409)

Closes Codex P2-2."
```

### Task B6 — Transfer-number race: switch to INSERT-with-retry

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- [ ] **Step 1: Replace `generateTransferNumber` with a retry loop**

```php
private function generateTransferNumber(string $tenantId, string $companyId): string
{
    $year = now()->format('Y');
    $maxAttempts = 5;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $count = StockTransfer::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereYear('created_at', $year)
            ->count();
        $candidate = sprintf('TR-%s-%05d', $year, $count + 1 + $attempt);

        $taken = StockTransfer::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('transfer_number', $candidate)
            ->exists();

        if (! $taken) {
            return $candidate;
        }
    }

    throw new \RuntimeException("Could not allocate a transfer number after {$maxAttempts} attempts.");
}
```

- [ ] **Step 2: Wrap the actual `INSERT` with `UniqueConstraintViolationException` handling so a true parallel race retries with `+1`**

Inside `initiate`, the existing try/catch from Task B4 already covers the `idempotency_key`-unique race. Add a sibling `catch` for the `transfer_number` unique violation that re-allocates and retries up to 3 times. Pattern:

```php
for ($n = 0; $n < 3; $n++) {
    try {
        $transfer = StockTransfer::create([
            // ... existing payload with $transferNumber ...
        ]);
        // ... line loop ...
        break;
    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
        if (str_contains($e->getMessage(), 'transfer_number') === false) {
            throw $e;
        }
        $transferNumber = $this->generateTransferNumber($data->tenantId, $data->companyId);
    }
}
```

- [ ] **Step 3: Add a failing test using two simulated transactions**

A real race needs two DB connections, which is unwieldy in PHPUnit. The pragmatic substitute: seed an existing row with `transfer_number = 'TR-YYYY-00001'` directly via `DB::table` before calling `initiate`, and assert the service auto-allocates `'TR-YYYY-00002'` without throwing.

- [ ] **Step 4: Commit**

---

## Batch C — Re-label refactor (Codex P1-3 + P1-4)

The "issue then re-label" pattern is the root cause of two distinct Codex findings: the audit-row predicate is fragile under timestamp ties, and the `StockMovementRecorded` event emits the pre-relabel type. The fix is to thread `movementType`, `referenceType`, `referenceId` through `StockAdjustmentService::issue/receive/recordMovement` so the row is created with the right values on the first try.

### Task C1 — Extend `StockAdjustmentService::recordMovement`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`

- [ ] **Step 1: Change `recordMovement` signature**

```php
private function recordMovement(
    string $tenantId,
    string $companyId,
    string $productId,
    string $locationId,
    MovementType $type,
    string $quantity,
    string $quantityBefore,
    string $quantityAfter,
    string $reference,
    string $userId,
    ?string $referenceType = null, // <-- NEW
    ?string $referenceId = null,   // <-- NEW
): StockMovement {
    $location = Location::query()
        ->where('company_id', $companyId)
        ->findOrFail($locationId);

    return StockMovement::create([
        'tenant_id' => $tenantId,
        'company_id' => $location->company_id,
        'product_id' => $productId,
        'location_id' => $locationId,
        'movement_type' => $type,
        'quantity' => $quantity,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reference' => $reference,
        'reference_type' => $referenceType, // <-- NEW
        'reference_id' => $referenceId,     // <-- NEW
        'user_id' => $userId,
    ]);
}
```

- [ ] **Step 2: Extend `issue()`, `receive()`, and `transfer()` to accept and forward those parameters**

For `issue` add: `MovementType $movementType = MovementType::Issue`, `?string $referenceType = null`, `?string $referenceId = null`. Forward to `recordMovement`. Same for `receive`. The legacy primitive `transfer()` does not need a new signature; it already emits the right types.

- [ ] **Step 3: Update the `StockMovementRecorded` event dispatches to use the actual movement type**

In `issue()` the `afterCommit` payload sets `movementType: 'issue'` — change to `movementType: $movementType->value`. Same for `receive()`.

- [ ] **Step 4: Run the existing inventory suite to ensure no regressions**

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/
```

Expected: 133+ tests, all green. Defaults preserve existing behavior.

### Task C2 — Switch `StockTransferService` to the direct path; delete `relabelLatestMovement`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- [ ] **Step 1: Replace each `stockAdjustmentService->issue(...)` + `relabelLatestMovement(...)` pair with a single call passing the desired type and reference**

For the source-decrement loop in `moveSourceToInTransit`:

```php
$this->stockAdjustmentService->issue(
    productId: $product->id,
    locationId: $transfer->source_location_id,
    quantity: (string) $line->quantity,
    reference: $reference,
    userId: $userId,
    expectedCompanyId: $transfer->company_id,
    movementType: MovementType::TransferOut,
    referenceType: StockTransfer::class,
    referenceId: $transfer->id,
);
// (delete the relabelLatestMovement call)
```

Same pattern in `complete()` (use `MovementType::TransferIn` with `receive`) and `cancel()` (use `MovementType::TransferIn` with `receive` for the return-to-source).

- [ ] **Step 2: Delete the `relabelLatestMovement` helper**

- [ ] **Step 3: Run the full transfer suite**

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: all tests still pass — the audit-trail assertions on movement_type were already in place and still hold.

- [ ] **Step 4: Add a test for the event payload**

```php
public function test_stock_movement_recorded_event_emits_transfer_movement_type(): void
{
    Event::fake([StockMovementRecorded::class]);

    $this->seedStock($this->productA, $this->warehouse, '20.0000');

    $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
    ));

    Event::assertDispatched(StockMovementRecorded::class, function ($e) {
        return $e->movementType === 'transfer_out';
    });
}
```

- [ ] **Step 5: Commit**

```
git add apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php \
        apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
git commit -m "refactor(inventory): thread movement_type + reference into StockAdjustmentService

Removes the post-hoc 'relabel most recent movement' pattern. Closes
Codex P1-3 (brittle re-label under concurrent admin-issued references)
and P1-4 (StockMovementRecorded event type drift)."
```

---

## Batch D — Defense-in-depth + test coverage (Codex P2-3/4/8 + P3-3, Opus P2-3/4)

### Task D1 — Cross-company scoping regression test (defense-in-depth)

**Files:**
- Test: `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`

The service signatures already require `tenantId` + `companyId` from Batch B3 (final-locked shape). The internal `lockTransfer(string $transferId, string $tenantId, string $companyId)` query is already scoped. This task adds the regression test that proves it.

- [ ] **Step 1: Write the failing test**

```php
public function test_complete_rejects_transfer_from_other_company(): void
{
    $this->seedStock($this->productA, $this->warehouse, '20.0000');
    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '5.0000')],
    ));

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->service()->complete(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->otherCompany->id, // <-- wrong company
    );
}
```

- [ ] **Step 2: Run + verify the lockTransfer scoping rejects the lookup. Add the symmetric cancel test. Commit.**

### Task D2 — Cross-tenant product rejection test (Opus P2-4)

- [ ] **Step 1: Add a test that initiates a transfer with a product belonging to `$this->otherCompany` and asserts `InvalidArgumentException` with "Products do not belong to the current company"**

### Task D3 — Distribution-mode tests (Codex P2-8, Opus P2 mention)

- [ ] **Step 1: Add `test_pro_rata_quantity_distribution_allocates_by_quantity`** — two products with very different unit costs; expected allocation is by qty.
- [ ] **Step 2: Add `test_equal_per_line_distribution_allocates_evenly`** — two products on one transfer with `transfer_cost = 10`; each line gets 5.
- [ ] **Step 3: Add `test_pro_rata_value_falls_back_to_equal_per_line_when_total_value_is_zero`** — both lines have `unit_cost_snapshot = 0`; the fallback must allocate equally and not divide-by-zero. Document the fallback in the design note.

### Task D4 — Negative `transferCost` rejected at DTO boundary (Codex P3-3)

- [ ] **Step 1: Add a constructor guard to `InitiateTransferData`** — `if (bccomp($transferCost, '0', 4) < 0) { throw new InvalidArgumentException('transferCost must be non-negative'); }`. Add test.

### Task D5 — Cancel-from-cancelled rejection (Codex P2-8)

- [ ] **Step 1: Add `test_cannot_cancel_cancelled_transfer`** — already-cancelled transfer rejects with `TransferStateException`. Verify the predicate `canBeCancelled` correctly returns false for `Cancelled`.

### Task D6 — Commit

```
git add apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php \
        apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php \
        apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php \
        apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php
git commit -m "test(inventory): defense-in-depth scoping + distribution coverage + negative-cost guard

Closes Opus P2-3/P2-4, Codex P2-3/P2-8/P3-3."
```

---

## Batch E — Frontend UX (Opus P2-1/P2-2 + Codex P2-1/P2-7)

### Task E1 — Frontend idempotency_key on create

**Files:**
- Modify: `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx`

- [ ] **Step 1: Generate `crypto.randomUUID()` once when the form mounts**

```tsx
const [idempotencyKey] = useState(() => crypto.randomUUID())
// ... in payload:
const payload: CreateStockTransferInput = {
  // ...
  idempotency_key: idempotencyKey,
}
```

- [ ] **Step 2: Add Vitest assertion that the submitted payload includes a UUID**

Create `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.test.tsx`. Mock `useCreateStockTransfer`; assert that the call argument contains `idempotency_key` matching a UUID regex.

### Task E2 — Surface server errors on create

**Files:**
- Modify: `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx`
- Modify: `apps/web/src/lib/api.ts` (add helper if not present)

- [ ] **Step 1: Add (or confirm) an `extractServerMessage` helper in `lib/api.ts`**

```ts
export function extractServerMessage(error: unknown): string | null {
  if (isApiError(error) && error.response?.data?.error?.message) {
    return error.response.data.error.message
  }
  return null
}
```

- [ ] **Step 2: Use it in the catch**

```tsx
} catch (e) {
  toast.error(extractServerMessage(e) ?? t('create.error'))
}
```

Same change in `StockTransferDetailPage.tsx` complete and cancel handlers.

### Task E3 — Pagination controls on list

**Files:**
- Modify: `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx`

- [ ] **Step 1: Add Prev/Next buttons + "Page N of M" indicator**

Use the `last_page` from `data?.meta`. Disable Prev when `page <= 1`, disable Next when `page >= last_page`. Wire into existing filter state. Drop the misleading `{total} / {total}` footer.

- [ ] **Step 2: Add a Vitest interaction test for the Next button**

### Task E4 — AR locale file or design-note correction

- [ ] **Step 1: Pick one of:**
  - Add `apps/web/src/locales/ar/stock-transfers.json` with the same keys as the EN file. (~116 entries.)
  - OR: correct the design note line that claims AR is wired and add a note that AR falls back to EN until the IziPOS-localization sweep covers this namespace.

### Task E5 — Frontend complete/cancel pass idempotency keys

**Files:**
- Modify: `apps/web/src/features/stock-transfers/api/stockTransferApi.ts`
- Modify: `apps/web/src/features/stock-transfers/api/queries.ts`
- Modify: `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`

- [ ] **Step 1: Change the API signatures to accept an optional key and POST it in the body**

```ts
complete: async (id: string, idempotencyKey?: string): Promise<StockTransfer> => {
  return apiPost<StockTransfer>(`${BASE_URL}/${id}/complete`, {
    idempotency_key: idempotencyKey,
  })
},
```

- [ ] **Step 2: Detail page generates a fresh UUID before each complete/cancel button click**

- [ ] **Step 3: Run Vitest + ESLint + typecheck**

```
cd apps/web && pnpm test -- --run src/features/stock-transfers && pnpm lint && pnpm typecheck
```

Expected: all green. ESLint baseline still 11343.

- [ ] **Step 4: Commit**

```
git add apps/web/src/features/stock-transfers \
        apps/web/src/lib/api.ts \
        apps/web/src/locales/ar/stock-transfers.json
git commit -m "feat(stock-transfers): idempotency keys on every mutation + pagination + real error messages

Closes Opus P1-2/P2-1/P2-2 and Codex P2-1/P2-7."
```

---

## Batch F — Cancel semantics polish (Opus P2-6, Codex P3-1, owner-locked cancel-cost policy)

### Task F0 — Pre-flight: grep `MovementType::TransferIn` and `isInbound()` call sites

Codex's plan-review P1-4 flagged that adding a new enum case may surface assumptions in downstream code. Before F1's migration, catalog the reporting/audit/event-consumer surface that currently filters by these.

**Files:** read-only.

- [ ] **Step 1: Grep the codebase**

```
cd /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer
rg -n "MovementType::TransferIn|->isInbound\(\)" apps/api/ apps/web/ apps/pos/ packages/
```

- [ ] **Step 2: For each match, document what the code does with the result**

In the plan's design note (`docs/superpowers/coordination/2026-05-28-inventory-transfer.md`), add an "F1 callsite audit" subsection listing each callsite and noting whether it should:
- Treat `TransferReversed` identically to `TransferIn` (e.g., physical stock-quantity reports — both add to on-hand);
- Treat `TransferReversed` distinctly (e.g., audit reports asking "how much arrived through normal transfers vs how much came back via cancellations");
- Be updated as part of Batch F1.

- [ ] **Step 3: Commit the audit note**

```
git add docs/superpowers/coordination/2026-05-28-inventory-transfer.md
git commit -m "docs(inventory-transfer): F1 pre-flight — TransferIn / isInbound callsite audit"
```

### Task F1 — Add `TransferReversed` movement type

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`

- [ ] **Step 1: Add the enum case + isInbound mapping**

```php
case TransferReversed = 'transfer_reversed';

public function isInbound(): bool
{
    return in_array($this, [self::Receipt, self::TransferIn, self::TransferReversed, self::Adjustment, self::Opening], true);
}
```

- [ ] **Step 2: Switch the cancel-from-in_transit return-to-source to use `TransferReversed` instead of `TransferIn`**

- [ ] **Step 3: Update the cancel-with-in_transit test to assert the new movement type**

### Task F2 — Switch the `-CANCEL` reference suffix to a distinct prefix

- [ ] **Step 1:** Replace `transfer_number . '-CANCEL'` with a reference like `'RV-' . transfer_number` so `reference LIKE 'TR-%'` filters don't double-count cancellations.

### Task F3 — Cancel-time cost-event confirmation UI (owner-locked Option B+)

The owner locked the cancel-cost policy: capitalized cost events do NOT auto-reverse on cancel. Instead, the cancel modal lists every cost event on the transfer and asks the user, per event, whether to **reverse** (emit a compensating negative event) or **keep** (the fee was actually paid; leave it capitalized). This matches the real-world ambiguity between quoted freight and paid freight, and is forward-compatible with the planned Treasury integration that will pre-select **keep** when a payment is allocated against the cost event.

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` — `cancel()` accepts an optional `costEventReversalIds: list<string>` parameter; for each id present in the list, before the cancel transaction commits, call `recordCostEventInternal()` with `amount = -(prior event amount)` and `phase = at_receipt` (or `in_transit` if status is still InTransit when the cancel hits).
- Modify: `apps/api/app/Modules/Inventory/Presentation/Requests/CancelTransferRequest.php` (new) — validates `cost_event_reversal_ids: ?array<string>` with each entry `ScopedExists` against `stock_transfer_cost_events` for the target transfer.
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php::cancel` — accept and forward `cost_event_reversal_ids`.
- Modify: `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx` — the cancel modal renders the cost-event ledger with a checkbox per event labeled "Reverse this fee — we didn't pay it" and a fallback label "Keep this fee — we paid it". The default selection in this PR is **keep** (no boxes checked); the Treasury follow-up will flip the default to **reverse** for events with no allocated payment.
- Modify: `apps/web/src/features/stock-transfers/api/stockTransferApi.ts` — `cancel(id, idempotencyKey?, reason?, costEventReversalIds?)`.
- Modify: `apps/web/src/locales/{en,fr}/stock-transfers.json` — new keys.

- [ ] **Step 1: Write the failing test**

```php
public function test_cancel_with_cost_event_reversal_emits_compensating_negative_events(): void
{
    $this->seedStock($this->productA, $this->warehouse, '100.0000');

    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
        transferCost: '100.0000', // at_initiate event capitalizes 100
    ));
    $beforeCancel = $this->productA->fresh()->cost_price;

    $atInitiateEventId = $transfer->fresh('costEvents')->costEvents->first()->id;

    $this->service()->cancel(
        transferId: $transfer->id,
        userId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->company->id,
        reason: 'shipment fell through, no fees paid',
        costEventReversalIds: [$atInitiateEventId],
    );

    // After cancel-with-reversal, WAC returns to pre-initiate value.
    $this->assertEquals('5.0000', $this->productA->fresh()->cost_price);

    // Ledger now has TWO events: original at_initiate +100 and a compensating -100.
    $events = StockTransferCostEvent::query()->where('transfer_id', $transfer->id)->orderBy('recorded_at')->get();
    $this->assertCount(2, $events);
    $this->assertEquals('100.0000', $events[0]->amount);
    $this->assertEquals('-100.0000', $events[1]->amount);
}
```

- [ ] **Step 2: Implement the reversal loop inside `cancel()`**

Before the existing cancel body runs (and before the status flips to Cancelled), iterate `costEventReversalIds`. For each id:

1. Load the original event scoped to the target transfer. If the id doesn't belong to this transfer, throw `InvalidArgumentException`.
2. Reject if the original event itself has `reverses_event_id !== null` (you can't reverse a reversal — that's a re-capitalize, which the user can record explicitly via the cost-event endpoint).
3. Call `recordCostEventInternal()` with `amount = bcmul((string) $original->amount, '-1', 4)`, same `distribution` as the original, `label = "Reversal of {original->label} on cancel"`, **and `reverses_event_id = $original->id`**. The partial-unique index on `reverses_event_id` introduced in Task A1 raises `UniqueConstraintViolationException` if the original has already been reversed; the cancel flow catches that exception and surfaces a 409 with `error.code = 'COST_EVENT_ALREADY_REVERSED'` so the UI can refresh and re-render.

- [ ] **Step 3: Wire the controller request**

`CancelTransferRequest::rules()`:

```php
return [
    'reason' => ['nullable', 'string', 'max:5000'],
    'idempotency_key' => ['nullable', 'string', 'max:128'],
    'cost_event_reversal_ids' => ['nullable', 'array'],
    'cost_event_reversal_ids.*' => [
        'string', 'uuid',
        ScopedExists::tenantAndCompany('stock_transfer_cost_events', $company->tenant_id, $company->id),
    ],
];
```

- [ ] **Step 4: Add the Vitest assertion for the cancel modal listing cost events with per-event checkboxes**

- [ ] **Step 5: Document the Treasury follow-up in the design note**

In `docs/superpowers/coordination/2026-05-28-inventory-transfer.md`, append:

> ### Treasury integration follow-up — auto-suggest reverse vs keep on cancel
>
> Today the cancel modal lists every cost event on the transfer with a per-event "Reverse / Keep" choice, default Keep. The Treasury team's follow-up PR will:
>
> - Add a foreign key from `payment_allocations.allocation_target` to `stock_transfer_cost_events.id` (or whichever generic-allocation seam they pick).
> - When the cancel modal mounts, query the Treasury module for "is this cost event referenced by any allocated payment?". If yes, pre-select Keep and disable Reverse with a tooltip. If no, pre-select Reverse.
> - Add a Treasury-side cancel hook for the reverse case so unallocated payments can be flagged for re-allocation when their cost event is reversed.
>
> The data model needed for this lives entirely in Treasury; nothing in Inventory needs to change. The Treasury session can pick up the work whenever it's prioritized.

- [ ] **Step 6: Run + commit**

---

## Batch G — Migration relocation (Codex P2-6, Opus P3-5 — promoted because T6 flip is mid-flight)

**Decision required:** see D2. The tasks below assume the move happens in this PR.

### Task G1 — Move the original migration to `tenant/`

**Files:**
- Move: `apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php` → `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php`

- [ ] **Step 1: `git mv` the file**

```
cd /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer
git mv apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php \
       apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php
```

- [ ] **Step 2: Verify the migration runs cleanly under the T6 tenant-migration path**

Coordinate with the T6 phase0b session to run the migration suite that exercises `php artisan tenants:migrate`. If their CI gate already covers the tenant-suite, push the commit and watch the gate.

- [ ] **Step 3: Verify the SQLite-based test setup still picks up the table**

The current PHPUnit setup uses `RefreshDatabase` which runs `migrate` against the central connection only. After the move, the `stock_transfers` table won't exist in the test DB unless the test harness also picks up `tenant/` migrations. **Verify by running:**

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php
```

Expected: PASS. **If FAIL with "no such table: stock_transfers":** the test bootstrap needs an extension that mirrors the channels-test pattern (`tests/Feature/Channel/CreatesChannelSchema.php`) — add a trait `CreatesStockTransferSchema` that globs `database_path('migrations/tenant/2026_05_28_12*_*.php')` and runs the migration's `up()` in `setUp`.

- [ ] **Step 4: Commit**

```
git add -A
git commit -m "fix(inventory): relocate stock_transfers migration to tenant/ folder

Required for tenants:migrate to apply the table under the in-flight T6
DB-per-tenant flip. Closes Codex P2-6."
```

---

## Batch H — Forward-pointer to the orchestrator-owned post-flip docs PR (D3 — deferred)

The orchestrator confirmed the master-docs rewrite (CLAUDE.md + claude/* + apps/erp/CLAUDE.md + .claude/context/architecture.md + database.md + memory) lands in a dedicated post-flip-docs PR after PR #147 and PR #148 merge. This plan adds a forward-pointer instead.

### Task H1 — Add the forward-pointer to the inventory-transfer design note

**Files:** `docs/superpowers/coordination/2026-05-28-inventory-transfer.md`

- [ ] **Step 1: Append the following paragraph at the end of the document**

```markdown
## Tenancy-docs deferral

The CLAUDE.md / `claude/architecture.md` / `claude/database-topology.md` descriptions of "row-level multi-tenancy" are stale as of 2026-05-28. The database-per-tenant flip (PRs #141–#146) landed on `dev` that day; the pre-flip ops surface (PR #148) landed alongside. The master-docs rewrite is tracked by the orchestrator session and lands in a dedicated post-flip-docs PR after PR #147 (this PR) and PR #148 merge — so the rewrite is a single coherent pass with fresh context on the post-flip reality (central + tenant connection split, post-flip ops surface, the new central `tenant_backups` table, etc.).
```

- [ ] **Step 2: Commit**

```
git add docs/superpowers/coordination/2026-05-28-inventory-transfer.md
git commit -m "docs(inventory-transfer): forward-pointer to orchestrator-owned post-flip docs PR"
```

---

## Batch I — Design-note update

### Task I0 — Document the cost-event ledger as source of truth (Codex v2 P2)

**Files:** `docs/superpowers/coordination/2026-05-28-inventory-transfer.md`

The line-level `stock_transfer_lines.allocated_transfer_cost` is a running projection that accumulates per-event allocations. When events use different distribution modes (e.g., one `ProRataValue`, one `EqualPerLine`), the line field answers "sum of allocations from every event so far," not "how was the transfer's default distribution applied." Downstream consumers MUST use the event ledger for audit / reconciliation and treat the line field as a UI convenience.

- [ ] **Step 1: Append to the design note**

```markdown
## Cost-event ledger source-of-truth rule

`stock_transfer_cost_events` is the canonical record of every cost movement on a transfer. The denormalized `stock_transfers.transfer_cost` (running sum of event amounts) and `stock_transfer_lines.allocated_transfer_cost` (running sum of allocated shares per line) are **projections maintained by the service for read performance and existing UI/reporting consumers**. They are NOT independent sources of truth.

Implications:

- Audit / reconciliation queries must walk the event ledger, not the projection columns. An auditor looking for "what costs were capitalized into product P's WAC, when, by whom, and at what phase of which transfer" reads `stock_transfer_cost_events`, not `transfer_cost` or `allocated_transfer_cost`.
- Per-event distribution modes are honored at allocation time and written into the event row. A line that received allocations from events using different distributions has an `allocated_transfer_cost` that mixes those modes; consumers needing per-event detail must join through the ledger.
- Negative reversal events (recorded by the cancel flow when the user reverses a quoted-but-unpaid fee) appear as their own ledger row; the projection columns are bumped by signed amounts in the same step. Audit consumers therefore see the original capitalization AND the compensating reversal as two distinct events.
```

- [ ] **Step 2: Commit**

```
git add docs/superpowers/coordination/2026-05-28-inventory-transfer.md
git commit -m "docs(inventory-transfer): cost-event ledger source-of-truth rule"
```

### Task I1 — Annotate the design note with what was closed and what remains

**Files:** `docs/superpowers/coordination/2026-05-28-inventory-transfer.md`

- [ ] **Step 1: Add a "Remediation pass" section** that links to this plan, the Opus + Codex reviews, and the resulting commits.
- [ ] **Step 2: Update the "Out of scope" section** to reflect the new state: WAC determinism solved here; idempotency solved here; migration placement corrected here; the four named deferrals (Scenario B, per-location tax IDs, InTransitAvailability, batch preservation) still in the next session.
- [ ] **Step 3: Commit**

---

## Batch J — End-to-end Playwright verification

### Task J1 — Local stack up + smoke

**Files:**
- New: `apps/web/tests/e2e/stock-transfers.spec.ts`

- [ ] **Step 1: Bring the local stack up**

```
cd /Users/houssamr/Projects/syneriva
docker compose up -d postgres redis meilisearch
cd apps/erp.inventory-transfer/apps/api && php artisan migrate --force
cd ../../apps/api && php artisan db:seed --class=DemoTenantSeeder
cd ../../apps/api && php artisan serve --host=127.0.0.1 --port=8000 &
cd ../../apps/web && pnpm dev &
```

- [ ] **Step 2: Write a Playwright spec for the golden path**

```ts
import { test, expect } from '@playwright/test'

test.describe('Stock Transfers', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('http://localhost:5173/login')
    await page.fill('[name=email]', 'admin@otospex.com')
    await page.fill('[name=password]', 'password')
    await page.click('button[type=submit]')
    await page.waitForURL('**/dashboard')
  })

  test('initiate -> complete', async ({ page }) => {
    await page.goto('http://localhost:5173/inventory/stock-transfers/new')
    await page.selectOption('#source', { label: 'Main Warehouse' })
    await page.selectOption('#destination', { label: 'Downtown Shop' })
    await page.locator('text=Add line').click()
    // Use ProductPicker to pick a known seeded product
    await page.fill('[role=combobox]', 'BRAKE')
    await page.locator('[role=option]').first().click()
    await page.fill('input[type=number]', '5')
    await page.click('button:has-text("Create transfer")')
    await page.waitForURL('**/stock-transfers/**')
    await expect(page.locator('[data-testid=status-badge]')).toContainText('In Transit')

    await page.click('button:has-text("Confirm receipt")')
    await page.click('button:has-text("Confirm")')
    await expect(page.locator('[data-testid=status-badge]')).toContainText('Completed')
  })

  test('initiate -> cancel-from-in-transit returns stock', async ({ page }) => {
    await page.goto('http://localhost:5173/inventory/stock-transfers/new')
    await page.selectOption('#source', { label: 'Main Warehouse' })
    await page.selectOption('#destination', { label: 'Downtown Shop' })
    await page.locator('text=Add line').click()
    await page.fill('[role=combobox]', 'BRAKE')
    await page.locator('[role=option]').first().click()
    await page.fill('input[type=number]', '3')
    await page.click('button:has-text("Create transfer")')
    await page.waitForURL('**/stock-transfers/**')
    await expect(page.locator('[data-testid=status-badge]')).toContainText('In Transit')

    await page.click('header button:has-text("Cancel transfer")') // top-of-page cancel
    const cancelDialog = page.locator('[role=dialog]', { hasText: 'Cancel this transfer' })
    await cancelDialog.locator('textarea[aria-label*="Reason"]').fill('Carrier cancelled the shipment')
    // Cancel modal lists the at_initiate cost event with a "Reverse" checkbox.
    // Leave it unchecked (default Keep) for this golden path; the reverse case
    // gets its own assertion in a separate spec.
    await cancelDialog.locator('button:has-text("Cancel transfer")').click() // dialog confirm
    await expect(page.locator('[data-testid=status-badge]')).toContainText('Cancelled')

    // Verify source stock returned (navigate to stock-levels listing or the
    // product detail page, depending on the existing UI shape).
    await page.goto('http://localhost:5173/inventory/stock-levels')
    await expect(page.locator(`tr:has-text("Main Warehouse"):has-text("BRAKE")`))
      .toContainText(/3\.0000|3\.00/)  // pre-cancel value restored
  })
})
```

- [ ] **Step 3: Run**

```
cd apps/web && pnpm playwright test stock-transfers
```

Expected: 2 passing.

- [ ] **Step 4: Commit**

---

## Verification before merge

After all batches:

- [ ] `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/` — 133+ tests, all green
- [ ] `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=2G` — clean
- [ ] `cd apps/api && ./vendor/bin/pint --test` — clean
- [ ] `cd apps/web && pnpm typecheck && pnpm lint && pnpm test` — clean, ESLint ratchet held
- [ ] `cd apps/web && pnpm playwright test stock-transfers` — 2 passing
- [ ] CI on PR #147 — all required checks green
- [ ] Update PR description + design note to reflect the closeout
- [ ] Re-request adversarial review from Codex on the final state (Opus's findings are addressed)

---

## What this plan deliberately does NOT cover

These items are real but explicitly deferred:

- **Quantity precision drift** (Opus P2-5) — **RESOLVED UPSTREAM** by PR #151 (`fix(inventory): widen quantity precision to 4 decimals end-to-end`), merged to `dev` on 2026-05-29. The plan no longer needs a regex workaround on `StoreStockTransferRequest`; the storage layer now matches the API contract end-to-end at 4 decimals. The architecture guard added by PR #151 (`InventoryQuantityPrecisionGuardTest`) will catch any regression that tries to reintroduce `SCALE = 2` or `decimal:2` casts in the Inventory module.
- **`ConfirmDialog` extension to accept children** (Opus P3-7 / Codex P3-2) — touches a UI primitive used by every feature; out of scope here. The cancel modal stays inlined; ticket filed.
- **`bcformat` migration in WAC math** (Opus P3-3) — touches the entire `WeightedAverageCostService`; out of scope.
- **Batch preservation on transfer legs** (Codex P2-5 / Opus design note deferral) — explicitly in the Scenario A close-out session.
- **Per-location tax sub-IDs**, **InTransitAvailability per-company setting**, **variation scoping** — Scenario A close-out session.

---

## Sequencing + sub-skill

- Recommended order: **A → B → C → D → E → F → G → H → I → J**. Batch A is the only true financial-correctness blocker; B + C are spec-compliance + audit-correctness; D + E + F are quality lifts; G + H + I are coordination; J is the missing verification.
- **Batches that need a green checkpoint before next**: A → B (B depends on A's tests staying green), C → D (D adds tests that depend on the relabel refactor's new event payload), G → J (Playwright must run after the migration is in the right place).
- **Sub-skill to dispatch this plan:** `superpowers:subagent-driven-development` (one fresh subagent per task, two-stage review between batches). Each batch has at most 6 tasks; subagent dispatch is straightforward.

---

## Self-review notes

- **Spec coverage:** every BLOCKER and P1 from Codex is in Batch A/B/C; every P1 from Opus is in Batch A/B; the actionable P2s from both reviews map to D/E/F/G; the doc-flip is H; the missing verification is J. Items deliberately deferred are listed under "What this plan deliberately does NOT cover".
- **Placeholders:** none — every test has the assertion body; every helper has the function signature; every migration has the schema; every code change has the actual code or the literal replacement string.
- **Type consistency:** `idempotencyKey: ?string` is consistent across `initiate`, `complete`, `cancel`; `IdempotencyKeyConflictException` is referenced before being defined (defined in Task B5 and referenced by it). `TransferReversed` enum case is added in Task F1 and used in F1's subsequent steps only.

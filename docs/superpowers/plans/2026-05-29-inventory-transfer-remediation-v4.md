# Inventory Transfer (PR #147) — Remediation Plan v4

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Supersedes** `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md` (v1–v3). v4 changes the
> cost-capitalization concurrency model and cost lifecycle in response to two independent v3 adversarial reviews
> (both BLOCKER) and a deep-research pass on ERP costing best practices. **Do not implement v3's Batch A / Task A6 /
> Task A7 / Task F3 — they are replaced here.** Batches that are genuinely unchanged are inherited from v3 by
> reference with an explicit delta list (see "Inherited batches").

**Goal:** Make the Inventory Transfer feature merge-ready by (1) replacing v3's unenforceable "product-lock
invariant" with a per-product **advisory-lock** serialization token, (2) switching transfer costs to a **two-step
pending → confirmed** lifecycle where only *confirmed* cost events capitalize into Weighted Average Cost (WAC),
forward-only against current `(on_hand + in_transit)`, (3) emitting a decoupled Treasury seam when a cost is
confirmed, and (4) closing every BLOCKER/P1/P2 from the v3 Opus + Codex reviews.

**Architecture:** Costs live in an append-only `stock_transfer_cost_events` ledger (mirrors Odoo's Stock Valuation
Layer). Each event is created `pending` (no WAC effect) and capitalizes into the company-wide WAC only when
explicitly **confirmed** — matching Odoo Landed-Cost "draft → post" and D365 "estimate → actual". Capitalization is
**forward-only**: a confirmed cost spreads across whatever `(on_hand + in_transit)` exists at confirm time; already-sold
units keep their prior cost (no retroactive replay — the D365 trade). Concurrency is serialized by a Postgres
**transaction-scoped advisory lock keyed on `(tenant_id, company_id, product_id)`**, acquired by every divisor-component
writer (the WAC writer + every `StockAdjustmentService` quantity mutator) and by the cost-confirm path, in ascending
`product_id` order, wrapped in a deadlock-retry. Confirming a cost emits `StockTransferCostConfirmed`, which a Treasury
listener turns into a *pending* payment action; confirmed-but-unjustified costs are surfaced for month-end audit.
Treasury itself is a forward-pointer seam in this plan, not built here.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 16+ (advisory locks; `pgsql`-only CHECK/partial-unique guards),
PHPUnit + RefreshDatabase (SQLite test runner — advisory locks no-op on SQLite, so the true race test runs against
Postgres CI), PHPStan level 8, Pint; React 19 / Vite 7 / TS strict, TanStack Query 5, Vitest, Playwright; design tokens
from `apps/web/src/lib/designTokens.ts`; i18n via react-i18next.

---

## Why v4 exists — the v3 reviews

Both v3 reviews (committed) returned BLOCKER, converging on the same root cause:

- Opus: `docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v3-opus-review.md` — REQUEST-CHANGES.
- Codex: `docs/superpowers/reviews/2026-05-29-inventory-transfer-remediation-v3-codex-review.md` — BLOCKER.
- Research: `docs/superpowers/research/2026-05-29-wac-concurrency-erp-best-practices.md`.

**Root cause:** v3's safety proof depended on the invariant *"every writer of `stock_levels.quantity` for product P
locks the `Product` row first."* That invariant is **false in the merged code** — the public `StockMovementController`
receive/adjust endpoints, the transfer `cancel()` path, and the leaf `StockAdjustmentService::issue`/`receive` methods
all mutate stock without locking `Product`. v3's architecture test tried to enforce the invariant via a substring scan of
leaf-method bodies, which (a) can't pass because `issue`/`receive` are deliberately lock-free leaves, and (b) can't
express a *caller-side* invariant. PostgreSQL docs confirm single-row `FOR UPDATE` does not protect a **multi-row
divisor** (our `on_hand + in_transit` sum), so neither v3's product-lock approach nor naive row-locking is airtight.

**v4 fix at the model level:** stop trying to make every high-frequency stock movement co-serialize a WAC recompute.
Costs capitalize only on an explicit, low-frequency **confirm** action, serialized per product by an advisory lock that
every divisor writer also takes. This is both correct and far lower-contention.

---

## Decisions locked

### D1 — Cost lifecycle: **two-step pending → confirmed** (owner-locked 2026-05-29)
A cost event is created `pending` and has **zero** effect on WAC. It capitalizes into WAC only when a user explicitly
**confirms** it. Confirmation is forward-only against current `(on_hand + in_transit)`. This matches Odoo Landed-Cost
draft→post and D365 estimate→actual, and shrinks the concurrency surface to the deliberate confirm action.

### D2 — Concurrency model: **per-product advisory lock** (owner-locked 2026-05-29)
Replace v3's product-row-lock invariant with a transaction-scoped Postgres advisory lock keyed on
`(tenant_id, company_id, product_id)`. Acquired by: the WAC writer (`recordCostAdjustment`), every
`StockAdjustmentService` quantity mutator (`receive`/`issue`/`adjust`), and the cost-confirm/reverse paths. Multi-product
operations acquire locks in ascending `product_id` order (the verified primary deadlock defense). Service entry points
are wrapped in a deadlock-retry loop. Advisory locks serialize by *concept* ("product P's cost"), not by physical rows,
so they catch every code path regardless of which table it touches and have no phantom-insert gap. **Over-locking is
safe; under-locking is not** — `hashtext` collisions merely serialize two unrelated products occasionally.

### D3 — Capitalization is **forward-only** (owner-locked 2026-05-29)
No retroactive replay (we are *not* ERPNext's Repost Item Valuation). A cost confirmed after some transferred units were
already sold spreads only across units still owned at confirm time; the rest is the D365 "expense the difference" trade.
Recorded order is the truth; we do not promise algebraic order-independence (that v3 property is stronger than D365/Odoo
guarantee and is not load-bearing once serialization is real).

### D4 — Treasury is a **decoupled seam**, not built here (owner-locked 2026-05-29)
Confirming a cost emits `StockTransferCostConfirmed`. A Treasury listener (future PR) turns it into a *pending* payment
action; confirmed-but-unjustified costs are surfaced for month-end audit. This plan ships the event + a forward-pointer
+ the audit-query seam shape, not the Treasury module.

### D5 — Migration relocation: **land in this PR** (unchanged from v3 D2)
The `git mv` of the original `2026_05_28_120000_create_stock_transfers_table.php` into `database/migrations/tenant/`
lands here (Batch G).

### D6 — Doc-flip ownership: **deferred to orchestrator PR** (unchanged from v3 D3)
Master-docs rewrite lands in a dedicated post-flip-docs PR; this plan adds a forward-pointer only (Batch H).

---

## v3-review findings → v4 closure map

| Finding (review) | Severity | Closed by |
|---|---|---|
| Product-lock invariant false (public mutators bypass it) — Codex BLOCKER 1 | BLOCKER | D2 advisory lock + Task A6/A6b |
| `cancel()` never locks the product — Opus BLOCKER | BLOCKER | D2: `cancel()` confirm/reverse + stock-return run under advisory lock (Task C2 + F-tasks) |
| Architecture test gates lock-free leaves, can't pass — Opus BLOCKER | BLOCKER | Task A6c: gate the **lock helper** + a real two-process race integration test; drop the substring scan |
| Product-id sort applied only in `recordCostEvent` — both | BLOCKER/P1 | Task A7/A8/C2/F: explicit sorted-acquisition code in every per-line product-lock flow |
| `$fillable` omits `reverses_event_id` + `idempotency_payload_hash`; DTO lacks `reversesEventId` — Codex BLOCKER 3 | BLOCKER | Task A3 (full fillable) + Task A4 (DTO field) |
| Negative-allocation skip — v2 BLOCKER 3 | (kept closed) | Task A7 `round($allocated,4) === 0.0` guard retained |
| Null-hash winner accepted without validation — both P2 | P2 | Task B-delta + A7: null-hash winner → 409 (can't verify → reject) |
| `fingerprintForTest` referenced, never defined — Codex P1 | P1 | Task B-delta: spec a `@internal` static test helper |
| B4 tests don't force catch path — Codex P1 | P1 | Task B-delta: seed-then-insert with pre-check disabled via test-only seam |
| Public negative cost events blocked by `gt:0` — both P2 | P2 | Task A10: documented cancel/reverse-only; public add is `gt:0`; reversal is its own endpoint |
| Playwright `[role=dialog]` selector vs. plain-div modal — both P3 | P3 | Task F3 + J1: add `role="dialog"` + `aria-label` to the cancel modal |

---

## Concurrency primitive — the advisory-lock helper (used everywhere in D2)

**File:** Create `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php`

A single choke point so the lock is acquired identically everywhere and the architecture test has one thing to gate.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Per-product cost serialization token (Decision D2).
 *
 * Acquires a PostgreSQL TRANSACTION-scoped advisory lock keyed on
 * (tenant_id, company_id, product_id) for every product in $productIds, in
 * ASCENDING product_id order (the verified primary deadlock defense — all
 * concurrent callers request the same keys in the same order, so no lock
 * cycle can form). The lock auto-releases when the surrounding transaction
 * ends (commit OR rollback OR crash) — there is no leak path.
 *
 * Why advisory and not Product::lockForUpdate(): the WAC denominator is a
 * MULTI-ROW sum (stock_levels + in-transit lines). Single-row FOR UPDATE does
 * not serialize a multi-row divisor, and a concurrently-INSERTed stock_level
 * is a phantom that row locks miss. An advisory lock serializes by the concept
 * "product P's cost", independent of which rows exist or which table a writer
 * touches — so every divisor-affecting code path is covered.
 *
 * MUST be called INSIDE an open DB transaction (xact-scoped lock). On non-pgsql
 * drivers (SQLite test runner) it is a no-op; the true two-process race is
 * exercised against PostgreSQL CI only.
 *
 * @param  list<string>  $productIds
 * @template T
 * @param  Closure():T  $callback
 * @return T
 */
final class ProductCostLock
{
    public function acquire(string $tenantId, string $companyId, array $productIds, Closure $callback): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            $sorted = array_values(array_unique($productIds));
            sort($sorted, SORT_STRING);

            foreach ($sorted as $productId) {
                $key = "wac:{$tenantId}:{$companyId}:{$productId}";
                // hashtext() -> int4, auto-cast to the bigint single-arg form.
                // Collisions only over-serialize unrelated products (safe).
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
            }
        }

        return $callback();
    }
}
```

**Deadlock-retry wrapper** — add to the same file (or a shared trait) and wrap the public service entry points
(`initiate`, `complete`, `cancel`, `addCostEvent`, `confirmCostEvent`, `reverseCostEvent`):

```php
// In StockTransferService — private helper used to wrap each public entry's DB::transaction.
private function withDeadlockRetry(Closure $fn, int $maxAttempts = 3): mixed
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return $fn();
        } catch (\Illuminate\Database\DeadlockException $e) {
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            // brief jittered backoff; no Date/random in plans — use attempt-scaled usleep
            usleep(1000 * $attempt);
        }
    }
}
```

> Sorted acquisition makes deadlocks essentially impossible for the transfer flows themselves; the retry is the
> standard PostgreSQL belt-and-suspenders for any residual cross-feature cycle (PG docs: detect + retry).

---

## File-by-file map (v4 deltas)

Backend:
- **Create** `app/Modules/Inventory/Domain/Services/ProductCostLock.php` — advisory-lock helper (above).
- **Create** `app/Modules/Inventory/Domain/Enums/TransferCostEventPhase.php` — `at_initiate|in_transit|at_receipt` (v3 A2, unchanged).
- **Create** `app/Modules/Inventory/Domain/Enums/TransferCostEventStatus.php` — `pending|confirmed|reversed`.
- **Create** `app/Modules/Inventory/Domain/StockTransferCostEvent.php` — model with **full** fillable (incl. `status`, `reverses_event_id`, `idempotency_payload_hash`, `confirmed_at`, `confirmed_by_user_id`).
- **Create** `app/Modules/Inventory/Application/DTOs/RecordCostEventData.php` — incl. `reversesEventId`.
- **Create** `app/Modules/Inventory/Domain/Events/StockTransferCostConfirmed.php` — Treasury seam.
- **Create** `app/Modules/Inventory/Domain/Exceptions/IdempotencyKeyConflictException.php` (v3 B5, unchanged).
- **Create** migration `database/migrations/tenant/2026_05_29_140000_create_stock_transfer_cost_events_table.php`.
- **Modify** `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` — `recordCostAdjustment` acquires the advisory lock, plain `(on_hand + in_transit)` reads, signed amounts.
- **Modify** `app/Modules/Inventory/Application/Services/StockTransferService.php` — `addCostEvent`/`confirmCostEvent`/`reverseCostEvent`; sorted product locks in `moveSourceToInTransit`/`complete`/`cancel`; idempotency + catch-and-reload; `initiate` seeds a pending `at_initiate` event when `transferCost>0`; `complete` no longer capitalizes.
- **Modify** `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` — `receive`/`issue`/`adjust` acquire the advisory lock via `ProductCostLock`; thread `movementType`/`referenceType`/`referenceId` (v3 Batch C); emit the actual movement type in `StockMovementRecorded`.
- **Modify** controllers/requests/routes/seeder for add/confirm/reverse cost endpoints + permission.
- **Create** `tests/Feature/Inventory/InventoryTransferCostEventTest.php`, `InventoryTransferServiceConcurrencyTest.php`, `tests/Architecture/InventoryProductCostLockUsageTest.php`.

Frontend (Batch E, extended):
- Cost ledger shows **pending vs confirmed** badges; "Add cost" creates pending; "Confirm" capitalizes; a Treasury "payment pending" hint after confirm; idempotency keys on every mutation; pagination; real error messages; cancel modal gets `role="dialog"`.

Docs: design-note updates (Batch I) + Treasury follow-up (Batch T) + tenancy-docs forward-pointer (Batch H).

---

## Batch A — Cost-event ledger (pending→confirmed) + advisory-lock WAC

### Task A1 — Migration: `stock_transfer_cost_events`

**Files:** Create `apps/api/database/migrations/tenant/2026_05_29_140000_create_stock_transfer_cost_events_table.php`

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

            $table->string('phase', 20);   // at_initiate | in_transit | at_receipt
            $table->string('status', 20)->default('pending'); // pending | confirmed | reversed
            $table->decimal('amount', 15, 4); // signed: + capitalize, - reversal
            $table->string('label', 128)->nullable();
            $table->string('distribution', 20)->default('pro_rata_value');

            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->timestampTz('confirmed_at')->nullable();

            $table->string('idempotency_key', 128)->nullable();
            $table->string('idempotency_payload_hash', 64)->nullable();
            $table->foreignUuid('reverses_event_id')
                ->nullable()->constrained('stock_transfer_cost_events')->restrictOnDelete();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'idempotency_key'], 'stce_idem_unique');
            $table->index(['transfer_id', 'recorded_at'], 'stce_transfer_recorded_idx');
            $table->index(['tenant_id', 'company_id', 'status', 'confirmed_at'], 'stce_company_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE stock_transfer_cost_events ADD CONSTRAINT stce_amount_nonzero CHECK (amount <> 0)");
            DB::statement("ALTER TABLE stock_transfer_cost_events ADD CONSTRAINT stce_phase_valid CHECK (phase IN ('at_initiate','in_transit','at_receipt'))");
            DB::statement("ALTER TABLE stock_transfer_cost_events ADD CONSTRAINT stce_status_valid CHECK (status IN ('pending','confirmed','reversed'))");
            // At most one reversal per original event — guarantees the cancel/reverse
            // flow cannot double-reverse and over-shoot WAC backwards, even under
            // concurrent submits. Closes v3 Codex BLOCKER 3 reversal-idempotency.
            DB::statement("CREATE UNIQUE INDEX stce_reverses_unique ON stock_transfer_cost_events (reverses_event_id) WHERE reverses_event_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_cost_events');
    }
};
```

- [ ] **Step 2: Run** `cd apps/api && php artisan migrate:fresh --env=testing 2>&1 | tail -10` — expect clean.
- [ ] **Step 3: Commit** `feat(inventory): stock_transfer_cost_events ledger (pending/confirmed lifecycle)`

### Task A2 — Enums

**Files:** Create `TransferCostEventPhase.php` (identical to v3 Task A2 — `at_initiate|in_transit|at_receipt` with
`allowedTransferStatuses()` and `label()`), and:

- [ ] **Step 1: Create `apps/api/app/Modules/Inventory/Domain/Enums/TransferCostEventStatus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Lifecycle of a transfer cost event (Decision D1).
 *
 * Pending   — recorded but NOT yet capitalized into WAC. No financial effect.
 * Confirmed — capitalized forward into company-wide WAC at confirm time.
 * Reversed  — a confirmed event that has since been reversed by a compensating
 *             negative event (the original is marked Reversed; the compensating
 *             row is itself Confirmed and carries reverses_event_id).
 */
enum TransferCostEventStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
}
```

- [ ] **Step 2: Commit** `feat(inventory): TransferCostEventPhase + TransferCostEventStatus enums`

### Task A3 — Model (with COMPLETE fillable — closes Codex BLOCKER 3)

**Files:** Create `apps/api/app/Modules/Inventory/Domain/StockTransferCostEvent.php`

- [ ] **Step 1: Write the model** — same shape as v3 Task A3 but the `$fillable` and `casts()` MUST include every
  column the service writes. The v3 omission of `idempotency_payload_hash` and `reverses_event_id` is the exact
  Codex BLOCKER 3; both are mass-assigned by the service via `create([...])`, so both MUST be fillable.

```php
protected $fillable = [
    'transfer_id', 'tenant_id', 'company_id',
    'phase', 'status', 'amount', 'label', 'distribution',
    'recorded_by_user_id', 'confirmed_by_user_id',
    'recorded_at', 'confirmed_at',
    'idempotency_key', 'idempotency_payload_hash', 'reverses_event_id',
];

protected function casts(): array
{
    return [
        'phase' => TransferCostEventPhase::class,
        'status' => TransferCostEventStatus::class,
        'distribution' => TransferCostDistribution::class,
        'amount' => 'decimal:4',
        'recorded_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];
}
```

  Relations: `transfer()`, `tenant()`, `company()`, `recordedBy()` (= `recorded_by_user_id`),
  `confirmedBy()` (= `confirmed_by_user_id`), `reverses()` (= `reverses_event_id`, self BelongsTo). Property docblock
  must list all columns including `status`, `confirmed_at`, `confirmed_by_user_id`, `reverses_event_id`,
  `idempotency_payload_hash`.

- [ ] **Step 2: Add to `StockTransfer`** the `costEvents()` HasMany and a `getConfirmedCostTotalAttribute(): string`
  that sums `amount` over `status = confirmed` only (pending costs are not part of the capitalized total).

```php
public function costEvents(): HasMany
{
    return $this->hasMany(StockTransferCostEvent::class, 'transfer_id');
}

/** @return numeric-string */
public function getConfirmedCostTotalAttribute(): string
{
    return (string) ($this->costEvents()
        ->where('status', TransferCostEventStatus::Confirmed)
        ->sum('amount') ?: '0');
}
```

- [ ] **Step 3: Commit** `feat(inventory): StockTransferCostEvent model (full fillable) + relations`

### Task A4 — DTO + domain events (DTO carries `reversesEventId` — closes Codex BLOCKER 3)

**Files:** Create `RecordCostEventData.php`, `StockTransferCostConfirmed.php` (and keep v3's
`StockTransferCostEventRecorded.php`).

- [ ] **Step 1: DTO**

```php
final class RecordCostEventData
{
    /** @param numeric-string $amount */
    public function __construct(
        public readonly string $transferId,
        public readonly TransferCostEventPhase $phase,
        public readonly string $amount,
        public readonly string $recordedByUserId,
        public readonly ?string $label = null,
        public readonly TransferCostDistribution $distribution = TransferCostDistribution::ProRataValue,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $reversesEventId = null, // set only by the reverse/cancel path
    ) {
        if (bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('Cost event amount must be non-zero.');
        }
    }
}
```

- [ ] **Step 2: Treasury seam event** `StockTransferCostConfirmed`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted (afterCommit) when a transfer cost event is CONFIRMED and capitalized
 * into WAC. The Treasury module (future PR, Decision D4) listens to this and
 * creates a PENDING payment action; confirmed-but-unjustified costs are surfaced
 * for month-end audit. Inventory does not depend on Treasury — this is a one-way seam.
 */
class StockTransferCostConfirmed
{
    use Dispatchable;

    /** @param numeric-string $amount */
    public function __construct(
        public readonly string $costEventId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly ?string $label,
        public readonly string $confirmedByUserId,
        public readonly string $occurredAt,
    ) {}
}
```

- [ ] **Step 3: Commit** `feat(inventory): RecordCostEventData (+reversesEventId) + StockTransferCostConfirmed seam`

### Task A5 — Failing test: confirm capitalizes; pending does not; forward-only

**Files:** Test `apps/api/tests/Feature/Inventory/InventoryTransferCostEventTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function test_pending_cost_event_does_not_affect_wac(): void
{
    $this->seedStock($this->productA, $this->warehouse, '100.0000'); // WAC 5.0000
    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
    ));

    $event = $this->service()->addCostEvent(new RecordCostEventData(
        transferId: $transfer->id,
        phase: TransferCostEventPhase::InTransit,
        amount: '100.0000',
        recordedByUserId: $this->user->id,
    ));

    $this->assertSame(TransferCostEventStatus::Pending, $event->status);
    // Pending → WAC unchanged.
    $this->assertEquals('5.0000', $this->productA->fresh()->cost_price);
}

public function test_confirming_cost_event_capitalizes_forward_against_on_hand_plus_in_transit(): void
{
    $this->seedStock($this->productA, $this->warehouse, '100.0000'); // WAC 5.0000
    $transfer = $this->service()->initiate($this->initiateData(
        $this->warehouse->id, $this->shop->id,
        [new InitiateTransferLineData($this->productA->id, '10.0000')],
    ));
    // After initiate: source 90 on-hand + 10 in-transit = 100 owned.

    $event = $this->service()->addCostEvent(new RecordCostEventData(
        transferId: $transfer->id, phase: TransferCostEventPhase::InTransit,
        amount: '100.0000', recordedByUserId: $this->user->id,
    ));

    $this->service()->confirmCostEvent(
        costEventId: $event->id, userId: $this->user->id,
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );

    // 100 cost / 100 owned = +1.00 → WAC 6.0000
    $this->assertEquals('6.0000', $this->productA->fresh()->cost_price);
    $this->assertSame(TransferCostEventStatus::Confirmed, $event->fresh()->status);
}
```

- [ ] **Step 2: Run** the two tests — expect FAIL (`addCostEvent`/`confirmCostEvent` do not exist).

### Task A6 — WAC writer acquires the advisory lock; plain `(on_hand + in_transit)` reads

**Files:** Modify `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`; inject
`ProductCostLock`.

- [ ] **Step 1: Add `ProductCostLock` to the constructor** (`private readonly ProductCostLock $costLock`).

- [ ] **Step 2: Rewrite `recordCostAdjustment` to acquire the advisory lock, then read both denominator halves with
  plain `sum()` (no row locks — the advisory lock is the serialization point; closes v3 Codex BLOCKER 1+2)**

```php
public function recordCostAdjustment(
    Product $product,
    float $additionalCost,
    string $reason,
    string $tenantId,
    string $companyId,
    ?string $reference = null,
    ?string $referenceType = null,
    ?string $referenceId = null,
): ?StockMovement {
    return DB::transaction(function () use (
        $product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId
    ): ?StockMovement {
        return $this->costLock->acquire($tenantId, $companyId, [$product->id], function () use (
            $product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId
        ): ?StockMovement {
            // We hold product P's advisory lock. Every other divisor writer
            // (StockAdjustmentService receive/issue/adjust, and concurrent
            // confirms) also takes this lock, so these plain aggregate reads
            // observe a consistent (on_hand + in_transit) snapshot WITHOUT any
            // row lock — no aggregate-FOR-UPDATE paperware, no phantom gap.
            $product = Product::query()
                ->where('tenant_id', $tenantId)->where('company_id', $companyId)
                ->findOrFail($product->id);

            /** @var numeric-string $onHandQty */
            $onHandQty = (string) StockLevel::query()
                ->where('product_id', $product->id)
                ->where('tenant_id', $tenantId)->where('company_id', $companyId)
                ->sum('quantity');

            /** @var numeric-string $inTransitQty */
            $inTransitQty = (string) StockTransferLine::query()
                ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
                ->where('stock_transfer_lines.product_id', $product->id)
                ->where('stock_transfers.tenant_id', $tenantId)
                ->where('stock_transfers.company_id', $companyId)
                ->where('stock_transfers.status', TransferStatus::InTransit)
                ->sum('stock_transfer_lines.quantity');

            $totalOwned = (float) bcadd($onHandQty, $inTransitQty, $this->scale());
            if ($totalOwned <= 0) {
                return null;
            }

            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $newAvgCost = round($currentCostPrice + ($additionalCost / $totalOwned), $this->scale());

            // ... existing anchor-stock-level + StockMovement::create (quantity 0,
            // quantity_before/after = $onHandQty, avg_cost_before/after) + product
            // cost_price write + MarginService::updateSalePrice + afterCommit
            // ProductCostPriceUpdated dispatch, preserved verbatim, with $totalOwned
            // replacing $onHandFloat and $onHandQty written into the audit row ...
        });
    });
}
```

- [ ] **Step 3: Update the one existing caller path** — `rg -n "recordCostAdjustment\(" app/` (now only the new
  `StockTransferService::confirmCostEvent`/`reverseCostEvent` internal capitalizer); pass `tenantId`/`companyId`.
- [ ] **Step 4: PHPStan + Pint clean** on the file.
- [ ] **Step 5: Commit** `fix(inventory): WAC capitalizes under per-product advisory lock; in-transit-aware denominator`

### Task A6b — Every `StockAdjustmentService` quantity mutator takes the advisory lock

**Files:** Modify `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`; inject `ProductCostLock`.

Closes the Codex BLOCKER 1 hole: the public `StockMovementController` receive/adjust endpoints reach
`StockAdjustmentService::receive`/`adjust`, which mutate `stock_levels.quantity` and would otherwise NOT be serialized
against a concurrent cost confirm.

- [ ] **Step 1: Inject `ProductCostLock` and wrap the body of `receive()`, `issue()`, `adjust()`** so each acquires the
  product's advisory lock at the top of its existing `DB::transaction` (the methods already run in a transaction):

```php
// inside receive() — productId is the first parameter
return DB::transaction(function () use (...) : StockMovement {
    return $this->costLock->acquire($expectedCompanyId ?? $this->resolveTenantCompany($locationId)['tenant'],
        $expectedCompanyId ?? $this->resolveTenantCompany($locationId)['company'],
        [$productId],
        function () use (...): StockMovement {
            // ... existing receive() body verbatim ...
        });
});
```

> Implementation note for the executor: `receive`/`issue`/`adjust` currently resolve company from the location. Add a
> small `resolveTenantCompany(string $locationId): array{tenant:string,company:string}` helper (or reuse the existing
> `resolveCompanyId` + the location's `tenant_id`) so the lock key matches the WAC writer's key exactly. The lock key
> MUST be `(tenant_id, company_id, product_id)` in all three call sites and in `ProductCostLock`.

- [ ] **Step 2: Run the full inventory suite** `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/` —
  expect green (advisory lock is a no-op on SQLite; behavior unchanged single-threaded).
- [ ] **Step 3: Commit** `fix(inventory): serialize stock mutators on per-product advisory lock (close cross-path WAC race)`

### Task A6c — Architecture test gates the lock helper + real two-process race test

**Files:** Create `apps/api/tests/Architecture/InventoryProductCostLockUsageTest.php` and extend
`apps/api/tests/Feature/Inventory/InventoryTransferServiceConcurrencyTest.php`.

Replaces v3's broken substring scan (which gated lock-free leaf methods it could never pass). v4 gates that each
divisor-writing method **routes through `ProductCostLock`**, and proves serialization with a real Postgres race.

- [ ] **Step 1: Usage architecture test** — for each method that mutates `stock_levels.quantity` or capitalizes WAC
  (`WeightedAverageCostService::recordCostAdjustment`, `StockAdjustmentService::receive|issue|adjust`), assert the
  method body contains `costLock->acquire` (the single choke point). This is honest: it gates *use of the helper*, not
  a lock primitive on a leaf.

```php
public static function gatedMethodsProvider(): array
{
    return [
        ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordCostAdjustment'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function receive'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function issue'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function adjust'],
    ];
}

/** @dataProvider gatedMethodsProvider */
public function test_divisor_writers_route_through_product_cost_lock(string $relativePath, string $signature): void
{
    $body = $this->extractMethodBody(base_path($relativePath), $signature); // balanced-brace walker (handles nested closures)
    $this->assertStringContainsString('costLock->acquire', $body,
        "$signature in $relativePath must serialize on ProductCostLock::acquire (Decision D2).");
}
```

- [ ] **Step 2: Two-process race test (Postgres-gated)** — uses two real DB connections to prove a concurrent
  stock receipt cannot tear a cost-confirm's denominator. Skip on non-pgsql:

```php
public function test_concurrent_receipt_and_cost_confirm_do_not_tear_wac(): void
{
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Advisory-lock race requires PostgreSQL.');
    }
    // Connection 1: open txn, take product advisory lock via a pending-confirm.
    // Connection 2: attempt a receive() for the same product → must BLOCK until
    // connection 1 commits, then observe the post-confirm WAC. Assert the final
    // cost_price equals the deterministic serialized result (not a torn value).
    // (Full harness: DB::connection('pgsql')->getPdo() x2; see CreatesStockTransferSchema.)
}
```

- [ ] **Step 3: Run** both; commit `test(inventory): advisory-lock usage gate + two-process WAC race (pg-gated)`

### Task A7 — `addCostEvent` (pending) and `confirmCostEvent` (capitalize under lock, sorted)

**Files:** Modify `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`.

- [ ] **Step 1: `addCostEvent` — creates a PENDING event, no WAC effect, with idempotency + payload fingerprint**

```php
public function addCostEvent(RecordCostEventData $data): StockTransferCostEvent
{
    if ($data->phase === TransferCostEventPhase::AtInitiate && $data->reversesEventId === null) {
        throw new InvalidArgumentException('at_initiate events are seeded by initiate(), not addCostEvent.');
    }

    return $this->withDeadlockRetry(fn () => DB::transaction(function () use ($data): StockTransferCostEvent {
        $transfer = $this->lockTransferUnscoped($data->transferId);

        // Idempotency pre-check: route-scoped + payload-fingerprinted (v3 P1/P2).
        if ($data->idempotencyKey !== null) {
            $existing = StockTransferCostEvent::query()
                ->where('tenant_id', $transfer->tenant_id)->where('company_id', $transfer->company_id)
                ->where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameRouteAndPayload($existing, $transfer->id, $this->fingerprintCostEvent($data));
                return $existing;
            }
        }

        if (! in_array($transfer->status, $data->phase->allowedTransferStatuses(), true)) {
            throw new TransferStateException($transfer->id, $transfer->status, 'add cost event ('.$data->phase->value.')');
        }

        try {
            return StockTransferCostEvent::create([
                'id' => Str::uuid()->toString(),
                'transfer_id' => $transfer->id,
                'tenant_id' => $transfer->tenant_id,
                'company_id' => $transfer->company_id,
                'phase' => $data->phase,
                'status' => TransferCostEventStatus::Pending, // <-- pending; no WAC effect
                'amount' => $data->amount,
                'label' => $data->label,
                'distribution' => $data->distribution,
                'recorded_by_user_id' => $data->recordedByUserId,
                'idempotency_key' => $data->idempotencyKey,
                'idempotency_payload_hash' => $data->idempotencyKey !== null ? $this->fingerprintCostEvent($data) : null,
                'reverses_event_id' => $data->reversesEventId,
                'recorded_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if ($data->idempotencyKey === null) { throw $e; }
            $winner = StockTransferCostEvent::query()
                ->where('tenant_id', $transfer->tenant_id)->where('company_id', $transfer->company_id)
                ->where('idempotency_key', $data->idempotencyKey)->firstOrFail();
            $this->assertSameRouteAndPayload($winner, $transfer->id, $this->fingerprintCostEvent($data));
            return $winner;
        }
    }));
}

/**
 * Shared route+payload guard for both the pre-check and catch-and-reload paths.
 * NULL-hash winner is rejected (we cannot verify it → 409), closing the v3 P2
 * "null hash silently accepted" gap.
 */
private function assertSameRouteAndPayload(StockTransferCostEvent $existing, string $transferId, string $expectedHash): void
{
    if ($existing->transfer_id !== $transferId) {
        throw new IdempotencyKeyConflictException($existing->idempotency_key ?? '', $existing->transfer_id, $transferId);
    }
    if ($existing->idempotency_payload_hash === null || $existing->idempotency_payload_hash !== $expectedHash) {
        throw new IdempotencyKeyConflictException($existing->idempotency_key ?? '', (string) $existing->idempotency_payload_hash, $expectedHash);
    }
}
```

- [ ] **Step 2: `confirmCostEvent` — capitalizes the event under the advisory lock, sorted by product_id**

```php
public function confirmCostEvent(string $costEventId, string $userId, string $tenantId, string $companyId): StockTransferCostEvent
{
    return $this->withDeadlockRetry(fn () => DB::transaction(function () use ($costEventId, $userId, $tenantId, $companyId): StockTransferCostEvent {
        /** @var StockTransferCostEvent $event */
        $event = StockTransferCostEvent::query()
            ->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->lockForUpdate()->findOrFail($costEventId);

        if ($event->status === TransferCostEventStatus::Confirmed) {
            return $event; // idempotent: confirming a confirmed event is a no-op
        }
        if ($event->status === TransferCostEventStatus::Reversed) {
            throw new TransferStateException($event->transfer_id, $event->status, 'confirm reversed cost event');
        }

        /** @var StockTransfer $transfer */
        $transfer = StockTransfer::query()->with('lines')
            ->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->lockForUpdate()->findOrFail($event->transfer_id);

        $this->capitalizeConfirmedEvent($transfer, $event);

        $event->status = TransferCostEventStatus::Confirmed;
        $event->confirmed_by_user_id = $userId;
        $event->confirmed_at = now();
        $event->save();

        $snapshot = $event;
        DB::afterCommit(function () use ($snapshot, $userId): void {
            event(new StockTransferCostConfirmed(
                costEventId: $snapshot->id, transferId: $snapshot->transfer_id,
                tenantId: $snapshot->tenant_id, companyId: $snapshot->company_id,
                amount: (string) $snapshot->amount, label: $snapshot->label,
                confirmedByUserId: $userId, occurredAt: now()->toIso8601String(),
            ));
        });

        return $event;
    }));
}

/**
 * Allocate a confirmed event's amount across the transfer's lines and capitalize
 * each share into WAC. Lines are sorted by product_id ASC so all concurrent
 * callers acquire product advisory locks in the same total order (deadlock-free).
 */
private function capitalizeConfirmedEvent(StockTransfer $transfer, StockTransferCostEvent $event): void
{
    $weights = $this->computeAllocationWeights($transfer, $event->distribution);
    $totalWeight = array_sum($weights);
    $eventAmount = (float) $event->amount;

    $sortedLines = $transfer->lines->sortBy('product_id')->values();

    foreach ($sortedLines as $line) {
        $product = Product::query()
            ->where('tenant_id', $transfer->tenant_id)->where('company_id', $transfer->company_id)
            ->findOrFail($line->product_id);

        $allocated = $totalWeight > 0
            ? $eventAmount * ($weights[$line->id] / $totalWeight)
            : $eventAmount / max(1, $transfer->lines->count());

        // Skip TRUE zero only — never skip negatives (reversals). Closes v2 BLOCKER 3.
        if (round($allocated, 4) === 0.0) { continue; }

        $line->allocated_transfer_cost = bcadd((string) $line->allocated_transfer_cost, (string) round($allocated, 4), 4);
        $line->save();

        // recordCostAdjustment acquires the per-product advisory lock internally (Task A6).
        $this->wacService->recordCostAdjustment(
            product: $product, additionalCost: $allocated,
            reason: 'stock_transfer_cost.'.$event->phase->value,
            tenantId: $transfer->tenant_id, companyId: $transfer->company_id,
            reference: $transfer->transfer_number,
            referenceType: StockTransfer::class, referenceId: $transfer->id,
        );
    }
}

private function lockTransferUnscoped(string $transferId): StockTransfer
{
    return StockTransfer::query()->with('lines')->lockForUpdate()->findOrFail($transferId);
}

private function fingerprintCostEvent(RecordCostEventData $data): string
{
    return hash('sha256', json_encode([
        'transfer_id' => $data->transferId, 'phase' => $data->phase->value,
        'amount' => $data->amount, 'label' => $data->label,
        'distribution' => $data->distribution->value, 'reverses_event_id' => $data->reversesEventId,
    ], JSON_THROW_ON_ERROR));
}
```

> `computeAllocationWeights()` is refactored (v3 note) to take a `TransferCostDistribution` argument.

- [ ] **Step 3: Run Task A5 tests** — expect PASS.
- [ ] **Step 4: Commit** `feat(inventory): addCostEvent (pending) + confirmCostEvent (capitalize forward under advisory lock)`

### Task A8 — `initiate()` seeds a PENDING `at_initiate` event; sorted product locks

**Files:** Modify `StockTransferService.php`.

- [ ] **Step 1:** After `moveSourceToInTransit`, when `bccomp($data->transferCost,'0',4) > 0`, create a **pending**
  `at_initiate` event via `addCostEvent` internals (status Pending). It does NOT capitalize until the user confirms it.
  Initialize `stock_transfers.transfer_cost` to the supplied value purely as a denormalized convenience; the ledger is
  the source of truth (Batch I0).
- [ ] **Step 2:** In `moveSourceToInTransit`, change `foreach ($transfer->lines as $line)` to iterate
  `$transfer->lines->sortBy('product_id')->values()` (sorted product-lock acquisition — closes the v3 sort gap for the
  initiate path).
- [ ] **Step 3: Commit** `feat(inventory): initiate seeds pending at_initiate cost event; sorted source locks`

### Task A9 — `complete()` no longer capitalizes; sorted product locks

**Files:** Modify `StockTransferService.php`.

- [ ] **Step 1:** Delete the `capitalizeTransferCost()` call and method from `complete()` (capitalization is now the
  confirm path only). `complete()` only moves stock + records receipt.
- [ ] **Step 2:** Change `complete()`'s `foreach ($transfer->lines as $line)` to
  `$transfer->lines->sortBy('product_id')->values()` (closes the v3 sort gap for complete).
- [ ] **Step 3: Run** the cost-event suite + `test_complete_*` — expect green (complete no longer touches WAC).
- [ ] **Step 4: Commit** `refactor(inventory): complete() stops capitalizing; sorted destination locks`

### Task A10 — HTTP endpoints: add / confirm / reverse cost events

**Files:** Modify controller, requests, routes, seeder, `usePermissions.ts`.

- [ ] **Step 1: `RecordCostEventRequest`** — `phase in [in_transit, at_receipt]`, `amount` `numeric|gt:0` (public add
  is always a positive, pending cost; negative amounts only ever arise from the reverse path), `label nullable`,
  `distribution nullable enum`, `idempotency_key nullable`.
- [ ] **Step 2: Controller methods + routes**
  - `POST /stock-transfers/{transfer}/cost-events` → `addCostEvent` (creates pending; 201).
  - `POST /stock-transfers/{transfer}/cost-events/{event}/confirm` → `confirmCostEvent` (capitalizes; 200).
  - `POST /stock-transfers/{transfer}/cost-events/{event}/reverse` → `reverseCostEvent` (Task F3; emits a confirmed
    negative compensating event with `reverses_event_id`; 200). Each scoped by company, `Str::isUuid` guarded, with
    `IdempotencyKeyConflictException → 409` and `TransferStateException → stateExceptionResponse`.
- [ ] **Step 3: Permissions** — add `inventory.transfers.record_cost` and `inventory.transfers.confirm_cost` to the
  seeder declaration + admin/manager grants; mirror in `usePermissions.ts`.
- [ ] **Step 4: Commit** `feat(inventory): cost-event add/confirm/reverse endpoints + permissions`

### Task A11 — Frontend: pending/confirmed ledger + Add/Confirm + Treasury hint

**Files:** stock-transfers types/api/queries/detail page + locales.

- [ ] **Step 1:** Type `StockTransferCostEvent` with `status: 'pending'|'confirmed'|'reversed'`,
  `confirmed_at: string|null`. Extend `StockTransfer` with `cost_events?: StockTransferCostEvent[]` and
  `confirmed_cost_total: string`.
- [ ] **Step 2:** API + hooks: `addCostEvent`, `confirmCostEvent`, `reverseCostEvent`; each invalidates the detail,
  list, and `['products']` query keys.
- [ ] **Step 3:** Detail page "Cost ledger" section: phase + **status badge** (Pending/Confirmed/Reversed), amount,
  label, recorded_by, recorded_at. "Add cost" button (when in_transit/completed) opens a fixed-size modal (amount,
  label, distribution, idempotency key on mount). A "Confirm" action per pending event (gated by
  `inventory.transfers.confirm_cost`); on confirm, toast `t('costs.confirmed_treasury_hint')` ("Cost capitalized — a
  pending payment was created in Treasury"). Surface server error messages via `extractServerMessage`.
- [ ] **Step 4:** `show()` returns `with(['costEvents.recordedBy','costEvents.confirmedBy'])` and serializes
  `cost_events` + `confirmed_cost_total`.
- [ ] **Step 5:** Vitest: add-cost creates pending (no cost change asserted via mocked mutation), confirm calls the
  confirm hook. `pnpm test -- --run src/features/stock-transfers && pnpm typecheck && pnpm lint` clean; ESLint ratchet held.
- [ ] **Step 6: Commit** `feat(stock-transfers): pending/confirmed cost ledger + confirm UX + Treasury hint`

### Task A12 — Negative-path + multi-phase tests

- [ ] Add: confirm-on-cancelled rejects; phase-vs-status guards; confirm is idempotent (second confirm = no-op, one
  capitalization); add with zero amount rejected at DTO; pending event on a cancelled transfer is voided not capitalized;
  multi-line `at_receipt` confirm spreads correctly. Commit.

---

## Batch T — Treasury seam (Decision D4; forward-pointer + audit shape only)

### Task T1 — Document the Treasury seam + month-end audit query shape

**Files:** `docs/superpowers/coordination/2026-05-28-inventory-transfer.md`

- [ ] **Step 1:** Append a "Treasury cost-confirmation seam" section:
  - `StockTransferCostConfirmed` is the one-way event Inventory emits on confirm. Inventory does not import Treasury.
  - The Treasury follow-up PR adds a listener that creates a **pending** payment action referencing the cost event id,
    and a `payment_allocations.allocation_target → stock_transfer_cost_events.id` seam (or the generic-allocation seam
    Treasury picks).
  - **Month-end unjustified-cost audit:** confirmed cost events with no allocated Treasury payment are surfaced via
    `stock_transfer_cost_events WHERE status='confirmed' AND id NOT IN (SELECT allocation_target FROM payment_allocations ...)`.
    The query lives in Treasury; the `stce_company_status_idx` index added in A1 supports it.
  - Accounting note: a confirmed-but-unpaid cost is a capitalized inventory cost with an offsetting accrued-liability /
    "goods-in-transit clearing" entry until the Treasury payment justifies it (the D365 clearing-account pattern). The
    journal wiring is Treasury's responsibility; Inventory only guarantees the event + the idempotent ledger.
- [ ] **Step 2: Commit** `docs(inventory-transfer): Treasury cost-confirmation seam + month-end audit shape`

---

## Batch F — Cancel semantics + cost reversal under the new model

### Task F0–F2 (inherited from v3, unchanged)
- F0: pre-flight grep of `MovementType::TransferIn`/`isInbound()` callsites → audit note. (v3 Task F0 verbatim.)
- F1: add `MovementType::TransferReversed` + `isInbound()` mapping; cancel-from-in_transit return uses it. (v3 Task F1.)
- F2: cancel reference prefix `RV-` instead of `-CANCEL` suffix. (v3 Task F2.)

### Task F3 — Cancel-time cost handling under pending/confirmed (REPLACES v3 F3)

The owner-locked model is now simpler than v3's: pending costs need no reversal (they never hit WAC); only **confirmed**
costs may need a compensating reversal.

**Files:** `StockTransferService.php`, `CancelTransferRequest.php` (new), controller, detail page, locales.

- [ ] **Step 1: `cancel()` runs its stock-return loop under sorted product advisory locks.** Change cancel's
  `foreach ($transfer->lines ...)` to iterate `$transfer->lines->sortBy('product_id')->values()`, and acquire each
  line's product lock via `ProductCostLock` before `receive()` (closes the Opus BLOCKER: cancel must hold the lock it
  mutates stock under). Pending cost events on the transfer are set to `status = reversed` (voided, no WAC effect).
- [ ] **Step 2: `reverseCostEvent(costEventId, userId, tenantId, companyId, ?idempotencyKey)`** — public + used by
  cancel for each confirmed event the user elects to reverse. It:
  1. Loads the original event scoped to (tenant, company); rejects if `status !== confirmed` or
     `reverses_event_id !== null` (can't reverse a reversal).
  2. Calls `addCostEvent(... reversesEventId: original->id, amount: bcmul(original->amount,'-1',4), phase: original->phase)`
     then `confirmCostEvent` on the new compensating event — so the reversal capitalizes the negative amount under the
     advisory lock. The `stce_reverses_unique` partial index makes a double-reverse raise
     `UniqueConstraintViolationException`, caught and surfaced as 409 `COST_EVENT_ALREADY_REVERSED`.
  3. Marks the original `status = reversed`.
- [ ] **Step 3: Cancel modal** lists confirmed cost events with a per-event "Reverse — we didn't pay it" checkbox
  (default unchecked = Keep). The modal container gets `role="dialog"` + `aria-label` (closes the v3 Playwright P3).
  `cancel()` accepts `costEventReversalIds: list<string>`; `CancelTransferRequest` validates each via
  `ScopedExists::tenantAndCompany('stock_transfer_cost_events', ...)`. **Batch failure semantics:** the whole cancel
  transaction is atomic — if any reversal id is invalid/already-reversed, the entire cancel rolls back and returns 409
  (documented behavior).
- [ ] **Step 4: Test** `test_cancel_reversing_confirmed_event_moves_wac_back`: initiate with `transferCost 100`, confirm
  the at_initiate event (WAC 100/100 owned → +1), cancel reversing it → WAC returns to pre-confirm. Plus
  `test_cannot_double_reverse_a_cost_event` (409).
- [ ] **Step 5: Commit** `feat(inventory): cancel-time confirmed-cost reversal (compensating event under advisory lock)`

---

## Inherited batches (unchanged from v3 except noted)

These v3 batches stand. Execute them from the v3 plan
(`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md`) with the deltas below.

- **Batch B — Idempotency (complete/cancel/initiate).** Inherit v3 Tasks B1–B6 **with three deltas:**
  1. The catch-and-reload path in B4 uses the same null-hash-rejecting guard as `assertSameRouteAndPayload`
     (null hash → 409, closing the v3 P2 gap) — mirror the helper for `InitiateTransferData`.
  2. Spec the test helper the v3 B4 test referenced but never defined: add a `@internal` static
     `StockTransferService::fingerprintForTest(InitiateTransferData $data): string` that delegates to the private
     `fingerprintPayload` (test-only seam), OR have the test reflect into the private method. Pick the static seam.
  3. The B4 concurrency test forces the catch path by seeding a winning row via `DB::table()` AND disabling the
     pre-check with a test-only flag (`StockTransferService::$skipIdempotencyPreCheckForTest = true`) so the INSERT
     actually hits the unique violation — otherwise the pre-check short-circuits and the catch path is never exercised.
- **Batch C — Re-label refactor (movement type threading).** Inherit v3 Tasks C1–C2 **with one delta:** C2's `cancel()`
  edit must also apply the sorted product-lock acquisition from Task F3 Step 1 (don't leave cancel's loop unsorted).
- **Batch D — Defense-in-depth tests.** Inherit v3 Tasks D1–D6 verbatim (cross-company scoping, cross-tenant product
  rejection, distribution-mode tests, negative `transferCost` DTO guard, cancel-from-cancelled).
- **Batch E — Frontend UX.** Inherit v3 Tasks E1–E5 verbatim (idempotency_key on create, surface server errors,
  pagination, AR locale decision, complete/cancel idempotency keys). Task A11 already covers the cost-ledger UI.
- **Batch G — Migration relocation.** Inherit v3 Task G1 verbatim (`git mv` create_stock_transfers into `tenant/`;
  verify the SQLite test bootstrap picks up `tenant/` migrations, adding `CreatesStockTransferSchema` if needed).
- **Batch H — Tenancy-docs forward-pointer.** Inherit v3 Task H1 verbatim.
- **Batch I — Design-note updates.** Inherit v3 Tasks I0–I1 **with one delta:** I0's source-of-truth rule must state
  that only `status = confirmed` events are capitalized; pending events are informational; the ledger (not
  `transfer_cost`) is canonical.
- **Batch J — E2E Playwright.** Inherit v3 Task J1 **with one delta:** the cancel modal now has `role="dialog"` (Task
  F3 Step 3), so the `page.locator('[role=dialog]', { hasText: ... })` selector resolves; add a third spec
  `initiate → add cost (pending) → confirm → assert WAC/cost badge flips to Confirmed`.

---

## Verification before merge

- [ ] `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/ tests/Architecture/InventoryProductCostLockUsageTest.php` — all green
- [ ] Two-process race test passes on PostgreSQL CI (skipped on SQLite)
- [ ] `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=2G` — clean
- [ ] `cd apps/api && ./vendor/bin/pint --test` — clean
- [ ] `cd apps/web && pnpm typecheck && pnpm lint && pnpm test` — clean, ESLint ratchet held
- [ ] `cd apps/web && pnpm playwright test stock-transfers` — 3 passing
- [ ] CI on PR #147 — all required checks green
- [ ] Re-request adversarial review (Opus + Codex) on the v4 final state

---

## What v4 deliberately does NOT cover

- **Treasury module** (payment actions, allocation FK, month-end audit job, journal wiring) — Batch T seam + Treasury
  follow-up PR (Decision D4).
- **Retroactive cost re-flow** (ERPNext-style replay) — explicitly out (Decision D3 forward-only).
- **Quantity precision drift** — resolved upstream by PR #151.
- **`ConfirmDialog` extension**, **`bcformat` in WAC math**, **batch preservation**, **per-location tax IDs**,
  **InTransitAvailability per-company**, **inter-company (Scenario B)** — deferred as in v3.

---

## Sequencing + sub-skill

- Order: **A (incl. A6/A6b/A6c lock foundation first) → B → C → T → D → E → F → G → H → I → J**.
- **Implementation timing:** start only AFTER the parallel precision-drift session lands, because A6b edits
  `StockAdjustmentService` (which precision is also touching). Rebase onto post-precision `dev` first.
- Sub-skill: `superpowers:subagent-driven-development` — one fresh subagent per task, two-stage review between batches.

---

## Self-review notes

- **Spec coverage:** every v3-review BLOCKER/P1/P2 maps to a task in the "findings → closure" table; D1–D6 each have an
  implementing batch; Treasury is a documented seam (D4) not a silent gap.
- **Concurrency proof:** the advisory lock is acquired by (i) the WAC writer (A6), (ii) every stock-quantity mutator
  (A6b), (iii) the confirm/reverse paths (A7/F3), all in sorted product order with deadlock-retry — so no divisor writer
  bypasses serialization (the exact v3 hole) and no lock-order cycle can form. The architecture test (A6c) gates the one
  choke point, and the two-process Postgres test proves it empirically.
- **Type consistency:** `RecordCostEventData.reversesEventId`, the model `$fillable` (incl. `status`,
  `reverses_event_id`, `idempotency_payload_hash`, `confirmed_at`, `confirmed_by_user_id`), and the DTO are aligned —
  this is the exact set v3 omitted. `addCostEvent`/`confirmCostEvent`/`reverseCostEvent` names are used consistently in
  service, controller, routes, frontend, and tests.
- **Placeholders:** the changed/critical batches (A, T, F3) carry real code; inherited batches reference v3 with
  explicit deltas (the executor reads both files — v3 is in the same repo).

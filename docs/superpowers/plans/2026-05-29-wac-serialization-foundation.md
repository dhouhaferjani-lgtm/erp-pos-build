# WAC Serialization Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Relationship to other work:** This is the **foundation** the Inventory-Transfer feature depends on. The transfer
> remediation plan (`2026-05-29-inventory-transfer-remediation-v4.md`) will be revised to **v5** to drop its own
> `ProductCostLock`/A6/A6b tasks and simply *use* the seam this plan establishes. **Land this plan first**, then v5.
> **Timing:** this plan edits `StockAdjustmentService` and `WeightedAverageCostService`, which the parallel
> precision-drift session is also touching — rebase onto post-precision `dev` before implementing.

**Goal:** Establish one per-product serialization seam (`ProductCostLock`) so every weighted-average-cost (WAC)
recompute reads a consistent company-wide `(on_hand + in_transit)` denominator, with no torn-divisor race against
concurrent stock movements — without touching the high-frequency POS sale write path.

**Architecture:** WAC is a single running average per product (company-wide `cost_price`). Only **cost-recompute** and
**row-creating** operations (purchases, returns, opening, cost adjustments, stock receipts, adjustments, transfer state
changes) can move the average *or* create a `stock_level` row; **pure decrements** (sales / issues / POS) can only
reduce an existing row and never recompute cost (verified: `WeightedAverageCostService::recordSale` writes
`avg_cost_after = $costPrice`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:255`).
The seam therefore makes the **recompute/create operations** the serialization owners: each acquires a transaction-scoped
Postgres **advisory lock** keyed on `(tenant_id, company_id, product_id)` (serializing them against each other and
closing the new-row phantom), and the company-wide-denominator reader additionally `FOR UPDATE`-locks the `stock_level`
rows + in-transit lines it sums (so a concurrent pure-decrement, which already row-locks its own row, blocks). Sales /
issues / POS paths are **unchanged**: they serialize against an in-flight recompute via the row locks they already take,
and cannot create the phantom rows that pure row-locking misses (you cannot sell stock that has no row). Deadlock-retry
is delegated to Laravel's native `DB::transaction($cb, attempts: 3)` (its `ConcurrencyErrorDetector` matches PG
SQLSTATE 40P01/40001).

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 16+ (advisory locks; no-op on the SQLite test runner — the true race
runs against Postgres CI), PHPUnit + RefreshDatabase, PHPStan level 8, Pint.

---

## Why this exists

Two adversarial reviews of the transfer plan (v3) and one of v4 (all BLOCKER) converged: there is no transfer-local fix
for WAC concurrency, because the WAC denominator is fed by many stock writers across Inventory/POS/Document. A code trace
(see `docs/superpowers/research/2026-05-29-wac-concurrency-erp-best-practices.md` and the reviews) established two facts
that make a *minimal* foundation possible:

1. **Sales never recompute cost and never create rows** — they only decrement an existing `stock_level` (insufficient-
   stock check blocks otherwise). So they need not take the seam lock; the recompute reader's row locks serialize them.
2. **Only inbound/cost ops create rows or recompute** — purchases, returns, opening, adjustments, transfers, cost
   adjustments. These are lower-frequency and are the natural serialization owners.

This is industry-aligned (Odoo/D365 serialize the recompute by locking the rows/product it reads, not by gating sales).

**Out of scope (flagged, not fixed here):**
- The POS sale write path (`ReceiptCreationService`/`Void`/`Return`/`PosCoreReceiptProjection`) — no change needed.
- **WAC divisor-basis inconsistency:** `recordPurchase`/`recordReturn` recompute the average against the *single
  receiving location's* quantity (`WeightedAverageCostService.php:93`, `:342`), while `recordCostAdjustment` uses the
  *company-wide* sum. This is a costing-*semantics* question (per-location vs company-wide WAC), independent of
  concurrency. **File a separate ticket; do not change it here.**
- The POS dual-write smell (`ReceiptCreationService::decrementStock` + `PosCoreReceiptProjection::decrementStock` both
  write the same row) — separate ticket.
- Treasury, the transfer feature, return-note additional fees (the latter fits the transfer cost-event ledger and is
  handled in the transfer plan).

---

## Coverage decision — who takes the seam lock

**Rule:** an operation acquires `ProductCostLock` iff it can **recompute `cost_price`** OR **create a `stock_level`
row**. Pure decrements of an existing row do not.

| Operation | Recomputes cost? | Can create a row? | Takes seam lock? |
|---|---|---|---|
| `WeightedAverageCostService::recordPurchase` | yes | yes (getOrCreate) | **YES** |
| `WeightedAverageCostService::recordReturn` | yes | yes (getOrCreate) | **YES** |
| `WeightedAverageCostService::recordCostAdjustment` | yes | no (qty unchanged) | **YES** (+ row-locks the sum) |
| `WeightedAverageCostService::recordSale` | no | no (firstOrFail) | no |
| `StockAdjustmentService::receive` | no | yes (getOrCreate) | **YES** |
| `StockAdjustmentService::adjust` | no | yes (count/reconcile) | **YES** |
| `StockAdjustmentService::transfer` | no | yes (dest getOrCreate) | **YES** |
| `StockAdjustmentService::issue` | no | no (lockStockLevel firstOrFail) | no |
| `InventoryOpeningService::postBatch` | yes | yes | **YES** |
| POS receipt decrement / projection | no | no | no |
| POS void / return restore | no | no (existing row) | no |

> The architecture test (Task 6) enforces this list: every method that calls `getOrCreateStockLevel`/`StockLevel::create`
> or writes `product->cost_price` must route through `ProductCostLock::acquire`.

---

## Task 1 — `ProductCostLock` domain service

**Files:**
- Create: `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php`
- Test: `apps/api/tests/Unit/Inventory/ProductCostLockTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Services\ProductCostLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCostLockTest extends TestCase
{
    public function test_acquire_runs_callback_and_returns_its_value(): void
    {
        $lock = new ProductCostLock();
        $result = DB::transaction(fn () => $lock->acquire('t', 'c', ['p2', 'p1'], fn () => 'ok'));
        $this->assertSame('ok', $result);
    }

    public function test_acquire_is_noop_on_non_pgsql_driver(): void
    {
        // On the SQLite test runner this must not throw and must still run the callback.
        $lock = new ProductCostLock();
        $ran = false;
        DB::transaction(function () use ($lock, &$ran): void {
            $lock->acquire('t', 'c', ['p1'], function () use (&$ran): void { $ran = true; });
        });
        $this->assertTrue($ran);
    }
}
```

- [ ] **Step 2: Run, expect fail** — `cd apps/api && ./vendor/bin/phpunit tests/Unit/Inventory/ProductCostLockTest.php` → class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Per-product cost serialization seam.
 *
 * Acquires a PostgreSQL TRANSACTION-scoped advisory lock keyed on
 * (tenant_id, company_id, product_id) for every product in $productIds, in
 * ASCENDING product_id order (the consistent lock-ordering deadlock defense).
 * The lock auto-releases when the surrounding transaction ends (commit, rollback,
 * or crash) — no leak path.
 *
 * Acquired ONLY by operations that recompute cost_price OR create a stock_level
 * row (see plan "Coverage decision"). Pure decrements (sales/issues/POS) do NOT
 * acquire it: they serialize against an in-flight recompute via the stock_level
 * row locks the recompute holds, and cannot create the phantom rows row-locking
 * alone would miss.
 *
 * MUST be called INSIDE an open DB transaction. No-op on non-pgsql drivers
 * (SQLite test runner) — the real two-process race is exercised against
 * PostgreSQL CI only. hashtext collisions only over-serialize unrelated products
 * (safe); they never drop a needed lock, provided all callers build the SAME
 * pre-hash string for the same logical product.
 *
 * @template T
 */
final class ProductCostLock
{
    /**
     * @param  list<string>  $productIds
     * @param  Closure():T  $callback
     * @return T
     */
    public function acquire(string $tenantId, string $companyId, array $productIds, Closure $callback): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            $sorted = array_values(array_unique($productIds));
            sort($sorted, SORT_STRING);

            foreach ($sorted as $productId) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["wac:{$tenantId}:{$companyId}:{$productId}"]);
            }
        }

        return $callback();
    }
}
```

- [ ] **Step 4: Run, expect pass.** PHPStan + Pint clean on the file.
- [ ] **Step 5: Commit** `feat(inventory): ProductCostLock per-product advisory-lock serialization seam`

---

## Task 2 — `recordCostAdjustment`: advisory lock + row-locked company-wide denominator

**Files:** Modify `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
(inject `private readonly ProductCostLock $costLock` in the constructor).

This is the canonical company-wide-denominator reader (the transfer/landed-cost path). It currently uses
`->lockForUpdate()->sum('quantity')` (`WeightedAverageCostService.php:480-485`) — aggregate `FOR UPDATE` that locks no
rows on PostgreSQL (verified v2 review). v4 signature adds `tenantId`/`companyId`.

- [ ] **Step 1: Write the failing test** (Postgres-gated row-lock behavior; on SQLite assert plain correctness)

```php
public function test_cost_adjustment_capitalizes_against_company_wide_on_hand(): void
{
    // product P: 60 at WH-A + 40 at WH-B = 100 company-wide, WAC 5.0000
    $this->seedStock($this->productA, $this->warehouseA, '60.0000');
    $this->seedStock($this->productA, $this->warehouseB, '40.0000');
    $this->productA->forceFill(['cost_price' => '5.0000'])->save();

    $this->wac()->recordCostAdjustment(
        product: $this->productA, additionalCost: 100.0, reason: 'freight',
        tenantId: $this->tenant->id, companyId: $this->company->id,
    );

    // 100 / 100 company-wide = +1.00 → 6.0000 (NOT 100/60 nor 100/40)
    $this->assertEquals('6.0000', $this->productA->fresh()->cost_price);
}
```

- [ ] **Step 2: Run, expect fail** (signature lacks `tenantId`/`companyId`).

- [ ] **Step 3: Rewrite the method** to: take the advisory lock, `FOR UPDATE`-lock every `stock_level` row for the
  product (then sum in PHP — no aggregate FOR UPDATE), read in-transit, capitalize against `(on_hand + in_transit)`.

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
            $product = Product::query()
                ->where('tenant_id', $tenantId)->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($product->id);

            // Row-lock the actual stock_level rows, then sum in PHP. This
            // serializes the read against any pure-decrement writer (sale/issue)
            // that lockForUpdate's one of these rows. New rows can only be
            // created by lock-taking operations (see ProductCostLock doc), so
            // the advisory lock closes the phantom.
            /** @var \Illuminate\Support\Collection<int, StockLevel> $levels */
            $levels = StockLevel::query()
                ->where('product_id', $product->id)
                ->where('tenant_id', $tenantId)->where('company_id', $companyId)
                ->lockForUpdate()->get();

            $onHand = '0';
            foreach ($levels as $level) {
                $onHand = bcadd($onHand, (string) $level->quantity, $this->scale());
            }

            /** @var numeric-string $inTransit */
            $inTransit = (string) \App\Modules\Inventory\Domain\StockTransferLine::query()
                ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
                ->where('stock_transfer_lines.product_id', $product->id)
                ->where('stock_transfers.tenant_id', $tenantId)
                ->where('stock_transfers.company_id', $companyId)
                ->where('stock_transfers.status', \App\Modules\Inventory\Domain\Enums\TransferStatus::InTransit)
                ->sum('stock_transfer_lines.quantity');

            $totalOwned = (float) bcadd($onHand, $inTransit, $this->scale());
            if ($totalOwned <= 0) {
                return null;
            }

            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $newAvgCost = round($currentCostPrice + ($additionalCost / $totalOwned), $this->scale());

            // ... existing anchor-stock-level pick + StockMovement::create (quantity 0,
            // quantity_before/after = (string) $totalOwned) + product cost_price write +
            // MarginService::updateSalePrice + afterCommit ProductCostPriceUpdated,
            // preserved verbatim, using $totalOwned for the audit qty fields ...
        });
    });
}
```

- [ ] **Step 4: Run the test, expect pass.** Update the single existing caller (`StockTransferService`, now the
  transfer cost confirm/reverse path in v5) to pass `tenantId`/`companyId` — `rg -n "recordCostAdjustment\(" app/`.
- [ ] **Step 5: PHPStan + Pint clean. Commit** `fix(inventory): recordCostAdjustment — advisory lock + row-locked company-wide denominator`

---

## Task 3 — Row-creating / recompute writers acquire the seam lock

**Files:** Modify `WeightedAverageCostService.php` (`recordPurchase`, `recordReturn`),
`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (`receive`, `adjust`, `transfer` — inject
`ProductCostLock`), `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php`.

Each wraps its existing transaction body in `costLock->acquire($tenantId, $companyId, [$productId], fn () => …)`. The
lock key MUST be `(tenant_id, company_id, product_id)` — the SAME tuple Task 2 uses. **`Location` carries only
`company_id`** (`apps/api/app/Modules/Company/Domain/Location.php`), so resolve `tenant_id` from the `Product` or
`Company`, never from the location.

- [ ] **Step 1: `recordPurchase` / `recordReturn`** — wrap the body; key off the input `$product`'s
  `tenant_id`/`company_id` (these methods already hold the product). Do **not** change their divisor basis (the flagged
  semantics question is out of scope).
- [ ] **Step 2: `StockAdjustmentService::receive` / `adjust` / `transfer`** — inject `ProductCostLock`; wrap each body.
  These resolve company from the location today; add a `resolveTenant(string $productId): string` (or pass the product's
  tenant) so the key tuple matches Task 2 exactly. For `transfer`, acquire BOTH product rows? No — `transfer` is one
  product across two locations; acquire the single product key once.
- [ ] **Step 3: `InventoryOpeningService::postBatch`** — wrap the per-product create/update in the seam lock.
- [ ] **Step 4: Run the full inventory + document + POS suites** `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Document` — expect green (advisory lock is a no-op on SQLite; single-threaded behavior unchanged).
- [ ] **Step 5: Commit** `fix(inventory): row-creating/recompute writers acquire ProductCostLock seam`

---

## Task 4 — `issue` / `recordSale` / POS stay lock-free (regression guard, no behavior change)

**Files:** Test only — `apps/api/tests/Architecture/InventoryCostLockCoverageTest.php` (Task 6 covers the assertion).

- [ ] **Step 1:** Add an assertion (in the Task 6 test) that `recordSale` and `StockAdjustmentService::issue` do **NOT**
  contain `costLock->acquire` (they must stay lock-free; taking the lock would add hot-path contention and is
  unnecessary per the coverage decision). Documents the deliberate boundary.
- [ ] **Step 2: Commit** with Task 6.

---

## Task 5 — Deadlock-retry via Laravel native `attempts`

**Files:** Modify the public WAC + `StockAdjustmentService` entry points to pass `attempts: 3` to `DB::transaction`.

v4 wrongly proposed catching `\Illuminate\Database\DeadlockException` (only wrapped for nested txns). The correct
mechanism is Laravel's native retry: `DB::transaction($callback, attempts: 3)`, whose `ConcurrencyErrorDetector` matches
PG deadlock (40P01) and serialization-failure (40001) and retries.

- [ ] **Step 1:** Change `recordCostAdjustment`, `recordPurchase`, `recordReturn`, and the `StockAdjustmentService`
  mutators to `DB::transaction(fn () => …, attempts: 3)`. (Sorted advisory-lock acquisition makes deadlocks essentially
  impossible among seam-taking ops; this is the belt-and-suspenders fallback for any cross-feature cycle.)
- [ ] **Step 2: Commit** `fix(inventory): native DB::transaction(attempts: 3) deadlock-retry on WAC/stock mutators`

---

## Task 6 — Architecture test: seam coverage + boundary

**Files:** Create `apps/api/tests/Architecture/InventoryCostLockCoverageTest.php`.

Honest enforcement (replaces v3's impossible substring-on-leaf scan): every method that **creates a stock_level row**
(calls `getOrCreateStockLevel` or `StockLevel::create`) OR **writes `cost_price`** MUST route through
`costLock->acquire`; the designated lock-free decrement methods MUST NOT.

- [ ] **Step 1: Write the test** — balanced-brace method-body extractor (handles nested closures), with two providers:

```php
public static function mustLockProvider(): array
{
    return [
        ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordPurchase'],
        ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordReturn'],
        ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordCostAdjustment'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function receive'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function adjust'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function transfer'],
        ['app/Modules/Inventory/Application/Services/InventoryOpeningService.php', 'public function postBatch'],
    ];
}

public static function mustNotLockProvider(): array
{
    return [
        ['app/Modules/Inventory/Application/Services/WeightedAverageCostService.php', 'public function recordSale'],
        ['app/Modules/Inventory/Domain/Services/StockAdjustmentService.php', 'public function issue'],
    ];
}

/** @dataProvider mustLockProvider */
public function test_recompute_or_creating_methods_take_the_seam_lock(string $path, string $sig): void
{
    $this->assertStringContainsString('costLock->acquire', $this->extractMethodBody(base_path($path), $sig),
        "$sig must serialize on ProductCostLock::acquire (it recomputes cost or creates a stock_level row).");
}

/** @dataProvider mustNotLockProvider */
public function test_pure_decrement_methods_stay_lock_free(string $path, string $sig): void
{
    $this->assertStringNotContainsString('costLock->acquire', $this->extractMethodBody(base_path($path), $sig),
        "$sig is a pure decrement and must stay lock-free (serializes via existing row locks).");
}
```

- [ ] **Step 2:** Add a guard test asserting `recordSale` / `issue` use `firstOrFail`/`lockStockLevel` (operate on an
  existing row only — the invariant that lets them stay lock-free). Documents *why* the boundary is safe.
- [ ] **Step 3: Run, expect pass. Commit** `test(inventory): seam-coverage architecture test + decrement boundary guard`

---

## Task 7 — Two-process WAC race (PostgreSQL-gated)

**Files:** Create `apps/api/tests/Feature/Inventory/WacSerializationConcurrencyTest.php`.

- [ ] **Step 1:** Write a test that opens two real PG connections: connection 1 begins a transaction and takes product
  P's advisory lock (via a `recordCostAdjustment` paused mid-flight, or a direct `pg_advisory_xact_lock`); connection 2
  issues a `recordPurchase`/`receive` for P and must **block** until connection 1 commits; assert the final `cost_price`
  equals the deterministic serialized result. `markTestSkipped` on non-pgsql.
- [ ] **Step 2:** Add a complementary test: a concurrent `recordSale` (decrement) on connection 2 blocks on connection
  1's row lock during a `recordCostAdjustment`, proving the lock-free decrement path is still serialized against the
  recompute via row locks.
- [ ] **Step 3: Run on a PG-backed harness; commit** `test(inventory): two-process WAC serialization race (pg-gated)`

---

## Verification before merge

- [ ] `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Document tests/Unit/Inventory tests/Architecture/InventoryCostLockCoverageTest.php` — green
- [ ] Two-process race test passes on PostgreSQL CI (skipped on SQLite)
- [ ] `./vendor/bin/phpstan analyse --memory-limit=2G` — clean
- [ ] `./vendor/bin/pint --test` — clean
- [ ] No change to POS receipt/void/return/projection files (confirm via `git diff --stat`)
- [ ] File the two flagged tickets: (1) WAC divisor-basis (per-location vs company-wide) in recordPurchase/recordReturn; (2) POS dual-write (ReceiptCreationService vs PosCoreReceiptProjection)
- [ ] Adversarial review (Codex) on the foundation before merge

---

## Sequencing + sub-skill

- Order: Task 1 → 2 → 3 → 5 → 6 → 4 → 7 (lock seam, then the canonical reader, then the other writers, then retry,
  then the enforcing tests, then the race proof).
- **Land after the precision-drift session** (it edits `StockAdjustmentService`); rebase first.
- After this merges, revise the transfer plan to **v5**: delete its `ProductCostLock`/A6/A6b tasks, and have the
  transfer cost-confirm/reverse path simply call the now-existing `recordCostAdjustment` (which is already seam-locked).
- Sub-skill: `superpowers:subagent-driven-development`.

---

## Self-review notes

- **Spec coverage:** the seam (Task 1), the canonical reader (Task 2), every other recompute/creating writer (Task 3),
  the decrement boundary (Tasks 4/6), native retry (Task 5), enforcement (Task 6), and the empirical race proof (Task 7)
  are each a task. The two known-but-out-of-scope issues are explicitly filed as tickets, not silently dropped.
- **Correctness argument:** recompute/creating ops serialize against each other via the advisory lock (closing the
  new-row phantom, since only they create rows); the canonical reader row-locks the rows it sums so pure decrements
  (which already row-lock) serialize against it. Sales/POS unchanged. No divisor writer escapes serialization, and no
  lock-order cycle forms (sorted acquisition + native retry).
- **Type consistency:** the lock key tuple `(tenant_id, company_id, product_id)` and the `costLock->acquire` signature
  are identical across Tasks 1–3; `recordCostAdjustment`'s new `tenantId`/`companyId` params match the single caller.
- **Placeholders:** none — every task carries real code or a verbatim-preservation note over existing bodies.

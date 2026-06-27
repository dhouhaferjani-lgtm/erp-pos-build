# Product Opening Balance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authorized user set an inline opening stock balance (qty + valuation cost) on the product create form (and on an eligible existing product), posting one `Opening` stock movement + stock level + cost basis + balanced GL entry, enter-once, with an audit-preserving reversal-based correction.

**Architecture:** Extract the canonical opening-posting logic out of `InventoryOpeningService::postBatch` into a reusable `OpeningBalancePostingService` (1..N lines, one GL entry, in-lock enter-once, concurrency-safe numbering, post-commit movement events). Three thin adapters drive it — product create, a standalone `POST /products/{id}/opening` (re-entry), and the import batch. Correction is a reversal (`reverses_movement_id`) gated to "active opening, no downstream activity". Opening GL entries are `is_historical=true` (excluded from the fiscal hash chain, per existing convention).

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL (db-per-tenant), Spatie LaravelData DTOs, PHPUnit; React 19 / Vite / TS strict / TanStack Query / react-hook-form, Vitest.

**Spec:** `docs/superpowers/specs/2026-06-26-product-opening-balance-design.md` (v2.3). Section refs below (e.g. "spec §4.1") point there.

## Global Constraints

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`, branch `feat/izipos-theme-product-editor`. All paths below are relative to it.
- **Run tests BY PATH only** — never the full PHPUnit suite (laptop crash; a broken `OwnerSalesSummaryServiceTest` fatals a bare run). Backend: `cd apps/api && php artisan test --filter=<Class> <path>`.
- **Backend env:** `apps/api/vendor` is a real dir (verified). Artisan commands that touch cache need `CACHE_STORE=array` (e.g. `CACHE_STORE=array php artisan typescript:transform`).
- **Validation error envelope** is `{error:{errors}}` — assert via `Tests\Traits\AssertsApiValidation`.
- **Precision rule 19:** money = string `decimal(N,3)`, regex `/^\d+(\.\d{1,3})?$/`; quantity = string `decimal(N,4)`, regex `/^\d+(\.\d{1,4})?$/`. Never cast money/qty to float. Use `CurrencyScale`/`QuantityScale` + injected `CurrencyScaleResolverInterface` (never `app()`).
- **Constructor injection only** (`private readonly`); enums for status/type; no `// TODO`/placeholder code; no magic strings.
- **i18n:** all new FE copy via `t()`; colors via design tokens from `@/lib/designTokens`.
- **`php artisan typescript:transform`** after any `ProductData`/DTO change (rule 7; generated types are source of truth).
- **Commit after each task.** Pre-flight (`./scripts/preflight.sh`) before the final commit of a task that changes shippable code.
- **Branch discipline (rule 21):** work in this worktree; reconcile with `origin/dev` before finishing; never force-push `dev`.

---

## File Structure

**Backend (new):**
- `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalanceLine.php` — one opening line (validates/canonicalizes qty+cost).
- `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalancePosting.php` — a posting (company, user, entryDate, isHistorical, source, reference, notes, lines[]).
- `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalancePostingResult.php` — JournalEntry + movement ids in input order.
- `apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php` — the use case.
- `apps/api/app/Modules/Inventory/Domain/Exceptions/OpeningAlreadyExistsException.php`
- `apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php`
- `apps/api/app/Modules/Product/Application/DTOs/OpeningStateData.php` — read-model state for the FE.
- `apps/api/app/Modules/Product/Presentation/Requests/PostOpeningBalanceRequest.php`

**Backend (modify):**
- `apps/api/app/Shared/Contracts/InventoryServiceInterface.php` — add `hasActiveOpening`, `hasDownstreamMovements`.
- `apps/api/app/Modules/Inventory/Application/Services/InventoryService.php` — implement them.
- `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php` — `postBatch` delegates to the new service.
- `apps/api/app/Modules/Product/Application/DTOs/ProductData.php` — opening-state fields.
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php` — opening rules.
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` — store() wrap + opening post + `postOpening()` action.
- `apps/api/app/Modules/Product/routes.php` — `POST products/{id}/opening` + `.../opening/reset`.

**Frontend (modify):**
- `apps/web/src/features/inventory/ProductForm.tsx` — opening section + state lock + reset/re-entry.
- i18n FR/EN inventory namespace files (opening copy).

---

## Task 1: Opening DTOs + exception

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalanceLine.php`
- Create: `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalancePosting.php`
- Create: `apps/api/app/Modules/Inventory/Application/DTOs/OpeningBalancePostingResult.php`
- Create: `apps/api/app/Modules/Inventory/Domain/Exceptions/OpeningAlreadyExistsException.php`
- Test: `apps/api/tests/Unit/Inventory/OpeningBalanceLineTest.php`

**Interfaces:**
- Produces:
  - `OpeningBalanceLine::__construct(string $productId, ?string $variantId, string $locationId, string $quantity, string $unitCost)` — constructor canonicalizes `quantity` to 4dp and `unitCost` to the given currency scale; throws `InvalidArgumentException` on non-numeric-string / over-scale / negative. Static `make(...)` factory taking the scale.
  - `OpeningBalancePosting` (readonly): `string $tenantId, string $companyId, string $userId, CarbonInterface $entryDate, bool $isHistorical, string $sourceType, string $sourceId, string $reference, ?string $notes, array<OpeningBalanceLine> $lines`.
  - `OpeningBalancePostingResult` (readonly): `JournalEntry $entry, array<int,string> $movementIdsInInputOrder`.
  - `OpeningAlreadyExistsException extends RuntimeException`.

- [ ] **Step 1: Write the failing test** — `apps/api/tests/Unit/Inventory/OpeningBalanceLineTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use InvalidArgumentException;
use Tests\TestCase;

final class OpeningBalanceLineTest extends TestCase
{
    public function test_canonicalizes_quantity_and_cost_to_scale(): void
    {
        $line = OpeningBalanceLine::make('p-1', null, 'loc-1', '10', '5.1', currencyScale: 3);

        $this->assertSame('10.0000', $line->quantity);
        $this->assertSame('5.100', $line->unitCost);
    }

    public function test_rejects_over_scale_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', '10.00001', '5.000', currencyScale: 3);
    }

    public function test_rejects_negative_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', '10', '-1.000', currencyScale: 3);
    }

    public function test_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', 'abc', '5.000', currencyScale: 3);
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd apps/api && php artisan test --filter=OpeningBalanceLineTest tests/Unit/Inventory/OpeningBalanceLineTest.php`
Expected: FAIL ("Class OpeningBalanceLine not found").

- [ ] **Step 3: Create `OpeningBalanceLine`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Support\Money\CurrencyScale;
use App\Support\Money\QuantityScale;
use InvalidArgumentException;

final readonly class OpeningBalanceLine
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $locationId,
        public string $quantity,
        public string $unitCost,
    ) {}

    public static function make(
        string $productId,
        ?string $variantId,
        string $locationId,
        string $quantity,
        string $unitCost,
        int $currencyScale,
    ): self {
        self::assertUnsignedDecimal($quantity, 4, 'quantity');
        self::assertUnsignedDecimal($unitCost, $currencyScale, 'unitCost');

        return new self(
            $productId,
            $variantId,
            $locationId,
            QuantityScale::bcformatStrict($quantity),
            CurrencyScale::bcformatStrict($unitCost, $currencyScale),
        );
    }

    private static function assertUnsignedDecimal(string $value, int $maxScale, string $field): void
    {
        if (preg_match('/^\d+(\.\d{1,'.$maxScale.'})?$/', $value) !== 1) {
            throw new InvalidArgumentException("Opening {$field} must be a non-negative decimal with at most {$maxScale} places, got: {$value}");
        }
    }
}
```

> NOTE: Verify the exact helper names/signatures of `CurrencyScale`/`QuantityScale` (read `apps/api/app/Support/Money/`). The spec mandates `bcformatStrict` for at-rest values; if `QuantityScale::bcformatStrict` takes no scale arg, keep as shown; otherwise pass `4`.

- [ ] **Step 4: Create the other two DTOs + exception**

`OpeningBalancePosting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Carbon\CarbonInterface;

final readonly class OpeningBalancePosting
{
    /** @param array<int, OpeningBalanceLine> $lines */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $userId,
        public CarbonInterface $entryDate,
        public bool $isHistorical,
        public string $sourceType,
        public string $sourceId,
        public string $reference,
        public ?string $notes,
        public array $lines,
    ) {}
}
```

`OpeningBalancePostingResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Accounting\Domain\JournalEntry;

final readonly class OpeningBalancePostingResult
{
    /** @param array<int, string> $movementIdsInInputOrder */
    public function __construct(
        public JournalEntry $entry,
        public array $movementIdsInInputOrder,
    ) {}
}
```

`OpeningAlreadyExistsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

final class OpeningAlreadyExistsException extends RuntimeException {}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `cd apps/api && php artisan test --filter=OpeningBalanceLineTest tests/Unit/Inventory/OpeningBalanceLineTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: PHPStan on new files + commit**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/DTOs app/Modules/Inventory/Domain/Exceptions --level=8`

```bash
git add apps/api/app/Modules/Inventory/Application/DTOs apps/api/app/Modules/Inventory/Domain/Exceptions apps/api/tests/Unit/Inventory/OpeningBalanceLineTest.php
git commit -m "feat(inventory): opening-balance posting DTOs + canonicalization"
```

---

## Task 2: `OpeningBalancePostingService` (the use case)

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php`
- Test: `apps/api/tests/Feature/Inventory/OpeningBalancePostingServiceTest.php`

**Interfaces:**
- Consumes: `OpeningBalancePosting`, `OpeningBalanceLine`, `OpeningBalancePostingResult`, `OpeningAlreadyExistsException` (Task 1); `ProductCostLock`, `CurrencyScaleResolverInterface`.
- Produces: `OpeningBalancePostingService::post(OpeningBalancePosting $posting): OpeningBalancePostingResult`. Public helper `generateOpeningEntryNumber(string $companyId): string` (concurrency-safe).

**Reference (mirror, don't reinvent):** `InventoryOpeningService::postBatch` (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:201-375`) for the movement/stock-level/cost/GL block, and `generateEntryNumber` (`:449-465`). The new service is the per-row block generalized to a list, plus an in-lock enter-once check and safe numbering. Read those lines before implementing.

- [ ] **Step 1: Write the failing test** — covers single-line post, balanced GL, is_historical, enter-once, events.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class OpeningBalancePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    // Use the project's standard tenant/company/product/location setup helper.
    // Mirror the arrange block of tests/Feature/Inventory/InventoryOpeningMovementReasonTest.php.

    public function test_posts_one_opening_movement_stock_level_cost_and_balanced_gl(): void
    {
        Event::fake([StockMovementRecorded::class]);
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();

        $service = app(OpeningBalancePostingService::class);
        $result = $service->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'Opening balance: '.$product->sku,
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)],
        ));

        $movement = StockMovement::findOrFail($result->movementIdsInInputOrder[0]);
        $this->assertSame(MovementType::Opening, $movement->movement_type);
        $this->assertSame(MovementReason::OpeningBalance, $movement->reason);
        $this->assertTrue($movement->is_historical);

        $this->assertSame('10.0000', StockLevel::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame('5.000000', (string) $product->fresh()->cost_price);

        $entry = $result->entry;
        $debit = $entry->lines->firstWhere('account_id', $this->accountId($company->id, SystemAccountPurpose::Inventory));
        $credit = $entry->lines->firstWhere('account_id', $this->accountId($company->id, SystemAccountPurpose::OpeningBalanceEquity));
        $this->assertSame('50.000', $debit->debit);
        $this->assertSame('50.000', $credit->credit);

        Event::assertDispatched(StockMovementRecorded::class);
    }

    public function test_rejects_second_active_opening_for_same_product_location(): void
    {
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();
        $service = app(OpeningBalancePostingService::class);
        $posting = fn () => new OpeningBalancePosting($tenant->id, $company->id, $user->id, now(), true, 'opening_balance', $product->id, 'x', null, [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)]);

        $service->post($posting());

        $this->expectException(\App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException::class);
        $service->post($posting());
    }
}
```

> Add `seedOpeningContext()` and `accountId()` helpers in the test (or a shared trait), mirroring the existing inventory-opening feature tests for tenant/company/chart-of-accounts seeding. The chart seeder assigns `Inventory` + `OpeningBalanceEquity` purposes (`database/seeders/GenericChartOfAccountsSeeder.php`).

- [ ] **Step 2: Run test, verify it fails**

Run: `cd apps/api && php artisan test --filter=OpeningBalancePostingServiceTest tests/Feature/Inventory/OpeningBalancePostingServiceTest.php`
Expected: FAIL ("Class OpeningBalancePostingService not found").

- [ ] **Step 3: Implement the service**

Read `InventoryOpeningService.php:201-375` and `:449-465` first, then create the service mirroring its transaction/lock/movement/stock-level/cost/GL structure, generalized over `$posting->lines`. Key differences from `postBatch`:
- Input is `OpeningBalancePosting` (DTOs), not an Eloquent batch.
- Step 3 (enter-once) per line: under the cost lock, query for an **active** opening and throw `OpeningAlreadyExistsException` if found:
  ```php
  $activeOpening = StockMovement::query()
      ->where('company_id', $posting->companyId)
      ->where('product_id', $line->productId)
      ->where('location_id', $line->locationId)
      ->where('movement_type', MovementType::Opening)
      ->whereNull('reverses_movement_id')                 // exclude reversal rows themselves
      ->whereDoesntHave('reversalOf')                     // exclude already-reversed originals
      ->exists();
  if ($activeOpening) {
      throw new OpeningAlreadyExistsException("Active opening already exists for product {$line->productId} at location {$line->locationId}");
  }
  ```
- Entry number: `generateOpeningEntryNumber()` wraps the existing read-max logic in an advisory lock to avoid the `(tenant_id, entry_number)` race. Use a PostgreSQL transaction-level advisory lock keyed by `hashtext("inv-ob-seq:{$tenantId}:{$companyId}:".date('Y'))` acquired with `pg_advisory_xact_lock` via `DB::select` at the start of the numbering, INSIDE the same `DB::transaction`. (Mirror the advisory-lock acquisition style used in `ProductCostLock`.)
- GL entry: set `is_historical => $posting->isHistorical`, `entry_date => $posting->entryDate`, `source_type => $posting->sourceType`, `source_id => $posting->sourceId`. Dr Inventory / Cr OBE = summed line value.
- After the transaction: dispatch `StockMovementRecorded` (+ `StockMovementRecordedV2` if it exists) per created movement via `DB::afterCommit()`. Check the exact event constructor signature in `WeightedAverageCostService.php:841-884` and mirror it.
- Return `OpeningBalancePostingResult($entry->load('lines'), $movementIds)`.

> Full code is ~120 lines mirroring `postBatch`'s inner closure. Do NOT duplicate `postBatch`'s batch bookkeeping (markRowsPosted etc.) — that stays in the import adapter (Task 3).

- [ ] **Step 4: Run test, verify it passes**

Run: `cd apps/api && php artisan test --filter=OpeningBalancePostingServiceTest tests/Feature/Inventory/OpeningBalancePostingServiceTest.php`
Expected: PASS.

- [ ] **Step 5: PHPStan + commit**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php --level=8`

```bash
git add apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php apps/api/tests/Feature/Inventory/OpeningBalancePostingServiceTest.php
git commit -m "feat(inventory): OpeningBalancePostingService — shared opening use case (GL + events + enter-once + safe numbering)"
```

---

## Task 3: Refactor `InventoryOpeningService::postBatch` to delegate

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php`
- Test: existing `apps/api/tests/Feature/Inventory/*Opening*Test.php` (regression) + add an event assertion.

**Interfaces:**
- Consumes: `OpeningBalancePostingService::post` (Task 2).
- Produces: unchanged public `postBatch(OpeningBalanceBatch, string): JournalEntry`.

- [ ] **Step 1: Run the existing opening tests to capture green baseline**

Run: `cd apps/api && php artisan test tests/Feature/Inventory/InventoryOpeningMovementReasonTest.php` (and any other `*Opening*` feature test files — list them with `ls tests/Feature/Inventory | grep -i opening`).
Expected: PASS (baseline before refactor).

- [ ] **Step 2: Add a failing regression test — import now dispatches movement events**

Add to the existing opening batch test (or a new `tests/Feature/Inventory/InventoryOpeningEventsTest.php`): `Event::fake([StockMovementRecorded::class])`, post a batch, `Event::assertDispatched(StockMovementRecorded::class)`.

Run it: Expected FAIL (postBatch doesn't dispatch yet).

- [ ] **Step 3: Refactor `postBatch` to build `OpeningBalanceLine[]` + `OpeningBalancePosting` and call the service**

- Inject `OpeningBalancePostingService` into the constructor.
- Replace the inner closure's movement/stock/cost/GL block with: map valid rows → `OpeningBalanceLine::make(...)` (currency scale via the resolver), build `OpeningBalancePosting(entryDate: $batch->cutover_date, isHistorical: true, sourceType: 'opening_balance', sourceId: $batch->id, reference: "Opening Balance Batch: {$batch->name}", notes: 'Initial inventory from opening balance import', lines: $lines)`, call `$result = $this->postingService->post($posting)`.
- Build `rowEntityMap` by zipping `$validRows` (same order used to build `$lines`) with `$result->movementIdsInInputOrder`, then call `markBatchValidated` + `markRowsPosted($rowEntityMap)` as before. Return `$result->entry`.
- Delete the now-unused private `generateEntryNumber` (moved to the service) if nothing else references it (grep first).

- [ ] **Step 4: Run all opening tests (regression) + the new event test**

Run: `cd apps/api && php artisan test tests/Feature/Inventory/InventoryOpeningMovementReasonTest.php tests/Feature/Inventory/InventoryOpeningEventsTest.php` (+ any other opening files).
Expected: PASS (behavior identical + events now fire).

- [ ] **Step 5: PHPStan + commit**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/InventoryOpeningService.php --level=8`

```bash
git add apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php apps/api/tests/Feature/Inventory/
git commit -m "refactor(inventory): postBatch delegates to OpeningBalancePostingService (events + safe numbering now shared)"
```

---

## Task 4: `InventoryServiceInterface` opening-state queries

**Files:**
- Modify: `apps/api/app/Shared/Contracts/InventoryServiceInterface.php`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryService.php`
- Test: `apps/api/tests/Feature/Inventory/InventoryServiceOpeningStateTest.php`

**Interfaces:**
- Produces (add to interface + impl):
  - `hasActiveOpening(string $companyId, string $productId): bool` — a non-reversed Opening movement exists (original not referenced by any reversal).
  - `hasDownstreamMovements(string $companyId, string $productId): bool` — any movement that is neither an Opening nor a reversal of an Opening.

- [ ] **Step 1: Write the failing test**

```php
public function test_active_opening_and_downstream_flags(): void
{
    [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();
    $svc = app(\App\Shared\Contracts\InventoryServiceInterface::class);

    $this->assertFalse($svc->hasActiveOpening($company->id, $product->id));
    $this->assertFalse($svc->hasDownstreamMovements($company->id, $product->id));

    app(\App\Modules\Inventory\Application\Services\OpeningBalancePostingService::class)->post(/* one line, as Task 2 */);

    $this->assertTrue($svc->hasActiveOpening($company->id, $product->id));
    $this->assertFalse($svc->hasDownstreamMovements($company->id, $product->id));
}
```

- [ ] **Step 2: Run, verify it fails** (method not defined).

Run: `cd apps/api && php artisan test --filter=InventoryServiceOpeningStateTest tests/Feature/Inventory/InventoryServiceOpeningStateTest.php`

- [ ] **Step 3: Add to the interface**

```php
public function hasActiveOpening(string $companyId, string $productId): bool;

public function hasDownstreamMovements(string $companyId, string $productId): bool;
```

- [ ] **Step 4: Implement in `InventoryService`**

```php
public function hasActiveOpening(string $companyId, string $productId): bool
{
    return StockMovement::query()
        ->where('company_id', $companyId)
        ->where('product_id', $productId)
        ->where('movement_type', MovementType::Opening)
        ->whereNull('reverses_movement_id')
        ->whereDoesntHave('reversalOf')
        ->exists();
}

public function hasDownstreamMovements(string $companyId, string $productId): bool
{
    return StockMovement::query()
        ->where('company_id', $companyId)
        ->where('product_id', $productId)
        ->where('movement_type', '!=', MovementType::Opening)
        ->whereNull('reverses_movement_id')          // a reversal of an opening is not "downstream activity"
        ->exists();
}
```

> Verify `InventoryService` already imports `StockMovement` + `MovementType`; add `use` if missing.

- [ ] **Step 5: Run, verify it passes; PHPStan; commit**

Run: `cd apps/api && php artisan test --filter=InventoryServiceOpeningStateTest tests/Feature/Inventory/InventoryServiceOpeningStateTest.php`
Then PHPStan on both files.

```bash
git add apps/api/app/Shared/Contracts/InventoryServiceInterface.php apps/api/app/Modules/Inventory/Application/Services/InventoryService.php apps/api/tests/Feature/Inventory/InventoryServiceOpeningStateTest.php
git commit -m "feat(inventory): hasActiveOpening / hasDownstreamMovements contract"
```

---

## Task 5: `ProductData` opening-state fields + transform

**Files:**
- Create: `apps/api/app/Modules/Product/Application/DTOs/OpeningStateData.php`
- Modify: `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:22-99`
- Test: `apps/api/tests/Unit/Product/ProductDataOpeningStateTest.php`

**Interfaces:**
- Produces: `OpeningStateData{ bool $has_active_opening, bool $has_downstream_movements, bool $can_enter_opening }` (`can_enter_opening = !has_active_opening && !has_downstream_movements`). `ProductData::fromModel(Product $product, ?ProductMediaData $media = null, ?OpeningStateData $opening = null)` adds `opening: ?OpeningStateData`.

- [ ] **Step 1: Failing test**

```php
public function test_product_data_includes_opening_state(): void
{
    $product = Product::factory()->create(); // or the project's product builder
    $data = ProductData::fromModel($product, null, new OpeningStateData(false, false, true));
    $this->assertTrue($data->opening->can_enter_opening);
}
```

- [ ] **Step 2: Run, verify it fails.**

Run: `cd apps/api && php artisan test --filter=ProductDataOpeningStateTest tests/Unit/Product/ProductDataOpeningStateTest.php`

- [ ] **Step 3: Create `OpeningStateData`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OpeningStateData extends Data
{
    public function __construct(
        public bool $has_active_opening,
        public bool $has_downstream_movements,
        public bool $can_enter_opening,
    ) {}

    public static function from(bool $hasActiveOpening, bool $hasDownstream): self
    {
        return new self($hasActiveOpening, $hasDownstream, ! $hasActiveOpening && ! $hasDownstream);
    }
}
```

- [ ] **Step 4: Add the param + field to `ProductData`**

Add constructor param `public ?OpeningStateData $opening = null,` (after `automotive_metadata`), and in `fromModel` add a third param `?OpeningStateData $opening = null` and pass `opening: $opening` in the `new self(...)`.

- [ ] **Step 5: Run, verify it passes; transform types**

Run: `cd apps/api && php artisan test --filter=ProductDataOpeningStateTest tests/Unit/Product/ProductDataOpeningStateTest.php`
Then: `cd apps/api && CACHE_STORE=array php artisan typescript:transform`
Confirm `packages/shared/types/` now has `OpeningStateData` and `ProductData.opening`.

- [ ] **Step 6: PHPStan + commit**

```bash
git add apps/api/app/Modules/Product/Application/DTOs/ apps/api/tests/Unit/Product/ProductDataOpeningStateTest.php packages/shared/types/
git commit -m "feat(product): expose opening-state on ProductData"
```

---

## Task 6: `CreateProductRequest` opening validation

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- Test: `apps/api/tests/Feature/Product/CreateProductOpeningValidationTest.php`

**Interfaces:**
- Produces: validated keys `opening_qty: ?string`, `opening_unit_cost: ?string` with the conditional rule "cost required when qty>0; cost forbidden when qty empty/0".

- [ ] **Step 1: Failing test** (use `AssertsApiValidation`, envelope `{error:{errors}}`)

```php
public function test_opening_cost_required_when_qty_positive(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']); // project helper
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload(['opening_qty' => '10']));
    $this->assertValidationError($resp, 'opening_unit_cost');
}

public function test_opening_cost_forbidden_without_positive_qty(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload(['opening_unit_cost' => '5.000']));
    $this->assertValidationError($resp, 'opening_unit_cost');
}

public function test_opening_qty_over_scale_rejected(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload(['opening_qty' => '10.00001', 'opening_unit_cost' => '5.000']));
    $this->assertValidationError($resp, 'opening_qty');
}
```

- [ ] **Step 2: Run, verify it fails.**

Run: `cd apps/api && php artisan test --filter=CreateProductOpeningValidationTest tests/Feature/Product/CreateProductOpeningValidationTest.php`

- [ ] **Step 3: Add rules + conditional**

In `rules()`:

```php
'opening_qty'       => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
'opening_unit_cost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
```

Add a `withValidator(Validator $validator)` (or extend the existing one) closure:

```php
$validator->after(function ($validator): void {
    $qty = $this->input('opening_qty');
    $cost = $this->input('opening_unit_cost');
    $hasPositiveQty = $qty !== null && $qty !== '' && bccomp((string) $qty, '0', 4) > 0;

    if ($hasPositiveQty && ($cost === null || $cost === '')) {
        $validator->errors()->add('opening_unit_cost', __('validation.opening_cost_required_with_qty'));
    }
    if (! $hasPositiveQty && $cost !== null && $cost !== '') {
        $validator->errors()->add('opening_unit_cost', __('validation.opening_cost_without_qty'));
    }
});
```

> If `CreateProductRequest` already defines `withValidator`, add the `after` block inside it. Add the two translation keys to `apps/api/lang` (FR + EN). The currency-scale regex shown is `3`; if the resolver yields a different scale per currency, keep `3` (the storage floor) — the service re-canonicalizes anyway.

- [ ] **Step 4: Run, verify it passes; commit**

Run: `cd apps/api && php artisan test --filter=CreateProductOpeningValidationTest tests/Feature/Product/CreateProductOpeningValidationTest.php`

```bash
git add apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php apps/api/lang apps/api/tests/Feature/Product/CreateProductOpeningValidationTest.php
git commit -m "feat(product): opening_qty/opening_unit_cost validation (rule 19 + conditional)"
```

---

## Task 7: `ProductController::store` — transaction, authz, opening post

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:41-46,315-428`
- Test: `apps/api/tests/Feature/Product/CreateProductWithOpeningTest.php`

**Interfaces:**
- Consumes: `OpeningBalancePostingService::post`, `LocationContext::{getDefaultLocation,validateLocationAccess}`, `InventoryServiceInterface::{hasActiveOpening,hasDownstreamMovements}`, `OpeningStateData::from`, `CurrencyScaleResolverInterface`.

- [ ] **Step 1: Failing tests** — happy path, authz, physical guard, no-location, afterCommit rollback, response opening-state.

```php
public function test_create_with_opening_posts_one_movement_and_locks(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload([
        'is_physical' => true, 'opening_qty' => '10', 'opening_unit_cost' => '5.000',
    ]));
    $resp->assertCreated();
    $resp->assertJsonPath('data.opening.has_active_opening', true);
    $resp->assertJsonPath('data.opening.can_enter_opening', false);
    $productId = $resp->json('data.id');
    $this->assertSame(1, StockMovement::where('product_id', $productId)->where('movement_type', MovementType::Opening)->count());
}

public function test_create_with_opening_requires_inventory_adjust(): void
{
    $this->actingAsUserWith(['products.create']); // NO inventory.adjust
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload(['is_physical' => true, 'opening_qty' => '10', 'opening_unit_cost' => '5.000']));
    $resp->assertForbidden();
}

public function test_opening_rejected_on_service_product(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $resp = $this->postJson('/api/v1/products', $this->validProductPayload(['type' => 'service', 'is_physical' => false, 'opening_qty' => '10', 'opening_unit_cost' => '5.000']));
    $resp->assertStatus(422);
}

public function test_product_rolled_back_and_no_event_when_opening_fails(): void
{
    Event::fake([ProductCreated::class]);
    // Force failure: a user/location with no default location configured → expect 422 and zero products created.
}
```

- [ ] **Step 2: Run, verify they fail.**

Run: `cd apps/api && php artisan test --filter=CreateProductWithOpeningTest tests/Feature/Product/CreateProductWithOpeningTest.php`

- [ ] **Step 3: Wire the controller**

- Add to the constructor: `private readonly OpeningBalancePostingService $openingPosting, private readonly LocationContext $locationContext, private readonly InventoryServiceInterface $inventory, private readonly CurrencyScaleResolverInterface $scaleResolver,`.
- Pull `opening_qty` / `opening_unit_cost` out of `$validated` before `Product::create` (so they're not mass-assigned): `$openingQty = $validated['opening_qty'] ?? null; $openingCost = $validated['opening_unit_cost'] ?? null; unset($validated['opening_qty'], $validated['opening_unit_cost']);`
- Authz gate (before any write): if `$openingQty !== null && bccomp((string)$openingQty,'0',4) > 0` then `abort_unless($request->user()->can('inventory.adjust'), 403)`.
- Wrap the body in `DB::transaction(function () use (...) { ... });`. Inside: `Product::create`, metadata creation (unchanged), then the opening block:
  ```php
  if ($openingQty !== null && bccomp((string) $openingQty, '0', 4) > 0) {
      abort_unless($product->is_physical, 422, __('inventory.opening_requires_physical'));
      $location = $this->locationContext->getDefaultLocation($companyId);
      abort_if($location === null, 422, __('inventory.no_active_location'));
      $this->locationContext->validateLocationAccess($location->id, $companyId, $request->user());
      $scale = $this->scaleResolver->getScale($company->currency); // pass currency — no bare no-arg
      $this->openingPosting->post(new OpeningBalancePosting(
          tenantId: $tenantId, companyId: $companyId, userId: $request->user()->id,
          entryDate: now(), isHistorical: true,
          sourceType: 'opening_balance', sourceId: $product->id,
          reference: 'Opening balance: '.($product->sku ?? $product->id), notes: null,
          lines: [OpeningBalanceLine::make($product->id, null, $location->id, (string) $openingQty, (string) $openingCost, $scale)],
      ));
  }
  ```
- Move `event(new ProductCreated(...))` (and it alone — metadata creations stay in the txn) to fire on `DB::afterCommit()` so a rolled-back opening doesn't leak the event. (Wrap the `event(...)` call in `DB::afterCommit(fn () => event(...))`.)
- After the transaction, reload + build the response. Compute opening state: `$opening = OpeningStateData::from($this->inventory->hasActiveOpening($companyId, $product->id), $this->inventory->hasDownstreamMovements($companyId, $product->id));` and pass to `ProductData::fromModel($product, $media, $opening)`.

> Add the translation keys `inventory.opening_requires_physical`, `inventory.no_active_location` (FR+EN). Verify `Company` has a `currency` attribute for the scale resolver; if the accessor differs, use the project's standard way to get company currency.

- [ ] **Step 4: Run, verify they pass.**

Run: `cd apps/api && php artisan test --filter=CreateProductWithOpeningTest tests/Feature/Product/CreateProductWithOpeningTest.php`

- [ ] **Step 5: PHPStan + commit**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Presentation/Controllers/ProductController.php --level=8`

```bash
git add apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php apps/api/lang apps/api/tests/Feature/Product/CreateProductWithOpeningTest.php
git commit -m "feat(product): post inline opening balance on create (authz + atomic + afterCommit)"
```

---

## Task 8: Standalone `POST /products/{id}/opening` (re-entry)

**Files:**
- Create: `apps/api/app/Modules/Product/Presentation/Requests/PostOpeningBalanceRequest.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` (add `postOpening`)
- Modify: `apps/api/app/Modules/Product/routes.php`
- Test: `apps/api/tests/Feature/Product/PostOpeningEndpointTest.php`

**Interfaces:**
- Produces: `POST /api/v1/products/{product}/opening` gated `module:Inventory` + `can:inventory.adjust`; body `{opening_qty, opening_unit_cost}`; 201 with `ProductData` (opening-state). Rejects when `!can_enter_opening` (409 if downstream activity; `OpeningAlreadyExistsException` → 409/422 if active opening already present).

- [ ] **Step 1: Failing test** — eligible product accepts; with active opening → rejected; without `inventory.adjust` → 403.

```php
public function test_posts_opening_on_eligible_existing_product(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $product = $this->createPhysicalProductNoOpening();
    $resp = $this->postJson("/api/v1/products/{$product->id}/opening", ['opening_qty' => '7', 'opening_unit_cost' => '3.000']);
    $resp->assertCreated()->assertJsonPath('data.opening.has_active_opening', true);
}

public function test_rejects_when_active_opening_exists(): void
{
    $this->actingAsUserWith(['products.create', 'inventory.adjust']);
    $product = $this->createProductWithOpening();
    $resp = $this->postJson("/api/v1/products/{$product->id}/opening", ['opening_qty' => '7', 'opening_unit_cost' => '3.000']);
    $resp->assertStatus(409);
}

public function test_requires_inventory_adjust(): void
{
    $this->actingAsUserWith(['products.view']);
    $product = $this->createPhysicalProductNoOpening();
    $this->postJson("/api/v1/products/{$product->id}/opening", ['opening_qty' => '7', 'opening_unit_cost' => '3.000'])->assertForbidden();
}
```

- [ ] **Step 2: Run, verify it fails** (route 404 / method missing).

- [ ] **Step 3: Create the FormRequest** mirroring Task 6's two rules + the same conditional `withValidator`; `authorize()` returns `true` (route middleware gates).

- [ ] **Step 4: Add `postOpening` to the controller**

```php
public function postOpening(PostOpeningBalanceRequest $request, string $product): JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();
    $company = $this->companyContext->requireCompany();
    $model = Product::where('company_id', $companyId)->findOrFail($product);

    abort_unless($model->is_physical, 422, __('inventory.opening_requires_physical'));
    abort_if($this->inventory->hasDownstreamMovements($companyId, $model->id), 409, __('inventory.opening_locked_downstream'));

    $location = $this->locationContext->getDefaultLocation($companyId);
    abort_if($location === null, 422, __('inventory.no_active_location'));
    $this->locationContext->validateLocationAccess($location->id, $companyId, $request->user());

    $qty = (string) $request->validated()['opening_qty'];
    $cost = (string) $request->validated()['opening_unit_cost'];
    $scale = $this->scaleResolver->getScale($company->currency);

    try {
        $this->openingPosting->post(new OpeningBalancePosting(
            tenantId: $company->tenant_id, companyId: $companyId, userId: $request->user()->id,
            entryDate: now(), isHistorical: true, sourceType: 'opening_balance', sourceId: $model->id,
            reference: 'Opening balance: '.($model->sku ?? $model->id), notes: null,
            lines: [OpeningBalanceLine::make($model->id, null, $location->id, $qty, $cost, $scale)],
        ));
    } catch (OpeningAlreadyExistsException) {
        abort(409, __('inventory.opening_already_exists'));
    }

    $media = $this->catalogMedia->forProduct($model->id, $company->tenant_id);
    $opening = OpeningStateData::from($this->inventory->hasActiveOpening($companyId, $model->id), $this->inventory->hasDownstreamMovements($companyId, $model->id));

    return response()->json(['data' => ProductData::fromModel($model->fresh(), $media, $opening)], 201);
}
```

- [ ] **Step 5: Add the route** (in the `module:Inventory` group of `apps/api/app/Modules/Product/routes.php`, alongside the existing `products` routes):

```php
Route::post('products/{product}/opening', [ProductController::class, 'postOpening'])
    ->middleware('can:inventory.adjust')
    ->name('products.opening.post');
```

- [ ] **Step 6: Run, verify it passes; PHPStan; commit**

Run: `cd apps/api && php artisan test --filter=PostOpeningEndpointTest tests/Feature/Product/PostOpeningEndpointTest.php`

```bash
git add apps/api/app/Modules/Product/Presentation/Requests/PostOpeningBalanceRequest.php apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php apps/api/app/Modules/Product/routes.php apps/api/lang apps/api/tests/Feature/Product/PostOpeningEndpointTest.php
git commit -m "feat(product): POST /products/{id}/opening re-entry endpoint"
```

---

## Task 9: Reset-opening (reversal) endpoint

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php`
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` (add `resetOpening`)
- Modify: `apps/api/app/Modules/Product/routes.php`
- Test: `apps/api/tests/Feature/Inventory/ResetOpeningBalanceServiceTest.php` + `apps/api/tests/Feature/Product/ResetOpeningEndpointTest.php`

**Interfaces:**
- Produces: `ResetOpeningBalanceService::reset(string $companyId, string $tenantId, string $productId, string $userId): void` — under the product cost lock: guard `hasActiveOpening && !hasDownstreamMovements` (else throw a domain exception → 409); post a reversing `StockMovement` (`reverses_movement_id` = opening id, negated qty, stock level → 0) + a contra `JournalEntry` (`is_historical=true`, Dr OBE / Cr Inventory); clear `cost_price`+`cost_updated_at`; `afterCommit` dispatch `StockMovementRecorded`.
- Route: `POST /api/v1/products/{product}/opening/reset` gated `module:Inventory` + `can:inventory.adjust`.

**Reference:** read `apps/api/app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php` (from the merged write-off work) for the canonical reversal pattern (how it sets `reverses_movement_id`, posts the contra movement + GL, and dispatches events). Mirror it.

- [ ] **Step 1: Failing service test**

```php
public function test_reset_reverses_opening_and_allows_reentry(): void
{
    [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();
    app(OpeningBalancePostingService::class)->post(/* one line qty 10 cost 5 */);

    app(ResetOpeningBalanceService::class)->reset($company->id, $tenant->id, $product->id, $user->id);

    // original + reversal both present
    $this->assertSame(2, StockMovement::where('product_id', $product->id)->where('movement_type', MovementType::Opening)->count());
    $this->assertNotNull(StockMovement::where('product_id', $product->id)->whereNotNull('reverses_movement_id')->first());
    // stock zeroed, cost cleared, re-enterable
    $this->assertSame('0.0000', StockLevel::where('product_id', $product->id)->value('quantity'));
    $this->assertFalse(app(InventoryServiceInterface::class)->hasActiveOpening($company->id, $product->id));
}

public function test_reset_blocked_when_downstream_movement_exists(): void
{
    // post opening, then a non-opening movement; expect a domain exception.
}
```

- [ ] **Step 2: Run, verify it fails.**

- [ ] **Step 3: Implement `ResetOpeningBalanceService`** mirroring `ReverseWriteOffService`. Throw a domain exception (e.g. reuse/new `OpeningLockedException`) when the guard fails.

- [ ] **Step 4: Add `resetOpening` controller action + route**

```php
public function resetOpening(Request $request, string $product): JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();
    $company = $this->companyContext->requireCompany();
    $model = Product::where('company_id', $companyId)->findOrFail($product);

    try {
        $this->resetOpening->reset($companyId, $company->tenant_id, $model->id, $request->user()->id);
    } catch (OpeningLockedException) {
        abort(409, __('inventory.opening_locked_downstream'));
    }

    $media = $this->catalogMedia->forProduct($model->id, $company->tenant_id);
    $opening = OpeningStateData::from($this->inventory->hasActiveOpening($companyId, $model->id), $this->inventory->hasDownstreamMovements($companyId, $model->id));
    return response()->json(['data' => ProductData::fromModel($model->fresh(), $media, $opening)], 200);
}
```

Route:

```php
Route::post('products/{product}/opening/reset', [ProductController::class, 'resetOpening'])
    ->middleware('can:inventory.adjust')
    ->name('products.opening.reset');
```

Inject `ResetOpeningBalanceService $resetOpening` into the controller constructor.

- [ ] **Step 5: Endpoint test** (`ResetOpeningEndpointTest`): reset while sole opening → 200 + `can_enter_opening` true; reset after a downstream movement → 409; without `inventory.adjust` → 403.

- [ ] **Step 6: Run all Task-9 tests; PHPStan; commit**

Run: `cd apps/api && php artisan test --filter=ResetOpeningBalanceServiceTest tests/Feature/Inventory/ResetOpeningBalanceServiceTest.php` and `--filter=ResetOpeningEndpointTest tests/Feature/Product/ResetOpeningEndpointTest.php`

```bash
git add apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php apps/api/app/Modules/Product/routes.php apps/api/lang apps/api/tests/Feature/
git commit -m "feat(inventory): reset-opening via reversal (audit-preserving) + endpoint"
```

---

## Task 10: Frontend — opening section, state lock, reset/re-entry

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: i18n inventory namespace (FR + EN) — read `apps/web/src/i18n` / the inventory namespace files.
- Test: `apps/web/src/features/inventory/__tests__/ProductForm.opening.test.tsx`

**Interfaces:**
- Consumes: `ProductData.opening` (`OpeningStateData`) from Task 5's generated types; endpoints `POST /products` (create), `POST /products/{id}/opening` (re-entry), `POST /products/{id}/opening/reset`.

- [ ] **Step 1: Failing Vitest** — section hidden without `inventory.adjust`; on an existing product with `can_enter_opening===false`, inputs disabled; Reset action shown only when `has_active_opening && !has_downstream_movements`.

```tsx
// Mirror existing ProductForm tests for provider/permission mocking (vi.mock of the permission + i18n hooks).
it('hides opening section without inventory.adjust permission', () => {
  // render with hasPermission('inventory.adjust') === false → query opening qty input is null
});

it('locks opening inputs and shows Reset when product has sole active opening', () => {
  // render edit mode with opening: { has_active_opening: true, has_downstream_movements: false, can_enter_opening: false }
  // → inputs disabled, "Reset opening" button present
});
```

- [ ] **Step 2: Run, verify it fails.**

Run: `cd apps/web && pnpm test ProductForm.opening`

- [ ] **Step 3: Implement the section**

Read `ProductForm.tsx` first (defaultValues ~153-190, submit ~297-323). Then:
- Add `opening_qty` / `opening_unit_cost` to form state (strings, default `''`).
- Render an "Opening stock" section gated by `hasModule('Inventory') && hasPermission('inventory.adjust') && isPhysical`, shown when creating OR when `product?.opening?.can_enter_opening === true`. Use `<QuantityInput>` + `<MoneyInput>` (already imported). Label/help via `t('inventory:opening.cost_help')` etc. — clarify "valuation cost (WAC basis)". Tokens for colors.
- Cost shown required once qty>0; clear/disable cost when qty empty.
- On submit during create: include `opening_qty`/`opening_unit_cost` in the `POST /products` payload only when qty>0. On an existing eligible product, submit opening via `POST /products/{id}/opening`.
- When `product?.opening?.can_enter_opening === false`: render locked helper. If `has_active_opening && !has_downstream_movements`, render a **Reset opening** button → `POST /products/{id}/opening/reset` (confirm dialog), then refetch. Else link to `/inventory/stock`.

- [ ] **Step 4: Run, verify it passes.**

Run: `cd apps/web && pnpm test ProductForm.opening`

- [ ] **Step 5: Lint/typecheck + commit**

Run: `cd apps/web && pnpm typecheck && pnpm lint --max-warnings=0 src/features/inventory/ProductForm.tsx`

```bash
git add apps/web/src/features/inventory/ProductForm.tsx apps/web/src/i18n apps/web/src/features/inventory/__tests__/ProductForm.opening.test.tsx
git commit -m "feat(web): inline opening-balance section + state lock + reset/re-entry"
```

---

## Final: Pre-flight + reconcile

- [ ] **Step 1: Full pre-flight**

Run: `cd apps/api && ./vendor/bin/phpstan analyse --level=8` (new/changed paths) `&& ./vendor/bin/pint --test` ; `cd apps/web && pnpm typecheck && pnpm lint`. Run the by-path test set from Tasks 2-10 together. (Do NOT run the full PHPUnit suite.)

- [ ] **Step 2: Reconcile with origin/dev (rule 21)**

Run: `git fetch origin dev && git merge origin/dev` (resolve any conflicts), re-run the affected by-path tests.

- [ ] **Step 3: Decide integration** via `superpowers:finishing-a-development-branch` (PR vs merge to local dev). Do not force-push `dev`.

---

## Self-Review (coverage map)

| Spec § | Task |
|--------|------|
| §2.1 / §4.1-4.2 extracted service | 2, 3 |
| §2.2 `is_historical=true` exclusion | 2 (posting), 7/8 (entryDate today) |
| §2.3 `inventory.adjust` authz | 7, 8, 9 |
| §2.4 location (default-or-first-active + access) | 7, 8 |
| §2.5 / §4.1-step-3 in-lock enter-once | 2 |
| §2.6 / §8 reset via reversal | 9 |
| §2.7 shared hardening (events, afterCommit, numbering) | 2, 3, 7 |
| §5 create flow | 7 |
| §5.1 re-entry endpoint | 8 |
| §6 validation | 6 |
| §7 opening-state DTO + contract | 4, 5 |
| §9 frontend | 10 |
| §11 tests | each task |
| H3 physical guard | 7, 8 |
| H7 required cost | 6 |
| Med1 canonicalization | 1 |
| Med2 company scope | 4 |

Deferred (spec §14, NOT in this plan): `is_historical` column immutability, OBE close-out, location picker, as-of-date picker, full cost-revaluation flow, product-page adjustment action.

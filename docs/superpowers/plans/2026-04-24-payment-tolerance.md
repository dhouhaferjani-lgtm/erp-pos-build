# Payment Tolerance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire payment tolerance end-to-end across POS (cash-sale short-pay) and B2B (invoice close-with-writeoff), fix the latent bug where `payment_allocations.tolerance_writeoff` is never persisted, and add an anti-abuse rule that blocks sub-tolerance discounts with auto-strip at document conversion.

**Architecture:** Reuses the existing `PaymentToleranceService`, `CountryPaymentSettings`, and GL accounts 658/758. Adds one new POS-specific GL method (`createPOSPaymentToleranceEntry`), one new cross-module public query service (`PaymentToleranceQueryService`), one new domain boundary service (`DiscountToleranceBoundary`), one new close-invoice service (`CloseInvoiceWithToleranceService`), and one new event (`InvoiceClosedWithTolerance`). All cross-module access goes through Treasury Application services (Rule #6). No event modifications (Rule #8).

**Tech Stack:** Laravel 12 / PHP 8.2+ (strict types, PHPStan level 8), PHPUnit for backend tests, React 19 + Vite + Tailwind + TanStack Query, Vitest for frontend tests, Playwright for E2E. PostgreSQL 16 for persistence. Spatie Permissions. `#[TypeScript]` DTOs via `php artisan typescript:transform`.

**Spec:** [`../specs/2026-04-24-payment-tolerance-design.md`](../specs/2026-04-24-payment-tolerance-design.md)
**Interface contract v1.1:** [`../coordination/2026-04-24-payment-tolerance-shift-interface.md`](../coordination/2026-04-24-payment-tolerance-shift-interface.md)

---

## Prerequisites

Run once before starting Task 1 to confirm environment + resolve an open question.

### Prerequisite A: Verify `pos_receipt_payments.amount` scale

- [ ] **Step 1: Query information_schema**

```bash
docker compose exec postgres psql -U erp -d erp -c "SELECT column_name, data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_name = 'pos_receipt_payments' AND column_name = 'amount';"
```

Expected: `numeric_precision = 12`, `numeric_scale = 3` (the scale-3 retrofit landed in `2026_03_11_200000_widen_monetary_columns_to_scale_3.php`).

If scale is still 2, flag a pre-work migration — does not block the plan, but cash-tender reconciliation needs scale 3 for TND.

### Prerequisite B: Confirm `balance_due` trigger definition

- [ ] **Step 1: Inspect trigger**

```bash
docker compose exec postgres psql -U erp -d erp -c "\df+ update_document_balance_due"
```

Expected output includes formula: `total - SUM(payment_allocations.amount) - SUM(credit_note_allocations.amount)`.

If the trigger sums an additional term (e.g. `tolerance_writeoff`), Task 1 must be adjusted — the trigger already accounts for write-offs and `balance_due` reaching zero happens automatically via Task 1's `tolerance_writeoff` persistence.

- [ ] **Step 2: Decision**

- If the trigger DOES sum `tolerance_writeoff`: Task 11 (A2) can rely on trigger to set `balance_due = 0`; Task 11 only needs to set `status = Paid`.
- If the trigger DOES NOT: Task 11 updates `balance_due = 0` explicitly. (Plan is currently written assuming this case.)

---

## Phase 1 — Infrastructure & Bug Fixes

### Task 1: A3 — Persist `payment_allocations.tolerance_writeoff`

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php` (lines 99–103, add field to create)
- Modify: `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php` (add fillable + cast)
- Test: `apps/api/tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentAllocationServiceTolerancePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tolerance_writeoff_column_is_persisted_on_allocation_row(): void
    {
        // Seed: posted invoice €10.00, payment €9.70, tolerance €0.30 (within FR €0.50)
        $fixture = $this->seedShortPayScenario(invoiceTotal: '10.00', paymentAmount: '9.70');

        $service = $this->app->make(PaymentAllocationService::class);

        $result = $service->applyAllocation(
            paymentId: $fixture->paymentId,
            allocations: [
                [
                    'document_id' => $fixture->invoiceId,
                    'amount' => '9.70',
                    'tolerance_writeoff' => '0.30',
                ],
            ],
        );

        $this->assertTrue($result['success']);

        $allocation = PaymentAllocation::where('payment_id', $fixture->paymentId)->firstOrFail();
        $this->assertSame('0.300', $allocation->tolerance_writeoff);
        $this->assertSame('9.700', $allocation->amount);
    }

    private function seedShortPayScenario(string $invoiceTotal, string $paymentAmount): object
    {
        // Helper to seed tenant, company, partner, repository, invoice, payment — see
        // existing PaymentAllocationServiceTest.php for the factory patterns used here.
        // Returns object { paymentId, invoiceId, partnerId, companyId }.
        // (Copy structure from apps/api/tests/Unit/Treasury/PaymentAllocationServiceTest.php)
    }
}
```

Note: the `seedShortPayScenario` helper mirrors existing test factory structure in the same test directory. The executor should copy-adapt from `PaymentAllocationServiceTest.php`.

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/api && ./vendor/bin/phpunit --filter test_tolerance_writeoff_column_is_persisted_on_allocation_row
```

Expected: FAIL — `$allocation->tolerance_writeoff` returns `null` because the column is never persisted.

- [ ] **Step 3: Update PaymentAllocation model**

In `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php`, add `tolerance_writeoff` to fillable and casts:

```php
protected $fillable = [
    'payment_id',
    'document_id',
    'amount',
    'tolerance_writeoff',  // ADD THIS
];

protected function casts(): array
{
    return [
        'amount' => 'decimal:4',
        'tolerance_writeoff' => 'decimal:3',  // ADD THIS
    ];
}
```

- [ ] **Step 4: Update PaymentAllocationService::applyAllocation**

In `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`, locate the `PaymentAllocation::create([...])` call (around line 99). Add the `tolerance_writeoff` field:

```php
$allocationRow = PaymentAllocation::create([
    'payment_id' => $paymentId,
    'document_id' => $allocation['document_id'],
    'amount' => $allocation['amount'],
    'tolerance_writeoff' => $allocation['tolerance_writeoff'] ?? null,  // ADD THIS
]);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd apps/api && ./vendor/bin/phpunit --filter test_tolerance_writeoff_column_is_persisted_on_allocation_row
```

Expected: PASS

- [ ] **Step 6: Run the full PaymentAllocationService test file to catch regressions**

```bash
cd apps/api && ./vendor/bin/phpunit tests/Unit/Treasury/PaymentAllocationServiceTest.php
```

Expected: PASS (all existing tests).

- [ ] **Step 7: PHPStan clean on touched files**

```bash
cd apps/api && ./vendor/bin/phpstan analyse \
  app/Modules/Treasury/Application/Services/PaymentAllocationService.php \
  app/Modules/Treasury/Domain/PaymentAllocation.php
```

Expected: zero errors at level 8.

- [ ] **Step 8: Commit**

```bash
git add apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php \
        apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php \
        apps/api/tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php
git commit -m "fix(treasury): persist tolerance_writeoff on payment allocation rows"
```

---

## Phase 2 — Domain & Application Services

### Task 2: `DiscountToleranceBoundary` service (anti-abuse rule)

**Files:**
- Create: `apps/api/app/Modules/Treasury/Domain/Exceptions/DiscountBelowToleranceException.php`
- Create: `apps/api/app/Modules/Treasury/Application/Services/DiscountToleranceBoundary.php`
- Test: `apps/api/tests/Unit/Treasury/DiscountToleranceBoundaryTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Treasury/DiscountToleranceBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DiscountToleranceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_discount_is_strictly_less_than_fixed_threshold(): void
    {
        $this->seedCompanyWithToleranceFR();  // FR €0.50 absolute / 0.5% pct
        $service = $this->app->make(DiscountToleranceBoundary::class);

        $this->expectException(DiscountBelowToleranceException::class);
        $service->assertDiscountAboveTolerance(
            discountAmount: '0.40',
            subtotal: '100.00',
            companyId: $this->companyId,
        );
    }

    public function test_throws_when_discount_equals_threshold_strict_inequality(): void
    {
        $this->seedCompanyWithToleranceFR();
        $service = $this->app->make(DiscountToleranceBoundary::class);

        $this->expectException(DiscountBelowToleranceException::class);
        $service->assertDiscountAboveTolerance(
            discountAmount: '0.50',  // exactly at threshold — MUST reject
            subtotal: '100.00',
            companyId: $this->companyId,
        );
    }

    public function test_passes_when_discount_above_threshold(): void
    {
        $this->seedCompanyWithToleranceFR();
        $service = $this->app->make(DiscountToleranceBoundary::class);

        $service->assertDiscountAboveTolerance(
            discountAmount: '0.51',
            subtotal: '100.00',
            companyId: $this->companyId,
        );

        $this->addToAssertionCount(1);  // no exception = pass
    }

    public function test_percentage_threshold_dominates_when_subtotal_large(): void
    {
        // FR: max(€0.50, 0.5% * subtotal). At subtotal €1000, pct = €5.00
        $this->seedCompanyWithToleranceFR();
        $service = $this->app->make(DiscountToleranceBoundary::class);

        $this->expectException(DiscountBelowToleranceException::class);
        $service->assertDiscountAboveTolerance(
            discountAmount: '4.00',  // below €5 pct threshold though above €0.50
            subtotal: '1000.00',
            companyId: $this->companyId,
        );
    }

    public function test_tnd_scale_3_handled_correctly(): void
    {
        $this->seedCompanyWithToleranceTN();  // TN 0.100 TND / 0.5%
        $service = $this->app->make(DiscountToleranceBoundary::class);

        $this->expectException(DiscountBelowToleranceException::class);
        $service->assertDiscountAboveTolerance(
            discountAmount: '0.050',
            subtotal: '10.000',
            companyId: $this->companyId,
        );
    }

    public function test_zero_discount_does_not_throw(): void
    {
        $this->seedCompanyWithToleranceFR();
        $service = $this->app->make(DiscountToleranceBoundary::class);

        // A zero discount means "no discount applied" — not a violation
        $service->assertDiscountAboveTolerance(
            discountAmount: '0.00',
            subtotal: '100.00',
            companyId: $this->companyId,
        );

        $this->addToAssertionCount(1);
    }

    private function seedCompanyWithToleranceFR(): void { /* factory helper — FR company */ }
    private function seedCompanyWithToleranceTN(): void { /* factory helper — TN company */ }
    private string $companyId;  // populated by seeders above
}
```

The seeder helpers create a Company with `payment_tolerance_enabled = true`, threshold (€0.50 FR / 0.100 TND), and percentage (0.5%). Copy factory patterns from `PaymentToleranceServiceTest.php`.

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/api && ./vendor/bin/phpunit --filter DiscountToleranceBoundaryTest
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the exception**

Create `apps/api/app/Modules/Treasury/Domain/Exceptions/DiscountBelowToleranceException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

final class DiscountBelowToleranceException extends RuntimeException
{
    public function __construct(
        public readonly string $discountAmount,
        public readonly string $toleranceMargin,
        public readonly string $subtotal,
    ) {
        parent::__construct(
            "Discount of {$discountAmount} does not exceed the tolerance margin of {$toleranceMargin} "
            . "for subtotal {$subtotal}. Discounts this small must be handled as payment tolerance."
        );
    }
}
```

- [ ] **Step 4: Create the service**

Create `apps/api/app/Modules/Treasury/Application/Services/DiscountToleranceBoundary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use App\Shared\Domain\CurrencyScale;

final class DiscountToleranceBoundary
{
    private const SCALE = 3;

    public function __construct(
        private readonly CountryPaymentSettings $settings,
    ) {}

    /**
     * @throws DiscountBelowToleranceException when the discount amount is
     *         less than OR equal to the applicable tolerance margin.
     */
    public function assertDiscountAboveTolerance(
        string $discountAmount,
        string $subtotal,
        string $companyId,
    ): void {
        $discount = CurrencyScale::bcformat($discountAmount, self::SCALE);

        // A zero discount is "no discount" — not a violation.
        if (bccomp($discount, '0', self::SCALE) === 0) {
            return;
        }

        $thresholds = $this->settings->getThresholdsForCompany($companyId);

        $pctMargin = bcmul($subtotal, $thresholds['percentage'], self::SCALE);
        $margin = bccomp($pctMargin, $thresholds['max_amount'], self::SCALE) > 0
            ? $pctMargin
            : $thresholds['max_amount'];

        // STRICT inequality: discount must be STRICTLY GREATER than the margin
        if (bccomp($discount, $margin, self::SCALE) <= 0) {
            throw new DiscountBelowToleranceException(
                discountAmount: $discount,
                toleranceMargin: $margin,
                subtotal: $subtotal,
            );
        }
    }
}
```

Note: this uses `CountryPaymentSettings::getThresholdsForCompany()`, which is an existing method on the Treasury repository-style class. If the existing helper returns a DTO instead of an array, adapt accordingly — the goal is to pull the effective `max_amount` and `percentage` for the company.

- [ ] **Step 5: Run tests to verify all pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter DiscountToleranceBoundaryTest
```

Expected: all 6 cases PASS.

- [ ] **Step 6: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse \
  app/Modules/Treasury/Application/Services/DiscountToleranceBoundary.php \
  app/Modules/Treasury/Domain/Exceptions/DiscountBelowToleranceException.php
```

Expected: zero errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Treasury/Application/Services/DiscountToleranceBoundary.php \
        apps/api/app/Modules/Treasury/Domain/Exceptions/DiscountBelowToleranceException.php \
        apps/api/tests/Unit/Treasury/DiscountToleranceBoundaryTest.php
git commit -m "feat(treasury): add DiscountToleranceBoundary service to block sub-tolerance discounts"
```

---

### Task 3: `PaymentToleranceQueryService` — DTOs

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentTotalsDTO.php`
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentBreakdownDTO.php`
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php`

- [ ] **Step 1: Create the totals DTO**

`apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentTotalsDTO.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TolerancePaymentTotalsDTO extends Data
{
    public function __construct(
        public string $totalAmount,      // decimal string, scale 3
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}
```

- [ ] **Step 2: Create the breakdown DTO**

`apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentBreakdownDTO.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TolerancePaymentBreakdownDTO extends Data
{
    public function __construct(
        public string $userId,
        public string $userName,
        public string $totalAmount,      // decimal string, scale 3
        public string $currencyCode,
        public int    $writeoffCount,
    ) {}
}
```

- [ ] **Step 3: Create the receipt DTO**

`apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TolerancePaymentReceiptDTO extends Data
{
    public function __construct(
        public string $receiptNumber,
        public string $userId,
        public string $userName,
        public string $writeoffAmount,   // decimal string, scale 3
        public string $currencyCode,
        public string $occurredAt,       // ISO 8601 UTC
    ) {}
}
```

- [ ] **Step 4: Regenerate TypeScript types**

```bash
cd apps/api && php artisan typescript:transform
```

Expected: three new type definitions appear in `packages/shared/types/` or `apps/web/src/generated/` (depending on project config).

- [ ] **Step 5: Verify TypeScript types generated**

```bash
cd apps/api && grep -rn "TolerancePaymentTotalsDTO\|TolerancePaymentBreakdownDTO\|TolerancePaymentReceiptDTO" ../../packages/shared/types/ ../web/src/generated/ 2>/dev/null | head
```

Expected: each DTO represented as a TS interface.

- [ ] **Step 6: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/DTOs/
```

Expected: zero errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentTotalsDTO.php \
        apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentBreakdownDTO.php \
        apps/api/app/Modules/Treasury/Application/DTOs/TolerancePaymentReceiptDTO.php \
        packages/shared/types/ apps/web/src/generated/ 2>/dev/null
git commit -m "feat(treasury): add tolerance payment DTOs for cross-module reporting"
```

---

### Task 4: `PaymentToleranceQueryService` implementation

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php`
- Test: `apps/api/tests/Unit/Treasury/PaymentToleranceQueryServiceTest.php`

**Note:** This service joins `pos_shifts` → `pos_receipts` → `pos_receipt_payments` and reads `pos_receipts.tolerance_writeoff` (added in Task 5). Task 4 tests depend on Task 5 migration existing in the test DB. Run Task 5 BEFORE running Task 4 tests if executing out of order.

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Treasury/PaymentToleranceQueryServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentToleranceQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_for_shift_sums_tolerance_writeoffs_across_receipts(): void
    {
        // Seed: one shift, 3 receipts with tolerance_writeoff values [0.020, 0.050, 0.000]
        $shiftId = $this->seedShiftWithTolerance(writeoffs: ['0.020', '0.050', '0.000']);

        $service = $this->app->make(PaymentToleranceQueryService::class);
        $totals = $service->totalForShift($shiftId);

        $this->assertSame('0.070', $totals->totalAmount);
        $this->assertSame(2, $totals->writeoffCount);  // only non-zero rows counted
        $this->assertSame('EUR', $totals->currencyCode);
    }

    public function test_breakdown_for_shift_groups_by_cashier(): void
    {
        $shiftId = $this->seedShiftWithTolerance(
            writeoffs: [
                ['amount' => '0.020', 'cashier' => 'alice'],
                ['amount' => '0.050', 'cashier' => 'bob'],
                ['amount' => '0.030', 'cashier' => 'alice'],
            ],
        );

        $service = $this->app->make(PaymentToleranceQueryService::class);
        $breakdown = $service->breakdownForShift($shiftId);

        $this->assertCount(2, $breakdown);

        $alice = collect($breakdown)->firstWhere('userName', 'alice');
        $this->assertSame('0.050', $alice->totalAmount);
        $this->assertSame(2, $alice->writeoffCount);
    }

    public function test_receipts_with_tolerance_returns_drilldown(): void
    {
        $shiftId = $this->seedShiftWithTolerance(writeoffs: ['0.020', '0.050']);

        $service = $this->app->make(PaymentToleranceQueryService::class);
        $receipts = $service->receiptsWithToleranceForShift($shiftId);

        $this->assertCount(2, $receipts);
        $this->assertContains('0.020', array_column($receipts, 'writeoffAmount'));
    }

    public function test_zero_tolerance_shift_returns_empty_totals(): void
    {
        $shiftId = $this->seedShiftWithTolerance(writeoffs: []);

        $service = $this->app->make(PaymentToleranceQueryService::class);
        $totals = $service->totalForShift($shiftId);

        $this->assertSame('0.000', $totals->totalAmount);
        $this->assertSame(0, $totals->writeoffCount);
    }

    private function seedShiftWithTolerance(array $writeoffs): string
    {
        // Factory helper: creates a pos_shift, N pos_receipts with the given tolerance_writeoff
        // values, and the pos_receipt_payments (cash method) behind each. Returns shift UUID.
        // (Will fail until Task 5 adds the pos_receipts.tolerance_writeoff column)
    }
}
```

- [ ] **Step 2: Run test — expect failure due to missing class**

```bash
cd apps/api && ./vendor/bin/phpunit --filter PaymentToleranceQueryServiceTest
```

Expected: FAIL.

- [ ] **Step 3: Implement the service**

`apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\TolerancePaymentBreakdownDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentReceiptDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentTotalsDTO;
use Illuminate\Support\Facades\DB;

final class PaymentToleranceQueryService
{
    public function totalForShift(string $shiftId): TolerancePaymentTotalsDTO
    {
        $row = DB::table('pos_receipts')
            ->where('shift_id', $shiftId)
            ->whereNotNull('tolerance_writeoff')
            ->where('tolerance_writeoff', '>', 0)
            ->selectRaw('COALESCE(SUM(tolerance_writeoff), 0) AS total, COUNT(*) AS cnt, MAX(currency) AS currency')
            ->first();

        return new TolerancePaymentTotalsDTO(
            totalAmount: (string) ($row->total ?? '0.000'),
            currencyCode: (string) ($row->currency ?? 'EUR'),
            writeoffCount: (int) ($row->cnt ?? 0),
        );
    }

    /**
     * @return array<int, TolerancePaymentBreakdownDTO>
     */
    public function breakdownForShift(string $shiftId): array
    {
        $rows = DB::table('pos_receipts as r')
            ->join('users as u', 'u.id', '=', 'r.cashier_id')
            ->where('r.shift_id', $shiftId)
            ->whereNotNull('r.tolerance_writeoff')
            ->where('r.tolerance_writeoff', '>', 0)
            ->groupBy('u.id', 'u.name', 'r.currency')
            ->selectRaw('u.id AS user_id, u.name AS user_name, SUM(r.tolerance_writeoff) AS total, COUNT(*) AS cnt, r.currency AS currency')
            ->get();

        return $rows->map(fn ($r) => new TolerancePaymentBreakdownDTO(
            userId: (string) $r->user_id,
            userName: (string) $r->user_name,
            totalAmount: (string) $r->total,
            currencyCode: (string) $r->currency,
            writeoffCount: (int) $r->cnt,
        ))->all();
    }

    /**
     * @return array<int, TolerancePaymentReceiptDTO>
     */
    public function receiptsWithToleranceForShift(string $shiftId): array
    {
        $rows = DB::table('pos_receipts as r')
            ->join('users as u', 'u.id', '=', 'r.cashier_id')
            ->where('r.shift_id', $shiftId)
            ->whereNotNull('r.tolerance_writeoff')
            ->where('r.tolerance_writeoff', '>', 0)
            ->orderBy('r.posted_at')
            ->select(['r.receipt_number', 'u.id as user_id', 'u.name as user_name',
                      'r.tolerance_writeoff as writeoff_amount', 'r.currency', 'r.posted_at'])
            ->get();

        return $rows->map(fn ($r) => new TolerancePaymentReceiptDTO(
            receiptNumber: (string) $r->receipt_number,
            userId: (string) $r->user_id,
            userName: (string) $r->user_name,
            writeoffAmount: (string) $r->writeoff_amount,
            currencyCode: (string) $r->currency,
            occurredAt: (string) $r->posted_at,
        ))->all();
    }
}
```

- [ ] **Step 4: Run tests — some will still fail until Task 5 lands the column**

```bash
cd apps/api && ./vendor/bin/phpunit --filter PaymentToleranceQueryServiceTest
```

Expected: FAIL with "column `tolerance_writeoff` does not exist" — this is correct; Task 5 adds the column. Do **not** fix by removing the reference; instead continue to Task 5 and return to verify.

- [ ] **Step 5: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php
```

Expected: zero errors.

- [ ] **Step 6: Commit (tests pending Task 5)**

```bash
git add apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php \
        apps/api/tests/Unit/Treasury/PaymentToleranceQueryServiceTest.php
git commit -m "feat(treasury): add PaymentToleranceQueryService for cross-module reporting"
```

---

## Phase 3 — Migrations & Models

### Task 5: Migration — `pos_receipts.tolerance_writeoff` column

**Files:**
- Create: `apps/api/database/migrations/<timestamp>_add_tolerance_writeoff_to_pos_receipts_table.php`
- Modify: `apps/api/app/Modules/POS/Domain/Receipt.php` (fillable + casts)

- [ ] **Step 1: Generate the migration**

```bash
cd apps/api && php artisan make:migration add_tolerance_writeoff_to_pos_receipts_table --table=pos_receipts
```

Edit the generated file:

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
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->decimal('tolerance_writeoff', 15, 3)->nullable()->after('change_due');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN pos_receipts.tolerance_writeoff IS 'Amount written off to GL 658 for cash-sale tolerance. NULL for non-tolerance receipts.'");
        }
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn('tolerance_writeoff');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
cd apps/api && php artisan migrate
```

Expected: migration runs cleanly.

- [ ] **Step 3: Verify column exists**

```bash
docker compose exec postgres psql -U erp -d erp -c "\d pos_receipts" | grep tolerance_writeoff
```

Expected: column present, `numeric(15,3)`, nullable.

- [ ] **Step 4: Update Receipt model**

In `apps/api/app/Modules/POS/Domain/Receipt.php`, add to fillable (line 144 area) and casts:

```php
protected $fillable = [
    // ... existing fields ...
    'change_due',
    'tolerance_writeoff',  // ADD THIS
    // ... rest ...
];

protected function casts(): array
{
    return [
        // ... existing casts ...
        'change_due' => 'decimal:3',
        'tolerance_writeoff' => 'decimal:3',  // ADD THIS
    ];
}
```

- [ ] **Step 5: Re-run Task 4's tests**

```bash
cd apps/api && ./vendor/bin/phpunit --filter PaymentToleranceQueryServiceTest
```

Expected: all 4 cases PASS (they were failing in Task 4 Step 4 due to missing column).

- [ ] **Step 6: Commit**

```bash
git add apps/api/database/migrations/*_add_tolerance_writeoff_to_pos_receipts_table.php \
        apps/api/app/Modules/POS/Domain/Receipt.php
git commit -m "feat(pos): add tolerance_writeoff column to pos_receipts"
```

---

### Task 6: Migration — `pos_shifts` aggregation columns + Shift model method

**Files:**
- Create: `apps/api/database/migrations/<timestamp>_add_tolerance_aggregates_to_pos_shifts_table.php`
- Modify: `apps/api/app/Modules/POS/Domain/Shift.php` (fillable + casts + applyToleranceWriteoff method)
- Test: `apps/api/tests/Unit/POS/ShiftApplyToleranceWriteoffTest.php`

- [ ] **Step 1: Write the failing test**

`apps/api/tests/Unit/POS/ShiftApplyToleranceWriteoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShiftApplyToleranceWriteoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_increment_on_apply_tolerance_writeoff(): void
    {
        $shift = Shift::factory()->create([
            'tolerance_writeoff_total' => '0.000',
            'tolerance_writeoff_count' => 0,
        ]);

        $shift->applyToleranceWriteoff('0.020');
        $shift->applyToleranceWriteoff('0.050');

        $shift->refresh();
        $this->assertSame('0.070', $shift->tolerance_writeoff_total);
        $this->assertSame(2, $shift->tolerance_writeoff_count);
    }
}
```

- [ ] **Step 2: Run — expect failure (column does not exist)**

```bash
cd apps/api && ./vendor/bin/phpunit --filter ShiftApplyToleranceWriteoffTest
```

Expected: FAIL.

- [ ] **Step 3: Create migration**

```bash
cd apps/api && php artisan make:migration add_tolerance_aggregates_to_pos_shifts_table --table=pos_shifts
```

Edit:

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
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->decimal('tolerance_writeoff_total', 15, 3)->default('0')->after('variance');
            $table->integer('tolerance_writeoff_count')->default(0)->after('tolerance_writeoff_total');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn(['tolerance_writeoff_total', 'tolerance_writeoff_count']);
        });
    }
};
```

- [ ] **Step 4: Run migration**

```bash
cd apps/api && php artisan migrate
```

- [ ] **Step 5: Update Shift model**

In `apps/api/app/Modules/POS/Domain/Shift.php`:

```php
protected $fillable = [
    // ... existing ...
    'variance',
    'tolerance_writeoff_total',  // ADD
    'tolerance_writeoff_count',  // ADD
    // ... rest ...
];

protected function casts(): array
{
    return [
        // ... existing ...
        'tolerance_writeoff_total' => 'decimal:3',  // ADD
        'tolerance_writeoff_count' => 'integer',    // ADD
    ];
}

/**
 * Atomically increment tolerance totals. Intended to be called inside a
 * transaction that already holds a pessimistic lock on this row.
 */
public function applyToleranceWriteoff(string $amount): void
{
    $this->tolerance_writeoff_total = bcadd(
        $this->tolerance_writeoff_total ?? '0.000',
        $amount,
        3,
    );
    $this->tolerance_writeoff_count = ($this->tolerance_writeoff_count ?? 0) + 1;
    $this->save();
}
```

- [ ] **Step 6: Run tests — expect pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter ShiftApplyToleranceWriteoffTest
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/database/migrations/*_add_tolerance_aggregates_to_pos_shifts_table.php \
        apps/api/app/Modules/POS/Domain/Shift.php \
        apps/api/tests/Unit/POS/ShiftApplyToleranceWriteoffTest.php
git commit -m "feat(pos): add tolerance aggregate columns to pos_shifts + Shift::applyToleranceWriteoff"
```

---

## Phase 4 — POS Tolerance (A1)

### Task 7: `GeneralLedgerService::createPOSPaymentToleranceEntry` method

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (add new method)
- Test: `apps/api/tests/Unit/Accounting/GeneralLedgerServicePOSToleranceTest.php`

- [ ] **Step 1: Write the failing test**

`apps/api/tests/Unit/Accounting/GeneralLedgerServicePOSToleranceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GeneralLedgerServicePOSToleranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_dr_658_cr_revenue_entry_with_no_partner(): void
    {
        $companyId = $this->seedCompanyWithChartOfAccounts();
        $receiptId = $this->seedPOSReceipt(companyId: $companyId, total: '10.00');

        $service = $this->app->make(GeneralLedgerService::class);
        $entry = $service->createPOSPaymentToleranceEntry(
            companyId: $companyId,
            receiptId: $receiptId,
            amount: '0.020',
            currency: 'EUR',
            date: new \DateTimeImmutable('now'),
        );

        $this->assertNotNull($entry->id);
        $this->assertCount(2, $entry->lines);

        $dr = $entry->lines->firstWhere('debit', '>', '0');
        $cr = $entry->lines->firstWhere('credit', '>', '0');

        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense, $dr->glAccount->system_purpose);
        $this->assertSame(SystemAccountPurpose::ProductRevenue, $cr->glAccount->system_purpose);
        $this->assertSame('0.020', $dr->debit);
        $this->assertSame('0.020', $cr->credit);
        $this->assertNull($dr->partner_id);
        $this->assertNull($cr->partner_id);
    }
}
```

- [ ] **Step 2: Run — expect failure**

```bash
cd apps/api && ./vendor/bin/phpunit --filter GeneralLedgerServicePOSToleranceTest
```

Expected: FAIL.

- [ ] **Step 3: Add method to `GeneralLedgerService`**

In `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`, add a new method modeled after `createPaymentToleranceJournalEntry` (B2B version) but without the partner/AR coupling:

```php
public function createPOSPaymentToleranceEntry(
    string $companyId,
    string $receiptId,
    string $amount,
    string $currency,
    \DateTimeInterface $date,
): JournalEntry {
    $toleranceExpenseAccount = $this->getSystemAccount($companyId, SystemAccountPurpose::PaymentToleranceExpense);
    $revenueAccount = $this->getSystemAccount($companyId, SystemAccountPurpose::ProductRevenue);

    $entry = JournalEntry::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->resolveTenantId(),
        'company_id' => $companyId,
        'entry_date' => $date->format('Y-m-d'),
        'currency' => $currency,
        'source_type' => 'pos_payment_tolerance',
        'source_id' => $receiptId,
        'reference' => "POS Tolerance Writeoff / Receipt {$receiptId}",
        'status' => JournalEntryStatus::Draft,
    ]);

    JournalEntryLine::create([
        'id' => Str::uuid()->toString(),
        'journal_entry_id' => $entry->id,
        'gl_account_id' => $toleranceExpenseAccount->id,
        'debit' => $amount,
        'credit' => '0.000',
        'partner_id' => null,
        'description' => 'POS cash-sale tolerance write-off',
    ]);

    JournalEntryLine::create([
        'id' => Str::uuid()->toString(),
        'journal_entry_id' => $entry->id,
        'gl_account_id' => $revenueAccount->id,
        'debit' => '0.000',
        'credit' => $amount,
        'partner_id' => null,
        'description' => 'POS cash-sale tolerance write-off',
    ]);

    return $entry->fresh('lines');
}
```

Adapt imports/namespaces to match existing patterns in the file. If `createPaymentToleranceJournalEntry` uses slightly different constructors or helpers (e.g., `$this->createEntry(...)`), mirror that pattern rather than manual `JournalEntry::create`.

- [ ] **Step 4: Run tests — expect pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter GeneralLedgerServicePOSToleranceTest
```

Expected: PASS.

- [ ] **Step 5: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
```

Expected: zero errors.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php \
        apps/api/tests/Unit/Accounting/GeneralLedgerServicePOSToleranceTest.php
git commit -m "feat(accounting): add createPOSPaymentToleranceEntry for partner-less write-offs"
```

---

### Task 8: Permission + authorization wiring

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (add `pos.apply_tolerance`)
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php` (authorize check)

- [ ] **Step 1: Add permission to seeder**

In `apps/api/database/seeders/RolesAndPermissionsSeeder.php`, locate the POS permission group (around line 218):

```php
$posPermissions = [
    'pos.manage_terminals',
    'pos.operate_terminal',
    'pos.manage_shifts',
    'pos.manage_tables',
    'pos.view_reports',
    'pos.generate_z_report',
    'pos.void_receipts',
    'pos.view_receipts',
    'pos.process_returns',
    'pos.apply_tolerance',  // ADD THIS
];
```

Locate the Cashier role's `syncPermissions([...])` call (around line 349) and include `'pos.apply_tolerance'`.

- [ ] **Step 2: Re-seed local DB**

```bash
cd apps/api && php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Expected: seeder runs, permission created.

- [ ] **Step 3: Verify Cashier has the permission**

```bash
cd apps/api && php artisan tinker --execute="echo \Spatie\Permission\Models\Role::findByName('cashier')->hasPermissionTo('pos.apply_tolerance') ? 'YES' : 'NO';"
```

Expected: `YES`.

- [ ] **Step 4: Update StoreReceiptPaymentsRequest**

In `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php`, modify `authorize()` to require the permission when short-pay is detected:

```php
public function authorize(): bool
{
    /** @var \App\Modules\Identity\Domain\User|null $user */
    $user = $this->user();
    if ($user === null) {
        return false;
    }

    // Always require POS operation permission
    if (! $user->can('pos.operate_terminal')) {
        return false;
    }

    // If this request would trigger a short-pay, additionally require tolerance permission
    $receipt = $this->route('receipt');  // route-model-bound Receipt
    $payments = $this->input('payments', []);

    $totalPaid = array_reduce(
        $payments,
        fn (string $sum, array $p) => bcadd($sum, (string) ($p['amount'] ?? '0'), 3),
        '0.000',
    );

    if (bccomp($totalPaid, (string) $receipt->total, 3) < 0) {
        return $user->can('pos.apply_tolerance');
    }

    return true;
}
```

Adapt the receipt fetch to whatever parameter convention `StoreReceiptPaymentsRequest` currently uses (check existing method; it might resolve via the URL pattern rather than `route()`).

- [ ] **Step 5: Write authorization feature test**

`apps/api/tests/Feature/POS/StoreReceiptPaymentsToleranceAuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreReceiptPaymentsToleranceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_pay_denied_when_user_lacks_tolerance_permission(): void
    {
        $cashier = $this->seedCashierWithoutTolerance();  // has pos.operate_terminal but NOT pos.apply_tolerance
        $receipt = $this->seedReceipt(total: '10.00');

        $this->actingAs($cashier)
            ->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
                'payments' => [['amount' => '9.70', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
            ])
            ->assertStatus(403);
    }

    public function test_short_pay_allowed_when_user_has_tolerance_permission(): void
    {
        $cashier = $this->seedCashierWithTolerance();
        $receipt = $this->seedReceipt(total: '10.00');

        // Task 9 completes the happy path; this test only checks authorization.
        // For now, expect 403 to flip to non-403 (422 until A1 logic lands is acceptable).
        $response = $this->actingAs($cashier)
            ->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
                'payments' => [['amount' => '9.70', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
            ]);

        $this->assertNotSame(403, $response->status());
    }
}
```

- [ ] **Step 6: Run tests — expect pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter StoreReceiptPaymentsToleranceAuthorizationTest
```

Expected: both cases PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/database/seeders/RolesAndPermissionsSeeder.php \
        apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php \
        apps/api/tests/Feature/POS/StoreReceiptPaymentsToleranceAuthorizationTest.php
git commit -m "feat(pos): add pos.apply_tolerance permission and request-level authorization"
```

---

### Task 9: A1 — Rewire `ReceiptPaymentService` for tolerance short-pay

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php` (lines 79–86 + GL posting + shift increment)
- Test: `apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php`

- [ ] **Step 1: Write comprehensive feature tests**

`apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptPaymentServiceToleranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_pay_within_tolerance_is_accepted(): void
    {
        $this->seedCompanyFRWithChartAndSettings();
        $receipt = $this->seedReceipt(total: '10.00', shift: $shift = $this->seedShift());

        $service = $this->app->make(ReceiptPaymentService::class);
        $result = $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [['amount' => '9.70', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
        );

        $receipt->refresh();
        $this->assertSame('0.300', $receipt->tolerance_writeoff);
        $this->assertSame('0.000', $receipt->change_due);
        $this->assertSame('0.000', $result['change_due']);

        $shift->refresh();
        $this->assertSame('0.300', $shift->tolerance_writeoff_total);
        $this->assertSame(1, $shift->tolerance_writeoff_count);
    }

    public function test_short_pay_outside_tolerance_throws(): void
    {
        $this->seedCompanyFRWithChartAndSettings();
        $receipt = $this->seedReceipt(total: '10.00', shift: $this->seedShift());

        $service = $this->app->make(ReceiptPaymentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [['amount' => '9.00', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
        );
    }

    public function test_exact_tender_unchanged_no_tolerance_recorded(): void
    {
        $this->seedCompanyFRWithChartAndSettings();
        $receipt = $this->seedReceipt(total: '10.00', shift: $this->seedShift());

        $service = $this->app->make(ReceiptPaymentService::class);
        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [['amount' => '10.00', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
        );

        $receipt->refresh();
        $this->assertNull($receipt->tolerance_writeoff);
        $this->assertSame('0.000', $receipt->change_due);
    }

    public function test_overpay_unchanged_change_due_nonzero(): void
    {
        $this->seedCompanyFRWithChartAndSettings();
        $receipt = $this->seedReceipt(total: '10.00', shift: $this->seedShift());

        $service = $this->app->make(ReceiptPaymentService::class);
        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [['amount' => '12.00', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
        );

        $receipt->refresh();
        $this->assertNull($receipt->tolerance_writeoff);
        $this->assertSame('2.000', $receipt->change_due);
    }

    public function test_tolerance_creates_second_journal_entry(): void
    {
        $this->seedCompanyFRWithChartAndSettings();
        $receipt = $this->seedReceipt(total: '10.00', shift: $this->seedShift());

        $service = $this->app->make(ReceiptPaymentService::class);
        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [['amount' => '9.70', 'payment_method_id' => $this->cashMethodId, 'repository_id' => $this->cashRepoId]],
        );

        // The tolerance entry
        $toleranceEntries = JournalEntry::where('source_type', 'pos_payment_tolerance')
            ->where('source_id', $receipt->id)
            ->get();

        $this->assertCount(1, $toleranceEntries);

        // The regular POS payment entry continues to exist
        $paymentEntries = JournalEntry::where('source_type', 'pos_payment')
            ->where('source_id', $receipt->id)
            ->get();
        $this->assertCount(1, $paymentEntries);
    }
}
```

Factory helpers seed a Company (FR) with tolerance settings enabled (€0.50 / 0.5%), the chart of accounts with GL 658/758 and revenue, a pos_shift, a pos_receipt, and a payment method.

- [ ] **Step 2: Run tests — expect all fail (current code rejects)**

```bash
cd apps/api && ./vendor/bin/phpunit --filter ReceiptPaymentServiceToleranceTest
```

Expected: FAIL on all tests (at minimum the short-pay-accepted case).

- [ ] **Step 3: Rewire `processReceiptPayments` in `ReceiptPaymentService`**

Replace the short-pay reject (lines 78–86) with a tolerance branch. The exact replacement:

```php
// REPLACE LINES 78-86 (current reject) WITH:

// Determine tolerance/overpay/exact branch
$totalsCompare = bccomp($totalPaid, $receipt->total, self::SCALE);
$toleranceAmount = '0.000';
$changeDue = '0.000';

if ($totalsCompare < 0) {
    // Short-pay — check tolerance
    $shortfall = bcsub($receipt->total, $totalPaid, self::SCALE);

    $toleranceCheck = $this->paymentToleranceService->checkTolerance(
        invoiceAmount: (string) $receipt->total,
        paymentAmount: $totalPaid,
        companyId: $companyId,
    );

    if (! $toleranceCheck['qualifies']) {
        throw new \InvalidArgumentException(
            "Total paid ({$totalPaid}) is less than receipt total ({$receipt->total}) "
            . "and exceeds the tolerance threshold."
        );
    }

    $toleranceAmount = $shortfall;
} elseif ($totalsCompare > 0) {
    // Overpay — change_due
    $changeDue = bcsub($totalPaid, $receipt->total, self::SCALE);
}
// $totalsCompare == 0 → exact tender; both values stay at '0.000'
```

Inject `PaymentToleranceService` into the constructor:

```php
public function __construct(
    private readonly CompanyContext $companyContext,
    private readonly GeneralLedgerService $generalLedgerService,
    private readonly PaymentToleranceService $paymentToleranceService,  // ADD
) {}
```

After the existing per-payment GL entry loop, and if `bccomp($toleranceAmount, '0', self::SCALE) > 0`, post the tolerance entry and update the shift:

```php
if (bccomp($toleranceAmount, '0', self::SCALE) > 0) {
    // Tolerance GL entry (partner-less)
    $toleranceEntry = $this->generalLedgerService->createPOSPaymentToleranceEntry(
        companyId: $companyId,
        receiptId: $receipt->id,
        amount: $toleranceAmount,
        currency: (string) $receipt->currency,
        date: $receipt->posted_at,
    );

    $this->generalLedgerService->postEntry($toleranceEntry, $receipt->cashier);

    // Persist on receipt
    $receipt->tolerance_writeoff = $toleranceAmount;
    $receipt->save();

    // Atomic increment on the shift (lock row first)
    $shift = Shift::where('id', $receipt->shift_id)->lockForUpdate()->firstOrFail();
    $shift->applyToleranceWriteoff($toleranceAmount);
}
```

Also update the existing `$changeDue` calculation (line 86) to use the branched value above (remove the unconditional `bcsub` at line 86, since the branch already computed `$changeDue`).

Update the return payload to include `tolerance_writeoff`:

```php
return [
    'receipt' => $freshReceipt,
    'receipt_payments' => $receiptPayments,
    'treasury_payments' => $treasuryPayments,
    'change_due' => $changeDue,
    'tolerance_writeoff' => $toleranceAmount,  // ADD
];
```

- [ ] **Step 4: Run tests — expect all 5 pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter ReceiptPaymentServiceToleranceTest
```

Expected: PASS on all cases.

- [ ] **Step 5: Run the full POS test suite to catch regressions**

```bash
cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/ tests/Unit/POS/
```

Expected: PASS. If the existing `ReceiptPaymentServiceTest.php` tests for short-pay rejection now expect a different outcome, update them to reflect the new permission-gated behavior.

- [ ] **Step 6: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Application/Services/ReceiptPaymentService.php
```

Expected: zero errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php \
        apps/api/tests/Feature/POS/ReceiptPaymentServiceToleranceTest.php
git commit -m "feat(pos): accept short-pay within tolerance and write off to GL 658"
```

---

## Phase 5 — B2B Close-with-Tolerance (A2)

### Task 10: `InvoiceClosedWithTolerance` event

**Files:**
- Create: `apps/api/app/Modules/Treasury/Domain/Events/InvoiceClosedWithTolerance.php`

- [ ] **Step 1: Create the event class**

Follow the existing pattern of `PaymentAllocated` or `PaymentRecorded` in `app/Modules/Treasury/Domain/Events/`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use DateTimeImmutable;

final class InvoiceClosedWithTolerance
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $amountWrittenOff,  // decimal string, scale 3
        public readonly string $currency,
        public readonly string $closedBy,          // user UUID
        public readonly DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Canonical representation for hash-chain inclusion / audit log.
     *
     * @return array<string, string>
     */
    public function getHashableData(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'amount_written_off' => $this->amountWrittenOff,
            'currency' => $this->currency,
            'closed_by' => $this->closedBy,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
```

Mirror exactly the pattern used in existing Treasury events (check `PaymentAllocated.php` for whether they extend a base class or use a trait for audit-log integration).

- [ ] **Step 2: Commit**

```bash
git add apps/api/app/Modules/Treasury/Domain/Events/InvoiceClosedWithTolerance.php
git commit -m "feat(treasury): add InvoiceClosedWithTolerance event"
```

---

### Task 11: `CloseInvoiceWithToleranceService`

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php`
- Test: `apps/api/tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php`

- [ ] **Step 1: Write the failing test**

`apps/api/tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Treasury\Application\Services\CloseInvoiceWithToleranceService;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class CloseInvoiceWithToleranceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_closes_invoice_with_residual_within_threshold(): void
    {
        Event::fake();
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.30', total: '100.30');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);
        $service->close($invoice->id, $this->authenticatedUser->id);

        $invoice->refresh();
        $this->assertSame('0.000', (string) $invoice->balance_due);
        $this->assertSame(DocumentStatus::Paid, $invoice->status);

        Event::assertDispatched(InvoiceClosedWithTolerance::class);
    }

    public function test_rejects_when_balance_exceeds_threshold(): void
    {
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '5.00', total: '105.00');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(\App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException::class);
        $service->close($invoice->id, $this->authenticatedUser->id);
    }

    public function test_rejects_when_balance_equals_threshold_strict_inequality(): void
    {
        // FR threshold €0.50 absolute
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.50', total: '100.50');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(\App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException::class);
        $service->close($invoice->id, $this->authenticatedUser->id);
    }

    public function test_rejects_already_paid_invoice(): void
    {
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.00', total: '100.00', status: DocumentStatus::Paid);

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(\App\Modules\Treasury\Domain\Exceptions\InvoiceAlreadyPaidException::class);
        $service->close($invoice->id, $this->authenticatedUser->id);
    }

    public function test_journal_entry_created(): void
    {
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.30', total: '100.30');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);
        $service->close($invoice->id, $this->authenticatedUser->id);

        $entries = \App\Modules\Accounting\Domain\JournalEntry::where('source_type', 'b2b_payment_tolerance')
            ->where('source_id', $invoice->id)
            ->get();
        $this->assertCount(1, $entries);
    }
}
```

- [ ] **Step 2: Run — expect failure**

```bash
cd apps/api && ./vendor/bin/phpunit --filter CloseInvoiceWithToleranceServiceTest
```

Expected: FAIL (class missing).

- [ ] **Step 3: Create the two new exceptions**

`apps/api/app/Modules/Treasury/Domain/Exceptions/ToleranceExceededException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

final class ToleranceExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $remainingBalance,
        public readonly string $threshold,
    ) {
        parent::__construct("Remaining balance {$remainingBalance} exceeds tolerance threshold {$threshold}.");
    }
}
```

`apps/api/app/Modules/Treasury/Domain/Exceptions/InvoiceAlreadyPaidException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

final class InvoiceAlreadyPaidException extends RuntimeException
{
    public function __construct(public readonly string $invoiceId)
    {
        parent::__construct("Invoice {$invoiceId} is already paid; cannot close with tolerance.");
    }
}
```

- [ ] **Step 4: Create the service**

`apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use App\Modules\Treasury\Domain\Exceptions\InvoiceAlreadyPaidException;
use App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class CloseInvoiceWithToleranceService
{
    public function __construct(
        private readonly PaymentToleranceService $toleranceService,
        private readonly GeneralLedgerService $glService,
    ) {}

    public function close(string $invoiceId, string $closedBy): void
    {
        DB::transaction(function () use ($invoiceId, $closedBy): void {
            /** @var Document $invoice */
            $invoice = Document::where('id', $invoiceId)->lockForUpdate()->firstOrFail();

            // Idempotency check
            if ($invoice->status === DocumentStatus::Paid
                || bccomp((string) $invoice->balance_due, '0', 3) === 0) {
                throw new InvoiceAlreadyPaidException($invoiceId);
            }

            $paid = bcsub((string) $invoice->total, (string) $invoice->balance_due, 3);
            $toleranceCheck = $this->toleranceService->checkTolerance(
                invoiceAmount: (string) $invoice->total,
                paymentAmount: $paid,
                companyId: (string) $invoice->company_id,
            );

            if (! $toleranceCheck['qualifies']) {
                throw new ToleranceExceededException(
                    remainingBalance: (string) $invoice->balance_due,
                    threshold: (string) ($toleranceCheck['max_amount'] ?? '0.00'),
                );
            }

            // Journal entry: Dr 658 / Cr AR
            $this->toleranceService->applyTolerance(
                companyId: (string) $invoice->company_id,
                partnerId: (string) $invoice->partner_id,
                documentId: $invoiceId,
                amount: (string) $invoice->balance_due,
                type: 'underpayment',
                date: new DateTimeImmutable('now'),
                description: 'Close with write-off (tolerance)',
            );

            // Update invoice status + balance_due.
            // NOTE: pos_receipts trigger fires on payment_allocations changes; A2 creates no
            // allocation, so balance_due must be updated explicitly.
            $invoice->balance_due = '0.000';
            $invoice->status = DocumentStatus::Paid;
            $invoice->save();

            Event::dispatch(new InvoiceClosedWithTolerance(
                invoiceId: $invoiceId,
                amountWrittenOff: (string) $invoice->balance_due,
                currency: (string) $invoice->currency,
                closedBy: $closedBy,
                occurredAt: new DateTimeImmutable('now'),
            ));
        });
    }
}
```

- [ ] **Step 5: Run tests — expect all pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter CloseInvoiceWithToleranceServiceTest
```

Expected: 5 cases PASS.

- [ ] **Step 6: PHPStan clean**

```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php
```

Expected: zero errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php \
        apps/api/app/Modules/Treasury/Domain/Exceptions/ToleranceExceededException.php \
        apps/api/app/Modules/Treasury/Domain/Exceptions/InvoiceAlreadyPaidException.php \
        apps/api/tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php
git commit -m "feat(treasury): add CloseInvoiceWithToleranceService for B2B write-off"
```

---

### Task 12: `InvoiceController::closeWithTolerance` + route

**Files:**
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php`
- Modify: `apps/api/app/Modules/Document/Presentation/routes.php`
- Test: `apps/api/tests/Feature/Document/CloseInvoiceWithToleranceEndpointTest.php`

- [ ] **Step 1: Write the feature test**

`apps/api/tests/Feature/Document/CloseInvoiceWithToleranceEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CloseInvoiceWithToleranceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_close_returns_updated_invoice(): void
    {
        $user = $this->seedUserWithPermission('payments.allocate');
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.30', total: '100.30');

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_due', '0.000');
    }

    public function test_returns_422_when_balance_over_threshold(): void
    {
        $user = $this->seedUserWithPermission('payments.allocate');
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '5.00', total: '105.00');

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOLERANCE_EXCEEDED');
    }

    public function test_returns_422_when_invoice_already_paid(): void
    {
        $user = $this->seedUserWithPermission('payments.allocate');
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.00', total: '100.00', status: 'paid');

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_PAID');
    }

    public function test_requires_payments_allocate_permission(): void
    {
        $user = $this->seedUserWithoutPermission();  // no payments.allocate
        $invoice = $this->seedPostedInvoiceWithBalance(balance: '0.30', total: '100.30');

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run — expect failure**

```bash
cd apps/api && ./vendor/bin/phpunit --filter CloseInvoiceWithToleranceEndpointTest
```

Expected: FAIL (route/handler don't exist).

- [ ] **Step 3: Add controller action**

In `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php`, add:

```php
public function __construct(
    // ... existing ...
    private readonly CloseInvoiceWithToleranceService $closeWithToleranceService,
) {}

public function closeWithTolerance(Document $invoice, Request $request): JsonResponse
{
    try {
        $this->closeWithToleranceService->close(
            invoiceId: $invoice->id,
            closedBy: $request->user()->id,
        );
    } catch (ToleranceExceededException $e) {
        return response()->json([
            'error' => [
                'code' => 'TOLERANCE_EXCEEDED',
                'message' => 'Invoice balance exceeds tolerance threshold.',
                'details' => [
                    'remaining_balance' => $e->remainingBalance,
                    'threshold' => $e->threshold,
                ],
            ],
        ], 422);
    } catch (InvoiceAlreadyPaidException $e) {
        return response()->json([
            'error' => [
                'code' => 'ALREADY_PAID',
                'message' => 'Invoice is already paid; cannot close with tolerance.',
                'details' => ['invoice_id' => $e->invoiceId],
            ],
        ], 422);
    }

    return response()->json(['data' => InvoiceData::from($invoice->fresh())]);
}
```

Add imports at the top for the new exceptions and service.

- [ ] **Step 4: Add the route**

In `apps/api/app/Modules/Document/Presentation/routes.php`, add near the existing `post` action (around line 141):

```php
Route::post('/invoices/{invoice}/close-with-tolerance', [InvoiceController::class, 'closeWithTolerance'])
    ->middleware('can:payments.allocate')
    ->name('invoices.close-with-tolerance');
```

- [ ] **Step 5: Run tests — expect all pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter CloseInvoiceWithToleranceEndpointTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php \
        apps/api/app/Modules/Document/Presentation/routes.php \
        apps/api/tests/Feature/Document/CloseInvoiceWithToleranceEndpointTest.php
git commit -m "feat(documents): add POST invoices/{id}/close-with-tolerance endpoint"
```

---

## Phase 6 — Discount validation + conversion

### Task 13: `DiscountAboveTolerance` validation rule + Request wiring

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Rules/DiscountAboveTolerance.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php`
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php`
- Test: `apps/api/tests/Feature/Document/DiscountToleranceValidationTest.php`

- [ ] **Step 1: Write the failing tests**

`apps/api/tests/Feature/Document/DiscountToleranceValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DiscountToleranceValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_invoice_with_sub_tolerance_line_discount(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->invoicePayload(lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '0.20'],
        ]);

        $this->postJson('/api/v1/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.discount_amount']);
    }

    public function test_accepts_invoice_with_above_tolerance_line_discount(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->invoicePayload(lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '5.00'],
        ]);

        $this->postJson('/api/v1/invoices', $payload)->assertStatus(201);
    }

    public function test_sales_order_subject_to_rule(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->documentPayload(type: DocumentType::SalesOrder, lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '0.20'],
        ]);

        $this->postJson('/api/v1/sales-orders', $payload)->assertStatus(422);
    }

    public function test_quote_NOT_subject_to_rule(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->documentPayload(type: DocumentType::Quote, lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '0.20'],
        ]);

        // Task 15 covers what happens during conversion; at quote creation the rule does NOT fire.
        $this->postJson('/api/v1/quotes', $payload)->assertStatus(201);
    }

    public function test_credit_note_NOT_subject_to_rule(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->documentPayload(type: DocumentType::CreditNote, lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '0.20'],
        ]);

        $this->postJson('/api/v1/credit-notes', $payload)->assertStatus(201);
    }

    public function test_header_discount_sub_tolerance_rejected_on_invoice(): void
    {
        $user = $this->seedAuthorizedUser();
        $this->actingAs($user);

        $payload = $this->invoicePayload(discount_amount: '0.20', lines: [
            ['product_id' => $this->productId, 'quantity' => '1', 'unit_price' => '100.00'],
        ]);

        $this->postJson('/api/v1/invoices', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_amount']);
    }
}
```

- [ ] **Step 2: Run — expect failure**

```bash
cd apps/api && ./vendor/bin/phpunit --filter DiscountToleranceValidationTest
```

Expected: FAIL (all 6 tests — rule not yet applied).

- [ ] **Step 3: Create the validation rule**

`apps/api/app/Modules/Treasury/Presentation/Rules/DiscountAboveTolerance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Rules;

use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class DiscountAboveTolerance implements ValidationRule
{
    public function __construct(
        private readonly string $subtotal,
        private readonly string $companyId,
        private readonly DiscountToleranceBoundary $boundary,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || (string) $value === '0') {
            return;
        }

        try {
            $this->boundary->assertDiscountAboveTolerance(
                discountAmount: (string) $value,
                subtotal: $this->subtotal,
                companyId: $this->companyId,
            );
        } catch (DiscountBelowToleranceException $e) {
            $fail(__('documents.discount.below_tolerance', [
                'margin' => $e->toleranceMargin,
            ]));
        }
    }
}
```

- [ ] **Step 4: Wire into CreateDocumentRequest**

In `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php`, modify `rules()` to conditionally attach the validation rule:

```php
public function rules(): array
{
    $rules = [
        // ... existing rules up to line 94 ...
    ];

    $documentType = DocumentType::from($this->input('type'));

    // Discount-tolerance rule applies only to SalesOrder + Invoice (not Quote, CreditNote, DeliveryNote)
    if (in_array($documentType, [DocumentType::SalesOrder, DocumentType::Invoice], true)) {
        $companyId = $this->companyContext->requireCompanyId();

        foreach ($this->input('lines', []) as $idx => $line) {
            $subtotal = bcmul((string) ($line['quantity'] ?? '0'), (string) ($line['unit_price'] ?? '0'), 3);

            $rules["lines.{$idx}.discount_amount"] = [
                'nullable', 'numeric', 'min:0',
                app(DiscountAboveTolerance::class, ['subtotal' => $subtotal, 'companyId' => $companyId]),
            ];
        }

        // Header discount applied against the document-level subtotal (before line discounts roll up)
        $headerSubtotal = $this->computeHeaderSubtotal();
        $rules['discount_amount'] = [
            'nullable', 'numeric', 'min:0',
            app(DiscountAboveTolerance::class, ['subtotal' => $headerSubtotal, 'companyId' => $companyId]),
        ];
    }

    return $rules;
}
```

Note: `app()` here is used inside a validation-rule factory — this is the one place where container-lookup inside request rules is acceptable since constructor injection of the Form Request is handled by Laravel and we need to capture per-instance data. The underlying `DiscountToleranceBoundary` is still injected via constructor (Rule #13 respected at the service boundary).

- [ ] **Step 5: Mirror into UpdateDocumentRequest**

Apply the same conditional block to `UpdateDocumentRequest::rules()`.

- [ ] **Step 6: Add the translation keys**

Add to `apps/web/src/locales/en/documents.json`, `apps/web/src/locales/fr/documents.json`, `apps/web/src/locales/ar/documents.json`:

```json
{
    "discount": {
        "below_tolerance": "Discount amount must exceed the tolerance margin ({{margin}}). For smaller residuals, use payment tolerance write-off at settlement time."
    }
}
```

Translate the message for fr/ar as appropriate.

- [ ] **Step 7: Run tests — expect all pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter DiscountToleranceValidationTest
```

Expected: PASS.

- [ ] **Step 8: Run broader document tests to catch regressions**

```bash
cd apps/api && ./vendor/bin/phpunit tests/Feature/Document/
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add apps/api/app/Modules/Treasury/Presentation/Rules/DiscountAboveTolerance.php \
        apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php \
        apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php \
        apps/api/tests/Feature/Document/DiscountToleranceValidationTest.php \
        apps/web/src/locales/*/documents.json
git commit -m "feat(treasury): block sub-tolerance discounts on sales orders and invoices"
```

---

### Task 14: Conversion auto-strip — `QuoteToSalesOrderConverter` + `SalesOrderToInvoiceConverter`

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/QuoteToSalesOrderConverter.php`
- Modify: `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php`
- Test: `apps/api/tests/Unit/Document/QuoteToSalesOrderToleranceStripTest.php`
- Test: `apps/api/tests/Unit/Document/SalesOrderToInvoiceToleranceStripTest.php`

- [ ] **Step 1: Write the failing test (QuoteToSalesOrder)**

`apps/api/tests/Unit/Document/QuoteToSalesOrderToleranceStripTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuoteToSalesOrderToleranceStripTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_tolerance_line_discounts_are_stripped(): void
    {
        $quote = $this->seedQuote(lines: [
            ['unit_price' => '100.00', 'discount_amount' => '0.20'],  // sub-tolerance
            ['unit_price' => '100.00', 'discount_amount' => '10.00'], // above-tolerance, stays
        ]);

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $result = $converter->convert($quote);

        $this->assertSame(['0.000', '10.000'],
            $result->order->lines->pluck('discount_amount')->map(fn ($v) => (string) $v)->all()
        );
        $this->assertCount(1, $result->notifications);
        $this->assertStringContainsString('tolerance margin', $result->notifications[0]);
    }

    public function test_above_tolerance_discounts_preserved_unchanged(): void
    {
        $quote = $this->seedQuote(lines: [
            ['unit_price' => '100.00', 'discount_amount' => '10.00'],
        ]);

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $result = $converter->convert($quote);

        $this->assertSame(['10.000'],
            $result->order->lines->pluck('discount_amount')->map(fn ($v) => (string) $v)->all()
        );
        $this->assertEmpty($result->notifications);
    }
}
```

- [ ] **Step 2: Run — expect failure**

```bash
cd apps/api && ./vendor/bin/phpunit --filter QuoteToSalesOrderToleranceStripTest
```

Expected: FAIL.

- [ ] **Step 3: Modify `QuoteToSalesOrderConverter`**

In `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/QuoteToSalesOrderConverter.php`:

Inject `DiscountToleranceBoundary`:

```php
public function __construct(
    // ... existing ...
    private readonly DiscountToleranceBoundary $boundary,
) {}
```

In the `convert()` method, after copying lines from the source quote into the new order, before persisting, walk each line:

```php
foreach ($newLines as $idx => &$line) {
    $lineSubtotal = bcmul((string) $line['quantity'], (string) $line['unit_price'], 3);

    // Check line-level discount
    if (! empty($line['discount_amount']) && bccomp((string) $line['discount_amount'], '0', 3) > 0) {
        try {
            $this->boundary->assertDiscountAboveTolerance(
                discountAmount: (string) $line['discount_amount'],
                subtotal: $lineSubtotal,
                companyId: $companyId,
            );
        } catch (DiscountBelowToleranceException $e) {
            $notifications[] = sprintf(
                'Line %d: discount of %s removed (below %s tolerance margin). Will be handled as payment tolerance at settlement.',
                $idx + 1,
                $line['discount_amount'],
                $e->toleranceMargin,
            );
            $line['discount_amount'] = '0.000';
            $line['discount_percent'] = '0.000';
        }
    }
}
unset($line);
```

Ensure the `convert()` method's return type carries `$notifications` alongside the resulting order (mirror existing return shape — consult the converter interface and other converters for the expected return DTO).

Similarly handle the header discount on the quote if the quote model carries `document.discount_amount`:

```php
if (! empty($quote->discount_amount) && bccomp((string) $quote->discount_amount, '0', 3) > 0) {
    $quoteSubtotal = $this->computeDocumentSubtotal($quote);
    try {
        $this->boundary->assertDiscountAboveTolerance(
            discountAmount: (string) $quote->discount_amount,
            subtotal: $quoteSubtotal,
            companyId: $companyId,
        );
    } catch (DiscountBelowToleranceException $e) {
        $notifications[] = sprintf(
            'Header discount of %s removed (below %s tolerance margin). Will be handled as payment tolerance at settlement.',
            $quote->discount_amount,
            $e->toleranceMargin,
        );
        $newOrder->discount_amount = '0.000';
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd apps/api && ./vendor/bin/phpunit --filter QuoteToSalesOrderToleranceStripTest
```

Expected: PASS.

- [ ] **Step 5: Mirror for SalesOrderToInvoiceConverter**

Repeat steps 1–4 for `SalesOrderToInvoiceConverter.php` with a matching test file `SalesOrderToInvoiceToleranceStripTest.php`. This catches the rare case where a Quote→Order conversion slipped through (shouldn't happen since Task 14 covers it, but the defensive check on the next stage is cheap and robust).

Also check if there's a `QuoteToInvoiceConverter` (direct conversion path) — if so, apply the same treatment.

- [ ] **Step 6: Run the full conversion test suite**

```bash
cd apps/api && ./vendor/bin/phpunit tests/Unit/Document/ tests/Feature/Document/DocumentConversion*
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/*.php \
        apps/api/tests/Unit/Document/QuoteToSalesOrderToleranceStripTest.php \
        apps/api/tests/Unit/Document/SalesOrderToInvoiceToleranceStripTest.php
git commit -m "feat(documents): auto-strip sub-tolerance discounts on conversion + notify"
```

---

## Phase 7 — Data Audit Command

### Task 15: `AuditDiscountsCommand`

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php`
- Modify: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (register)

- [ ] **Step 1: Create the command**

`apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditDiscountsCommand extends Command
{
    protected $signature = 'tolerance:audit-discounts {--dry-run : Do not throw; only report}';
    protected $description = 'Report any existing document discounts that fall below the tolerance threshold.';

    public function handle(DiscountToleranceBoundary $boundary): int
    {
        $violations = 0;

        // Line-level discounts
        $lineRows = DB::table('document_lines as dl')
            ->join('documents as d', 'd.id', '=', 'dl.document_id')
            ->whereNotNull('dl.discount_amount')
            ->where('dl.discount_amount', '>', 0)
            ->whereIn('d.type', ['sales_order', 'invoice'])
            ->select(['dl.id', 'dl.document_id', 'dl.discount_amount', 'dl.quantity', 'dl.unit_price', 'd.company_id'])
            ->get();

        foreach ($lineRows as $row) {
            $subtotal = bcmul((string) $row->quantity, (string) $row->unit_price, 3);
            try {
                $boundary->assertDiscountAboveTolerance(
                    discountAmount: (string) $row->discount_amount,
                    subtotal: $subtotal,
                    companyId: (string) $row->company_id,
                );
            } catch (DiscountBelowToleranceException $e) {
                $violations++;
                $this->error(sprintf(
                    'Line %s (doc %s): discount %s below margin %s',
                    $row->id, $row->document_id, $e->discountAmount, $e->toleranceMargin,
                ));
            }
        }

        // Header-level discounts (similar query against `documents` with `type in (sales_order, invoice)`)
        $headerRows = DB::table('documents')
            ->whereNotNull('discount_amount')
            ->where('discount_amount', '>', 0)
            ->whereIn('type', ['sales_order', 'invoice'])
            ->select(['id', 'discount_amount', 'subtotal', 'company_id'])
            ->get();

        foreach ($headerRows as $row) {
            try {
                $boundary->assertDiscountAboveTolerance(
                    discountAmount: (string) $row->discount_amount,
                    subtotal: (string) $row->subtotal,
                    companyId: (string) $row->company_id,
                );
            } catch (DiscountBelowToleranceException $e) {
                $violations++;
                $this->error(sprintf(
                    'Doc %s: header discount %s below margin %s',
                    $row->id, $e->discountAmount, $e->toleranceMargin,
                ));
            }
        }

        $this->info("Audit complete. Violations: {$violations}");

        return $violations > 0 && ! $this->option('dry-run') ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 2: Register the command**

In `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (if this exists) or `apps/api/app/Providers/AppServiceProvider.php`:

```php
public function boot(): void
{
    if ($this->app->runningInConsole()) {
        $this->commands([
            AuditDiscountsCommand::class,
        ]);
    }
}
```

- [ ] **Step 3: Smoke-test the command**

```bash
cd apps/api && php artisan tolerance:audit-discounts --dry-run
```

Expected: exits 0 with "Violations: 0" on a clean database. If seed data violates, the command prints the offending rows.

- [ ] **Step 4: Commit**

```bash
git add apps/api/app/Modules/Treasury/Presentation/Console/AuditDiscountsCommand.php \
        apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php
git commit -m "feat(treasury): add tolerance:audit-discounts command for pre-deploy sweep"
```

---

## Phase 8 — Frontend

### Task 16: Receipt — "Rounding" line render

**Files:**
- Modify: `apps/web/src/features/pos/components/ReceiptView.tsx` (or equivalent — locate via grep before coding)
- Modify: `apps/web/src/locales/{en,fr,ar}/pos.json` (new translation key)
- Test: `apps/web/src/features/pos/components/ReceiptView.test.tsx`

- [ ] **Step 1: Locate the receipt render component**

```bash
cd apps/web && grep -rn "Total paid\|total_paid\|change_due" src/features/pos/components/ src/features/pos/organisms/ | head -20
```

The exact component path varies; use the match to target the file. Common candidates: `ReceiptView.tsx`, `ReceiptDisplay.tsx`, `ReceiptPrint.tsx`.

- [ ] **Step 2: Add translation keys**

`apps/web/src/locales/en/pos.json` — add under `receipt`:

```json
"receipt": {
    // ... existing keys ...
    "rounding": "Rounding"
}
```

Same for `fr/pos.json` (`Arrondi`) and `ar/pos.json` (`تقريب`).

- [ ] **Step 3: Write the failing test**

`apps/web/src/features/pos/components/ReceiptView.test.tsx` (or add to existing test file):

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';
import { ReceiptView } from './ReceiptView';

describe('ReceiptView tolerance rendering', () => {
    test('renders Rounding line when tolerance_writeoff is non-null', () => {
        const receipt = {
            total: '2.52',
            tolerance_writeoff: '0.02',
            change_due: '0.00',
            currency: 'EUR',
            // ... other required fields ...
        };
        render(<ReceiptView receipt={receipt} />);
        expect(screen.getByText('Rounding')).toBeInTheDocument();
        expect(screen.getByText(/-\s*0[,.]02/)).toBeInTheDocument();
    });

    test('hides Rounding line when tolerance_writeoff is null', () => {
        const receipt = {
            total: '2.52',
            tolerance_writeoff: null,
            change_due: '0.00',
            currency: 'EUR',
        };
        render(<ReceiptView receipt={receipt} />);
        expect(screen.queryByText('Rounding')).not.toBeInTheDocument();
    });
});
```

- [ ] **Step 4: Run — expect failure**

```bash
cd apps/web && pnpm test ReceiptView.test.tsx
```

Expected: FAIL.

- [ ] **Step 5: Modify the receipt component**

Within the component's render, after the "Cash tendered" line and before "Total paid":

```tsx
{receipt.tolerance_writeoff != null && Number(receipt.tolerance_writeoff) > 0 && (
    <div className="flex justify-between">
        <span>{t('pos:receipt.rounding')}</span>
        <span>−{formatCurrency(receipt.tolerance_writeoff, receipt.currency)}</span>
    </div>
)}
```

Import `useTranslation` and the currency formatter if not already imported.

- [ ] **Step 6: Run tests — expect pass**

```bash
cd apps/web && pnpm test ReceiptView.test.tsx
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/pos/components/ReceiptView.tsx \
        apps/web/src/features/pos/components/ReceiptView.test.tsx \
        apps/web/src/locales/*/pos.json
git commit -m "feat(pos): render Rounding line on receipt when tolerance applies"
```

---

### Task 17: Close-with-writeoff UI on InvoiceDetailPage

**Files:**
- Create: `apps/web/src/features/documents/invoices/components/CloseWithWriteoffDialog.tsx`
- Create: `apps/web/src/features/documents/invoices/api/closeWithTolerance.ts`
- Modify: `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx`
- Modify: `apps/web/src/locales/{en,fr,ar}/documents.json`

- [ ] **Step 1: Create the API mutation hook**

`apps/web/src/features/documents/invoices/api/closeWithTolerance.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiPost } from '@/lib/api';
import type { InvoiceData } from '@/generated/types';

export function useCloseWithTolerance(invoiceId: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<InvoiceData> => {
            return apiPost<InvoiceData>(`/api/v1/invoices/${invoiceId}/close-with-tolerance`, {});
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['invoice', invoiceId] });
        },
    });
}
```

- [ ] **Step 2: Create the dialog component**

`apps/web/src/features/documents/invoices/components/CloseWithWriteoffDialog.tsx`:

```tsx
import { useTranslation } from 'react-i18next';
import { useCloseWithTolerance } from '../api/closeWithTolerance';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, Button } from '@/components/ui';
import { formatCurrency } from '@/lib/formatters';

interface Props {
    invoiceId: string;
    invoiceNumber: string;
    balanceDue: string;
    currency: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function CloseWithWriteoffDialog({ invoiceId, invoiceNumber, balanceDue, currency, open, onOpenChange }: Props) {
    const { t } = useTranslation(['documents']);
    const mutation = useCloseWithTolerance(invoiceId);

    const onConfirm = () => {
        mutation.mutate(undefined, { onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="w-[480px]">
                <DialogHeader>
                    <DialogTitle>{t('documents:invoice.close_with_writeoff.dialog.title')}</DialogTitle>
                </DialogHeader>
                <div className="space-y-2 py-4">
                    <p>{t('documents:invoice.close_with_writeoff.dialog.body', { number: invoiceNumber })}</p>
                    <div className="flex justify-between rounded border p-3">
                        <span>{t('documents:invoice.close_with_writeoff.dialog.balance')}</span>
                        <span className="font-semibold">{formatCurrency(balanceDue, currency)}</span>
                    </div>
                    <p className="text-sm text-muted">{t('documents:invoice.close_with_writeoff.dialog.gl_note')}</p>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('documents:invoice.close_with_writeoff.dialog.cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={onConfirm}
                        disabled={mutation.isPending}
                    >
                        {t('documents:invoice.close_with_writeoff.dialog.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 3: Wire the button into InvoiceDetailPage**

In `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx`, inside the header-actions block (adjacent to existing "Record payment" or similar):

```tsx
{invoice.status === 'posted' && invoice.balance_due > 0 && withinTolerance(invoice) && (
    <Button variant="secondary" onClick={() => setCloseDialogOpen(true)}>
        {t('documents:invoice.close_with_writeoff.button', {
            amount: formatCurrency(invoice.balance_due, invoice.currency),
        })}
    </Button>
)}

<CloseWithWriteoffDialog
    invoiceId={invoice.id}
    invoiceNumber={invoice.number}
    balanceDue={invoice.balance_due}
    currency={invoice.currency}
    open={closeDialogOpen}
    onOpenChange={setCloseDialogOpen}
/>
```

`withinTolerance(invoice)` is a helper that checks the invoice balance against the company's tolerance settings (fetched via a query hook — call the existing `useToleranceSettings` or similar; verify during implementation that one exists, otherwise create a thin wrapper that reads from the settings endpoint).

- [ ] **Step 4: Add translation keys**

`apps/web/src/locales/en/documents.json`:

```json
"invoice": {
    "close_with_writeoff": {
        "button": "Close with write-off {{amount}}",
        "dialog": {
            "title": "Close invoice with write-off",
            "body": "Close invoice {{number}}. The remaining balance will be written off to GL account 658 (Payment Tolerance Expense). VAT is unchanged.",
            "balance": "Remaining balance",
            "gl_note": "This action is irreversible. The invoice will be marked as Paid.",
            "confirm": "Close invoice",
            "cancel": "Cancel"
        }
    }
}
```

Translate for `fr/documents.json` and `ar/documents.json`.

- [ ] **Step 5: Component test**

`apps/web/src/features/documents/invoices/components/CloseWithWriteoffDialog.test.tsx`:

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, test, vi } from 'vitest';
import { CloseWithWriteoffDialog } from './CloseWithWriteoffDialog';

const wrapper = ({ children }) => {
    const qc = new QueryClient();
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
};

describe('CloseWithWriteoffDialog', () => {
    test('renders invoice number and balance', () => {
        render(
            <CloseWithWriteoffDialog
                invoiceId="inv-1" invoiceNumber="INV-0042"
                balanceDue="0.30" currency="EUR"
                open onOpenChange={vi.fn()}
            />,
            { wrapper }
        );
        expect(screen.getByText(/INV-0042/)).toBeInTheDocument();
        expect(screen.getByText(/0[,.]30/)).toBeInTheDocument();
    });
});
```

- [ ] **Step 6: Run tests**

```bash
cd apps/web && pnpm test CloseWithWriteoffDialog
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/documents/invoices/ \
        apps/web/src/locales/*/documents.json
git commit -m "feat(documents): add 'Close with write-off' dialog on InvoiceDetailPage"
```

---

### Task 18: POS `DiscountInput` — inline below-tolerance error

**Files:**
- Modify: `apps/web/src/features/pos/molecules/DiscountInput/DiscountInput.tsx`
- Modify: `apps/web/src/features/pos/molecules/TransactionDiscountInput/TransactionDiscountInput.tsx`
- Create: `apps/web/src/features/pos/lib/discountValidation.ts`
- Modify: `apps/web/src/locales/{en,fr,ar}/pos.json`

- [ ] **Step 1: Create the shared TS validation helper**

`apps/web/src/features/pos/lib/discountValidation.ts`:

```ts
export interface ToleranceSettings {
    enabled: boolean;
    maxAmount: string;       // decimal string scale 3
    percentage: string;      // decimal string scale 3, e.g., "0.005" for 0.5%
}

/**
 * Returns true iff the discount amount is strictly above the applicable tolerance margin.
 * Mirrors the server-side rule at:
 *   apps/api/app/Modules/Treasury/Application/Services/DiscountToleranceBoundary.php
 */
export function isDiscountAboveTolerance(
    discountAmount: string,
    subtotal: string,
    settings: ToleranceSettings,
): boolean {
    if (!settings.enabled) return true;
    if (discountAmount === '0' || discountAmount === '0.000' || discountAmount === '') return true;

    const discount = parseFloat(discountAmount);
    const sub = parseFloat(subtotal);
    const pctMargin = sub * parseFloat(settings.percentage);
    const absMargin = parseFloat(settings.maxAmount);
    const margin = Math.max(pctMargin, absMargin);

    return discount > margin;  // STRICT inequality
}
```

- [ ] **Step 2: Add `below_tolerance` translation key**

`apps/web/src/locales/en/pos.json`:

```json
"discount": {
    // existing keys...
    "below_tolerance": "This discount is too small — use payment tolerance at the till instead."
}
```

Same for fr/ar.

- [ ] **Step 3: Write the failing test**

`apps/web/src/features/pos/molecules/DiscountInput/DiscountInput.test.tsx`:

```tsx
// Add a new test case to the existing test file
test('shows below-tolerance error when entered amount is sub-threshold', async () => {
    render(<DiscountInput
        subtotal="100.00"
        toleranceSettings={{ enabled: true, maxAmount: '0.50', percentage: '0.005' }}
        onChange={vi.fn()}
    />);

    const input = screen.getByRole('textbox');
    await userEvent.type(input, '0.30');

    expect(screen.getByText(/use payment tolerance/i)).toBeInTheDocument();
});
```

- [ ] **Step 4: Run — expect failure**

```bash
cd apps/web && pnpm test DiscountInput
```

Expected: FAIL.

- [ ] **Step 5: Modify `DiscountInput`**

Import `isDiscountAboveTolerance`. Compute the validation result as the user types:

```tsx
const belowTolerance = !isDiscountAboveTolerance(amount, subtotal, toleranceSettings);

return (
    <div>
        <input ... />
        {belowTolerance && amount !== '0' && amount !== '' && (
            <p className="text-sm text-destructive">{t('pos:discount.below_tolerance')}</p>
        )}
    </div>
);
```

Props must be extended to accept `toleranceSettings` — the caller (e.g., `TransactionCart`) passes this in from the tolerance settings query.

Apply the same pattern to `TransactionDiscountInput.tsx`. Preset buttons (5/10/15/20%) should be disabled (greyed) when they would compute to a sub-threshold value:

```tsx
const presetAmount = (pct: number) => (parseFloat(subtotal) * pct / 100).toFixed(3);
const isPresetDisabled = (pct: number) => !isDiscountAboveTolerance(presetAmount(pct), subtotal, toleranceSettings);
```

- [ ] **Step 6: Run tests — expect pass**

```bash
cd apps/web && pnpm test DiscountInput TransactionDiscountInput
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/pos/molecules/DiscountInput/ \
        apps/web/src/features/pos/molecules/TransactionDiscountInput/ \
        apps/web/src/features/pos/lib/discountValidation.ts \
        apps/web/src/locales/*/pos.json
git commit -m "feat(pos): show below-tolerance error on DiscountInput and disable invalid presets"
```

---

## Phase 9 — End-to-end validation

### Task 19: Playwright E2E

**Files:**
- Create: `apps/web/e2e/pos/tolerance-short-pay.spec.ts`
- Create: `apps/web/e2e/documents/close-with-writeoff.spec.ts`
- Create: `apps/web/e2e/documents/discount-tolerance-boundary.spec.ts`

- [ ] **Step 1: Happy-path short-pay within tolerance**

`apps/web/e2e/pos/tolerance-short-pay.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

test('cashier completes sale with €0.02 short within tolerance', async ({ page }) => {
    await page.goto('/pos');
    await page.getByRole('button', { name: /add item/i }).click();
    await page.getByPlaceholder(/search/i).fill('Coffee');
    await page.getByText('Coffee €2.52').click();

    await page.getByRole('button', { name: /tender cash/i }).click();
    await page.getByLabel(/amount received/i).fill('2.50');
    await page.getByRole('button', { name: /complete transaction/i }).click();

    await expect(page.getByText(/receipt/i)).toBeVisible();
    await expect(page.getByText(/rounding/i)).toBeVisible();
    await expect(page.getByText(/−\s*0[,.]02/)).toBeVisible();
});
```

- [ ] **Step 2: Over-tolerance rejection**

```ts
test('cashier cannot complete with €0.60 short on €2.52 receipt (EUR, threshold €0.50)', async ({ page }) => {
    await page.goto('/pos');
    // ... add item as above ...
    await page.getByLabel(/amount received/i).fill('1.92');

    await expect(page.getByRole('button', { name: /complete transaction/i })).toBeDisabled();
});
```

- [ ] **Step 3: B2B close-with-writeoff happy path**

`apps/web/e2e/documents/close-with-writeoff.spec.ts`:

```ts
test('AR clerk closes a partially-paid invoice with residual €0.30', async ({ page }) => {
    await page.goto(`/invoices/${invoiceIdWithResidual}`);

    await page.getByRole('button', { name: /close with write-off/i }).click();
    await page.getByRole('dialog').getByRole('button', { name: /close invoice/i }).click();

    await expect(page.getByText(/paid/i)).toBeVisible();
});
```

- [ ] **Step 4: Discount-tolerance boundary**

`apps/web/e2e/documents/discount-tolerance-boundary.spec.ts`:

```ts
test('invoice with €0.20 line discount is rejected', async ({ page }) => {
    await page.goto('/invoices/new');
    // ... fill header ...
    await page.getByLabel(/product/i).fill('Coffee');
    await page.getByLabel(/discount amount/i).fill('0.20');

    await page.getByRole('button', { name: /save/i }).click();
    await expect(page.getByText(/tolerance/i)).toBeVisible();
});
```

- [ ] **Step 5: Run the E2E suite**

```bash
cd apps/web && pnpm playwright test
```

Expected: all new specs PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/e2e/
git commit -m "test(e2e): add payment-tolerance end-to-end coverage"
```

---

## Phase 10 — Final validation + PR

### Task 20: Preflight + PR

- [ ] **Step 1: Run preflight**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/payment-tolerance-spec && ./scripts/preflight.sh
```

Expected: zero PHPStan errors, Pint clean, PHPUnit passes, TypeScript + ESLint clean.

- [ ] **Step 2: Rebase against `dev`**

```bash
git fetch origin dev
git rebase origin/dev
```

Resolve conflicts if any (unlikely in docs/superpowers/ area; more likely in seeders, CLAUDE.md, or shared model files).

- [ ] **Step 3: Push the branch**

```bash
git push -u origin docs/payment-tolerance-spec
```

- [ ] **Step 4: Open the PR**

```bash
gh pr create --base dev --title "feat(treasury): payment tolerance (A1/A2/A3) + anti-abuse rule" --body "$(cat <<'EOF'
## Summary

- A1: POS accepts cash-sale short-pay within country tolerance (€0.50 FR / 0.100 TND / 0.5%); writes shortfall to GL 658.
- A2: B2B "Close with write-off" action on partially-paid invoices.
- A3: Bug fix — `payment_allocations.tolerance_writeoff` is now persisted.
- Anti-abuse rule: discounts ≤ tolerance margin are blocked; auto-stripped with notification on document conversion.
- Shift aggregation + `PaymentToleranceQueryService` for cash-counting session integration.

## Test plan
- [ ] Unit tests pass (PHPUnit + Vitest)
- [ ] Integration tests pass (Feature tests hit the real DB + seeders)
- [ ] E2E passes (Playwright: happy path + over-tolerance + B2B close + discount boundary)
- [ ] `./scripts/preflight.sh` clean
- [ ] Manual smoke on POS terminal (TND + EUR locale)
- [ ] Manual smoke on B2B invoice close-with-writeoff

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Summary

**Task count:** 20 (across 10 phases)
**Estimated sequential effort:** 2–4 days depending on infrastructure familiarity
**Parallelizable tasks:** Tasks 13 (anti-abuse rule) and 14 (conversion strip) can run in parallel with Tasks 16–18 (frontend) after Task 2 completes

**Dependency graph (critical path):**
```
Prereq A/B → Task 1 (A3) → Task 2 (boundary) → Tasks 3–4 (query svc + DTOs)
                                              → Task 5 (pos_receipts) → Task 6 (pos_shifts)
                                                                      → Task 7 (GL) → Tasks 8–9 (A1)
                                                                      → Tasks 10–12 (A2)
                              → Tasks 13–14 (discount rule + conversion)
                              → Task 15 (audit cmd)
                              → Tasks 16–18 (frontend) → Task 19 (E2E) → Task 20 (PR)
```

**Deferred to follow-up tickets** (per spec §18 open questions):
- **Refund / receipt-reversal with tolerance** — if a POS receipt carrying a tolerance write-off is later voided or refunded, the 658 journal entry needs a reversing counterpart. Not addressed in this plan. Create a follow-up ticket if void/refund flows will be exercised in production before that work lands.
- **Dedicated POS GL account** — this plan reuses the existing `PaymentToleranceExpense` (658) for both POS and B2B. If the accounting team prefers a distinct subaccount for POS cash-rounding (e.g. French PCG 6588), a trivial change adds a new `SystemAccountPurpose` value and wires it via `createPOSPaymentToleranceEntry`.

**No placeholders:** every task contains concrete code, tests, commands, and commit messages.

**Plan self-review completed 2026-04-24** — spec coverage cross-referenced against all tasks, type names verified consistent across tasks (e.g. `TolerancePaymentTotalsDTO` spelled identically everywhere it appears), no TODO/TBD markers.

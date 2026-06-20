# Owner Reporting Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Revision:** r3 — corrected after two Codex plan reviews (`…/reviews/2026-06-20-owner-reporting-plan-codex-review.md` and `…-r2.md`). r2 changes: reuse the real `OwnerReportingTest` fixtures via an extracted trait (no hand-rolled inserts); resolve per-company currency + enforce single-currency + return `currencyCode`; round (not truncate) percent deltas; build `EChartsOption` for `OwnerChart`; add Big.js `formatQuantity` to `lib/decimal`; gate the Reports route via `<RequirePermission permission="dashboard.owner">` and the Sidebar via a new `ownerReports` module-permission; mandatory stock-alert location wiring; thicker endpoint test; no `as any`. r3 changes (the r2-review BLOCKER + 2 MED + LOW): dedicated `seedReturn` helper that satisfies the return-receipt DB CHECK (`original_receipt_id` + `return_reason`) and writes NO payment row (avoids the `amount > 0` CHECK); `averageBasket` and percent now use `CurrencyScale::bcround` (round, not truncate); `useMemo` builds the conditional `location_ids` spread inside the callback (exhaustive-deps clean); delete the orphaned `reports.tenantScope.test.tsx` alongside `ReportsPage.tsx`.

**Goal:** Add a dedicated, permission-gated owner Reports section that aggregates sales across all locations, fronted by a KPI-card row + sales trend, sourced from a new precision-correct backend summary service.

**Architecture:** Backend adds one new application service (`OwnerSalesSummaryService`) + DTOs + endpoint in the existing Accounting owner-report surface (NOT extending the float-tainted `SalesReportService`). Frontend relocates the existing `OwnerDashboardPage` out of the main Dashboard into a new `dashboard.owner`-gated `/reports` route where the header `LocationSwitcher` is hidden via a layout prop, and adds a KPI row, a rolled-up sales trend, and a location scope multiselect.

**Tech Stack:** Laravel 12 (PHP 8.2 strict), Spatie LaravelData + TypeScriptTransformer, PostgreSQL, BCMath; React 19 / TS strict / TanStack Query 5 / ECharts / Vitest.

## Global Constraints

- Constructor injection only; never `app()` in app code (test setup may use `$this->app->make`). (rule 13)
- Strict typing: no `mixed` (PHP), no `any` (TS — `@typescript-eslint/no-explicit-any` is a warning and `--max-warnings=0` is used, so `as any` fails lint). (rule 3)
- Money/quantity NEVER touch float: backend `CurrencyScale::bcformatStrict((string) $value, $scale)` with `CurrencyScaleResolverInterface::getScale($currencyCode)` (pass an EXPLICIT currency — bare no-arg `getScale()` throws without `CompanyContext`); frontend `formatCurrency`/`formatQuantity` from `@/lib/decimal` (Big.js) — never `parseFloat`. `Number(...)` is permitted ONLY at the ECharts coordinate boundary (matches existing `SalesByLocationChart`) and on percent values (percent is NOT currency-scaled). (rule 19)
- Enum values: type-hint `ReceiptType` and compare with the enum; in raw SQL bindings use `ReceiptType::Sale->value`. (rule 9)
- Route inside existing Accounting group: middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` + `->middleware('can:dashboard.owner')`. (rule 12)
- Types flow from backend: run `php artisan typescript:transform` after DTO changes; never hand-author domain response types. (rule 7)
- `apiGet<T>` returns `response.data.data`; the summary returns `{ "data": {...} }`, so `apiGet<SalesSummaryReport>` yields the object. (rule 14, verified `lib/api.ts`)
- All user-facing text via `t()` in the `reports` i18n namespace (en + fr). (rule 11)
- New `.tsx` uses `@/lib/designTokens`; reusing existing shared `ui` components (e.g. `StatCard`) as-is is allowed. (rule 18)
- TDD: red → green → commit per task. NEVER run the full PHPUnit suite — scope every run with `--filter`/path. (rule 2 + memory)
- SQL aggregates use `CASE WHEN ... END` (portable across the SQLite Unit suite and PG) — never `FILTER (WHERE ...)`. Numeric exactness is asserted on PG; on the SQLite test connection, aggregate SUMs may return floats — keep summary feature assertions at currency-scale-friendly values and run against the PG test connection for full exactness.
- Aggregate scope: receipts with `is_voided = false AND training_flag = false`, filtered by `OwnerReportScope`, `posted_at ∈ [from.startOfDay, to.endOfDay]`.
- Test placement mirrors existing owner-report tests: backend under `tests/Feature/Accounting/`, frontend under `features/owner-dashboard/.../__tests__/`. Commit messages: follow the repo's active convention for the branch (the `feat(reports): …` examples below are a default — adapt if the active phase/convention differs).

---

### Task 1: `SalesSummaryData` + `SalesSummaryDeltaData` DTOs

**Files:**
- Create: `apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php`
- Create: `apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php`
- Test: `apps/api/tests/Unit/Accounting/Reports/SalesSummaryDataTest.php`

**Interfaces:**
- Produces: `SalesSummaryData` (Spatie `Data`, `#[TypeScript]`) readonly fields:
  `currencyCode:string, grossSales:string, returnsAmount:string, netSales:string, salesCount:int, returnsCount:int, itemsSold:string, averageBasket:?string, delta:SalesSummaryDeltaData`.
- Produces: `SalesSummaryDeltaData`: `grossSalesAbs:string, grossSalesPct:?string, salesCountAbs:int, salesCountPct:?string` (`*Pct` null ⇒ prior base was zero → render "new").

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryDeltaData;
use Tests\TestCase;

final class SalesSummaryDataTest extends TestCase
{
    public function test_it_exposes_summary_fields_as_strings(): void
    {
        $dto = new SalesSummaryData(
            currencyCode: 'EUR',
            grossSales: '1500.00',
            returnsAmount: '50.00',
            netSales: '1450.00',
            salesCount: 12,
            returnsCount: 1,
            itemsSold: '34.0000',
            averageBasket: '125.00',
            delta: new SalesSummaryDeltaData('200.00', '15.38', 3, '33.33'),
        );

        $array = $dto->toArray();

        $this->assertSame('EUR', $array['currencyCode']);
        $this->assertSame('1500.00', $array['grossSales']);
        $this->assertSame(12, $array['salesCount']);
        $this->assertNull((new SalesSummaryDeltaData('0.00', null, 0, null))->grossSalesPct);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Accounting/Reports/SalesSummaryDataTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Write the DTOs**

`SalesSummaryDeltaData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryDeltaData extends Data
{
    public function __construct(
        public readonly string $grossSalesAbs,
        public readonly ?string $grossSalesPct,
        public readonly int $salesCountAbs,
        public readonly ?string $salesCountPct,
    ) {}
}
```

`SalesSummaryData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryData extends Data
{
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $grossSales,
        public readonly string $returnsAmount,
        public readonly string $netSales,
        public readonly int $salesCount,
        public readonly int $returnsCount,
        public readonly string $itemsSold,
        public readonly ?string $averageBasket,
        public readonly SalesSummaryDeltaData $delta,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes** — `cd apps/api && ./vendor/bin/phpunit tests/Unit/Accounting/Reports/SalesSummaryDataTest.php` → PASS.

- [ ] **Step 5: Generate types** — `cd apps/api && php artisan typescript:transform`; verify `grep -r "SalesSummaryData" packages/shared/types` shows both DTOs under `App.Modules.Accounting.Application.DTOs.Reports`.

- [ ] **Step 6: Pint + PHPStan (scoped)** — `cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Application/DTOs/Reports && ./vendor/bin/phpstan analyse app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php` → 0 errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php \
        apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php \
        apps/api/tests/Unit/Accounting/Reports/SalesSummaryDataTest.php packages/shared/types
git commit -m "feat(reports): owner sales summary DTOs + generated types"
```

---

### Task 2: Extract reusable owner-reporting test fixtures into a trait

**Why:** Task 3–4 need valid `Tenant/Company/Location/Terminal/PaymentMethod/Receipt/ReceiptLine/ReceiptPayment` rows with all NOT NULL columns. The proven setup already exists in `tests/Feature/Accounting/OwnerReportingTest.php`; extract it so new tests reuse it instead of hand-rolling inserts (which would crash on missing required columns).

**Files:**
- Create: `apps/api/tests/Feature/Accounting/Concerns/InteractsWithOwnerReporting.php`
- Test: this trait is exercised by Task 3's test; no standalone test. (Verify by running the existing `OwnerReportingTest` still green after no source change — the trait is additive.)

**Interfaces:**
- Produces trait `InteractsWithOwnerReporting` providing properties `$tenant,$company,$childCompany,$owner,$userWithoutPermission,$locationA,$locationB,$terminalA,$terminalB,$cashMethod,$cardMethod` and methods:
  - `setUpOwnerReportingFixtures(): void` (call from the test's `setUp()` AFTER `parent::setUp()`)
  - `seedReceipt(Location $location, Terminal $terminal, string $postedAt, string $total, bool $trainingFlag = false): Receipt` (SALE only — writes a positive cash payment)
  - `seedReturn(Location $location, Terminal $terminal, string $postedAt, string $negativeTotal, Receipt $original, string $reason = 'customer_request'): Receipt` (RETURN — sets `original_receipt_id` + `return_reason` per the DB CHECK; writes NO payment row to avoid the `pos_receipt_payments.amount > 0` CHECK)
  - `seedReceiptWithLine(Product $product, Location $location, Terminal $terminal, string $postedAt, string $lineTotal, string $quantity): Receipt`
  - `companyHeaders(): array{X-Company-Id:string}`

- [ ] **Step 1: Create the trait (copy the proven helpers from `OwnerReportingTest`, generalized for `ReceiptType`)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Concerns;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithOwnerReporting
{
    protected Tenant $tenant;
    protected Company $company;
    protected Company $childCompany;
    protected User $owner;
    protected User $userWithoutPermission;
    protected Location $locationA;
    protected Location $locationB;
    protected Terminal $terminalA;
    protected Terminal $terminalB;
    protected PaymentMethod $cashMethod;

    protected function setUpOwnerReportingFixtures(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Parent Company']);
        $this->childCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Child Company',
            'parent_company_id' => $this->company->id, 'is_headquarters' => false,
        ]);
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create(['user_id' => $this->owner->id, 'company_id' => $this->company->id, 'role' => 'owner']);
        UserCompanyMembership::create(['user_id' => $this->userWithoutPermission->id, 'company_id' => $this->company->id, 'role' => 'manager']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('dashboard.owner', 'sanctum');
        $this->owner->givePermissionTo('dashboard.owner');

        $this->locationA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Downtown']);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Airport']);
        $this->terminalA = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->locationA->id]);
        $this->terminalB = Terminal::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->locationB->id]);
        $this->cashMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Cash', 'code' => 'CASH']);
    }

    protected function companyHeaders(): array
    {
        return ['X-Company-Id' => $this->company->id];
    }

    protected function seedReceipt(Location $location, Terminal $terminal, string $postedAt, string $total, bool $trainingFlag = false): Receipt
    {
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Sale,
            'posted_at' => $postedAt,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'training_flag' => $trainingFlag,
        ]);

        // Mirrors the proven OwnerReportingTest payment insert (sales only, positive amount).
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'cash',
            'amount' => $total,
        ]);

        return $receipt;
    }

    /**
     * Return receipt: negative total. DB CHECK requires receipt_type='return' to carry
     * original_receipt_id + return_reason. NO payment row is written (pos_receipt_payments
     * has CHECK amount > 0; the summary service reads pos_receipts, not payments, so a
     * return needs no tender row for these tests).
     */
    protected function seedReturn(Location $location, Terminal $terminal, string $postedAt, string $negativeTotal, Receipt $original, string $reason = 'customer_request'): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->owner->id,
            'cashier_name' => 'Owner Cashier',
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => $reason,
            'posted_at' => $postedAt,
            'subtotal' => $negativeTotal,
            'tax_amount' => '0.000',
            'total' => $negativeTotal,
            'training_flag' => false,
        ]);
    }

    protected function seedReceiptWithLine(Product $product, Location $location, Terminal $terminal, string $postedAt, string $lineTotal, string $quantity): Receipt
    {
        $receipt = $this->seedReceipt($location, $terminal, $postedAt, $lineTotal);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => $lineTotal,
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => $lineTotal,
            'discount_amount' => '0.00',
        ]);

        return $receipt;
    }
}
```

> **Implementer note:** `seedReceipt`/`seedReceiptWithLine`/`companyHeaders` are copied from the proven (green) `OwnerReportingTest` helpers — do NOT change their insert shape. `seedReturn` is the new return-aware helper (sets `original_receipt_id` + `return_reason`, writes no payment row). If any factory/field has drifted, open `OwnerReportingTest.php` and match it exactly. A later cleanup can re-point `OwnerReportingTest` at this trait (out of scope here to avoid touching a green test).

- [ ] **Step 2: Verify the existing suite still green (no source changed)**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Accounting/OwnerReportingTest.php`
Expected: PASS (unchanged). Pint: `./vendor/bin/pint tests/Feature/Accounting/Concerns`.

- [ ] **Step 3: Commit**

```bash
git add apps/api/tests/Feature/Accounting/Concerns/InteractsWithOwnerReporting.php
git commit -m "test(reports): extract reusable owner-reporting fixtures trait"
```

---

### Task 3: `OwnerSalesSummaryService` (precision-correct, currency-aware aggregate)

**Files:**
- Create: `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php`
- Test: `apps/api/tests/Feature/Accounting/OwnerSalesSummaryServiceTest.php`

**Interfaces:**
- Consumes: `DateRangeData` (`->from`,`->to` `CarbonImmutable`), `CurrencyScaleResolverInterface::getScale(string)`/`getScaleSafe()`, `CurrencyScale::bcformatStrict(string,int)`, `ReceiptType::{Sale,Return}->value`, `companies.currency`.
- Produces: `summary(DateRangeData $range, array $companyIds, array $locationIds): SalesSummaryData`. Prior window = `[from - lenDays, from - 1 day]`, `lenDays = from→to inclusive`. Resolves one currency across `companyIds` (throws `AuthorizationException` if mixed). Empty scope → zeroed summary with `currencyCode=''`.

- [ ] **Step 1: Write the failing test (uses the Task-2 trait)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\OwnerSalesSummaryService;
use App\Modules\Product\Domain\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

final class OwnerSalesSummaryServiceTest extends TestCase
{
    use InteractsWithOwnerReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnerReportingFixtures();
    }

    private function range(): DateRangeData
    {
        return new DateRangeData(CarbonImmutable::parse('2026-06-09'), CarbonImmutable::parse('2026-06-16'));
    }

    public function test_summary_separates_gross_sales_returns_and_computes_deltas(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Widget', 'sku' => 'W1']);
        // current window: sales 100 + 200, return -50 (references sale #1), items 2 + 3
        $sale1 = $this->seedReceiptWithLine($product, $this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00', '2.0000');
        $this->seedReceiptWithLine($product, $this->locationB, $this->terminalB, '2026-06-11 10:00:00', '200.00', '3.0000');
        $this->seedReturn($this->locationA, $this->terminalA, '2026-06-12 10:00:00', '-50.00', $sale1);
        // prior window (2026-06-01..06-08): sale 150
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-05 10:00:00', '150.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id, $this->locationB->id],
        );

        $this->assertSame('300.00', $summary->grossSales);
        $this->assertSame('50.00', $summary->returnsAmount);
        $this->assertSame('250.00', $summary->netSales);
        $this->assertSame(2, $summary->salesCount);
        $this->assertSame(1, $summary->returnsCount);
        $this->assertSame('5.0000', $summary->itemsSold);
        $this->assertSame('150.00', $summary->averageBasket);
        $this->assertSame('150.00', $summary->delta->grossSalesAbs);
        $this->assertSame('100.00', $summary->delta->grossSalesPct);  // (300-150)/150*100
    }

    public function test_zero_previous_period_yields_null_percentages(): void
    {
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertNull($summary->delta->grossSalesPct);
        $this->assertNull($summary->delta->salesCountPct);
    }

    public function test_percentage_rounds_half_away_from_zero(): void
    {
        // prior 3 (count), current 5 → 66.666.. → 66.67
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-03 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-04 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-05 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-12 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-13 10:00:00', '10.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-14 10:00:00', '10.00');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertSame('66.67', $summary->delta->salesCountPct);
    }

    public function test_average_basket_rounds_half_away_from_zero(): void
    {
        // gross 100.01 over 2 sales → 50.005 → 50.01 (round, not truncate), EUR scale 2
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '100.00');
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '0.01');

        $summary = $this->app->make(OwnerSalesSummaryService::class)->summary(
            $this->range(), [$this->company->id], [$this->locationA->id],
        );

        $this->assertSame('50.01', $summary->averageBasket);
    }
}
```

> **Implementer note:** the `Company` factory default currency is EUR (verified — `CompanyFactory:49`), so the summary formats at scale 2 and these scale-2 string assertions are correct. If a future fixture overrides the company to TND (scale 3), the expected strings become scale 3.

- [ ] **Step 2: Run test to verify it fails** — `cd apps/api && ./vendor/bin/phpunit tests/Feature/Accounting/OwnerSalesSummaryServiceTest.php` → FAIL (service missing).

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryDeltaData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OwnerSalesSummaryService
{
    private const QTY_SCALE = 4;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     *
     * @throws AuthorizationException
     */
    public function summary(DateRangeData $range, array $companyIds, array $locationIds): SalesSummaryData
    {
        if ($companyIds === [] || $locationIds === []) {
            return $this->zero('', $this->scaleResolver->getScaleSafe(null, 3));
        }

        $currency = $this->resolveCurrency($companyIds);
        $scale = $this->scaleResolver->getScale($currency);

        $current = $this->aggregate($range, $companyIds, $locationIds);

        $lengthDays = (int) $range->from->startOfDay()->diffInDays($range->to->startOfDay()) + 1;
        $prior = $this->aggregate(
            new DateRangeData(from: $range->from->subDays($lengthDays), to: $range->from->subDay()),
            $companyIds,
            $locationIds,
        );

        $averageBasket = $current['saleCount'] > 0
            ? CurrencyScale::bcround(bcdiv($current['gross'], (string) $current['saleCount'], $scale + 2), $scale)
            : null;

        return new SalesSummaryData(
            currencyCode: $currency,
            grossSales: CurrencyScale::bcformatStrict($current['gross'], $scale),
            returnsAmount: CurrencyScale::bcformatStrict($current['returns'], $scale),
            netSales: CurrencyScale::bcformatStrict(bcsub($current['gross'], $current['returns'], $scale + 1), $scale),
            salesCount: $current['saleCount'],
            returnsCount: $current['returnCount'],
            itemsSold: CurrencyScale::bcformatStrict($current['items'], self::QTY_SCALE),
            averageBasket: $averageBasket,
            delta: new SalesSummaryDeltaData(
                grossSalesAbs: CurrencyScale::bcformatStrict(bcsub($current['gross'], $prior['gross'], $scale + 1), $scale),
                grossSalesPct: $this->pct($current['gross'], $prior['gross']),
                salesCountAbs: $current['saleCount'] - $prior['saleCount'],
                salesCountPct: $this->pct((string) $current['saleCount'], (string) $prior['saleCount']),
            ),
        );
    }

    /**
     * @param  list<string>  $companyIds
     *
     * @throws AuthorizationException
     */
    private function resolveCurrency(array $companyIds): string
    {
        $currencies = DB::table('companies')->whereIn('id', $companyIds)->distinct()->pluck('currency');

        if ($currencies->count() > 1) {
            throw new AuthorizationException('Owner reporting cannot aggregate across companies with different currencies.');
        }

        return (string) ($currencies->first() ?? 'EUR');
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return array{gross:string, returns:string, saleCount:int, returnCount:int, items:string}
     */
    private function aggregate(DateRangeData $range, array $companyIds, array $locationIds): array
    {
        $sale = ReceiptType::Sale->value;
        $return = ReceiptType::Return->value;

        $receipts = DB::table('pos_receipts')
            ->whereIn('company_id', $companyIds)
            ->whereIn('location_id', $locationIds)
            ->where('is_voided', false)
            ->where('training_flag', false)
            ->whereBetween('posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->selectRaw('COALESCE(SUM(CASE WHEN receipt_type = ? THEN total ELSE 0 END), 0) as gross', [$sale])
            ->selectRaw('COALESCE(ABS(SUM(CASE WHEN receipt_type = ? THEN total ELSE 0 END)), 0) as returns', [$return])
            ->selectRaw('COUNT(CASE WHEN receipt_type = ? THEN 1 END) as sale_count', [$sale])
            ->selectRaw('COUNT(CASE WHEN receipt_type = ? THEN 1 END) as return_count', [$return])
            ->first();

        $items = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            ->where('pos_receipts.receipt_type', $sale)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as items')
            ->first();

        return [
            'gross' => (string) ($receipts->gross ?? '0'),
            'returns' => (string) ($receipts->returns ?? '0'),
            'saleCount' => (int) ($receipts->sale_count ?? 0),
            'returnCount' => (int) ($receipts->return_count ?? 0),
            'items' => (string) ($items->items ?? '0'),
        ];
    }

    private function pct(string $current, string $prior): ?string
    {
        if (bccomp($prior, '0', 6) === 0) {
            return null;
        }

        $raw = bcmul(bcdiv(bcsub($current, $prior, 8), $prior, 8), '100', 6);

        // Round (not truncate) at the boundary — percent is NOT currency-scaled.
        return CurrencyScale::bcround($raw, 2);
    }

    private function zero(string $currency, int $scale): SalesSummaryData
    {
        $zero = CurrencyScale::bcformatStrict('0', $scale);

        return new SalesSummaryData(
            currencyCode: $currency,
            grossSales: $zero,
            returnsAmount: $zero,
            netSales: $zero,
            salesCount: 0,
            returnsCount: 0,
            itemsSold: CurrencyScale::bcformatStrict('0', self::QTY_SCALE),
            averageBasket: null,
            delta: new SalesSummaryDeltaData($zero, null, 0, null),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes** — `cd apps/api && ./vendor/bin/phpunit tests/Feature/Accounting/OwnerSalesSummaryServiceTest.php` → PASS. (If the local default connection is SQLite and a decimal assertion is off by float noise, run against the PG test connection per the project recipe; the CASE/ABS SQL is portable.)

- [ ] **Step 5: Pint + PHPStan (scoped)** — `cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php && ./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php` → 0 errors (no float casts on money; scales come from the resolver/const, not literals on money columns).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php \
        apps/api/tests/Feature/Accounting/OwnerSalesSummaryServiceTest.php
git commit -m "feat(reports): currency-aware precision-correct OwnerSalesSummaryService"
```

---

### Task 4: `salesSummary` endpoint (controller + route)

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php` (constructor + `salesSummary()`)
- Modify: `apps/api/app/Modules/Accounting/Presentation/routes.php` (Owner Reporting MVP block)
- Test: `apps/api/tests/Feature/Accounting/SalesSummaryEndpointTest.php`

**Interfaces:**
- Consumes: `OwnerSalesSummaryService::summary()`, `GetOwnerSalesReportRequest`, `OwnerReportScope`, `$this->ownerUser()`.
- Produces: `GET /api/v1/reports/sales/summary` → `{ "data": SalesSummaryData }`, gated `can:dashboard.owner`.

- [ ] **Step 1: Write the failing test (mirror `OwnerReportingTest` auth via the trait; cover returns/prior/scope/403)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

final class SalesSummaryEndpointTest extends TestCase
{
    use InteractsWithOwnerReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnerReportingFixtures();
    }

    public function test_owner_can_fetch_sales_summary_with_returns(): void
    {
        Sanctum::actingAs($this->owner);
        $sale = $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '120.00');
        $this->seedReturn($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '-20.00', $sale);

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16', $this->companyHeaders())
            ->assertOk()
            ->assertJsonPath('data.grossSales', '120.00')
            ->assertJsonPath('data.returnsAmount', '20.00')
            ->assertJsonPath('data.salesCount', 1)
            ->assertJsonStructure(['data' => ['currencyCode', 'grossSales', 'returnsAmount', 'salesCount', 'averageBasket', 'delta' => ['grossSalesAbs', 'grossSalesPct']]]);
    }

    public function test_location_scope_is_enforced(): void
    {
        Sanctum::actingAs($this->owner);
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '120.00');
        $this->seedReceipt($this->locationB, $this->terminalB, '2026-06-10 10:00:00', '300.00');

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16&location_ids[]='.$this->locationA->id, $this->companyHeaders())
            ->assertOk()
            ->assertJsonPath('data.grossSales', '120.00');
    }

    public function test_non_owner_is_forbidden(): void
    {
        Sanctum::actingAs($this->userWithoutPermission);

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16', $this->companyHeaders())
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/api && ./vendor/bin/phpunit tests/Feature/Accounting/SalesSummaryEndpointTest.php` → FAIL (route missing).

- [ ] **Step 3: Add service to controller constructor + action**

Add import + constructor param:
```php
use App\Modules\Accounting\Application\Services\Reports\OwnerSalesSummaryService;
// ... in constructor:
        private readonly OwnerSalesSummaryService $ownerSalesSummaryService,
```
Add the action (mirrors `salesByLocation`):
```php
public function salesSummary(GetOwnerSalesReportRequest $request): JsonResponse
{
    $user = $this->ownerUser($request->user());
    $companyIds = $this->ownerReportScope->companyIds($request->companyIds(), $user);
    $locationIds = $this->ownerReportScope->locationIds($companyIds, $request->locationIds(), $user);

    return response()->json([
        'data' => $this->ownerSalesSummaryService->summary(
            range: $request->dateRange(),
            companyIds: $companyIds,
            locationIds: $locationIds,
        ),
    ]);
}
```

- [ ] **Step 4: Add the route** — inside the `// Owner Reporting MVP` block in `routes.php`:
```php
Route::get('/reports/sales/summary', [ReportsController::class, 'salesSummary'])
    ->middleware('can:dashboard.owner')
    ->name('reports.sales.summary');
```

- [ ] **Step 5: Run test to verify it passes** — `cd apps/api && ./vendor/bin/phpunit tests/Feature/Accounting/SalesSummaryEndpointTest.php` → PASS.

- [ ] **Step 6: Pint + PHPStan + commit**
```bash
cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Presentation && ./vendor/bin/phpstan analyse app/Modules/Accounting/Presentation/Controllers/ReportsController.php
git add apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php apps/api/app/Modules/Accounting/Presentation/routes.php apps/api/tests/Feature/Accounting/SalesSummaryEndpointTest.php
git commit -m "feat(reports): GET /reports/sales/summary owner endpoint"
```

---

### Task 5: `fetchSalesSummary` API client + `useSalesSummary` hook

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts`
- Modify: `apps/web/src/features/owner-dashboard/hooks/useOwnerReports.ts`
- Test: `apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.summary.test.tsx`

**Interfaces:**
- Produces: `SalesSummaryReport` alias; `fetchSalesSummary(params: OwnerDateRangeParams): Promise<SalesSummaryReport>`; `useSalesSummary(params, canFetch?)`.

- [ ] **Step 1: Write the failing test**
```tsx
import { describe, expect, it, vi } from 'vitest'
import { fetchSalesSummary } from '../api/ownerReportsApi'
import { apiGet } from '@/lib/api'

vi.mock('@/lib/api', () => ({ apiGet: vi.fn().mockResolvedValue({ grossSales: '120.00' }) }))

describe('fetchSalesSummary', () => {
  it('requests the summary endpoint with location params', async () => {
    await fetchSalesSummary({ from: '2026-06-01', to: '2026-06-30', location_ids: ['loc-1'] })
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('/reports/sales/summary?'))
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('location_ids%5B%5D=loc-1'))
  })
})
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/__tests__/ownerReportsApi.summary.test.tsx` → FAIL.

- [ ] **Step 3: Add type + fetcher to `ownerReportsApi.ts`**
```ts
export type SalesSummaryReport = App.Modules.Accounting.Application.DTOs.Reports.SalesSummaryData

export async function fetchSalesSummary(params: OwnerDateRangeParams): Promise<SalesSummaryReport> {
  return apiGet<SalesSummaryReport>(`/reports/sales/summary?${buildParams(params)}`)
}
```

- [ ] **Step 4: Add the hook + key to `useOwnerReports.ts`** (add `fetchSalesSummary`/`SalesSummaryReport` to imports)
```ts
  salesSummary: (params: OwnerDateRangeParams) => [...ownerReportKeys.all, 'sales-summary', params] as const,
```
```ts
export function useSalesSummary(params: OwnerDateRangeParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch
  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.salesSummary(params)),
    queryFn: () => fetchSalesSummary(params),
    enabled,
  })
}
```

- [ ] **Step 5: Run test + typecheck** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/__tests__/ownerReportsApi.summary.test.tsx && pnpm typecheck` → PASS.

- [ ] **Step 6: Commit**
```bash
git add apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts apps/web/src/features/owner-dashboard/hooks/useOwnerReports.ts apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.summary.test.tsx
git commit -m "feat(reports): web sales-summary api client + hook"
```

---

### Task 6: Big.js `formatQuantity` in `@/lib/decimal`

**Why:** `@/lib/decimal` exports `formatCurrency` (Big.js) but no `formatQuantity`; the only `formatQuantity` (`lib/format.ts`) uses `parseFloat` (forbidden on quantity). Add a Big.js-backed one so the KPI cards format quantity without float.

**Files:**
- Modify: `apps/web/src/lib/decimal.ts`
- Test: `apps/web/src/lib/__tests__/decimal.formatQuantity.test.ts`

**Interfaces:**
- Produces: `formatQuantity(amount: string | number, scale = 4): string` (Big.js `toFixed(scale)`, no float).

- [ ] **Step 1: Write the failing test**
```ts
import { describe, expect, it } from 'vitest'
import { formatQuantity } from '../decimal'

describe('formatQuantity', () => {
  it('formats a decimal string to 4 places without float', () => {
    expect(formatQuantity('5')).toBe('5.0000')
    expect(formatQuantity('5.5', 2)).toBe('5.50')
  })
})
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/lib/__tests__/decimal.formatQuantity.test.ts` → FAIL (not exported).

- [ ] **Step 3: Add the function to `lib/decimal.ts`** (reuse the existing `Big`/`safeBig` imports already in that file)
```ts
export function formatQuantity(amount: string | number, scale = 4): string {
  let big: Big
  try {
    big = typeof amount === 'number' ? new Big(amount) : safeBig(String(amount))
  } catch {
    big = new Big(0)
  }
  return big.toFixed(scale)
}
```

- [ ] **Step 4: Run test + lint** — `cd apps/web && pnpm vitest run src/lib/__tests__/decimal.formatQuantity.test.ts && pnpm lint --max-warnings=0 src/lib/decimal.ts` → PASS.

- [ ] **Step 5: Commit**
```bash
git add apps/web/src/lib/decimal.ts apps/web/src/lib/__tests__/decimal.formatQuantity.test.ts
git commit -m "feat(reports): Big.js formatQuantity helper in lib/decimal"
```

---

### Task 7: `SalesSummaryCards` KPI row component

**Files:**
- Create: `apps/web/src/features/owner-dashboard/components/SalesSummaryCards.tsx`
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx`

**Interfaces:**
- Consumes: `SalesSummaryReport`, `StatCard` (`@/components/ui/StatCard`), `formatCurrency`/`formatQuantity` (`@/lib/decimal`).
- Produces: `<SalesSummaryCards data isLoading isError />` — 5 cards + skeleton + error card; delta chip on Total sales + Transactions via `StatCard.trend`.

- [ ] **Step 1: Write the failing test**
```tsx
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesSummaryCards } from '../SalesSummaryCards'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

const data = {
  currencyCode: 'EUR', grossSales: '300.00', returnsAmount: '50.00', netSales: '250.00',
  salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.00',
  delta: { grossSalesAbs: '150.00', grossSalesPct: '100.00', salesCountAbs: 1, salesCountPct: '50.00' },
}

describe('SalesSummaryCards', () => {
  it('renders five KPI cards with formatted values', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.transactions')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.returns')).toBeInTheDocument()
    expect(screen.getByText('5.0000')).toBeInTheDocument()  // itemsSold via formatQuantity
  })

  it('renders an error card on error', () => {
    render(<SalesSummaryCards data={undefined} isLoading={false} isError />)
    expect(screen.getByText('reports:ownerDashboard.kpi.error')).toBeInTheDocument()
  })

  it('renders a skeleton while loading', () => {
    render(<SalesSummaryCards data={undefined} isLoading isError={false} />)
    expect(screen.getByTestId('kpi-skeleton')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx` → FAIL.

- [ ] **Step 3: Write the component**
```tsx
import { useTranslation } from 'react-i18next'
import { ShoppingBag, Receipt, Wallet, Package, Undo2 } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { formatCurrency, formatQuantity } from '@/lib/decimal'
import type { SalesSummaryReport } from '../api/ownerReportsApi'

interface SalesSummaryCardsProps {
  data: SalesSummaryReport | undefined
  isLoading: boolean
  isError: boolean
}

export function SalesSummaryCards({ data, isLoading, isError }: SalesSummaryCardsProps) {
  const { t } = useTranslation(['reports'])

  if (isLoading) {
    return (
      <div data-testid="kpi-skeleton" className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className={`h-28 animate-pulse rounded-lg border ${borderColors.light} ${colors.white}`} />
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className={`rounded-lg border ${borderColors.light} ${colors.white} p-6 text-sm ${textColors.tertiary}`}>
        {t('reports:ownerDashboard.kpi.error')}
      </div>
    )
  }

  const currency = data.currencyCode || 'EUR'
  const pct = (v: string | null) =>
    v === null ? undefined : { value: Number(v), label: t('reports:ownerDashboard.kpi.vsPrevious'), isPositive: Number(v) >= 0 }

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <StatCard label={t('reports:ownerDashboard.kpi.totalSales')} value={formatCurrency(data.grossSales, true, currency)} icon={ShoppingBag} trend={pct(data.delta.grossSalesPct)} />
      <StatCard label={t('reports:ownerDashboard.kpi.transactions')} value={String(data.salesCount)} icon={Receipt} trend={pct(data.delta.salesCountPct)} />
      <StatCard label={t('reports:ownerDashboard.kpi.avgBasket')} value={data.averageBasket ? formatCurrency(data.averageBasket, true, currency) : '—'} icon={Wallet} />
      <StatCard label={t('reports:ownerDashboard.kpi.itemsSold')} value={formatQuantity(data.itemsSold)} icon={Package} />
      <StatCard label={t('reports:ownerDashboard.kpi.returns')} value={formatCurrency(data.returnsAmount, true, currency)} icon={Undo2} />
    </div>
  )
}
```

- [ ] **Step 4: Add i18n keys** — in `apps/web/src/locales/en/reports.json` and `fr/reports.json` under `ownerDashboard`:
```json
"kpi": { "totalSales": "Total sales", "transactions": "Transactions", "avgBasket": "Average basket", "itemsSold": "Items sold", "returns": "Returns", "vsPrevious": "vs previous period", "error": "Could not load summary" }
```
(fr: "Ventes totales","Transactions","Panier moyen","Articles vendus","Retours","vs période précédente","Impossible de charger le résumé".)

- [ ] **Step 5: Run test + lint** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx && pnpm lint --max-warnings=0 src/features/owner-dashboard/components/SalesSummaryCards.tsx` → PASS (`Number()` is only on percent — allowed).

- [ ] **Step 6: Commit**
```bash
git add apps/web/src/features/owner-dashboard/components/SalesSummaryCards.tsx apps/web/src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx apps/web/src/locales/en/reports.json apps/web/src/locales/fr/reports.json
git commit -m "feat(reports): owner KPI summary cards with skeleton + error states"
```

---

### Task 8: `showLocationSwitcher` layout seam + integration test

**Files:**
- Modify: `apps/web/src/components/organisms/TopBar/TopBar.tsx`
- Modify: `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx`
- Test: `apps/web/src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx`

**Interfaces:**
- Produces: `TopBarProps.showLocationSwitcher?: boolean` (default `true`); `DashboardLayout` hides the switcher on `/reports`.

- [ ] **Step 1: Write the failing integration test (route-driven, not just the prop)**
```tsx
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { DashboardLayout } from '../DashboardLayout'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k, i18n: { changeLanguage: vi.fn(), language: 'en' } }) }))
vi.mock('../../../organisms/LocationSwitcher', () => ({ LocationSwitcher: () => <div data-testid="location-switcher" /> }))
// Mock the heavier children the layout renders so it mounts in jsdom:
vi.mock('../../../organisms/Sidebar', () => ({ Sidebar: () => <div /> }))
vi.mock('../../../organisms/CompanySelector', () => ({ CompanySelector: () => <div /> }))

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes><Route element={<DashboardLayout />}><Route path={path} element={<div>page</div>} /></Route></Routes>
    </MemoryRouter>,
  )
}

describe('DashboardLayout location switcher visibility', () => {
  it('hides the switcher on /reports', () => {
    renderAt('/reports')
    expect(screen.queryByTestId('location-switcher')).not.toBeInTheDocument()
  })
  it('shows the switcher on an operational route', () => {
    renderAt('/inventory')
    expect(screen.getByTestId('location-switcher')).toBeInTheDocument()
  })
})
```

> **Implementer note:** The exact mock paths depend on `DashboardLayout`'s imports — adjust the relative `vi.mock` paths to match. Mock only what's needed to mount (Sidebar, CompanySelector, LocationSwitcher, any provider that throws in jsdom). If mounting the full layout proves brittle, fall back to a `TopBar`-prop unit test PLUS a thin assertion that `DashboardLayout` computes `showLocationSwitcher` from `useLocation().pathname` — but prefer the integration test.

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx` → FAIL.

- [ ] **Step 3: Add the prop in `TopBar.tsx`**
```tsx
interface TopBarProps {
  onMenuClick?: () => void
  onSearchClick?: () => void
  showLocationSwitcher?: boolean
}

export function TopBar({ onMenuClick, onSearchClick, showLocationSwitcher = true }: TopBarProps) {
```
Replace the unconditional render (~line 99):
```tsx
{showLocationSwitcher && <LocationSwitcher className="hidden lg:block" />}
```

- [ ] **Step 4: Pass the prop from `DashboardLayout.tsx`**
```tsx
import { Outlet, useLocation } from 'react-router-dom'
// in the component body:
const location = useLocation()
const showLocationSwitcher = !location.pathname.startsWith('/reports')
// ...
<TopBar onMenuClick={() => { setSidebarOpen(true) }} onSearchClick={openCommandPalette} showLocationSwitcher={showLocationSwitcher} />
```
> Route knowledge lives in the LAYOUT, not the global `TopBar`. Do NOT mutate `locationStore` anywhere here.

- [ ] **Step 5: Run test + typecheck** — `cd apps/web && pnpm vitest run src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx && pnpm typecheck` → PASS.

- [ ] **Step 6: Commit**
```bash
git add apps/web/src/components/organisms/TopBar/TopBar.tsx apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx apps/web/src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx
git commit -m "feat(reports): hide header LocationSwitcher on /reports via layout seam"
```

---

### Task 9: `SalesTrendChart` (rolled-up total trend)

**Files:**
- Create: `apps/web/src/features/owner-dashboard/lib/rollupSalesByPeriod.ts`
- Create: `apps/web/src/features/owner-dashboard/components/SalesTrendChart.tsx`
- Test: `apps/web/src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts`

**Interfaces:**
- Produces: `rollupSalesByPeriod(rows: SalesByLocationReport[]): { period: string; total: number }[]` — sums `gross_sales` per `period`. `Number()` is at the ECharts coordinate boundary only (matches `SalesByLocationChart`).
- Produces: `<SalesTrendChart data isLoading isError />` — builds an `EChartsOption` and passes it to `OwnerChart`.

- [ ] **Step 1: Write the failing test (typed fixtures — no `as any`)**
```ts
import { describe, expect, it } from 'vitest'
import { rollupSalesByPeriod } from '../rollupSalesByPeriod'
import type { SalesByLocationReport } from '../../api/ownerReportsApi'

const rows: SalesByLocationReport[] = [
  { period: '2026-06-01', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '100', receipt_count: 1 },
  { period: '2026-06-01', company_id: 'c', company_name: 'C', location_id: 'b', location_name: 'B', gross_sales: '50', receipt_count: 1 },
  { period: '2026-06-02', company_id: 'c', company_name: 'C', location_id: 'a', location_name: 'A', gross_sales: '200', receipt_count: 1 },
]

describe('rollupSalesByPeriod', () => {
  it('sums gross sales per period across locations', () => {
    expect(rollupSalesByPeriod(rows)).toEqual([
      { period: '2026-06-01', total: 150 },
      { period: '2026-06-02', total: 200 },
    ])
  })
})
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts` → FAIL.

- [ ] **Step 3: Write the rollup + chart**

`rollupSalesByPeriod.ts`:
```ts
import type { SalesByLocationReport } from '../api/ownerReportsApi'

// Number() here is the ECharts coordinate boundary only (mirrors SalesByLocationChart); displayed money stays server-string.
export function rollupSalesByPeriod(rows: SalesByLocationReport[]): { period: string; total: number }[] {
  const byPeriod = new Map<string, number>()
  for (const row of rows) {
    byPeriod.set(row.period, (byPeriod.get(row.period) ?? 0) + Number(row.gross_sales))
  }
  return [...byPeriod.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([period, total]) => ({ period, total }))
}
```

`SalesTrendChart.tsx` (mirror `SalesByLocationChart`: build `option`, pass `option={option}`):
```tsx
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { EChartsOption } from 'echarts'
import { chartColors } from '@/lib/designTokens'
import { OwnerChart } from './OwnerChart'
import { rollupSalesByPeriod } from '../lib/rollupSalesByPeriod'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  isLoading?: boolean
  isError?: boolean
}

export function SalesTrendChart({ data, isLoading = false, isError = false }: SalesTrendChartProps) {
  const { t } = useTranslation(['reports'])
  const series = useMemo(() => rollupSalesByPeriod(data), [data])

  const option: EChartsOption = {
    color: [chartColors.primary],
    tooltip: { trigger: 'axis' as const },
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    xAxis: { type: 'category' as const, data: series.map((p) => p.period) },
    yAxis: { type: 'value' as const },
    series: [{ type: 'line' as const, smooth: true, data: series.map((p) => p.total) }],
  }

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.salesTrend.title')}
      option={option}
      isLoading={isLoading}
      isError={isError}
      isEmpty={series.length === 0}
    />
  )
}
```

- [ ] **Step 4: Add i18n key** — `reports.json` (en/fr): `ownerDashboard.salesTrend.title` = "Sales over time" / "Évolution des ventes".

- [ ] **Step 5: Run test + typecheck + lint** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts && pnpm typecheck && pnpm lint --max-warnings=0 src/features/owner-dashboard/components/SalesTrendChart.tsx src/features/owner-dashboard/lib/rollupSalesByPeriod.ts` → PASS.

- [ ] **Step 6: Commit**
```bash
git add apps/web/src/features/owner-dashboard/components/SalesTrendChart.tsx apps/web/src/features/owner-dashboard/lib/rollupSalesByPeriod.ts apps/web/src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts apps/web/src/locales/en/reports.json apps/web/src/locales/fr/reports.json
git commit -m "feat(reports): rolled-up sales-over-time trend chart"
```

---

### Task 10: Owner Reports route + nav gating + relocate overview + retire orphan

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (insert KPI row + trend; add `useSalesSummary`)
- Modify: `apps/web/src/features/dashboard/Dashboard.tsx` (remove the `<OwnerDashboardPage />` embed + import)
- Modify: `apps/web/src/routes/index.tsx` (replace the `/reports`→`/finance` redirect with a real route gated by `<RequirePermission permission="dashboard.owner">`)
- Modify: `apps/web/src/hooks/usePermissions.ts` (add `ownerReports: ['dashboard.owner']` to `MODULE_PERMISSIONS`)
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (add a "Reports" nav entry with `permission: 'ownerReports'`)
- Delete: `apps/web/src/features/reports/ReportsPage.tsx` (+ remove its import/usage)
- Test: extend `apps/web/src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx`; add `apps/web/src/hooks/__tests__/usePermissions.ownerReports.test.ts`

**Interfaces:**
- Consumes: `SalesSummaryCards` (Task 7), `SalesTrendChart` (Task 9), `useSalesSummary` (Task 5).
- Produces: `/reports` route (gated `dashboard.owner`); Sidebar "Reports" item gated via `ownerReports` module-permission; main Dashboard no longer renders the owner section.

- [ ] **Step 1: Write the failing tests (red)**

Add a `useSalesSummary` mock to the `useOwnerReports` mock block in `OwnerDashboardPage.test.tsx`:
```tsx
  useSalesSummary: () => ({
    data: { currencyCode: 'EUR', grossSales: '300.00', returnsAmount: '50.00', netSales: '250.00', salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.00', delta: { grossSalesAbs: '150.00', grossSalesPct: '100.00', salesCountAbs: 1, salesCountPct: '50.00' } },
    isLoading: false, isError: false,
  }),
```
```tsx
  it('renders the KPI summary row', () => {
    render(<OwnerDashboardPage />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
  })
```
New permission test `usePermissions.ownerReports.test.ts`:
```ts
import { describe, expect, it } from 'vitest'
import { MODULE_PERMISSIONS } from '../usePermissions'

describe('ownerReports module permission', () => {
  it('maps to dashboard.owner', () => {
    expect(MODULE_PERMISSIONS.ownerReports).toEqual(['dashboard.owner'])
  })
})
```

- [ ] **Step 2: Run tests to verify they fail** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx src/hooks/__tests__/usePermissions.ownerReports.test.ts` → FAIL.

- [ ] **Step 3: Add the module-permission mapping** — in `usePermissions.ts` `MODULE_PERMISSIONS`, add:
```ts
  ownerReports: ['dashboard.owner'],
```

- [ ] **Step 4: Insert KPI row + trend into `OwnerDashboardPage`**
```tsx
import { SalesSummaryCards } from './components/SalesSummaryCards'
import { SalesTrendChart } from './components/SalesTrendChart'
import { /* existing */, useSalesSummary } from './hooks/useOwnerReports'
// in component, with other hooks:
const summary = useSalesSummary(dateParams, canViewOwnerDashboard)
// JSX, right after <OwnerDashboardFilters .../>:
<SalesSummaryCards data={summary.data} isLoading={summary.isLoading} isError={summary.isError} />
<SalesTrendChart data={sales.data ?? []} isLoading={sales.isLoading} isError={sales.isError} />
```

- [ ] **Step 5: Remove the embed from the main Dashboard** — in `Dashboard.tsx` delete the `import { OwnerDashboardPage } from '../owner-dashboard'` and the `<OwnerDashboardPage />` usage (~line 314).

- [ ] **Step 6: Replace the `/reports` redirect with a gated route** — in `routes/index.tsx`, find the existing `/reports` → `/finance` redirect (search `path="reports"` / `Navigate to="/finance"`) and replace it with:
```tsx
import { OwnerDashboardPage } from '../features/owner-dashboard'
// route entry (mirror sibling route style; nest under the DashboardLayout route as siblings are):
<Route path="reports" element={<RequirePermission permission="dashboard.owner"><OwnerDashboardPage /></RequirePermission>} />
```
> `RequirePermission` already supports a `permission="..."` prop (verified — see the `permission="sales.create"` usages). Use `permission`, NOT `moduleKey`.

- [ ] **Step 7: Add the Sidebar entry + delete the orphan**
- In `Sidebar.tsx`, add a nav module/child "Reports": `{ key: 'reports', href: '/reports', icon: BarChart3, permission: 'ownerReports' }` following the existing nav structure (place sensibly near the top or under an owner group). Label via the sidebar's existing `t()` key mechanism (add the `reports` nav key to the sidebar i18n).
- `grep -rn "ReportsPage" apps/web/src` — references are `features/reports/ReportsPage.tsx` AND its colocated test `features/reports/reports.tenantScope.test.tsx` (which imports `ReportsPage`). Delete BOTH (remove the whole `features/reports/` dir if nothing else remains) plus any route/nav import. Confirm no references remain.

- [ ] **Step 8: Run tests + typecheck + lint**
Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard src/hooks/__tests__/usePermissions.ownerReports.test.ts && pnpm typecheck && pnpm lint --max-warnings=0 src/features/owner-dashboard src/routes/index.tsx src/components/organisms/Sidebar/Sidebar.tsx src/hooks/usePermissions.ts`
Expected: PASS; no dangling `ReportsPage` references.

- [ ] **Step 9: Commit**
```bash
git add apps/web/src/features/owner-dashboard apps/web/src/features/dashboard/Dashboard.tsx apps/web/src/routes/index.tsx apps/web/src/hooks/usePermissions.ts apps/web/src/components/organisms/Sidebar/Sidebar.tsx apps/web/src/hooks/__tests__/usePermissions.ownerReports.test.ts apps/web/src/locales/en/common.json apps/web/src/locales/fr/common.json
git rm apps/web/src/features/reports/ReportsPage.tsx apps/web/src/features/reports/reports.tenantScope.test.tsx
git commit -m "feat(reports): gated owner Reports route + nav; relocate owner overview; retire orphan ReportsPage"
```

---

### Task 11: Location scope multiselect + mandatory wiring

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/components/OwnerDashboardFilters.tsx` (add multiselect; extend value with `locationIds: string[]`)
- Modify: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (pass `location_ids` into EVERY owner hook, including `useLowStockAlerts` and `useSalesSummary`)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/OwnerDashboardFilters.test.tsx`

**Interfaces:**
- Consumes: `useLocationStore.locations` (read-only option source; NEVER `currentLocationId`/`switchLocation`).
- Produces: `OwnerDashboardFiltersValue` gains `locationIds: string[]` (`[]` = all). Page maps to `location_ids` (omit when empty).

- [ ] **Step 1: Write the failing test**
```tsx
import { render, screen, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OwnerDashboardFilters } from '../OwnerDashboardFilters'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))
vi.mock('@/stores/locationStore', () => ({
  useLocationStore: (sel: (s: { locations: { id: string; name: string }[] }) => unknown) =>
    sel({ locations: [{ id: 'loc-1', name: 'Tunis' }, { id: 'loc-2', name: 'Marsa' }] }),
}))

describe('OwnerDashboardFilters location scope', () => {
  it('emits selected location ids', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day', locationIds: [] }} onChange={onChange} />)
    fireEvent.click(screen.getByLabelText('Tunis'))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ locationIds: ['loc-1'] }))
  })
})
```

- [ ] **Step 2: Run test to verify it fails** — `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/OwnerDashboardFilters.test.tsx` → FAIL.

- [ ] **Step 3: Extend the filter type + UI**
- Add `locationIds: string[]` to `OwnerDashboardFiltersValue`.
- `const locations = useLocationStore((s) => s.locations)`.
- Render a checkbox per location (`<input type="checkbox" aria-label={loc.name} checked={value.locationIds.includes(loc.id)} onChange={...}>`) + an "All locations" reset that sets `locationIds: []`. Tokens + `t()` keys `reports:ownerDashboard.filters.allLocations` / `...filters.locations`. Tolerate empty `locations` (render nothing/just "All").

- [ ] **Step 4: Wire `location_ids` into ALL owner hooks** — in `OwnerDashboardPage`, build the conditional spread INSIDE the memo callbacks (no external closure — `react-hooks/exhaustive-deps` runs as a warning and the lint step uses `--max-warnings=0`):
```tsx
const dateParams = useMemo(() => ({
  from: filters.from,
  to: filters.to,
  ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
}), [filters.from, filters.to, filters.locationIds])

const stockParams = useMemo(() => ({
  threshold_pct: 100,
  ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
}), [filters.locationIds])
```
Pass `dateParams` into `useSalesByLocation`, `useTopSkus`, `useRevenueByCategory`, `usePaymentMethodBreakdown`, `useCashRegisterReconciliation`, `useSalesSummary`; pass `stockParams` into `useLowStockAlerts` (`StockAlertsParams` already accepts `location_ids`). Initialize `defaultFilters()` with `locationIds: []`.

- [ ] **Step 5: Run tests + typecheck + lint** — `cd apps/web && pnpm vitest run src/features/owner-dashboard && pnpm typecheck && pnpm lint --max-warnings=0 src/features/owner-dashboard` → PASS.

- [ ] **Step 6: Commit**
```bash
git add apps/web/src/features/owner-dashboard
git commit -m "feat(reports): owner location scope multiselect wired through all owner reports incl. stock alerts"
```

---

## Self-Review (r2)

**Codex BLOCKER/HIGH resolution:**
- BLOCKER-1 (hand-rolled seed missing columns) → Task 2 trait reuses proven `Receipt::factory()`/`seedReceipt` with all required columns; Tasks 3–4 use it.
- BLOCKER-2 (bare `getScale()` throws) → Task 3 resolves company `currency` and calls `getScale($currency)`; empty-scope uses `getScaleSafe(null,3)`.
- BLOCKER-3 (nonexistent test helpers/namespace) → Tasks 3–4 live in `Tests\Feature\Accounting`, use the trait, `Sanctum::actingAs`, `companyHeaders()`.
- BLOCKER-4 (`OwnerChart` children vs `option`) → Task 9 builds `EChartsOption` and passes `option={option}`.
- HIGH-1 (`Number()` on money) → confined to the chart coordinate boundary with a comment, matching the existing `SalesByLocationChart` precedent; displayed money/KPIs use server strings + Big.js formatters.
- HIGH-2 (Sidebar permission not gating) → Task 10 adds `MODULE_PERMISSIONS.ownerReports = ['dashboard.owner']`, nav `permission: 'ownerReports'`, and a real route guard `<RequirePermission permission="dashboard.owner">` replacing the `/reports`→`/finance` redirect; tested.
- HIGH-3 (`formatQuantity` missing) → Task 6 adds a Big.js `formatQuantity` to `@/lib/decimal`.
- HIGH-4 (multi-currency aggregation) → Task 3 `resolveCurrency()` enforces single currency + returns `currencyCode` in the DTO (Task 1).
- HIGH-5 (stock-alerts conditional) → Task 11 wires `location_ids` into `useLowStockAlerts` mandatorily.

**MEDIUM:** percent rounding (Task 3 `bcround`, test `66.67`); no `as any` (Task 9 typed fixtures); layout integration test (Task 8); thicker endpoint test incl. returns/scope/403 (Task 4).

**Placeholder scan:** no TBD/TODO; "implementer notes" point to matching existing sibling code (mock paths, factory drift) and name the file to copy from — explicit, not deferred work.

**Type consistency:** DTO field names (`currencyCode/grossSales/returnsAmount/netSales/salesCount/returnsCount/itemsSold/averageBasket/delta.{grossSalesAbs,grossSalesPct,salesCountAbs,salesCountPct}`) identical across Task 1 (PHP), 5 (TS), 7 (component), 10 (mock). `fetchSalesSummary`/`useSalesSummary` consistent 5→10. `rollupSalesByPeriod` consistent Task 9. `showLocationSwitcher` consistent Task 8. `OwnerDashboardFiltersValue.locationIds` consistent Task 11. `ownerReports` module key consistent Task 10.

**Residual risk:** SQLite-vs-PG numeric exactness in Task 3 (mitigated: scale-friendly values + PG recommendation). `pos_receipts.total` is `decimal(12,2)` storage, so a TND third decimal is always 0 from this table — acceptable (display formats to resolver scale); not in scope to change the fiscal schema.

# Owner Reporting Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated, permission-gated owner Reports section that aggregates sales across all locations, fronted by a KPI-card row + sales trend, sourced from a new precision-correct backend summary service.

**Architecture:** Backend adds one new application service (`OwnerSalesSummaryService`) + DTO + endpoint in the existing Accounting owner-report surface (NOT extending the float-tainted `SalesReportService`). Frontend relocates the existing `OwnerDashboardPage` out of the main Dashboard into a new `dashboard.owner`-gated Reports route where the header `LocationSwitcher` is hidden via a layout prop, and adds a KPI row, a rolled-up sales trend, and a location scope multiselect.

**Tech Stack:** Laravel 12 (PHP 8.2 strict), Spatie LaravelData + TypeScriptTransformer, PostgreSQL, BCMath; React 19 / TS strict / TanStack Query 5 / ECharts / Vitest.

## Global Constraints

- Constructor injection only; never `app()`. (CLAUDE.md rule 13)
- Strict typing: no `mixed` (PHP), no `any` (TS). (rule 3)
- Money/quantity NEVER touch float: backend `CurrencyScale::bcformatStrict((string) $value, $scale)` with injected `CurrencyScaleResolverInterface`; frontend `formatCurrency`/`formatQuantity` from `@/lib/decimal` (Big.js-based) — never `parseFloat`/`Number(...)` on money. Percent is NOT currency-scaled. (rule 19)
- Enum values via `ReceiptType::Sale->value` / `ReceiptType::Return->value` — never string literals. (rule 9)
- Route added inside existing Accounting group: middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` + `->middleware('can:dashboard.owner')`. (rule 12)
- Types flow from backend: run `php artisan typescript:transform` after DTO changes; never hand-author domain response types. (rule 7)
- `apiGet`/`apiPost` already unwrap `response.data.data`; the summary returns a single object under `{ "data": {...} }`, so `apiGet<T>` returns the object directly — no double-unwrap. (rule 14)
- All user-facing text via `t()` in the `reports` i18n namespace (en + fr). (rule 11)
- New `.tsx` uses `@/lib/designTokens` only; reusing existing shared `ui` components (e.g. `StatCard`) as-is is allowed. (rule 18)
- TDD: red → green → commit per task. NEVER run the full PHPUnit suite (crashes the laptop) — always `--filter`/path-scoped. (rule 2 + memory)
- SQL aggregates use `CASE WHEN ... END` (portable across the SQLite Unit suite and PG) — never `FILTER (WHERE ...)`.
- Scope: receipts with `is_voided = false AND training_flag = false`, filtered by `OwnerReportScope`, `posted_at ∈ [from.startOfDay, to.endOfDay]`.

---

### Task 1: `SalesSummaryData` + `SalesSummaryDeltaData` DTOs

**Files:**
- Create: `apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php`
- Create: `apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php`
- Test: `apps/api/tests/Unit/Modules/Accounting/Reports/SalesSummaryDataTest.php`

**Interfaces:**
- Produces: `SalesSummaryData` (Spatie `Data`, `#[TypeScript]`) with readonly fields:
  `grossSales:string, returnsAmount:string, netSales:string, salesCount:int, returnsCount:int, itemsSold:string, averageBasket:?string, delta:SalesSummaryDeltaData`.
- Produces: `SalesSummaryDeltaData` with readonly fields:
  `grossSalesAbs:string, grossSalesPct:?string, salesCountAbs:int, salesCountPct:?string`.
  (`*Pct` is `null` when the prior-period base is zero — render "new", never divide by zero.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Accounting\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryDeltaData;
use Tests\TestCase;

final class SalesSummaryDataTest extends TestCase
{
    public function test_it_exposes_summary_fields_as_strings(): void
    {
        $dto = new SalesSummaryData(
            grossSales: '1500.000',
            returnsAmount: '50.000',
            netSales: '1450.000',
            salesCount: 12,
            returnsCount: 1,
            itemsSold: '34.0000',
            averageBasket: '125.000',
            delta: new SalesSummaryDeltaData(
                grossSalesAbs: '200.000',
                grossSalesPct: '15.38',
                salesCountAbs: 3,
                salesCountPct: '33.33',
            ),
        );

        $array = $dto->toArray();

        $this->assertSame('1500.000', $array['grossSales']);
        $this->assertSame('50.000', $array['returnsAmount']);
        $this->assertSame(12, $array['salesCount']);
        $this->assertNull((new SalesSummaryDeltaData('0.000', null, 0, null))->grossSalesPct);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Accounting/Reports/SalesSummaryDataTest.php`
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

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Accounting/Reports/SalesSummaryDataTest.php`
Expected: PASS.

- [ ] **Step 5: Generate TypeScript types**

Run: `cd apps/api && php artisan typescript:transform`
Expected: regenerates the shared types; `SalesSummaryData` and `SalesSummaryDeltaData` appear under `App.Modules.Accounting.Application.DTOs.Reports` in the generated `.d.ts` (e.g. `packages/shared/types/generated.d.ts`). Verify: `grep -r "SalesSummaryData" packages/shared/types`.

- [ ] **Step 6: Run Pint + PHPStan (scoped)**

Run: `cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Application/DTOs/Reports && ./vendor/bin/phpstan analyse app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php`
Expected: no style diff, 0 errors.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryData.php \
        apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesSummaryDeltaData.php \
        apps/api/tests/Unit/Modules/Accounting/Reports/SalesSummaryDataTest.php \
        packages/shared/types
git commit -m "feat(reports): owner sales summary DTOs + generated types"
```

---

### Task 2: `OwnerSalesSummaryService` (precision-correct aggregate)

**Files:**
- Create: `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php`
- Test: `apps/api/tests/Feature/Modules/Accounting/Reports/OwnerSalesSummaryServiceTest.php`

**Interfaces:**
- Consumes: `DateRangeData` (`->from`, `->to` are `CarbonImmutable`), `CurrencyScaleResolverInterface::getScale(?string)`, `CurrencyScale::bcformatStrict(string,int)`, `ReceiptType::{Sale,Return}->value`.
- Produces: `OwnerSalesSummaryService::summary(DateRangeData $range, array $companyIds, array $locationIds): SalesSummaryData`. The prior window is `[from - (to-from), from)` at day granularity. Returns zero-filled summary (deltas `null` pct) when scope arrays are empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounting\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\Services\Reports\OwnerSalesSummaryService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OwnerSalesSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedReceipt(string $companyId, string $locationId, string $type, string $total, string $postedAt, string $qty): void
    {
        $receiptId = (string) Str::uuid();
        DB::table('pos_receipts')->insert([
            'id' => $receiptId,
            'company_id' => $companyId,
            'location_id' => $locationId,
            'receipt_type' => $type,
            'total' => $total,
            'subtotal' => $total,
            'tax_amount' => '0',
            'discount_amount' => '0',
            'is_voided' => false,
            'training_flag' => false,
            'posted_at' => $postedAt,
            'created_at' => $postedAt,
            'updated_at' => $postedAt,
        ]);
        DB::table('pos_receipt_lines')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receiptId,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => $qty,
            'line_total' => $total,
            'unit_price' => $total,
        ]);
    }

    public function test_summary_separates_gross_sales_and_returns(): void
    {
        $companyId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['id' => $locationId, 'company_id' => $companyId, 'name' => 'Tunis', 'created_at' => now(), 'updated_at' => now()]);

        // current period: 2 sales (100 + 200), 1 return (-50)
        $this->seedReceipt($companyId, $locationId, ReceiptType::Sale->value, '100.000', '2026-06-10 10:00:00', '2.0000');
        $this->seedReceipt($companyId, $locationId, ReceiptType::Sale->value, '200.000', '2026-06-11 10:00:00', '3.0000');
        $this->seedReceipt($companyId, $locationId, ReceiptType::Return->value, '-50.000', '2026-06-12 10:00:00', '-1.0000');
        // prior period (2026-06-01..06-08): 1 sale (150)
        $this->seedReceipt($companyId, $locationId, ReceiptType::Sale->value, '150.000', '2026-06-05 10:00:00', '1.0000');

        $service = $this->app->make(OwnerSalesSummaryService::class);
        $summary = $service->summary(
            new DateRangeData(CarbonImmutable::parse('2026-06-09'), CarbonImmutable::parse('2026-06-16')),
            [$companyId],
            [$locationId],
        );

        $this->assertSame('300.000', $summary->grossSales);
        $this->assertSame('50.000', $summary->returnsAmount);
        $this->assertSame('250.000', $summary->netSales);
        $this->assertSame(2, $summary->salesCount);
        $this->assertSame(1, $summary->returnsCount);
        $this->assertSame('5.0000', $summary->itemsSold);
        $this->assertSame('150.000', $summary->averageBasket);
        // prior gross 150 → delta abs 150, pct 100.00
        $this->assertSame('150.000', $summary->delta->grossSalesAbs);
        $this->assertSame('100.00', $summary->delta->grossSalesPct);
    }

    public function test_zero_previous_period_yields_null_percentages(): void
    {
        $companyId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locations')->insert(['id' => $locationId, 'company_id' => $companyId, 'name' => 'Tunis', 'created_at' => now(), 'updated_at' => now()]);
        $this->seedReceipt($companyId, $locationId, ReceiptType::Sale->value, '100.000', '2026-06-10 10:00:00', '1.0000');

        $service = $this->app->make(OwnerSalesSummaryService::class);
        $summary = $service->summary(
            new DateRangeData(CarbonImmutable::parse('2026-06-09'), CarbonImmutable::parse('2026-06-16')),
            [$companyId],
            [$locationId],
        );

        $this->assertNull($summary->delta->grossSalesPct);
        $this->assertNull($summary->delta->salesCountPct);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Accounting/Reports/OwnerSalesSummaryServiceTest.php`
Expected: FAIL — service does not exist. (If `pos_receipts`/`pos_receipt_lines` columns differ, adjust the seed insert to the real non-null columns — check the migration first.)

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
use Illuminate\Support\Facades\DB;

final class OwnerSalesSummaryService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     */
    public function summary(DateRangeData $range, array $companyIds, array $locationIds): SalesSummaryData
    {
        $moneyScale = $this->scaleResolver->getScale();
        $qtyScale = 4;

        if ($companyIds === [] || $locationIds === []) {
            return $this->zero($moneyScale, $qtyScale);
        }

        $current = $this->aggregate($range, $companyIds, $locationIds);

        $lengthDays = (int) $range->from->startOfDay()->diffInDays($range->to->startOfDay()) + 1;
        $priorRange = new DateRangeData(
            from: $range->from->subDays($lengthDays),
            to: $range->from->subDay(),
        );
        $prior = $this->aggregate($priorRange, $companyIds, $locationIds);

        $grossSales = CurrencyScale::bcformatStrict((string) $current['gross'], $moneyScale);
        $returnsAmount = CurrencyScale::bcformatStrict((string) $current['returns'], $moneyScale);
        $netSales = CurrencyScale::bcformatStrict(bcsub((string) $current['gross'], (string) $current['returns'], $moneyScale + 1), $moneyScale);
        $itemsSold = CurrencyScale::bcformatStrict((string) $current['items'], $qtyScale);
        $averageBasket = $current['saleCount'] > 0
            ? CurrencyScale::bcformatStrict(bcdiv((string) $current['gross'], (string) $current['saleCount'], $moneyScale + 1), $moneyScale)
            : null;

        return new SalesSummaryData(
            grossSales: $grossSales,
            returnsAmount: $returnsAmount,
            netSales: $netSales,
            salesCount: (int) $current['saleCount'],
            returnsCount: (int) $current['returnCount'],
            itemsSold: $itemsSold,
            averageBasket: $averageBasket,
            delta: new SalesSummaryDeltaData(
                grossSalesAbs: CurrencyScale::bcformatStrict(bcsub((string) $current['gross'], (string) $prior['gross'], $moneyScale + 1), $moneyScale),
                grossSalesPct: $this->pct((string) $current['gross'], (string) $prior['gross']),
                salesCountAbs: (int) $current['saleCount'] - (int) $prior['saleCount'],
                salesCountPct: $this->pct((string) $current['saleCount'], (string) $prior['saleCount']),
            ),
        );
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
            ->selectRaw("COALESCE(SUM(CASE WHEN receipt_type = ? THEN total ELSE 0 END), 0) as gross", [$sale])
            ->selectRaw("COALESCE(ABS(SUM(CASE WHEN receipt_type = ? THEN total ELSE 0 END)), 0) as returns", [$return])
            ->selectRaw("COUNT(CASE WHEN receipt_type = ? THEN 1 END) as sale_count", [$sale])
            ->selectRaw("COUNT(CASE WHEN receipt_type = ? THEN 1 END) as return_count", [$return])
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
        if (bccomp($prior, '0', 4) === 0) {
            return null;
        }

        return bcadd(bcmul(bcdiv(bcsub($current, $prior, 6), $prior, 6), '100', 4), '0', 2);
    }

    private function zero(int $moneyScale, int $qtyScale): SalesSummaryData
    {
        $zeroMoney = CurrencyScale::bcformatStrict('0', $moneyScale);

        return new SalesSummaryData(
            grossSales: $zeroMoney,
            returnsAmount: $zeroMoney,
            netSales: $zeroMoney,
            salesCount: 0,
            returnsCount: 0,
            itemsSold: CurrencyScale::bcformatStrict('0', $qtyScale),
            averageBasket: null,
            delta: new SalesSummaryDeltaData($zeroMoney, null, 0, null),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Accounting/Reports/OwnerSalesSummaryServiceTest.php`
Expected: PASS. (This is a Feature test; if the local default connection is SQLite it will still run — the CASE/ABS expressions are portable. The numeric exactness assertions hold on PG; if SQLite returns a float-formatted intermediate, prefer running against the PG test connection per the project's PG test recipe.)

- [ ] **Step 5: Pint + PHPStan (scoped)**

Run: `cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php && ./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php`
Expected: 0 errors (note: the new service has constructor injection and no float casts — the `ForbidFloatCastOnDecimalProperty`/`ForbidHardcodedBcmathScale` guards should pass since scales come from the resolver, not literals on money).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php \
        apps/api/tests/Feature/Modules/Accounting/Reports/OwnerSalesSummaryServiceTest.php
git commit -m "feat(reports): precision-correct OwnerSalesSummaryService"
```

---

### Task 3: `salesSummary` endpoint (controller + route)

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php` (add `OwnerSalesSummaryService` to constructor + new `salesSummary()` action)
- Modify: `apps/api/app/Modules/Accounting/Presentation/routes.php` (add route in the "Owner Reporting MVP" block)
- Test: `apps/api/tests/Feature/Modules/Accounting/Reports/SalesSummaryEndpointTest.php`

**Interfaces:**
- Consumes: `OwnerSalesSummaryService::summary()`, `GetOwnerSalesReportRequest` (reused), `OwnerReportScope`, `$this->ownerUser()`.
- Produces: `GET /api/v1/reports/sales/summary` → `{ "data": SalesSummaryData }`, gated `can:dashboard.owner`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounting\Reports;

use Tests\Feature\Modules\Accounting\Reports\Concerns\SeedsOwnerReportingTenant; // see note
use Tests\TestCase;

final class SalesSummaryEndpointTest extends TestCase
{
    public function test_owner_can_fetch_sales_summary(): void
    {
        // Arrange: authenticate a user with dashboard.owner + company context + 1 sale.
        // Reuse the same seeding helper the existing owner-report endpoint tests use
        // (search tests/Feature/Modules/Accounting for the salesByLocation endpoint test
        // and mirror its auth/company-context setup).
        $owner = $this->actingAsOwnerWithSales(grossSales: '120.000');

        $response = $this->getJson('/api/v1/reports/sales/summary?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertJsonPath('data.grossSales', '120.000')
            ->assertJsonStructure(['data' => ['grossSales', 'returnsAmount', 'salesCount', 'averageBasket', 'delta' => ['grossSalesAbs', 'grossSalesPct']]]);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $this->actingAsUserWithoutPermission('dashboard.owner');

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-01&to=2026-06-30')
            ->assertForbidden();
    }
}
```

> **Implementer note:** The two helper methods above (`actingAsOwnerWithSales`, `actingAsUserWithoutPermission`) must mirror the EXISTING owner-report endpoint tests. Before writing this test, open the existing test for `reports/sales/by-location` (search `tests/Feature/Modules/Accounting` for `by-location` / `dashboard.owner`) and reuse its exact authentication, `RolesAndPermissionsSeeder`, and company-context bootstrap. Do not invent a new auth pattern.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Accounting/Reports/SalesSummaryEndpointTest.php`
Expected: FAIL — route/action missing (404/500).

- [ ] **Step 3: Add the service to the controller constructor + the action**

In `ReportsController.php` constructor, add the import and parameter:
```php
use App\Modules\Accounting\Application\Services\Reports\OwnerSalesSummaryService;
// ...
        private readonly OwnerSalesSummaryService $ownerSalesSummaryService,
```

Add the action (mirror `salesByLocation`):
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

- [ ] **Step 4: Add the route**

In `routes.php`, inside the `// Owner Reporting MVP` block (next to `reports/sales/by-location`):
```php
Route::get('/reports/sales/summary', [ReportsController::class, 'salesSummary'])
    ->middleware('can:dashboard.owner')
    ->name('reports.sales.summary');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Accounting/Reports/SalesSummaryEndpointTest.php`
Expected: PASS (200 with grossSales; 403 for non-owner).

- [ ] **Step 6: Pint + PHPStan (scoped) + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Accounting/Presentation && ./vendor/bin/phpstan analyse app/Modules/Accounting/Presentation/Controllers/ReportsController.php
git add apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php \
        apps/api/app/Modules/Accounting/Presentation/routes.php \
        apps/api/tests/Feature/Modules/Accounting/Reports/SalesSummaryEndpointTest.php
git commit -m "feat(reports): GET /reports/sales/summary owner endpoint"
```

---

### Task 4: `fetchSalesSummary` API client + `useSalesSummary` hook

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts`
- Modify: `apps/web/src/features/owner-dashboard/hooks/useOwnerReports.ts`
- Test: `apps/web/src/features/owner-dashboard/api/__tests__/ownerReportsApi.summary.test.ts`

**Interfaces:**
- Produces: `SalesSummaryReport` type alias; `fetchSalesSummary(params: OwnerDateRangeParams): Promise<SalesSummaryReport>`; `useSalesSummary(params, canFetch?)` returning a TanStack query of `SalesSummaryReport`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it, vi } from 'vitest'
import { fetchSalesSummary } from '../ownerReportsApi'
import { apiGet } from '@/lib/api'

vi.mock('@/lib/api', () => ({ apiGet: vi.fn().mockResolvedValue({ grossSales: '120.000' }) }))

describe('fetchSalesSummary', () => {
  it('requests the summary endpoint with date + location params', async () => {
    await fetchSalesSummary({ from: '2026-06-01', to: '2026-06-30', location_ids: ['loc-1'] })
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('/reports/sales/summary?'))
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('location_ids%5B%5D=loc-1'))
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/api/__tests__/ownerReportsApi.summary.test.ts`
Expected: FAIL — `fetchSalesSummary` is not exported.

- [ ] **Step 3: Add the type + fetcher to `ownerReportsApi.ts`**

```ts
export type SalesSummaryReport = App.Modules.Accounting.Application.DTOs.Reports.SalesSummaryData

export async function fetchSalesSummary(params: OwnerDateRangeParams): Promise<SalesSummaryReport> {
  return apiGet<SalesSummaryReport>(`/reports/sales/summary?${buildParams(params)}`)
}
```

- [ ] **Step 4: Add the hook to `useOwnerReports.ts`**

Add `fetchSalesSummary` + `SalesSummaryReport` to the import block, add a key, and the hook:
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

- [ ] **Step 5: Run test + typecheck**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/api/__tests__/ownerReportsApi.summary.test.ts && pnpm typecheck`
Expected: PASS; no TS errors (the generated `SalesSummaryData` type from Task 1 resolves).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts \
        apps/web/src/features/owner-dashboard/hooks/useOwnerReports.ts \
        apps/web/src/features/owner-dashboard/api/__tests__/ownerReportsApi.summary.test.ts
git commit -m "feat(reports): web sales-summary api client + hook"
```

---

### Task 5: `SalesSummaryCards` KPI row component

**Files:**
- Create: `apps/web/src/features/owner-dashboard/components/SalesSummaryCards.tsx`
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx`

**Interfaces:**
- Consumes: `SalesSummaryReport`, `StatCard` from `@/components/ui/StatCard`, `formatCurrency`/`formatQuantity` from `@/lib/decimal`.
- Produces: `<SalesSummaryCards data={...} isLoading isError />` — 5 cards (Total sales, Transactions, Avg basket, Items sold, Returns). Skeleton while loading, error card on error. Delta chip on Total sales + Transactions via `StatCard.trend` (percent → `Number()` is allowed; percent is NOT money).

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesSummaryCards } from '../SalesSummaryCards'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

const data = {
  grossSales: '300.000', returnsAmount: '50.000', netSales: '250.000',
  salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.000',
  delta: { grossSalesAbs: '150.000', grossSalesPct: '100.00', salesCountAbs: 1, salesCountPct: '50.00' },
}

describe('SalesSummaryCards', () => {
  it('renders five KPI cards with values', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.transactions')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.returns')).toBeInTheDocument()
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

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx`
Expected: FAIL — component does not exist.

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

  const pct = (v: string | null): { value: number; label: string; isPositive: boolean } | undefined =>
    v === null ? undefined : { value: Number(v), label: t('reports:ownerDashboard.kpi.vsPrevious'), isPositive: Number(v) >= 0 }

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <StatCard label={t('reports:ownerDashboard.kpi.totalSales')} value={formatCurrency(data.grossSales)} icon={ShoppingBag} trend={pct(data.delta.grossSalesPct)} />
      <StatCard label={t('reports:ownerDashboard.kpi.transactions')} value={String(data.salesCount)} icon={Receipt} trend={pct(data.delta.salesCountPct)} />
      <StatCard label={t('reports:ownerDashboard.kpi.avgBasket')} value={data.averageBasket ? formatCurrency(data.averageBasket) : '—'} icon={Wallet} />
      <StatCard label={t('reports:ownerDashboard.kpi.itemsSold')} value={formatQuantity(data.itemsSold)} icon={Package} />
      <StatCard label={t('reports:ownerDashboard.kpi.returns')} value={formatCurrency(data.returnsAmount)} icon={Undo2} />
    </div>
  )
}
```

> **Implementer note:** Confirm `formatQuantity` is exported from `@/lib/decimal`; if it lives elsewhere, import from the module the sibling widgets use. `formatCurrency(value)` defaults to EUR symbol — pass the tenant currency if the surrounding page has it; otherwise the default is acceptable for this iteration.

- [ ] **Step 4: Add i18n keys**

Add to `apps/web/src/locales/en/reports.json` (and the `fr` equivalent) under `ownerDashboard`:
```json
"kpi": {
  "totalSales": "Total sales",
  "transactions": "Transactions",
  "avgBasket": "Average basket",
  "itemsSold": "Items sold",
  "returns": "Returns",
  "vsPrevious": "vs previous period",
  "error": "Could not load summary"
}
```
(French: "Ventes totales", "Transactions", "Panier moyen", "Articles vendus", "Retours", "vs période précédente", "Impossible de charger le résumé".)

- [ ] **Step 5: Run test + lint**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx && pnpm lint --max-warnings=0 src/features/owner-dashboard/components/SalesSummaryCards.tsx`
Expected: PASS; no ESLint errors (no `parseFloat`/`Number` on money — `Number()` is only on the percent delta, which is allowed).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/owner-dashboard/components/SalesSummaryCards.tsx \
        apps/web/src/features/owner-dashboard/components/__tests__/SalesSummaryCards.test.tsx \
        apps/web/src/locales/en/reports.json apps/web/src/locales/fr/reports.json
git commit -m "feat(reports): owner KPI summary cards with skeleton + error states"
```

---

### Task 6: `showLocationSwitcher` layout seam

**Files:**
- Modify: `apps/web/src/components/organisms/TopBar/TopBar.tsx` (add optional prop, render switcher conditionally)
- Modify: `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx` (compute + pass the prop based on route)
- Test: `apps/web/src/components/organisms/TopBar/__tests__/TopBar.locationSwitcher.test.tsx`

**Interfaces:**
- Produces: `TopBarProps.showLocationSwitcher?: boolean` (default `true`). When `false`, `<LocationSwitcher>` is not rendered.

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { TopBar } from '../TopBar'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k, i18n: { changeLanguage: vi.fn(), language: 'en' } }) }))
vi.mock('../../LocationSwitcher', () => ({ LocationSwitcher: () => <div data-testid="location-switcher" /> }))
vi.mock('../../CompanySelector', () => ({ CompanySelector: () => <div /> }))

describe('TopBar location switcher seam', () => {
  it('hides the location switcher when showLocationSwitcher is false', () => {
    render(<MemoryRouter><TopBar showLocationSwitcher={false} /></MemoryRouter>)
    expect(screen.queryByTestId('location-switcher')).not.toBeInTheDocument()
  })

  it('shows the location switcher by default', () => {
    render(<MemoryRouter><TopBar /></MemoryRouter>)
    expect(screen.getByTestId('location-switcher')).toBeInTheDocument()
  })
})
```

> If `TopBar` pulls other stores/hooks that break under render, mock them minimally (mirror existing TopBar tests if present). Keep mocks to what's needed to render.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/components/organisms/TopBar/__tests__/TopBar.locationSwitcher.test.tsx`
Expected: FAIL — prop not honored (switcher always renders).

- [ ] **Step 3: Add the prop in `TopBar.tsx`**

```tsx
interface TopBarProps {
  onMenuClick?: () => void
  onSearchClick?: () => void
  showLocationSwitcher?: boolean
}

export function TopBar({ onMenuClick, onSearchClick, showLocationSwitcher = true }: TopBarProps) {
```
Replace the unconditional render at line ~99:
```tsx
{showLocationSwitcher && <LocationSwitcher className="hidden lg:block" />}
```

- [ ] **Step 4: Pass the prop from `DashboardLayout.tsx`**

Add `useLocation` and derive the flag; render TopBar with it:
```tsx
import { Outlet, useLocation } from 'react-router-dom'
// inside the component:
const location = useLocation()
const showLocationSwitcher = !location.pathname.startsWith('/reports')
// ...
<TopBar onMenuClick={() => { setSidebarOpen(true) }} onSearchClick={openCommandPalette} showLocationSwitcher={showLocationSwitcher} />
```

> Route knowledge lives in the LAYOUT (which owns routing context), not in the global `TopBar`. Do NOT mutate `locationStore` anywhere in this task.

- [ ] **Step 5: Run test + typecheck**

Run: `cd apps/web && pnpm vitest run src/components/organisms/TopBar/__tests__/TopBar.locationSwitcher.test.tsx && pnpm typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/organisms/TopBar/TopBar.tsx \
        apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx \
        apps/web/src/components/organisms/TopBar/__tests__/TopBar.locationSwitcher.test.tsx
git commit -m "feat(reports): hide header LocationSwitcher on /reports via layout prop seam"
```

---

### Task 7: `SalesTrendChart` (rolled-up total trend)

**Files:**
- Create: `apps/web/src/features/owner-dashboard/components/SalesTrendChart.tsx`
- Create: `apps/web/src/features/owner-dashboard/lib/rollupSalesByPeriod.ts`
- Test: `apps/web/src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts`

**Interfaces:**
- Produces: `rollupSalesByPeriod(rows: SalesByLocationReport[]): { period: string; total: number }[]` — sums `gross_sales` per `period` across locations. `Number()` here is at the chart-adapter boundary ONLY (chart coordinates, not displayed money).
- Produces: `<SalesTrendChart data={SalesByLocationReport[]} isLoading isError />` (ECharts line).

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest'
import { rollupSalesByPeriod } from '../rollupSalesByPeriod'

describe('rollupSalesByPeriod', () => {
  it('sums gross sales per period across locations', () => {
    const rows = [
      { period: '2026-06-01', gross_sales: '100', location_id: 'a' },
      { period: '2026-06-01', gross_sales: '50', location_id: 'b' },
      { period: '2026-06-02', gross_sales: '200', location_id: 'a' },
    ] as any
    expect(rollupSalesByPeriod(rows)).toEqual([
      { period: '2026-06-01', total: 150 },
      { period: '2026-06-02', total: 200 },
    ])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts`
Expected: FAIL — module missing.

- [ ] **Step 3: Write the rollup + chart**

`rollupSalesByPeriod.ts`:
```ts
import type { SalesByLocationReport } from '../api/ownerReportsApi'

export function rollupSalesByPeriod(rows: SalesByLocationReport[]): { period: string; total: number }[] {
  const byPeriod = new Map<string, number>()
  for (const row of rows) {
    byPeriod.set(row.period, (byPeriod.get(row.period) ?? 0) + Number(row.gross_sales))
  }
  return [...byPeriod.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([period, total]) => ({ period, total }))
}
```

`SalesTrendChart.tsx` (mirror the existing `OwnerChart`/`SalesByLocationChart` ECharts wrapper — reuse the shared `OwnerChart` frame if present for loading/error/empty states):
```tsx
import ReactECharts from 'echarts-for-react'
import { useTranslation } from 'react-i18next'
import { OwnerChart } from './OwnerChart'
import { chartColors } from '@/lib/designTokens'
import { rollupSalesByPeriod } from '../lib/rollupSalesByPeriod'
import type { SalesByLocationReport } from '../api/ownerReportsApi'

interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  isLoading: boolean
  isError: boolean
}

export function SalesTrendChart({ data, isLoading, isError }: SalesTrendChartProps) {
  const { t } = useTranslation(['reports'])
  const series = rollupSalesByPeriod(data)

  return (
    <OwnerChart
      title={t('reports:ownerDashboard.salesTrend.title')}
      isLoading={isLoading}
      isError={isError}
      isEmpty={series.length === 0}
    >
      <ReactECharts
        option={{
          xAxis: { type: 'category', data: series.map((p) => p.period) },
          yAxis: { type: 'value' },
          series: [{ type: 'line', smooth: true, data: series.map((p) => p.total), itemStyle: { color: chartColors.primary } }],
          tooltip: { trigger: 'axis' },
          grid: { left: 48, right: 16, top: 24, bottom: 32 },
        }}
        style={{ height: 280 }}
      />
    </OwnerChart>
  )
}
```

> **Implementer note:** Verify the exact props of the shared `OwnerChart` frame (`title/isLoading/isError/isEmpty/children`) and `chartColors.primary` in `@/lib/designTokens`; adapt names to match. If `OwnerChart` does not accept children, follow whatever wrapper `SalesByLocationChart.tsx` uses.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts && pnpm typecheck`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/owner-dashboard/components/SalesTrendChart.tsx \
        apps/web/src/features/owner-dashboard/lib/rollupSalesByPeriod.ts \
        apps/web/src/features/owner-dashboard/lib/__tests__/rollupSalesByPeriod.test.ts
git commit -m "feat(reports): rolled-up sales-over-time trend chart"
```

---

### Task 8: Owner Reports route + nav + relocate `OwnerDashboardPage` + retire orphan

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (insert KPI row + trend at the top, before the widget grid; add `useSalesSummary`)
- Modify: `apps/web/src/features/dashboard/Dashboard.tsx` (remove the `<OwnerDashboardPage />` embed at line ~314 and its import)
- Modify: `apps/web/src/routes/index.tsx` (add `/reports` route rendering `OwnerDashboardPage`, gated `dashboard.owner`)
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (add a "Reports" nav entry gated `dashboard.owner`)
- Delete: `apps/web/src/features/reports/ReportsPage.tsx` (+ remove any route/nav references to it)
- Test: `apps/web/src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx` (extend existing)

**Interfaces:**
- Consumes: `SalesSummaryCards` (Task 5), `SalesTrendChart` (Task 7), `useSalesSummary` (Task 4).
- Produces: `/reports` route showing KPI row + trend + existing widgets; main Dashboard no longer renders the owner section.

- [ ] **Step 1: Extend the existing test (red)**

In `OwnerDashboardPage.test.tsx`, add a `useSalesSummary` mock to the `useOwnerReports` mock block and a new assertion:
```tsx
  useSalesSummary: () => ({
    data: { grossSales: '300.000', returnsAmount: '50.000', netSales: '250.000', salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.000', delta: { grossSalesAbs: '150.000', grossSalesPct: '100.00', salesCountAbs: 1, salesCountPct: '50.00' } },
    isLoading: false, isError: false,
  }),
```
```tsx
  it('renders the KPI summary row', () => {
    render(<OwnerDashboardPage />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
  })
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx`
Expected: FAIL — KPI row not rendered yet.

- [ ] **Step 3: Insert KPI row + trend into `OwnerDashboardPage`**

Add imports + hook usage and render the row above the widget grid:
```tsx
import { SalesSummaryCards } from './components/SalesSummaryCards'
import { SalesTrendChart } from './components/SalesTrendChart'
import { useSalesByLocation, useSalesSummary, /* ...existing... */ } from './hooks/useOwnerReports'
// inside the component, alongside other hooks:
const summary = useSalesSummary(dateParams, canViewOwnerDashboard)
// in JSX, right after <OwnerDashboardFilters .../>:
<SalesSummaryCards data={summary.data} isLoading={summary.isLoading} isError={summary.isError} />
<SalesTrendChart data={sales.data ?? []} isLoading={sales.isLoading} isError={sales.isError} />
```

- [ ] **Step 4: Remove the embed from the main Dashboard**

In `Dashboard.tsx`, delete the `import { OwnerDashboardPage } from '../owner-dashboard'` line and the `<OwnerDashboardPage />` usage (~line 314).

- [ ] **Step 5: Add the route + nav + delete the orphan**

- In `routes/index.tsx`, add a route for `/reports` rendering `OwnerDashboardPage`, gated with the existing permission-guard pattern (mirror how other `dashboard.owner`/permission-gated routes are declared in this file — search for `dashboard.owner` or an existing `RequirePermission`/guard wrapper and copy it).
- In `Sidebar.tsx`, add a "Reports" entry (label via `t()`, gated `dashboard.owner`) following the existing nav-group pattern (lines ~262–278 for "Accounting & Reports"). Use a `BarChart3` lucide icon.
- Delete `features/reports/ReportsPage.tsx` and remove any import/route/nav references to it (grep `ReportsPage` first: `grep -rn "ReportsPage" apps/web/src`).

- [ ] **Step 6: Run tests + typecheck + lint**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard && pnpm typecheck && pnpm lint --max-warnings=0 src/features/owner-dashboard src/routes/index.tsx src/components/organisms/Sidebar/Sidebar.tsx`
Expected: PASS; no dangling `ReportsPage` references; no TS/lint errors.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/owner-dashboard apps/web/src/features/dashboard/Dashboard.tsx \
        apps/web/src/routes/index.tsx apps/web/src/components/organisms/Sidebar/Sidebar.tsx
git rm apps/web/src/features/reports/ReportsPage.tsx
git commit -m "feat(reports): dedicated owner Reports route + nav; relocate owner overview; retire orphan ReportsPage"
```

---

### Task 9: Location scope multiselect + wiring

**Files:**
- Modify: `apps/web/src/features/owner-dashboard/components/OwnerDashboardFilters.tsx` (add an "All locations" + per-store multiselect; extend `OwnerDashboardFiltersValue` with `locationIds: string[]`)
- Modify: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (pass `location_ids` into every hook's params)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/OwnerDashboardFilters.test.tsx`

**Interfaces:**
- Consumes: `useLocationStore.locations` as the read-only option source (NEVER `currentLocationId` / `switchLocation`).
- Produces: `OwnerDashboardFiltersValue` gains `locationIds: string[]` (`[]` = all locations). Page maps it to `location_ids` (omit the param when empty so the backend returns all).

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OwnerDashboardFilters } from '../OwnerDashboardFilters'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))
vi.mock('@/stores/locationStore', () => ({
  useLocationStore: (sel: any) => sel({ locations: [{ id: 'loc-1', name: 'Tunis' }, { id: 'loc-2', name: 'Marsa' }] }),
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

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/OwnerDashboardFilters.test.tsx`
Expected: FAIL — no location control.

- [ ] **Step 3: Extend the filter type + UI**

- Add `locationIds: string[]` to `OwnerDashboardFiltersValue`.
- Read options: `const locations = useLocationStore((s) => s.locations)`.
- Render a checkbox per location (label = name, `aria-label`/`htmlFor` = name) plus an "All locations" reset; toggling updates `value.locationIds` via `onChange`. Use design tokens; strings via `t()` (add keys `reports:ownerDashboard.filters.allLocations`, `...filters.locations`).
- `defaultFilters()` in `OwnerDashboardPage` must initialize `locationIds: []`.

- [ ] **Step 4: Wire `location_ids` into hooks**

In `OwnerDashboardPage`, build a param spread:
```tsx
const locationParam = filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}
const dateParams = useMemo(() => ({ from: filters.from, to: filters.to, ...locationParam }), [filters.from, filters.to, filters.locationIds])
```
Pass `dateParams` (now including `location_ids` when set) into `useSalesByLocation`, `useTopSkus`, `useRevenueByCategory`, `usePaymentMethodBreakdown`, `useCashRegisterReconciliation`, and `useSalesSummary`. (Stock alerts uses `location_ids` too — pass it through if the param shape allows.)

- [ ] **Step 5: Run tests + typecheck + lint**

Run: `cd apps/web && pnpm vitest run src/features/owner-dashboard && pnpm typecheck && pnpm lint --max-warnings=0 src/features/owner-dashboard`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/owner-dashboard
git commit -m "feat(reports): owner location scope multiselect wired through all owner reports"
```

---

## Self-Review

**Spec coverage (§ → task):**
- §2.1 dedicated section → Task 8. §2.2 owner-only / dashboard.owner gate → Tasks 3, 8. §2.3 KPI row + trend → Tasks 5, 7, 8. §4 KPI SQL contract → Task 2 (+ DTO Task 1). §5 backend (new service, DTO, endpoint, reused request, TDD) → Tasks 1–3. §6 frontend (route, relocate, layout-seam switcher hide, location scope, KPI row, trend, comparison reuse, i18n/tokens) → Tasks 4–9. §7 test fixtures only / no demo seeder → respected (Task 2 uses `RefreshDatabase`; no seeder task). §8 out-of-scope (margin, POS location filter, exports, Z-aggregation, finance absorption, no SalesReportService refactor) → none added. §10 resolved Q's → deltas null-on-zero (Task 2), location source = `useLocationStore.locations` separate state (Task 9), gross+returns cards (Task 5).
- Margin: correctly absent. Header switcher hidden via layout prop not route-sniff-in-TopBar: Task 6 ✓.

**Placeholder scan:** No "TBD/TODO". Two "implementer notes" point to verifying EXISTING patterns (auth helper in Task 3, OwnerChart/formatQuantity names in Tasks 5/7) — these are explicit "match the sibling code" instructions, not deferred work, because the exact local helper names must be read at implementation time; each note names the file to copy from.

**Type consistency:** DTO field names (`grossSales`, `returnsAmount`, `salesCount`, `averageBasket`, `delta.grossSalesPct`…) are identical across Task 1 (PHP), Task 4 (TS alias), Task 5 (component), Task 8 (page mock). `fetchSalesSummary`/`useSalesSummary` names consistent Tasks 4→8. `rollupSalesByPeriod` consistent Task 7. `showLocationSwitcher` consistent Task 6. `OwnerDashboardFiltersValue.locationIds` consistent Task 9.

**Known residual risk (flagged for Codex plan review):** SQLite vs PG numeric exactness in Task 2 assertions (mitigated: CASE/ABS portable; recommend PG test connection for exactness). Task 3 auth helpers depend on the existing endpoint test's pattern (explicit instruction to mirror). Sidebar/route guard wiring in Task 8 references existing patterns rather than reproducing the whole router.

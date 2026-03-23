# VAT Reporting Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add multi-country VAT reporting with period management, summary reports, and country-specific exports to the Taxation module.

**Architecture:** Strategy pattern per country (Tunisia, France, UK). Persisted `vat_periods` with status lifecycle (OPEN→CLOSED→FILED). Aggregation via `VatDataRepositoryInterface` querying existing `document_tax_details` and `pos_receipt_vat_details`. JSONB for country-specific declaration data. Exporters for PDF, CSV, FEC, MTD JSON, TEIF XML.

**Tech Stack:** Laravel 12 / PHP 8.2+ (backend), React 19 / TypeScript / TanStack Query 5 (frontend), PostgreSQL 16, Vitest (frontend tests), PHPUnit (backend tests).

**Spec:** `docs/superpowers/specs/2026-03-23-vat-reporting-design.md`

**Conventions:** Read `docs/conventions/README.md` before starting. Key rules: constructor injection only, no `mixed`/`any`, enums for all status columns, `['api', 'auth:sanctum', SetPermissionsTeam::class]` on all routes, `apiGet` already unwraps `response.data.data`.

---

## File Map

### Backend — New Files

| File | Layer | Responsibility |
|------|-------|---------------|
| `apps/api/app/Modules/Taxation/Domain/Entities/VatPeriod.php` | Domain | Period entity (Model + HasUuids) |
| `apps/api/app/Modules/Taxation/Domain/Entities/VatPeriodBreakdown.php` | Domain | Immutable breakdown entity (UPDATED_AT=null) |
| `apps/api/app/Modules/Taxation/Domain/Enums/VatPeriodStatus.php` | Domain | OPEN, CLOSED, FILED + label() |
| `apps/api/app/Modules/Taxation/Domain/Enums/VatPeriodType.php` | Domain | MONTHLY, QUARTERLY, ANNUAL + label() |
| `apps/api/app/Modules/Taxation/Domain/Enums/VatDirection.php` | Domain | OUTPUT, INPUT + label() |
| `apps/api/app/Modules/Taxation/Domain/Enums/VatExportFormat.php` | Domain | PDF, CSV, FEC, MTD_JSON, TEIF_XML + label() |
| `apps/api/app/Modules/Taxation/Domain/Repositories/VatPeriodRepositoryInterface.php` | Domain | Period persistence interface |
| `apps/api/app/Modules/Taxation/Domain/Repositories/VatDataRepositoryInterface.php` | Domain | Tax data aggregation interface |
| `apps/api/app/Modules/Taxation/Domain/Contracts/VatReportStrategyInterface.php` | Domain | Country strategy contract |
| `apps/api/app/Modules/Taxation/Domain/Contracts/VatExporterInterface.php` | Domain | Export format contract |
| `apps/api/app/Modules/Taxation/Domain/DTOs/VatAggregation.php` | Domain | Per-rate aggregation result (readonly) |
| `apps/api/app/Modules/Taxation/Domain/DTOs/VatSummary.php` | Domain | Full period summary (readonly) |
| `apps/api/app/Modules/Taxation/Domain/Services/VatCreditService.php` | Domain | Carry-forward calculations (pure bcmath) |
| `apps/api/app/Modules/Taxation/Domain/Events/VatPeriodClosed.php` | Domain | Event dispatched on close |
| `apps/api/app/Modules/Taxation/Domain/Events/VatPeriodFiled.php` | Domain | Event dispatched on file |
| `apps/api/app/Modules/Taxation/Application/DTOs/VatPeriodData.php` | Application | Period DTO (fromEntity + toArray) |
| `apps/api/app/Modules/Taxation/Application/DTOs/VatSummaryData.php` | Application | Summary DTO (fromEntity + toArray) |
| `apps/api/app/Modules/Taxation/Application/DTOs/VatDeclarationData.php` | Application | Country-mapped fields DTO |
| `apps/api/app/Modules/Taxation/Application/Services/VatPeriodManagementService.php` | Application | CRUD + status transitions |
| `apps/api/app/Modules/Taxation/Application/Services/VatReportGenerationService.php` | Application | Orchestrates aggregation + strategy |
| `apps/api/app/Modules/Taxation/Application/Services/VatExportService.php` | Application | Resolves exporter + generates file |
| `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatPeriodRepository.php` | Infrastructure | Period persistence implementation |
| `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php` | Infrastructure | Tax data aggregation queries |
| `apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php` | Infrastructure | Tunisia: monthly, 19/13/7%, timbre, retenue |
| `apps/api/app/Modules/Taxation/Infrastructure/Strategies/FranceVatStrategy.php` | Infrastructure | France: monthly CA3, 20/10/5.5/2.1% |
| `apps/api/app/Modules/Taxation/Infrastructure/Strategies/UkVatStrategy.php` | Infrastructure | UK: quarterly, 20/5/0%, 9-box |
| `apps/api/app/Modules/Taxation/Infrastructure/Exporters/PdfVatExporter.php` | Infrastructure | PDF summary export |
| `apps/api/app/Modules/Taxation/Infrastructure/Exporters/CsvVatExporter.php` | Infrastructure | CSV detail export |
| `apps/api/app/Modules/Taxation/Infrastructure/Exporters/FecExporter.php` | Infrastructure | France FEC (18-field flat file) |
| `apps/api/app/Modules/Taxation/Infrastructure/Exporters/MtdJsonExporter.php` | Infrastructure | UK MTD (9-box JSON) |
| `apps/api/app/Modules/Taxation/Infrastructure/Exporters/TeifXmlExporter.php` | Infrastructure | Tunisia TEIF XML |
| `apps/api/app/Modules/Taxation/Presentation/Controllers/VatPeriodController.php` | Presentation | Period CRUD + lifecycle endpoints |
| `apps/api/app/Modules/Taxation/Presentation/Controllers/VatReportController.php` | Presentation | Summary + export endpoints |
| `apps/api/app/Modules/Taxation/Presentation/Requests/GenerateVatPeriodsRequest.php` | Presentation | Validation for period generation |
| `apps/api/app/Modules/Taxation/Presentation/Requests/VatExportRequest.php` | Presentation | Validation for export format |
| `apps/api/app/Modules/Taxation/Presentation/Resources/VatPeriodResource.php` | Presentation | Period JSON serialization |
| `apps/api/app/Modules/Taxation/Presentation/Resources/VatSummaryResource.php` | Presentation | Summary JSON serialization |
| `apps/api/database/migrations/xxxx_create_vat_periods_table.php` | Migration | vat_periods + vat_period_breakdowns |

### Backend — Modified Files

| File | Change |
|------|--------|
| `apps/api/app/Modules/Taxation/Providers/TaxationServiceProvider.php` | Add service/repo bindings (after line 55) |
| `apps/api/app/Modules/Taxation/routes.php` | Add VAT period + report routes (after line 75) |
| `apps/api/database/seeders/RolesAndPermissionsSeeder.php` | Add `reports.manage` permission (line ~160, ~265, ~290) |

### Frontend — New Files

| File | Responsibility |
|------|---------------|
| `apps/web/src/features/vat-reporting/pages/VatPeriodsPage.tsx` | Period list page (container) |
| `apps/web/src/features/vat-reporting/pages/VatReportPage.tsx` | Period detail page (container) |
| `apps/web/src/features/vat-reporting/components/VatPeriodStatusBadge.tsx` | Atom: status badge |
| `apps/web/src/features/vat-reporting/components/VatSummaryCards.tsx` | Molecule: 4 stat cards |
| `apps/web/src/features/vat-reporting/components/VatBreakdownTable.tsx` | Molecule: rate-by-rate table |
| `apps/web/src/features/vat-reporting/components/VatPeriodList.tsx` | Organism: period table |
| `apps/web/src/features/vat-reporting/components/VatSpecialItems.tsx` | Molecule: country-specific items |
| `apps/web/src/features/vat-reporting/components/VatExportMenu.tsx` | Molecule: export dropdown |
| `apps/web/src/features/vat-reporting/hooks/useVatPeriods.ts` | useQuery for periods |
| `apps/web/src/features/vat-reporting/hooks/useVatReport.ts` | useQuery for report summary |
| `apps/web/src/features/vat-reporting/hooks/useVatPeriodActions.ts` | useMutation: generate/close/reopen/file |
| `apps/web/src/features/vat-reporting/hooks/useVatExport.ts` | useMutation: download export |
| `apps/web/src/features/vat-reporting/api.ts` | API functions |
| `apps/web/src/features/vat-reporting/types.ts` | TypeScript interfaces |

### Frontend — Modified Files

| File | Change |
|------|--------|
| `apps/web/src/routes/index.tsx` | Add lazy imports + routes (after line 134) |
| `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` | Add nav item to accountingAndReports (after line 232) |
| `apps/web/src/locales/en/finance.json` | Add vatReporting translation keys |
| `apps/web/src/locales/fr/finance.json` | Add vatReporting translation keys (French) |

### Test Files

| File | Tests |
|------|-------|
| `apps/api/tests/Unit/Taxation/VatCreditServiceTest.php` | Carry-forward logic |
| `apps/api/tests/Unit/Taxation/VatPeriodEntityTest.php` | Entity, casts, scopes |
| `apps/api/tests/Unit/Taxation/TunisiaVatStrategyTest.php` | Period generation, special items, rates |
| `apps/api/tests/Unit/Taxation/FranceVatStrategyTest.php` | Period generation, CA3 mapping |
| `apps/api/tests/Unit/Taxation/UkVatStrategyTest.php` | Period generation, 9-box mapping |
| `apps/api/tests/Unit/Taxation/CsvVatExporterTest.php` | CSV format output |
| `apps/api/tests/Unit/Taxation/MtdJsonExporterTest.php` | 9-box JSON structure |
| `apps/api/tests/Unit/Taxation/FecExporterTest.php` | 18-field tab-delimited format |
| `apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php` | Aggregation query correctness |
| `apps/api/tests/Unit/Taxation/VatPeriodManagementServiceTest.php` | Status transitions, chain protection |
| `apps/api/tests/Feature/Taxation/VatPeriodControllerTest.php` | API endpoints, auth, validation |
| `apps/api/tests/Feature/Taxation/VatReportControllerTest.php` | Summary + export endpoints |
| `apps/api/database/factories/VatPeriodFactory.php` | Test factory for VatPeriod |
| `apps/web/src/features/vat-reporting/components/__tests__/VatPeriodStatusBadge.test.tsx` | Renders correct colors |
| `apps/web/src/features/vat-reporting/components/__tests__/VatBreakdownTable.test.tsx` | Renders rate rows |
| `apps/web/src/features/vat-reporting/components/__tests__/VatSummaryCards.test.tsx` | Renders stat cards |

---

## Task 1: Database Schema + Enums + Entities

**Files:**
- Create: `apps/api/database/migrations/xxxx_create_vat_periods_table.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Enums/VatPeriodStatus.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Enums/VatPeriodType.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Enums/VatDirection.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Enums/VatExportFormat.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Entities/VatPeriod.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Entities/VatPeriodBreakdown.php`
- Create: `apps/api/database/factories/VatPeriodFactory.php`
- Test: `apps/api/tests/Unit/Taxation/VatPeriodEntityTest.php`

- [ ] **Step 1: Write the entity test**

```php
// tests/Unit/Taxation/VatPeriodEntityTest.php
<?php
declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Entities\VatPeriodBreakdown;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Domain\Enums\VatDirection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatPeriodEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_period_casts_enums_correctly(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->createCompany()->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Open,
        ]);

        $this->assertInstanceOf(VatPeriodStatus::class, $period->status);
        $this->assertInstanceOf(VatPeriodType::class, $period->period_type);
        $this->assertEquals(VatPeriodStatus::Open, $period->status);
    }

    public function test_vat_period_has_breakdowns_relationship(): void
    {
        $period = VatPeriod::factory()->create();

        $breakdown = VatPeriodBreakdown::create([
            'vat_period_id' => $period->id,
            'direction' => VatDirection::Output,
            'tax_rate' => '19.00',
            'base_amount' => '10000.000',
            'vat_amount' => '1900.000',
            'document_count' => 5,
            'is_recoverable' => true,
        ]);

        $this->assertCount(1, $period->fresh()->breakdowns);
        $this->assertInstanceOf(VatDirection::class, $breakdown->direction);
    }

    public function test_vat_period_breakdown_is_immutable(): void
    {
        $this->assertNull(VatPeriodBreakdown::UPDATED_AT);
    }

    public function test_vat_period_status_labels(): void
    {
        $this->assertEquals('Open', VatPeriodStatus::Open->label());
        $this->assertEquals('Closed', VatPeriodStatus::Closed->label());
        $this->assertEquals('Filed', VatPeriodStatus::Filed->label());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatPeriodEntityTest.php --filter=test_vat_period_casts_enums_correctly`
Expected: FAIL (class not found)

- [ ] **Step 3: Create the 4 enums**

Create `VatPeriodStatus.php`, `VatPeriodType.php`, `VatDirection.php`, `VatExportFormat.php` in `Domain/Enums/`. Each is a `string` backed enum with `label()` method. Follow the exact pattern from `TaxType.php` in the same directory.

```php
// VatPeriodStatus.php
<?php
declare(strict_types=1);
namespace App\Modules\Taxation\Domain\Enums;

enum VatPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Filed = 'FILED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Filed => 'Filed',
        };
    }
}
```

Same pattern for `VatPeriodType` (Monthly/Quarterly/Annual), `VatDirection` (Output/Input), `VatExportFormat` (Pdf/Csv/Fec/MtdJson/TeifXml).

- [ ] **Step 4: Create the migration**

Run: `cd apps/api && php artisan make:migration create_vat_periods_table`

```php
// Two tables in one migration
public function up(): void
{
    Schema::create('vat_periods', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('company_id')->constrained('companies');
        $table->char('country_code', 2);
        $table->foreign('country_code')->references('code')->on('countries');
        $table->string('period_type', 20);
        $table->string('label', 50);
        $table->date('period_start');
        $table->date('period_end');
        $table->string('status', 20)->default('OPEN');
        $table->decimal('total_output_vat', 15, 3)->nullable();
        $table->decimal('total_input_vat', 15, 3)->nullable();
        $table->decimal('net_vat', 15, 3)->nullable();
        $table->decimal('credit_brought_forward', 15, 3)->default(0);
        $table->decimal('credit_carried_forward', 15, 3)->default(0);
        $table->decimal('amount_payable', 15, 3)->default(0);
        $table->jsonb('special_items')->nullable();
        $table->jsonb('declaration_data')->nullable();
        $table->timestamp('closed_at')->nullable();
        $table->foreignUuid('closed_by')->nullable()->constrained('users');
        $table->timestamp('filed_at')->nullable();
        $table->foreignUuid('filed_by')->nullable()->constrained('users');
        $table->string('filing_reference')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();

        $table->unique(['company_id', 'period_start', 'period_end']);
        $table->index(['company_id', 'status']);
        $table->index(['company_id', 'country_code', 'period_start']);
    });

    Schema::create('vat_period_breakdowns', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('vat_period_id')->constrained('vat_periods')->cascadeOnDelete();
        $table->string('direction', 10);
        $table->decimal('tax_rate', 5, 2);
        $table->foreignUuid('tax_configuration_id')->nullable()->constrained('tax_configurations')->nullOnDelete();
        $table->decimal('base_amount', 15, 3);
        $table->decimal('vat_amount', 15, 3);
        $table->integer('document_count');
        $table->boolean('is_recoverable')->default(true);
        $table->timestamp('created_at')->nullable();

        $table->index('vat_period_id');
        $table->index(['vat_period_id', 'direction']);
        $table->unique(['vat_period_id', 'direction', 'tax_rate', 'is_recoverable']);
    });
}
```

- [ ] **Step 5: Create VatPeriod entity**

```php
// Domain/Entities/VatPeriod.php
<?php
declare(strict_types=1);
namespace App\Modules\Taxation\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
// ... imports for enums, Carbon

/**
 * @property string $id
 * @property string $company_id
 * @property string $country_code
 * @property VatPeriodType $period_type
 * @property string $label
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property VatPeriodStatus $status
 * @property string|null $total_output_vat
 * @property string|null $total_input_vat
 * @property string|null $net_vat
 * @property string $credit_brought_forward
 * @property string $credit_carried_forward
 * @property string $amount_payable
 * @property array|null $special_items
 * @property array|null $declaration_data
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property Carbon|null $filed_at
 * @property string|null $filed_by
 * @property string|null $filing_reference
 * @property string|null $notes
 */
class VatPeriod extends Model
{
    use HasUuids;

    protected $table = 'vat_periods';

    protected $fillable = [
        'company_id', 'country_code', 'period_type', 'label',
        'period_start', 'period_end', 'status',
        'total_output_vat', 'total_input_vat', 'net_vat',
        'credit_brought_forward', 'credit_carried_forward', 'amount_payable',
        'special_items', 'declaration_data',
        'closed_at', 'closed_by', 'filed_at', 'filed_by',
        'filing_reference', 'notes',
    ];

    protected $casts = [
        'period_type' => VatPeriodType::class,
        'status' => VatPeriodStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'total_output_vat' => 'decimal:3',
        'total_input_vat' => 'decimal:3',
        'net_vat' => 'decimal:3',
        'credit_brought_forward' => 'decimal:3',
        'credit_carried_forward' => 'decimal:3',
        'amount_payable' => 'decimal:3',
        'special_items' => 'array',
        'declaration_data' => 'array',
        'closed_at' => 'datetime',
        'filed_at' => 'datetime',
    ];

    public function breakdowns(): HasMany
    {
        return $this->hasMany(VatPeriodBreakdown::class);
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->whereYear('period_start', $year);
    }

    public function isOpen(): bool
    {
        return $this->status === VatPeriodStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === VatPeriodStatus::Closed;
    }

    public function isFiled(): bool
    {
        return $this->status === VatPeriodStatus::Filed;
    }
}
```

- [ ] **Step 6: Create VatPeriodBreakdown entity**

Same pattern but with `public const UPDATED_AT = null;` for immutability. Casts `direction` to `VatDirection::class`, `tax_rate` to `decimal:2`, amounts to `decimal:3`.

- [ ] **Step 7: Create VatPeriodFactory**

```php
// database/factories/VatPeriodFactory.php
<?php
declare(strict_types=1);
namespace Database\Factories;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Database\Eloquent\Factories\Factory;

class VatPeriodFactory extends Factory
{
    protected $model = VatPeriod::class;

    public function definition(): array
    {
        return [
            'company_id' => \App\Modules\Company\Domain\Company::factory(),
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Open,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => VatPeriodStatus::Open]);
    }

    public function closed(): static
    {
        return $this->state([
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
            'total_output_vat' => '10000.000',
            'total_input_vat' => '6000.000',
            'net_vat' => '4000.000',
            'amount_payable' => '4000.000',
        ]);
    }

    public function filed(): static
    {
        return $this->state([
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
            'total_output_vat' => '10000.000',
            'total_input_vat' => '6000.000',
            'net_vat' => '4000.000',
            'amount_payable' => '4000.000',
        ]);
    }
}
```

Also add `HasFactory` trait to `VatPeriod` entity and reference the factory.

- [ ] **Step 8: Run migration**

Run: `cd apps/api && php artisan migrate`

- [ ] **Step 9: Run tests**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatPeriodEntityTest.php`
Expected: all 4 tests PASS

- [ ] **Step 10: Commit**

```bash
git add apps/api/database/migrations/ apps/api/app/Modules/Taxation/Domain/Enums/ apps/api/app/Modules/Taxation/Domain/Entities/VatPeriod.php apps/api/app/Modules/Taxation/Domain/Entities/VatPeriodBreakdown.php apps/api/tests/Unit/Taxation/VatPeriodEntityTest.php
git commit -m "feat(taxation): add VatPeriod schema, enums, and entities"
```

---

## Task 2: Domain DTOs + VatCreditService

**Files:**
- Create: `apps/api/app/Modules/Taxation/Domain/DTOs/VatAggregation.php`
- Create: `apps/api/app/Modules/Taxation/Domain/DTOs/VatSummary.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Services/VatCreditService.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Events/VatPeriodClosed.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Events/VatPeriodFiled.php`
- Test: `apps/api/tests/Unit/Taxation/VatCreditServiceTest.php`

- [ ] **Step 1: Write the VatCreditService test**

```php
// tests/Unit/Taxation/VatCreditServiceTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\Services\VatCreditService;
use PHPUnit\Framework\TestCase;

class VatCreditServiceTest extends TestCase
{
    private VatCreditService $service;

    protected function setUp(): void
    {
        $this->service = new VatCreditService();
    }

    public function test_net_positive_no_credit_forward(): void
    {
        // Output > Input, no previous credit
        $result = $this->service->calculate(
            totalOutputVat: '9500.000',
            totalInputVat: '5700.000',
            creditBroughtForward: '0.000',
        );

        $this->assertEquals('3800.000', $result['net_vat']);
        $this->assertEquals('3800.000', $result['amount_payable']);
        $this->assertEquals('0.000', $result['credit_carried_forward']);
    }

    public function test_net_negative_generates_credit(): void
    {
        // Input > Output → credit
        $result = $this->service->calculate(
            totalOutputVat: '3000.000',
            totalInputVat: '5000.000',
            creditBroughtForward: '0.000',
        );

        $this->assertEquals('-2000.000', $result['net_vat']);
        $this->assertEquals('0.000', $result['amount_payable']);
        $this->assertEquals('2000.000', $result['credit_carried_forward']);
    }

    public function test_credit_fully_covers_liability(): void
    {
        $result = $this->service->calculate(
            totalOutputVat: '5000.000',
            totalInputVat: '3000.000',
            creditBroughtForward: '3000.000',
        );

        $this->assertEquals('2000.000', $result['net_vat']);
        $this->assertEquals('0.000', $result['amount_payable']);
        $this->assertEquals('1000.000', $result['credit_carried_forward']);
    }

    public function test_credit_partially_covers_liability(): void
    {
        $result = $this->service->calculate(
            totalOutputVat: '5000.000',
            totalInputVat: '2000.000',
            creditBroughtForward: '1000.000',
        );

        $this->assertEquals('3000.000', $result['net_vat']);
        $this->assertEquals('2000.000', $result['amount_payable']);
        $this->assertEquals('0.000', $result['credit_carried_forward']);
    }

    public function test_credit_accumulates_with_negative_net(): void
    {
        // Previous credit + new credit
        $result = $this->service->calculate(
            totalOutputVat: '1000.000',
            totalInputVat: '3000.000',
            creditBroughtForward: '500.000',
        );

        $this->assertEquals('-2000.000', $result['net_vat']);
        $this->assertEquals('0.000', $result['amount_payable']);
        $this->assertEquals('2500.000', $result['credit_carried_forward']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatCreditServiceTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Create Domain DTOs**

Create `VatAggregation.php` (readonly: direction, taxRate, baseAmount, vatAmount, documentCount, isRecoverable, taxConfigurationId) and `VatSummary.php` (readonly: outputBreakdowns[], inputBreakdowns[], totalOutputVat, totalInputVat). Both with `toArray()`.

- [ ] **Step 4: Create VatCreditService**

```php
// Domain/Services/VatCreditService.php
<?php
declare(strict_types=1);
namespace App\Modules\Taxation\Domain\Services;

class VatCreditService
{
    /**
     * Calculate credit carry-forward and amount payable.
     * Pure bcmath — no side effects.
     *
     * @return array{net_vat: string, amount_payable: string, credit_carried_forward: string}
     */
    public function calculate(
        string $totalOutputVat,
        string $totalInputVat,
        string $creditBroughtForward,
    ): array {
        $netVat = bcsub($totalOutputVat, $totalInputVat, 3);

        if (bccomp($netVat, '0', 3) <= 0) {
            // Input exceeds output — full credit
            $creditCarriedForward = bcadd(
                bcmul($netVat, '-1', 3), // abs(netVat)
                $creditBroughtForward,
                3,
            );
            return [
                'net_vat' => $netVat,
                'amount_payable' => '0.000',
                'credit_carried_forward' => $creditCarriedForward,
            ];
        }

        // Output exceeds input — check if credit covers it
        if (bccomp($creditBroughtForward, $netVat, 3) >= 0) {
            return [
                'net_vat' => $netVat,
                'amount_payable' => '0.000',
                'credit_carried_forward' => bcsub($creditBroughtForward, $netVat, 3),
            ];
        }

        return [
            'net_vat' => $netVat,
            'amount_payable' => bcsub($netVat, $creditBroughtForward, 3),
            'credit_carried_forward' => '0.000',
        ];
    }
}
```

- [ ] **Step 5: Create domain events**

Create `VatPeriodClosed.php` and `VatPeriodFiled.php` in `Domain/Events/`. Simple event classes with `public function __construct(public VatPeriod $period) {}`.

- [ ] **Step 6: Run tests**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatCreditServiceTest.php`
Expected: all 5 tests PASS

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Taxation/Domain/
git commit -m "feat(taxation): add VatCreditService, domain DTOs, and events"
```

---

## Task 3: Repository Interfaces + Infrastructure Implementations

**Files:**
- Create: `apps/api/app/Modules/Taxation/Domain/Repositories/VatPeriodRepositoryInterface.php`
- Create: `apps/api/app/Modules/Taxation/Domain/Repositories/VatDataRepositoryInterface.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatPeriodRepository.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php`
- Test: `apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php`

- [ ] **Step 1: Write VatDataRepository test**

```php
// tests/Feature/Taxation/VatDataRepositoryTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentVatDataRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatDataRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_invoice_tax_details_as_output(): void
    {
        // Seed: company, invoice document with document_tax_details
        // Assert: aggregation returns OUTPUT direction with correct sums
    }

    public function test_aggregates_expense_tax_details_as_input(): void
    {
        // Seed: company, expense document with document_tax_details
        // Assert: aggregation returns INPUT direction
    }

    public function test_groups_by_tax_rate(): void
    {
        // Seed: invoices at 19% and 7%
        // Assert: two separate VatAggregation entries
    }

    public function test_excludes_stamp_duty_from_aggregation(): void
    {
        // Seed: document_tax_detail with is_stamp_duty = true
        // Assert: not included in regular aggregation
    }

    public function test_filters_by_date_range(): void
    {
        // Seed: documents inside and outside date range
        // Assert: only documents within range are aggregated
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Taxation/VatDataRepositoryTest.php`
Expected: FAIL

- [ ] **Step 3: Create VatPeriodRepositoryInterface**

Methods: `findById(string $id): ?VatPeriod`, `findByCompanyAndYear(string $companyId, int $year): Collection`, `create(array $data): VatPeriod`, `update(string $id, array $data): VatPeriod`, `delete(string $id): bool`, `findPreviousPeriod(VatPeriod $period): ?VatPeriod`, `hasClosedOrFiledSuccessor(VatPeriod $period): bool`.

- [ ] **Step 2: Create VatDataRepositoryInterface**

```php
interface VatDataRepositoryInterface
{
    /**
     * Aggregate tax data from document_tax_details + pos_receipt_vat_details
     * grouped by tax_rate and direction.
     *
     * @return VatAggregation[]
     */
    public function aggregateByRateAndDirection(
        string $companyId,
        string $dateFrom,
        string $dateTo,
    ): array;
}
```

- [ ] **Step 3: Implement EloquentVatPeriodRepository**

Standard Eloquent implementation. `findPreviousPeriod` queries by `company_id` + `period_end < $period->period_start` ordered by `period_end DESC`, limit 1. `hasClosedOrFiledSuccessor` queries by `company_id` + `period_start > $period->period_start` + `status IN (CLOSED, FILED)`.

- [ ] **Step 4: Implement EloquentVatDataRepository**

This is the core query. Uses `DB::table('document_tax_details')` joined with `documents` to determine direction (Invoice/CreditNote → OUTPUT, Expense → INPUT). Union with `DB::table('pos_receipt_vat_details')` joined with POS receipts (all OUTPUT). Groups by tax_rate + direction. Joins `tax_configurations` to get `is_recoverable`. Returns array of `VatAggregation` DTOs.

Key query logic:
```php
// Documents query
DB::table('document_tax_details as dtd')
    ->join('documents as d', 'dtd.document_id', '=', 'd.id')
    ->where('d.company_id', $companyId)
    ->whereBetween('d.created_at', [$dateFrom, $dateTo])
    ->whereIn('d.type', ['invoice', 'credit_note', 'expense'])
    ->where('dtd.is_stamp_duty', false)
    ->selectRaw("
        dtd.tax_rate,
        CASE WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT' ELSE 'INPUT' END as direction,
        SUM(dtd.tax_base) as base_amount,
        SUM(dtd.tax_amount) as vat_amount,
        COUNT(DISTINCT d.id) as document_count
    ")
    ->groupByRaw("dtd.tax_rate, CASE WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT' ELSE 'INPUT' END");
```

- [ ] **Step 5: Run repository tests**

Run: `cd apps/api && php artisan test tests/Feature/Taxation/VatDataRepositoryTest.php`
Expected: all 5 tests PASS

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Taxation/Domain/Repositories/ apps/api/app/Modules/Taxation/Infrastructure/Repositories/ apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php
git commit -m "feat(taxation): add VatPeriod and VatData repository interfaces and implementations"
```

---

## Task 4: Country Strategies

**Files:**
- Create: `apps/api/app/Modules/Taxation/Domain/Contracts/VatReportStrategyInterface.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Strategies/FranceVatStrategy.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Strategies/UkVatStrategy.php`
- Test: `apps/api/tests/Unit/Taxation/TunisiaVatStrategyTest.php`
- Test: `apps/api/tests/Unit/Taxation/FranceVatStrategyTest.php`
- Test: `apps/api/tests/Unit/Taxation/UkVatStrategyTest.php`

- [ ] **Step 1: Write Tunisia strategy test**

```php
// tests/Unit/Taxation/TunisiaVatStrategyTest.php
public function test_default_period_type_is_monthly(): void
{
    $strategy = new TunisiaVatStrategy();
    $this->assertEquals(VatPeriodType::Monthly, $strategy->getDefaultPeriodType());
}

public function test_generates_12_monthly_periods(): void
{
    $strategy = new TunisiaVatStrategy();
    $periods = $strategy->generatePeriods(1, 2026); // Jan fiscal year
    $this->assertCount(12, $periods);
    $this->assertEquals('January 2026', $periods[0]['label']);
    $this->assertEquals('2026-01-01', $periods[0]['period_start']);
    $this->assertEquals('2026-01-31', $periods[0]['period_end']);
}

public function test_expected_rates(): void
{
    $strategy = new TunisiaVatStrategy();
    $rates = $strategy->getExpectedRates();
    $this->assertContains('19.00', $rates);
    $this->assertContains('13.00', $rates);
    $this->assertContains('7.00', $rates);
}

public function test_supported_export_formats(): void
{
    $strategy = new TunisiaVatStrategy();
    $formats = $strategy->getSupportedExportFormats();
    $this->assertContains(VatExportFormat::Pdf, $formats);
    $this->assertContains(VatExportFormat::Csv, $formats);
    $this->assertContains(VatExportFormat::TeifXml, $formats);
    $this->assertNotContains(VatExportFormat::Fec, $formats);
}
```

- [ ] **Step 2: Write France + UK strategy tests**

Similar pattern. France: monthly periods, rates [20, 10, 5.5, 2.1], formats [PDF, CSV, FEC]. UK: quarterly periods (4), rates [20, 5, 0], formats [PDF, CSV, MtdJson]. UK test must verify `generatePeriods` with fiscal year starting in April produces correct Q1 (Apr-Jun).

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/TunisiaVatStrategyTest.php tests/Unit/Taxation/FranceVatStrategyTest.php tests/Unit/Taxation/UkVatStrategyTest.php`
Expected: FAIL

- [ ] **Step 4: Create the strategy interface**

```php
// Domain/Contracts/VatReportStrategyInterface.php
interface VatReportStrategyInterface
{
    public function getDefaultPeriodType(): VatPeriodType;
    public function generatePeriods(int $fiscalYearStartMonth, int $year): array;
    public function mapToDeclaration(VatSummary $summary): VatDeclarationData;
    public function getSupportedExportFormats(): array;
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array;
    public function getExpectedRates(): array;
}
```

- [ ] **Step 5: Create VatExporterInterface**

```php
// Domain/Contracts/VatExporterInterface.php
interface VatExporterInterface
{
    public function supports(VatExportFormat $format): bool;
    public function export(VatSummaryData $summary, VatDeclarationData $declaration): StreamedResponse;
    public function getContentType(): string;
    public function getFilename(VatPeriod $period): string;
}
```

- [ ] **Step 6: Implement TunisiaVatStrategy**

`getDefaultPeriodType()` returns `Monthly`. `generatePeriods()` creates 12 monthly periods using Carbon. `getExpectedRates()` returns `['19.00', '13.00', '7.00', '0.00']`. `getSupportedExportFormats()` returns `[Pdf, Csv, TeifXml]`. `getSpecialLineItems()` queries stamp duty count from `document_tax_details` and retenue from `withholding_certificates`. `mapToDeclaration()` maps to DGI form fields.

- [ ] **Step 7: Implement FranceVatStrategy**

Monthly periods. Rates: `['20.00', '10.00', '5.50', '2.10']`. Formats: `[Pdf, Csv, Fec]`. `mapToDeclaration()` maps to CA3 line numbers (Line 08 = 20%, Line 09 = 5.5%, Line 9B = 10%, Line 11 = 2.1%, Lines 19-21 = deductible).

- [ ] **Step 8: Implement UkVatStrategy**

Quarterly periods. Rates: `['20.00', '5.00', '0.00']`. Formats: `[Pdf, Csv, MtdJson]`. `mapToDeclaration()` maps to 9-box model. Boxes 6-9 must be rounded to whole pounds (no decimals).

- [ ] **Step 9: Run tests**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/TunisiaVatStrategyTest.php tests/Unit/Taxation/FranceVatStrategyTest.php tests/Unit/Taxation/UkVatStrategyTest.php`
Expected: all PASS

- [ ] **Step 10: Commit**

```bash
git add apps/api/app/Modules/Taxation/Domain/Contracts/ apps/api/app/Modules/Taxation/Infrastructure/Strategies/ apps/api/tests/Unit/Taxation/*StrategyTest.php
git commit -m "feat(taxation): add country strategies (Tunisia, France, UK)"
```

---

## Task 5: Application Services + Service Provider Bindings

**Files:**
- Create: `apps/api/app/Modules/Taxation/Application/DTOs/VatPeriodData.php`
- Create: `apps/api/app/Modules/Taxation/Application/DTOs/VatSummaryData.php`
- Create: `apps/api/app/Modules/Taxation/Application/DTOs/VatDeclarationData.php`
- Create: `apps/api/app/Modules/Taxation/Application/Services/VatPeriodManagementService.php`
- Create: `apps/api/app/Modules/Taxation/Application/Services/VatReportGenerationService.php`
- Create: `apps/api/app/Modules/Taxation/Application/Services/VatExportService.php`
- Modify: `apps/api/app/Modules/Taxation/Providers/TaxationServiceProvider.php`

- [ ] **Step 1: Write VatPeriodManagementService test**

```php
// tests/Unit/Taxation/VatPeriodManagementServiceTest.php
public function test_close_period_sets_status_and_snapshots(): void
{
    // Mock repository, report generation service, credit service
    // Call closePeriod with an OPEN period
    // Assert: status changed to CLOSED, totals populated, event dispatched
}

public function test_close_rejects_non_open_period(): void
{
    $this->expectException(\DomainException::class);
    // Call closePeriod with a CLOSED period
}

public function test_reopen_blocked_when_successor_is_closed(): void
{
    // Mock repository hasClosedOrFiledSuccessor returns true
    $this->expectException(\DomainException::class);
    // Call reopenPeriod
}

public function test_reopen_deletes_breakdowns_and_clears_totals(): void
{
    // Mock repository hasClosedOrFiledSuccessor returns false
    // Call reopenPeriod with a CLOSED period
    // Assert: status back to OPEN, breakdowns deleted, totals null
}

public function test_file_rejects_open_period(): void
{
    $this->expectException(\DomainException::class);
    // Call filePeriod with an OPEN period
}

public function test_file_sets_filed_status_and_dispatches_event(): void
{
    // Call filePeriod with a CLOSED period
    // Assert: status FILED, filed_at set, VatPeriodFiled event dispatched
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatPeriodManagementServiceTest.php`
Expected: FAIL

- [ ] **Step 3: Create Application DTOs**

`VatPeriodData` — readonly class with `fromEntity(VatPeriod)` and `toArray()`. Maps all fields. Enum values via `->value`, dates via `->toIso8601String()`.

`VatSummaryData` — readonly class with period info + output/input breakdown arrays + net_vat + credit fields + special_items + declaration.

`VatDeclarationData` — readonly class with country-mapped fields. Use typed properties per country: `/** @var array<string, string|int|float> */ public array $fields`. Avoid `mixed` — declaration fields are always string/numeric key-value pairs.

- [ ] **Step 4: Create VatPeriodManagementService**

Constructor injects: `VatPeriodRepositoryInterface`, `VatReportGenerationService`, `VatCreditService`.

Methods:
- `generatePeriods(string $companyId, int $year, VatReportStrategyInterface $strategy): array` — creates periods in `DB::transaction`
- `closePeriod(VatPeriod $period, ?string $notes, string $userId): VatPeriodData` — validates OPEN status, calculates summary, snapshots breakdowns, calculates carry-forward, dispatches event
- `reopenPeriod(VatPeriod $period): VatPeriodData` — validates CLOSED (not FILED), checks no closed/filed successor, deletes breakdowns, clears totals
- `filePeriod(VatPeriod $period, ?string $filingReference, string $userId): VatPeriodData` — validates CLOSED, sets filed_at/by, dispatches event

- [ ] **Step 5: Create VatReportGenerationService**

Constructor injects: `VatDataRepositoryInterface`, `TunisiaVatStrategy`, `FranceVatStrategy`, `UkVatStrategy`.

Methods:
- `resolveStrategy(string $countryCode): VatReportStrategyInterface` — match statement
- `generateSummary(string $companyId, string $countryCode, string $dateFrom, string $dateTo): VatSummaryData` — calls repo + strategy

- [ ] **Step 6: Create VatExportService**

Constructor injects all 5 exporters. `export(VatSummaryData, VatDeclarationData, VatExportFormat, VatPeriod): StreamedResponse` — finds matching exporter via `supports()`.

- [ ] **Step 7: Update TaxationServiceProvider**

Add to `register()` after existing bindings (line ~55 of `TaxationServiceProvider.php`):

```php
// VAT Reporting
$this->app->singleton(VatCreditService::class);
$this->app->singleton(VatPeriodManagementService::class);
$this->app->singleton(VatReportGenerationService::class);
$this->app->singleton(VatExportService::class);
$this->app->bind(VatPeriodRepositoryInterface::class, EloquentVatPeriodRepository::class);
$this->app->bind(VatDataRepositoryInterface::class, EloquentVatDataRepository::class);
```

- [ ] **Step 8: Run management service tests**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/VatPeriodManagementServiceTest.php`
Expected: all 6 tests PASS

- [ ] **Step 9: Commit**

```bash
git add apps/api/app/Modules/Taxation/Application/ apps/api/app/Modules/Taxation/Providers/ apps/api/tests/Unit/Taxation/VatPeriodManagementServiceTest.php
git commit -m "feat(taxation): add application services, DTOs, and DI bindings"
```

---

## Task 6: Presentation Layer (Controllers, Routes, Permissions)

**Files:**
- Create: `apps/api/app/Modules/Taxation/Presentation/Controllers/VatPeriodController.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Controllers/VatReportController.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Requests/GenerateVatPeriodsRequest.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Requests/VatReportRequest.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Requests/VatExportRequest.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Resources/VatPeriodResource.php`
- Create: `apps/api/app/Modules/Taxation/Presentation/Resources/VatSummaryResource.php`
- Modify: `apps/api/app/Modules/Taxation/routes.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Test: `apps/api/tests/Feature/Taxation/VatPeriodControllerTest.php`
- Test: `apps/api/tests/Feature/Taxation/VatReportControllerTest.php`

- [ ] **Step 1: Write VatPeriodController feature test**

```php
// tests/Feature/Taxation/VatPeriodControllerTest.php
public function test_list_periods_requires_auth(): void
{
    $this->getJson('/api/v1/vat/periods')->assertStatus(401);
}

public function test_list_periods_returns_periods_for_company(): void
{
    $this->actingAs($this->user);
    // Seed a period via factory
    $response = $this->getJson('/api/v1/vat/periods?year=2026');
    $response->assertOk()->assertJsonStructure(['data' => [['id', 'label', 'status']]]);
}

public function test_generate_periods_creates_monthly_for_tunisia(): void
{
    $this->actingAs($this->manager);
    $response = $this->postJson('/api/v1/vat/periods/generate', ['year' => 2026]);
    $response->assertStatus(201);
    $this->assertCount(12, $response->json('data'));
}

public function test_close_period_snapshots_data(): void
{
    $this->actingAs($this->manager);
    $period = VatPeriod::factory()->open()->create(['company_id' => $this->company->id]);
    $response = $this->postJson("/api/v1/vat/periods/{$period->id}/close");
    $response->assertOk();
    $this->assertEquals('CLOSED', $response->json('data.status'));
}

public function test_cannot_reopen_filed_period(): void
{
    $this->actingAs($this->manager);
    $period = VatPeriod::factory()->filed()->create(['company_id' => $this->company->id]);
    $response = $this->postJson("/api/v1/vat/periods/{$period->id}/reopen");
    $response->assertStatus(422);
}

public function test_cannot_file_open_period(): void
{
    $this->actingAs($this->manager);
    $period = VatPeriod::factory()->open()->create(['company_id' => $this->company->id]);
    $response = $this->postJson("/api/v1/vat/periods/{$period->id}/file");
    $response->assertStatus(422);
}

public function test_cannot_reopen_period_when_successor_is_closed(): void
{
    $this->actingAs($this->manager);
    $jan = VatPeriod::factory()->closed()->create([
        'company_id' => $this->company->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'label' => 'January 2026',
    ]);
    VatPeriod::factory()->closed()->create([
        'company_id' => $this->company->id,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'label' => 'February 2026',
    ]);
    $response = $this->postJson("/api/v1/vat/periods/{$jan->id}/reopen");
    $response->assertStatus(422);
}
```

Also write `VatReportControllerTest`:

```php
// tests/Feature/Taxation/VatReportControllerTest.php
public function test_summary_returns_vat_breakdown(): void
{
    $this->actingAs($this->manager);
    $period = VatPeriod::factory()->closed()->create(['company_id' => $this->company->id]);
    $response = $this->getJson("/api/v1/vat/reports/{$period->id}/summary");
    $response->assertOk()->assertJsonStructure([
        'data' => ['period', 'output_vat', 'input_vat', 'net_vat', 'amount_payable'],
    ]);
}

public function test_ad_hoc_summary_with_date_range(): void
{
    $this->actingAs($this->manager);
    $response = $this->getJson('/api/v1/vat/reports/summary?date_from=2026-01-01&date_to=2026-01-31');
    $response->assertOk();
}

public function test_export_formats_returns_country_specific_formats(): void
{
    $this->actingAs($this->manager);
    $period = VatPeriod::factory()->closed()->create(['company_id' => $this->company->id]);
    $response = $this->getJson("/api/v1/vat/reports/{$period->id}/export-formats");
    $response->assertOk()->assertJsonStructure(['data' => [['format', 'label']]]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && php artisan test tests/Feature/Taxation/VatPeriodControllerTest.php`
Expected: FAIL

- [ ] **Step 3: Add `reports.manage` permission to seeder**

Modify `RolesAndPermissionsSeeder.php`:
- Add `'reports.manage'` to the permissions array (around line 160, after `'reports.operational'`)
- Assign to Manager role (around line 290)
- Assign to Accountant role

- [ ] **Step 4: Create request validation classes**

`GenerateVatPeriodsRequest`: rules `['year' => ['required', 'integer', 'min:2020', 'max:2100']]`
`VatReportRequest`: rules `['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]`
`VatExportRequest`: validates format string against allowed values

- [ ] **Step 5: Create Resources**

`VatPeriodResource` extends `JsonResource` with `@mixin VatPeriod`. Maps all fields, enums to `->value`, dates to ISO strings. Includes `breakdowns` when loaded.

`VatSummaryResource` extends `JsonResource`. Maps period + output/input breakdowns + totals + special items + declaration.

- [ ] **Step 6: Create VatPeriodController**

Thin controller. Constructor injects `CompanyContext`, `VatPeriodManagementService`, `VatReportGenerationService`.

Methods: `index`, `show`, `generate`, `close`, `reopen`, `file`. Each validates → calls service → returns resource.

- [ ] **Step 7: Create VatReportController**

Constructor injects `CompanyContext`, `VatReportGenerationService`, `VatExportService`.

Methods: `summary` (custom date range), `periodSummary` (period-based), `exportFormats`, `export`.

- [ ] **Step 8: Add routes**

Add to `apps/api/app/Modules/Taxation/routes.php` after existing routes:

```php
// VAT Period Management
Route::prefix('vat/periods')->group(function (): void {
    Route::get('/', [VatPeriodController::class, 'index'])->middleware('can:reports.view');
    Route::post('/generate', [VatPeriodController::class, 'generate'])->middleware('can:reports.manage');
    Route::get('/{id}', [VatPeriodController::class, 'show'])->middleware('can:reports.view');
    Route::post('/{id}/close', [VatPeriodController::class, 'close'])->middleware('can:reports.manage');
    Route::post('/{id}/reopen', [VatPeriodController::class, 'reopen'])->middleware('can:reports.manage');
    Route::post('/{id}/file', [VatPeriodController::class, 'file'])->middleware('can:reports.manage');
});

// VAT Reports & Exports
Route::prefix('vat/reports')->group(function (): void {
    Route::get('/summary', [VatReportController::class, 'summary'])->middleware('can:reports.financial');
    Route::get('/{periodId}/summary', [VatReportController::class, 'periodSummary'])->middleware('can:reports.financial');
    Route::get('/{periodId}/export-formats', [VatReportController::class, 'exportFormats'])->middleware('can:reports.financial');
    Route::get('/{periodId}/export/{format}', [VatReportController::class, 'export'])->middleware('can:reports.financial');
});
```

- [ ] **Step 9: Run feature tests**

Run: `cd apps/api && php artisan test tests/Feature/Taxation/VatPeriodControllerTest.php tests/Feature/Taxation/VatReportControllerTest.php`
Expected: all PASS

- [ ] **Step 10: Commit**

```bash
git add apps/api/app/Modules/Taxation/Presentation/ apps/api/app/Modules/Taxation/routes.php apps/api/database/seeders/RolesAndPermissionsSeeder.php apps/api/tests/Feature/Taxation/
git commit -m "feat(taxation): add VAT reporting controllers, routes, and permissions"
```

---

## Task 7: Exporters (CSV, PDF, FEC, MTD JSON, TEIF XML)

**Files:**
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/CsvVatExporter.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/PdfVatExporter.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/FecExporter.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/MtdJsonExporter.php`
- Create: `apps/api/app/Modules/Taxation/Infrastructure/Exporters/TeifXmlExporter.php`
- Test: `apps/api/tests/Unit/Taxation/CsvVatExporterTest.php`
- Test: `apps/api/tests/Unit/Taxation/MtdJsonExporterTest.php`
- Test: `apps/api/tests/Unit/Taxation/FecExporterTest.php`

- [ ] **Step 1: Write CSV exporter test**

Test that output contains expected headers (Date,Rate,Direction,Base,VAT,Document Count) and correctly formatted rows.

- [ ] **Step 2: Write MTD JSON exporter test**

Test 9-box JSON structure: `vatDueSales`, `vatDueAcquisitions`, `totalVatDue`, `vatReclaimedCurrPeriod`, `netVatDue` (always positive), `totalValueSalesExVAT` (whole pounds), etc.

- [ ] **Step 3: Write FEC exporter test**

Test 18-field tab-delimited format: correct field order, date format AAAAMMJJ, filename format `SIRENFECAAAAMMJJ`.

- [ ] **Step 4: Run tests to verify they fail**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/CsvVatExporterTest.php tests/Unit/Taxation/MtdJsonExporterTest.php tests/Unit/Taxation/FecExporterTest.php`

- [ ] **Step 5: Implement CsvVatExporter**

Returns `StreamedResponse` with CSV content. Headers: Rate, Direction, Base HT, VAT Amount, Document Count, Is Recoverable. One row per breakdown.

- [ ] **Step 6: Implement PdfVatExporter**

Uses Laravel's built-in PDF capabilities (or DomPDF). Renders a summary view matching the report page layout.

- [ ] **Step 7: Implement FecExporter**

18-field tab-delimited. Date format AAAAMMJJ. Filename: `{SIREN}FEC{AAAAMMJJ}`. VAT-filtered subset only (accounts starting with 44x).

- [ ] **Step 8: Implement MtdJsonExporter**

Maps summary to 9-box JSON. Boxes 1-5 as decimals (2 places). Boxes 6-9 as integers (whole pounds). `netVatDue` always positive (abs value).

- [ ] **Step 9: Implement TeifXmlExporter**

XML format for Tunisia El Fatoora. Follow TEIF specification. Include tax identification, VAT breakdown, transaction details.

- [ ] **Step 10: Run tests**

Run: `cd apps/api && php artisan test tests/Unit/Taxation/CsvVatExporterTest.php tests/Unit/Taxation/MtdJsonExporterTest.php tests/Unit/Taxation/FecExporterTest.php`
Expected: all PASS

- [ ] **Step 11: Commit**

```bash
git add apps/api/app/Modules/Taxation/Infrastructure/Exporters/ apps/api/tests/Unit/Taxation/*ExporterTest.php
git commit -m "feat(taxation): add VAT export formats (CSV, PDF, FEC, MTD JSON, TEIF XML)"
```

---

## Task 8: Frontend — Types, API, Hooks

**Files:**
- Create: `apps/web/src/features/vat-reporting/types.ts`
- Create: `apps/web/src/features/vat-reporting/api.ts`
- Create: `apps/web/src/features/vat-reporting/hooks/useVatPeriods.ts`
- Create: `apps/web/src/features/vat-reporting/hooks/useVatReport.ts`
- Create: `apps/web/src/features/vat-reporting/hooks/useVatPeriodActions.ts`
- Create: `apps/web/src/features/vat-reporting/hooks/useVatExport.ts`

- [ ] **Step 1: Create types.ts**

Define interfaces matching the API response shape from the spec (Section 7.7). All amounts as `string`. Enums as string union types. Include `VatPeriod`, `VatRateBreakdown`, `VatDirectionSummary`, `VatReportSummary`, `VatExportFormat`, `VatPeriodsFilters`.

- [ ] **Step 2: Create api.ts**

```typescript
import { apiGet, apiPost } from '@/lib/api'
import type { VatPeriod, VatReportSummary, VatExportFormat, VatPeriodsFilters } from './types'

export async function getVatPeriods(filters?: VatPeriodsFilters): Promise<VatPeriod[]> {
  const params = new URLSearchParams()
  if (filters?.year) params.append('year', String(filters.year))
  if (filters?.status) params.append('status', filters.status)
  const qs = params.toString()
  return apiGet<VatPeriod[]>(qs ? `/vat/periods?${qs}` : '/vat/periods')
}

export async function getVatPeriod(id: string): Promise<VatPeriod> {
  return apiGet<VatPeriod>(`/vat/periods/${id}`)
}

export async function generateVatPeriods(year: number): Promise<VatPeriod[]> {
  return apiPost<VatPeriod[]>('/vat/periods/generate', { year })
}

export async function closeVatPeriod(id: string, notes?: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/close`, { notes })
}

export async function reopenVatPeriod(id: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/reopen`)
}

export async function fileVatPeriod(id: string, filingReference?: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/file`, { filing_reference: filingReference })
}

export async function getVatReportSummary(periodId: string): Promise<VatReportSummary> {
  return apiGet<VatReportSummary>(`/vat/reports/${periodId}/summary`)
}

export async function getVatExportFormats(periodId: string): Promise<VatExportFormat[]> {
  return apiGet<VatExportFormat[]>(`/vat/reports/${periodId}/export-formats`)
}
```

- [ ] **Step 3: Create hooks**

`useVatPeriods(filters)` — `useQuery({ queryKey: ['vat-periods', filters], queryFn: () => getVatPeriods(filters) })`

`useVatReport(periodId)` — `useQuery({ queryKey: ['vat-report', periodId], queryFn: () => getVatReportSummary(periodId), enabled: !!periodId })`

`useVatPeriodActions()` — returns `{ generateMutation, closeMutation, reopenMutation, fileMutation }` each using `useMutation` with `queryClient.invalidateQueries({ queryKey: ['vat-periods'] })` on success.

`useVatExport()` — `useMutation` that triggers file download via `window.open()` or blob download.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/features/vat-reporting/
git commit -m "feat(web): add VAT reporting types, API functions, and hooks"
```

---

## Task 9: Frontend — Components (Atomic Design)

**Files:**
- Create: `apps/web/src/features/vat-reporting/components/VatPeriodStatusBadge.tsx`
- Create: `apps/web/src/features/vat-reporting/components/VatSummaryCards.tsx`
- Create: `apps/web/src/features/vat-reporting/components/VatBreakdownTable.tsx`
- Create: `apps/web/src/features/vat-reporting/components/VatPeriodList.tsx`
- Create: `apps/web/src/features/vat-reporting/components/VatSpecialItems.tsx`
- Create: `apps/web/src/features/vat-reporting/components/VatExportMenu.tsx`
- Test: `apps/web/src/features/vat-reporting/components/__tests__/VatPeriodStatusBadge.test.tsx`
- Test: `apps/web/src/features/vat-reporting/components/__tests__/VatBreakdownTable.test.tsx`
- Test: `apps/web/src/features/vat-reporting/components/__tests__/VatSummaryCards.test.tsx`

- [ ] **Step 1: Write VatPeriodStatusBadge test**

Test 3 statuses render with correct text and appropriate colors (green for Open, amber for Closed, blue for Filed).

- [ ] **Step 2: Write VatBreakdownTable test**

Test that it renders rate rows, amounts, document counts, total row. Test that `showRecoverable` prop controls recoverable column visibility.

- [ ] **Step 3: Write VatSummaryCards test**

Test that it renders 4 stat cards with the provided amounts.

- [ ] **Step 4: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run src/features/vat-reporting/`

- [ ] **Step 5: Implement VatPeriodStatusBadge**

Atom component. Uses existing `Badge` component from `@/components/atoms/Badge/Badge.tsx`. Maps status to variant/color.

```tsx
import { Badge } from '@/components/atoms/Badge/Badge'
import { useTranslation } from 'react-i18next'
import type { VatPeriodStatus } from '../types'

const statusConfig: Record<VatPeriodStatus, { variant: string; color: string }> = {
  OPEN: { variant: 'success', color: 'green' },
  CLOSED: { variant: 'warning', color: 'amber' },
  FILED: { variant: 'info', color: 'blue' },
}

export function VatPeriodStatusBadge({ status }: { status: VatPeriodStatus }) {
  const { t } = useTranslation('finance')
  const config = statusConfig[status]
  return <Badge variant={config.variant}>{t(`vatReporting.status.${status.toLowerCase()}`)}</Badge>
}
```

- [ ] **Step 6: Implement VatSummaryCards**

Molecule. Composes 4 `StatCard` components from `@/components/ui/StatCard.tsx`. Props: `outputVat`, `inputVat`, `creditBroughtForward`, `amountPayable` (all strings). Uses currency formatting.

- [ ] **Step 7: Implement VatBreakdownTable**

Molecule. Props: `breakdowns: VatRateBreakdown[]`, `direction: VatDirection`, `showRecoverable?: boolean`. Standard table following TrialBalancePage pattern (`text-start`/`text-end`, `divide-y`). Total row at bottom.

- [ ] **Step 8: Implement VatPeriodList**

Organism. Props: `periods: VatPeriod[]`, action callbacks. Composes `VatPeriodStatusBadge`, `Button`, `ConfirmDialog`. Shows context-aware actions per status.

- [ ] **Step 9: Implement VatSpecialItems**

Molecule. Props: `specialItems: Record<string, unknown>`, `countryCode: string`. Renders grid of country-specific items. Tunisia: timbre fiscal + retenue. France: credit TVA. UK: EU acquisitions.

- [ ] **Step 10: Implement VatExportMenu**

Molecule. Uses `useVatExport` hook + `getVatExportFormats`. Dropdown button showing only country-supported formats.

- [ ] **Step 11: Run tests**

Run: `cd apps/web && pnpm test -- --run src/features/vat-reporting/`
Expected: all PASS

- [ ] **Step 12: Commit**

```bash
git add apps/web/src/features/vat-reporting/components/
git commit -m "feat(web): add VAT reporting components (atomic design)"
```

---

## Task 10: Frontend — Pages, Routes, Navigation, i18n

**Files:**
- Create: `apps/web/src/features/vat-reporting/pages/VatPeriodsPage.tsx`
- Create: `apps/web/src/features/vat-reporting/pages/VatReportPage.tsx`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/locales/en/finance.json`
- Modify: `apps/web/src/locales/fr/finance.json`

- [ ] **Step 1: Add i18n keys**

Add to `apps/web/src/locales/en/finance.json` under a `vatReporting` key:

```json
"vatReporting": {
  "title": "VAT Reporting",
  "subtitle": "Manage VAT periods and declarations",
  "generatePeriods": "Generate Periods",
  "ytdOutput": "Total Output VAT",
  "ytdInput": "Total Input VAT",
  "ytdNet": "Net VAT Due",
  "ytdCredit": "Credit Carried",
  "columns": {
    "period": "Period",
    "dateRange": "Date Range",
    "outputVat": "Output VAT",
    "inputVat": "Input VAT",
    "netDue": "Net Due",
    "status": "Status",
    "actions": "Actions"
  },
  "status": {
    "open": "Open",
    "closed": "Closed",
    "filed": "Filed"
  },
  "actions": {
    "view": "View",
    "close": "Close",
    "reopen": "Reopen",
    "file": "File",
    "export": "Export"
  },
  "detail": {
    "backToList": "Back to VAT Periods",
    "outputBreakdown": "Output VAT Breakdown",
    "inputBreakdown": "Input VAT Breakdown",
    "specialItems": "Special Items",
    "creditBroughtForward": "Credit B/F",
    "amountPayable": "Amount Payable"
  },
  "confirmClose": "Are you sure you want to close this period? This will snapshot the current VAT data.",
  "confirmFile": "Are you sure you want to mark this period as filed? This action cannot be undone."
}
```

Add corresponding French translations to `fr/finance.json`.

- [ ] **Step 2: Create VatPeriodsPage**

Thin container. Uses `useVatPeriods`, `useVatPeriodActions`. State: `year` (number). Composes: `VatSummaryCards` (YTD), `VatPeriodList`. Loading/error states via `QueryError`.

- [ ] **Step 3: Create VatReportPage**

Thin container. Uses `useVatReport(id)` from route params. Composes: back link, `VatPeriodStatusBadge`, `VatExportMenu`, `VatSummaryCards`, `VatBreakdownTable` (×2: output + input), `VatSpecialItems`.

- [ ] **Step 4: Add routes**

Add to `apps/web/src/routes/index.tsx` after existing finance routes (line ~134):

```typescript
const VatPeriodsPage = lazy(() => import('../features/vat-reporting/pages/VatPeriodsPage').then(m => ({ default: m.VatPeriodsPage })))
const VatReportPage = lazy(() => import('../features/vat-reporting/pages/VatReportPage').then(m => ({ default: m.VatReportPage })))
```

Add route elements under the `finance` path:
```tsx
<Route path="vat-periods" element={<RequirePermission moduleKey="reports"><SuspenseWrapper><VatPeriodsPage /></SuspenseWrapper></RequirePermission>} />
<Route path="vat-report/:id" element={<RequirePermission moduleKey="reports"><SuspenseWrapper><VatReportPage /></SuspenseWrapper></RequirePermission>} />
```

- [ ] **Step 5: Add sidebar navigation**

Add to `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` in the `accountingAndReports` children array (after line ~232):

```typescript
{ key: 'vatReporting', href: '/finance/vat-periods', icon: Receipt, module: 'reports' },
```

Add `Receipt` to the lucide-react imports.

- [ ] **Step 6: Add navigation translation key**

Add `"vatReporting": "VAT Reporting"` to `navigation` section in `common.json`.

- [ ] **Step 7: Verify typecheck + lint**

Run: `cd apps/web && pnpm typecheck && pnpm lint`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/features/vat-reporting/pages/ apps/web/src/routes/ apps/web/src/components/organisms/Sidebar/ apps/web/src/locales/
git commit -m "feat(web): add VAT reporting pages, routes, sidebar navigation, and i18n"
```

---

## Task 11: Integration Testing + Preflight

**Files:**
- All files from tasks 1-10

- [ ] **Step 1: Run full backend test suite**

Run: `cd apps/api && php artisan test --filter=Taxation`
Expected: all tests PASS

- [ ] **Step 2: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan`
Expected: zero errors

- [ ] **Step 3: Run Pint**

Run: `cd apps/api && ./vendor/bin/pint`
Expected: no changes needed (or fix and commit)

- [ ] **Step 4: Run frontend tests**

Run: `cd apps/web && pnpm test -- --run`
Expected: all tests PASS

- [ ] **Step 5: Run frontend typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: PASS

- [ ] **Step 6: Run ESLint**

Run: `cd apps/web && pnpm lint`
Expected: PASS

- [ ] **Step 7: Run preflight**

Run: `./scripts/preflight.sh`
Expected: all checks PASS

- [ ] **Step 8: Final commit if any fixes**

```bash
git add -A
git commit -m "fix(taxation): address preflight issues in VAT reporting"
```

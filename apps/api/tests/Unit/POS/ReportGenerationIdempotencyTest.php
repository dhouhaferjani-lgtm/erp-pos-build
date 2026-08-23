<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CashCountDispatcher;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

final class ReportGenerationIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private ReportGenerationService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant and company first so CompanyContext can be bound before service resolution
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // Bind CompanyContext so CurrencyScaleResolver inside container-resolved services has context
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = new ReportGenerationService(
            $this->app->make(ShiftManagementService::class),
            $this->app->make(CashDrawerService::class),
            $this->app->make(ZReportHashService::class),
            $this->app->make(GrandtotalService::class),
            $this->mockCurrencyScale(3),
            $this->app->make(CashCountValidationService::class),
            $this->app->make(FraudSettingsResolver::class),
            $this->app->make(ZReportCountRepository::class),
            $this->app->make(PaymentToleranceQueryService::class),
            $this->app->make(TaxIdentityResolver::class),
            $this->app->make(CashCountDispatcher::class),
        );

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_second_generate_z_report_for_same_shift_returns_existing(): void
    {
        $terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);

        $shift = $this->app->make(ShiftManagementService::class)->openShift($terminal, $this->cashier, '100.00');

        // Back-date the shift open so the GRANDTOTAL_DAILY period has real
        // duration. The grandtotal event spans [shift.opened_at, now()]; on
        // PostgreSQL these timestamps are stored at second precision, so a
        // shift opened in the same whole second as Z-report generation would
        // collapse to period_start == period_end and violate the
        // pos_grandtotal_period (period_end > period_start) CHECK. Real shifts
        // always span time.
        $shift->update(['opened_at' => now()->subHour()]);

        Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T001-C042-L01-POS01-2026-00000001',
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'receipt-1'),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '42.02',
            'tax_amount' => '7.98',
            'discount_amount' => '0.00',
            'total' => '50.00',
            'currency' => 'TND',
            'is_voided' => false,
            'is_training' => false,
        ]);

        $first = $this->service->generateZReport($terminal, $this->cashier);
        $second = $this->service->generateZReport($terminal, $this->cashier);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->z_number, $second->z_number);
        $this->assertSame($first->fiscal_hash, $second->fiscal_hash);
        $this->assertSame(1, ZReport::where('shift_id', $shift->id)->count());
        $this->assertTrue($second->was_reused ?? false);
    }
}

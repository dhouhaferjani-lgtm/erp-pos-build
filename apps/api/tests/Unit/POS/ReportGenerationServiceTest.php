<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Unit tests for ReportGenerationService
 *
 * Verifies:
 * - Correct column references (total, subtotal, tax_amount)
 * - Voided receipt exclusion
 * - VAT breakdown aggregation
 * - Payment method aggregation
 */
class ReportGenerationServiceTest extends TestCase
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
        );

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * Test that calculateShiftTotals uses correct Receipt columns
     * (total, subtotal, tax_amount — NOT total_amount, net_amount)
     */
    public function test_calculate_shift_totals_uses_correct_columns(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'is_voided' => false,
        ]);

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals(1, $result['sales_count']);
        $this->assertEquals('119.000', $result['gross_sales']);
        $this->assertEquals('100.000', $result['net_sales']);
        $this->assertEquals('19.000', $result['tax_amount']);
        $this->assertEquals(0, $result['voided_count']);
    }

    /**
     * Test that voided receipts are excluded from totals
     */
    public function test_voided_receipts_excluded_from_totals(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'subtotal' => '50.00',
            'tax_amount' => '9.50',
            'total' => '59.50',
            'is_voided' => false,
        ]);

        $this->createReceipt($terminal, [
            'subtotal' => '200.00',
            'tax_amount' => '38.00',
            'total' => '238.00',
            'is_voided' => true,
            'voided_at' => now(),
        ]);

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals(1, $result['sales_count']);
        $this->assertEquals('59.500', $result['gross_sales']);
        $this->assertEquals(1, $result['voided_count']);
    }

    /**
     * Test that multiple receipts are aggregated correctly
     */
    public function test_multiple_receipts_aggregated_correctly(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'is_voided' => false,
        ]);

        $this->createReceipt($terminal, [
            'subtotal' => '50.00',
            'tax_amount' => '9.50',
            'total' => '59.50',
            'is_voided' => false,
        ]);

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals(2, $result['sales_count']);
        $this->assertEquals('178.500', $result['gross_sales']);
        $this->assertEquals('150.000', $result['net_sales']);
        $this->assertEquals('28.500', $result['tax_amount']);
    }

    /**
     * Test empty period returns zeros
     */
    public function test_empty_period_returns_zeros(): void
    {
        $terminal = $this->createPersistedTerminal();

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals(0, $result['sales_count']);
        $this->assertEquals('0.00', $result['gross_sales']);
        $this->assertEquals('0.00', $result['net_sales']);
        $this->assertEquals('0.00', $result['tax_amount']);
    }

    private function createPersistedTerminal(): Terminal
    {
        return Terminal::create([
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
    }

    private int $receiptSequence = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(Terminal $terminal, array $overrides = []): Receipt
    {
        $this->receiptSequence++;

        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('T001-C042-L01-POS01-2026-%08d', $this->receiptSequence),
            'chain_sequence' => $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "receipt-{$this->receiptSequence}"),
            'previous_hash' => $this->receiptSequence > 1 ? hash('sha256', 'receipt-'.($this->receiptSequence - 1)) : null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
            'total' => '119.00',
            'currency' => 'TND',
            'is_voided' => false,
        ];

        return Receipt::create(array_merge($defaults, $overrides));
    }
}

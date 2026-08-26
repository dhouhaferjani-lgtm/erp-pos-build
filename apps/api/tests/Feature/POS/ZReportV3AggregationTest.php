<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CashCountDispatcher;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Application\Services\ShiftExpectedCashService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Task 39: Z-report v3 sale-vs-return split + voucher counters.
 *
 * Verifies:
 * - Receipts with type=Sale count into sales_count/gross_sales/net_sales.
 * - Receipts with type=Return count into refunds_count/refunds_amount.
 * - At v3: vouchers_issued_count/amount and vouchers_redeemed_count/amount are populated.
 * - At v2: voucher counters are absent (backward compat).
 */
class ZReportV3AggregationTest extends TestCase
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

        // ONE resolver instance shared with ShiftExpectedCashService: the two
        // must agree on scale or the per-tender expected totals and the report's
        // own rounding drift apart.
        $scaleResolver = $this->mockCurrencyScale(3);

        $this->service = new ReportGenerationService(
            $this->app->make(ShiftManagementService::class),
            $this->app->make(CashDrawerService::class),
            $this->app->make(ZReportHashService::class),
            $this->app->make(GrandtotalService::class),
            $scaleResolver,
            $this->app->make(CashCountValidationService::class),
            $this->app->make(FraudSettingsResolver::class),
            $this->app->make(ZReportCountRepository::class),
            $this->app->make(PaymentToleranceQueryService::class),
            $this->app->make(TaxIdentityResolver::class),
            $this->app->make(CashCountDispatcher::class),
            new ShiftExpectedCashService($scaleResolver),
        );

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_z_report_split_counts_sales_and_returns_separately(): void
    {
        $terminal = $this->createTerminal();

        // Two sale receipts
        $sale1 = $this->createReceipt($terminal, [
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);
        $this->createReceipt($terminal, [
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
        ]);

        // One return receipt. A return row must carry original_receipt_id +
        // return_reason (pos_receipts_return_logic, enforced by PostgreSQL).
        $this->createReceipt($terminal, [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $sale1->id,
            'return_reason' => ReturnReason::Defective,
            'subtotal' => '30.00',
            'tax_amount' => '6.00',
            'total' => '36.00',
        ]);

        // One voided receipt. A voided row must carry voided_by + fiscal_status
        // (pos_receipts_void_logic, enforced by PostgreSQL).
        $this->createReceipt($terminal, [
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '10.00',
            'tax_amount' => '2.00',
            'total' => '12.00',
            'is_voided' => true,
            'voided_at' => now(),
            'voided_by' => $this->cashier->id,
            'fiscal_status' => FiscalStatus::Voided,
        ]);

        $method = new ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $terminal, now()->subHour(), now()->addHour());

        // Sales: 2 non-voided sale receipts
        $this->assertSame(2, $result['sales_count']);
        $this->assertSame('180.000', $result['gross_sales']);
        $this->assertSame('150.000', $result['net_sales']);
        $this->assertSame('30.000', $result['tax_amount']);

        // Returns: 1 return receipt
        $this->assertSame(1, $result['refunds_count']);
        $this->assertSame('36.000', $result['refunds_amount']);

        // Voided: 1
        $this->assertSame(1, $result['voided_count']);
    }

    public function test_z_report_voucher_counters_at_v3_include_issued_and_redeemed(): void
    {
        $terminal = $this->createTerminal();
        $shift = $this->createShift($terminal);

        // Create a voucher to attach ledger entries to
        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Issued ledger entry within the window
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'terminal_id' => $terminal->id,
            'event' => VoucherEvent::Issued,
            'amount' => '25.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        // Another Issued entry
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'terminal_id' => $terminal->id,
            'event' => VoucherEvent::Issued,
            'amount' => '10.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        // Redeemed entry — negative amount
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'terminal_id' => $terminal->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-15.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        // Ledger entry outside the window — must NOT appear
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'terminal_id' => $terminal->id,
            'event' => VoucherEvent::Issued,
            'amount' => '999.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now()->subDays(3),
        ]);

        $method = new ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        // Pass schemaVersion=3 to activate voucher counter path
        $result = $method->invoke($this->service, $terminal, now()->subHour(), now()->addHour(), 3);

        $this->assertArrayHasKey('vouchers_issued_count', $result);
        $this->assertSame(2, $result['vouchers_issued_count']);
        $this->assertSame('35.000', $result['vouchers_issued_amount']);

        $this->assertArrayHasKey('vouchers_redeemed_count', $result);
        $this->assertSame(1, $result['vouchers_redeemed_count']);
        $this->assertSame('15.000', $result['vouchers_redeemed_amount']);
    }

    public function test_z_report_v2_omits_voucher_counters_for_backward_compat(): void
    {
        $terminal = $this->createTerminal();

        $method = new ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        // Default schema version (1 / no argument)
        $result = $method->invoke($this->service, $terminal, now()->subHour(), now()->addHour());

        $this->assertArrayNotHasKey('vouchers_issued_count', $result);
        $this->assertArrayNotHasKey('vouchers_issued_amount', $result);
        $this->assertArrayNotHasKey('vouchers_redeemed_count', $result);
        $this->assertArrayNotHasKey('vouchers_redeemed_amount', $result);

        // Explicit v2
        $result2 = $method->invoke($this->service, $terminal, now()->subHour(), now()->addHour(), 2);

        $this->assertArrayNotHasKey('vouchers_issued_count', $result2);
        $this->assertArrayNotHasKey('vouchers_redeemed_count', $result2);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function createTerminal(): Terminal
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
            'max_discount_percent' => 20.0,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
        ]);
    }

    private int $seq = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(Terminal $terminal, array $overrides = []): Receipt
    {
        $this->seq++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->seq),
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => $this->seq,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "test-{$this->seq}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pay'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'discount_amount' => '0.000',
            'total' => '120.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ], $overrides));
    }
}

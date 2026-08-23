<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
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
            $this->app->make(TaxIdentityResolver::class),
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
            'voided_by' => $this->cashier->id,
            'fiscal_status' => FiscalStatus::Voided,
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
     * B-6(ii) / Option A3 — the LEGACY (v1/v2 server-authored) aggregation must
     * NET refund VAT into `vat_breakdown`, exactly as the device does.
     *
     * Before this fix the return branch `continue`d before the `vatDetails`
     * loop, so a server-authored Z reported a SALE-ONLY per-rate table while
     * every device-authored Z reported a NET one — and the §3.1 reconciliation
     * against `EloquentVatDataRepository` (which deducts return rows via
     * `-ABS()`) failed on every legacy terminal that took a return.
     *
     * The sign-era normalization is `magnitude()`-then-SUBTRACT (never a bare
     * `bcadd` of a possibly-negative row): the two POS writers store OPPOSITE
     * signs for the same refund (canonical projection positive, legacy
     * `ReceiptReturnService` negative), the same reason
     * `EloquentVatDataRepository.php:112-113` uses `-ABS()` rather than `-`.
     */
    public function test_return_receipt_vat_details_are_netted_into_vat_breakdown(): void
    {
        $terminal = $this->createPersistedTerminal();

        $sale = $this->createReceipt($terminal, [
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'is_voided' => false,
        ]);
        $this->createVatDetail($sale, '19.00', '100.000', '19.000');

        // POSITIVE-signed return rows (canonical projection era).
        $returnPositive = $this->createReturnReceipt($terminal, $sale, [
            'subtotal' => '30.00',
            'tax_amount' => '5.70',
            'total' => '35.70',
        ]);
        $this->createVatDetail($returnPositive, '19.00', '30.000', '5.700');

        // NEGATIVE-signed return rows (legacy ReceiptReturnService era).
        $returnNegative = $this->createReturnReceipt($terminal, $sale, [
            'subtotal' => '-10.00',
            'tax_amount' => '-1.90',
            'total' => '-11.90',
        ]);
        $this->createVatDetail($returnNegative, '19.00', '-10.000', '-1.900');

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->service, $terminal, now()->subHour(), now()->addHour());

        /** @var list<array<string, mixed>> $breakdown */
        $breakdown = $result['vat_breakdown'];
        $this->assertCount(1, $breakdown, 'Both eras must fold into the single 19% group');

        $row = $breakdown[0];
        $this->assertEquals('11.400', $row['vat_amount'], 'Net VAT = 19.000 − 5.700 − 1.900');
        $this->assertEquals('60.000', $row['net_amount'], 'Net base = 100.000 − 30.000 − 10.000');
        $this->assertEquals('71.400', $row['gross_amount'], 'Net gross = 119.000 − 35.700 − 11.900');

        // The sale-only headline stays sale-only (§3.2 wedge 6): it is NEVER a
        // declaration input, and the refund VAT is exactly the wedge between it
        // and the net table.
        $this->assertEquals('19.000', $result['tax_amount']);
        $this->assertSame(2, $result['refunds_count']);
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

    /**
     * Boundary-receipt regression: a receipt whose created_at falls inside shift A's window
     * but whose posted_at falls inside shift B's window must be attributed to shift B by
     * calculateShiftTotals.
     *
     * Before this fix, calculateShiftTotals used created_at, so the receipt would land in
     * shift A for that aggregator and shift B for PaymentToleranceQueryService (posted_at).
     * After the fix, both aggregators use posted_at and agree about shift B.
     *
     * Mechanism: the receipt is created normally (posted_at = shift B), then created_at is
     * back-dated into shift A via a raw DB update (created_at is not protected by the
     * NF525 immutability trigger).
     *
     * REALIGNMENT-LOG 2026-04-26: shift-window canonical field is pos_receipts.posted_at.
     */
    public function test_boundary_receipt_is_attributed_by_posted_at_not_created_at(): void
    {
        $terminal = $this->createPersistedTerminal();

        // Shift A window: 2 hours ago → 1 hour ago.
        $shiftAOpen = now()->subHours(2);
        $shiftAClose = now()->subHour();

        // Shift B window: 1 hour ago → now (open).
        $shiftBOpen = $shiftAClose;
        $shiftBClose = now()->addHour();

        // Create receipt with posted_at inside shift B and created_at back-dated
        // into shift A. created_at is written at INSERT time rather than via a
        // post-seal UPDATE: the PostgreSQL receipt immutability trigger now
        // rejects ANY update to a fiscalized receipt (other than the
        // fiscalized->voided transition), so a raw created_at update after
        // sealing is blocked. Setting created_at on the unsaved model keeps the
        // whole write inside a single INSERT.
        $boundaryReceipt = $this->createReceipt($terminal, [
            'subtotal' => '80.00',
            'tax_amount' => '8.00',
            'total' => '88.00',
            'is_voided' => false,
            'posted_at' => $shiftBOpen->clone()->addMinutes(5),
            'created_at' => $shiftAOpen->clone()->addMinutes(5),
        ]);

        $method = new \ReflectionMethod(ReportGenerationService::class, 'calculateShiftTotals');
        $method->setAccessible(true);

        // Shift A query — receipt must NOT appear (posted_at is outside shift A).
        $shiftAResult = $method->invoke($this->service, $terminal, $shiftAOpen, $shiftAClose);
        $this->assertSame(
            0,
            $shiftAResult['sales_count'],
            'Boundary receipt must NOT appear in shift A: posted_at is in shift B, only created_at is in shift A',
        );

        // Shift B query — receipt MUST appear (posted_at is inside shift B).
        $shiftBResult = $method->invoke($this->service, $terminal, $shiftBOpen, $shiftBClose);
        $this->assertSame(
            1,
            $shiftBResult['sales_count'],
            'Boundary receipt MUST appear in shift B: posted_at is inside shift B window',
        );
        $this->assertSame('88.000', $shiftBResult['gross_sales']);
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
     * A return receipt carrying the two columns the PostgreSQL
     * `pos_receipts_return_logic` CHECK requires (original receipt + reason).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createReturnReceipt(Terminal $terminal, Receipt $original, array $overrides = []): Receipt
    {
        return $this->createReceipt($terminal, array_merge([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::Defective,
        ], $overrides));
    }

    /**
     * @param  numeric-string  $taxRate
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $vatAmount
     */
    private function createVatDetail(Receipt $receipt, string $taxRate, string $netAmount, string $vatAmount): ReceiptVatDetail
    {
        return ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => $taxRate,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'gross_amount' => bcadd($netAmount, $vatAmount, 3),
        ]);
    }

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

        $attributes = array_merge($defaults, $overrides);

        // created_at is guarded (not mass-assignable). When a test needs a
        // back-dated creation time it must be written at INSERT time, because
        // the receipt is sealed (fiscalized) on insert and the immutability
        // trigger blocks any later UPDATE.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $receipt = new Receipt($attributes);
        if ($createdAt !== null) {
            $receipt->created_at = $createdAt;
        }
        $receipt->save();

        return $receipt;
    }
}

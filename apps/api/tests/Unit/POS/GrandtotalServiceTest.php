<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Unit tests for GrandtotalService
 *
 * Verifies:
 * - Period totals use correct Receipt columns (total, NOT total_amount)
 * - Perpetual totals use correct column in raw SQL (total, NOT total_amount)
 * - Voided receipts excluded correctly
 */
class GrandtotalServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private GrandtotalService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GrandtotalService($this->mockCurrencyScale(3));

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * Test calculatePeriodTotals uses correct columns
     */
    public function test_period_totals_uses_correct_columns(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'is_voided' => false,
        ]);

        $result = $this->service->calculatePeriodTotals(
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals('119.000', $result['gross_sales']);
        $this->assertEquals('100.000', $result['net_sales']);
        $this->assertEquals('19.000', $result['tax_amount']);
        $this->assertEquals(1, $result['sales_count']);
        $this->assertEquals(0, $result['refunds_count']);
    }

    /**
     * Test that voided receipts count as refunds in period totals
     */
    public function test_voided_receipts_counted_as_refunds(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'total' => '119.00',
            'is_voided' => true,
            'voided_at' => now(),
        ]);

        $result = $this->service->calculatePeriodTotals(
            $terminal,
            now()->subHour(),
            now()->addHour()
        );

        $this->assertEquals('0.00', $result['gross_sales']);
        $this->assertEquals(0, $result['sales_count']);
        $this->assertEquals(1, $result['refunds_count']);
        $this->assertEquals('119.000', $result['refunds_amount']);
    }

    /**
     * Test calculatePerpetualTotals uses correct column in raw SQL
     */
    public function test_perpetual_totals_uses_correct_columns(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'total' => '119.00',
            'tax_amount' => '19.00',
            'is_voided' => false,
        ]);

        $this->createReceipt($terminal, [
            'total' => '59.50',
            'tax_amount' => '9.50',
            'is_voided' => false,
        ]);

        $result = $this->service->calculatePerpetualTotals($terminal);

        $this->assertEquals('178.500', $result['lifetime_sales']);
        $this->assertEquals('28.500', $result['lifetime_tax']);
        $this->assertEquals(2, $result['lifetime_transactions']);
    }

    /**
     * Test that voided receipts are excluded from perpetual totals
     */
    public function test_voided_receipts_excluded_from_perpetual_totals(): void
    {
        $terminal = $this->createPersistedTerminal();

        $this->createReceipt($terminal, [
            'total' => '119.00',
            'tax_amount' => '19.00',
            'is_voided' => false,
        ]);

        $this->createReceipt($terminal, [
            'total' => '200.00',
            'tax_amount' => '38.00',
            'is_voided' => true,
            'voided_at' => now(),
        ]);

        $result = $this->service->calculatePerpetualTotals($terminal);

        $this->assertEquals('119.000', $result['lifetime_sales']);
        $this->assertEquals('19.000', $result['lifetime_tax']);
        $this->assertEquals(1, $result['lifetime_transactions']);
    }

    /**
     * Test empty terminal returns zeros for perpetual totals
     */
    public function test_empty_terminal_perpetual_totals(): void
    {
        $terminal = $this->createPersistedTerminal();

        $result = $this->service->calculatePerpetualTotals($terminal);

        $this->assertEquals('0.000', $result['lifetime_sales']);
        $this->assertEquals('0.000', $result['lifetime_tax']);
        $this->assertEquals(0, $result['lifetime_transactions']);
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
            'fiscal_hash' => hash('sha256', "gt-receipt-{$this->receiptSequence}"),
            'previous_hash' => null,
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

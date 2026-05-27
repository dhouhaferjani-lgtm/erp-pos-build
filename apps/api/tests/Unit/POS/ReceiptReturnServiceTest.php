<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\RefundDestinationResolver;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for ReceiptReturnService
 *
 * Verifies:
 * - Return receipt discount_amount equals sum of proportional line discounts
 * - Missing stock level logs a warning during return stock restore
 */
class ReceiptReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptReturnService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
        $this->createOpenShift();

        $companyContext = new CompanyContext;
        $companyContext->setCompanyId($this->company->id);

        $this->service = new ReceiptReturnService(
            $companyContext,
            $this->app->make(CashDrawerService::class),
            $this->app->make(CurrencyScaleResolverInterface::class),
            $this->app->make(ReceiptFinalizationService::class),
            $this->app->make(RefundDestinationResolver::class),
            $this->app->make(VoucherIssuanceService::class),
            $this->app->make(PaymentRefundService::class),
            $this->app->make(ReceiptHashService::class),
        );
    }

    public function test_return_receipt_has_correct_discount_amount(): void
    {
        // Arrange: Create a sale receipt with two discounted lines
        $saleReceipt = $this->createReceipt();

        $line1 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '4.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '36.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.748',
            'discount_amount' => '4.000',
            'discount_reason' => '10% off',
        ]);

        $line2 = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 2,
            'product_code' => 'PROD-002',
            'product_name' => 'Widget B',
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '50.000',
            'line_total' => '90.000',
            'tax_rate' => '19.00',
            'tax_amount' => '14.370',
            'discount_amount' => '10.000',
            'discount_reason' => '10% off',
        ]);

        // Act: Return full quantities of both lines
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line1->id, 'quantity' => '4.000'],
                ['line_id' => $line2->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: discount_amount should be the sum of line discounts (4.000 + 10.000 = 14.000)
        $this->assertEquals('14.000', $returnReceipt->discount_amount);

        // Also verify individual line discounts are correct
        $returnLines = $returnReceipt->lines->sortBy('line_number')->values();
        $this->assertEquals('4.000', $returnLines[0]->discount_amount);
        $this->assertEquals('10.000', $returnLines[1]->discount_amount);
    }

    public function test_return_receipt_has_proportional_discount_for_partial_return(): void
    {
        // Arrange: Create a sale receipt with a discounted line
        $saleReceipt = $this->createReceipt();

        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '4.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '36.000',
            'tax_rate' => '19.00',
            'tax_amount' => '5.748',
            'discount_amount' => '4.000',
            'discount_reason' => '10% off',
        ]);

        // Act: Return only 2 of 4 items (50%)
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: discount should be proportional: 4.000 * (2/4) = 2.000
        $this->assertEquals('2.000', $returnReceipt->discount_amount);
    }

    public function test_stock_restore_logs_warning_when_no_stock_level(): void
    {
        Log::spy();

        // Arrange: Create a sale receipt with a product line but no stock level
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $saleReceipt = $this->createReceipt();

        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '25.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '7.983',
            'discount_amount' => '0.000',
        ]);

        // No StockLevel created for this product — should log warning

        // Act
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: Log::warning was called with the expected message
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($product): bool {
                return $message === 'No stock level found for product during return stock restore'
                    && $context['product_id'] === $product->id
                    && $context['location_id'] === $this->location->id;
            })
            ->once();
    }

    public function test_stock_restore_does_not_log_warning_when_stock_level_exists(): void
    {
        Log::spy();

        // Arrange: Create a sale receipt with a product line AND a stock level
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        $saleReceipt = $this->createReceipt();

        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '25.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '7.983',
            'discount_amount' => '0.000',
        ]);

        // Act
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: Log::warning was NOT called for stock restore
        Log::shouldNotHaveReceived('warning');
    }

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 200,
            'current_year' => 2026,
            // Explicitly pinned to v2 so this test targets the legacy hash path.
            // See V2ToV3ChainReplayTest legacy hash audit note (Task 43).
            'fiscal_schema_version' => 2,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createOpenShift(): Shift
    {
        return Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private int $receiptSequence = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        $this->receiptSequence++;

        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->receiptSequence),
            'chain_sequence' => 100 + $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "return-test-receipt-{$this->receiptSequence}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            // Header total must satisfy pos_receipts_totals on PostgreSQL:
            // total = subtotal + tax_amount - discount_amount. Line-level
            // discounts (set per-test on receipt lines) drive return proration,
            // not this header field.
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
            'is_voided' => false,
        ];

        return Receipt::create(array_merge($defaults, $overrides));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
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
use Tests\TestCase;

/**
 * Task 6: Thread per-line disposition through processReturn → validateReturnQuantities
 *
 * Verifies:
 * (a) No disposition key → defaults to RESTOCK, stock is increased (backward-compat pinned)
 * (b) Unknown disposition string → processReturn throws \InvalidArgumentException
 * (c) Illegal combos (service-side guard):
 *     - disposition='restock' + physical_receipt=false → \InvalidArgumentException
 *     - disposition='not_received' + physical_receipt=true → \InvalidArgumentException
 */
class ReceiptReturnServiceDispositionTest extends TestCase
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

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

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

    // =========================================================================
    // (a) No disposition key → defaults to RESTOCK (stock increased)
    // =========================================================================

    public function test_absent_disposition_defaults_to_restock_and_increases_stock(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $stockLevel = $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '2.000',
        ]);

        // Act: no 'disposition' key — must behave exactly as before Task 6
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: stock was INCREASED (RESTOCK = +qty)
        $this->assertSame('12.0000', (string) $stockLevel->refresh()->quantity);

        // Assert: a pos_receipt_return stock movement was written with positive quantity
        $movement = StockMovement::where('reference_type', 'pos_receipt_return')
            ->where('reference_id', $returnReceipt->id)
            ->firstOrFail();

        $this->assertSame('2.0000', (string) $movement->quantity);
        $this->assertSame($product->id, $movement->product_id);
    }

    // =========================================================================
    // (b) Unknown disposition string → \InvalidArgumentException
    // =========================================================================

    public function test_unknown_disposition_string_throws_invalid_argument_exception(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '2.000',
        ]);

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000', 'disposition' => 'banana'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    // =========================================================================
    // (c) Service-side illegal-combo guard
    // =========================================================================

    public function test_restock_with_physical_receipt_false_throws_invalid_argument_exception(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '2.000',
        ]);

        // Assert: RESTOCK + physical_receipt=false is an illegal combo
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                [
                    'line_id' => $line->id,
                    'quantity' => '2.000',
                    'disposition' => 'restock',
                    'physical_receipt' => false,
                ],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    public function test_not_received_with_physical_receipt_true_throws_invalid_argument_exception(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '2.000',
        ]);

        // Assert: NOT_RECEIVED + physical_receipt=true is an illegal combo
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                [
                    'line_id' => $line->id,
                    'quantity' => '2.000',
                    'disposition' => 'not_received',
                    'physical_receipt' => true,
                ],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    // =========================================================================
    // Helpers — mirrors ReceiptReturnServiceTest scaffold exactly
    // =========================================================================

    private function createStockLevel(string $productId, ?string $variantId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProductLine(Receipt $receipt, Product $product, array $overrides = []): ReceiptLine
    {
        $defaults = [
            'receipt_id' => $receipt->id,
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
        ];

        return ReceiptLine::create(array_merge($defaults, $overrides));
    }

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS06',
            'name' => 'Test Terminal T6',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 300,
            'current_year' => 2026,
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
            'receipt_number' => sprintf('POS06-2026-%08d', $this->receiptSequence),
            'chain_sequence' => 200 + $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "t6-receipt-{$this->receiptSequence}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier T6',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
            'is_voided' => false,
        ];

        return Receipt::create(array_merge($defaults, $overrides));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Application\Services\ReturnScrapWriteOffService;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\RefundDestinationResolver;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Application\Services\RestockPolicyResolver;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        // Note: ReceiptReturnService receives a separate CompanyContext instance from the
        // service container; both this local instance and the singleton must be seeded.
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
            $this->app->make(RestockPolicyResolver::class),
            $this->app->make(LegacyCorrectionGuard::class),
            $this->app->make(ReturnScrapWriteOffService::class),
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

    // =========================================================================
    // Variant-aware restock (F1 / F2 — variant retrofit audit 2026-06-10)
    // =========================================================================

    public function test_return_restores_variant_scoped_stock_row(): void
    {
        // Arrange: one product with two variants, three stock rows at the
        // same location (variant A, variant B, product-level NULL row).
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);
        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        $stockA = $this->createStockLevel($product->id, $variantA->id, '8.0000');
        $stockB = $this->createStockLevel($product->id, $variantB->id, '5.0000');
        $stockNull = $this->createStockLevel($product->id, null, '50.0000');

        // Draft-path sale receipt: line carries variant A.
        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'variant_id' => $variantA->id,
            'quantity' => '2.000',
        ]);

        // Act: return the variant-A line in full.
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: ONLY variant A's stock row was restored.
        $this->assertSame('10.0000', (string) $stockA->refresh()->quantity);
        $this->assertSame('5.0000', (string) $stockB->refresh()->quantity);
        $this->assertSame('50.0000', (string) $stockNull->refresh()->quantity);

        // Assert: the StockMovement row carries the variant.
        $movement = StockMovement::where('reference_type', 'pos_receipt_return')
            ->where('reference_id', $returnReceipt->id)
            ->firstOrFail();
        $this->assertSame($variantA->id, $movement->variant_id);
    }

    public function test_return_restores_null_row_for_projection_path_lines(): void
    {
        // Projection-path receipts carry variant_id = NULL on their lines and
        // were decremented on the variant_id IS NULL stock row. The restore
        // must reverse that exact row even when variant rows exist — never
        // re-derive the variant.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        $stockVariant = $this->createStockLevel($product->id, $variant->id, '8.0000');
        $stockNull = $this->createStockLevel($product->id, null, '40.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'variant_id' => null,
            'quantity' => '3.000',
        ]);

        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '3.000'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame('43.0000', (string) $stockNull->refresh()->quantity);
        $this->assertSame('8.0000', (string) $stockVariant->refresh()->quantity);

        $movement = StockMovement::where('reference_type', 'pos_receipt_return')
            ->where('reference_id', $returnReceipt->id)
            ->firstOrFail();
        $this->assertNull($movement->variant_id);
    }

    public function test_return_receipt_lines_persist_variant_id(): void
    {
        // F2 — return receipt lines must copy variant_id from the original line.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);
        $this->createStockLevel($product->id, $variant->id, '8.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'variant_id' => $variant->id,
            'quantity' => '2.000',
        ]);

        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $returnLine = $returnReceipt->lines->firstOrFail();
        $this->assertSame($variant->id, $returnLine->variant_id);
        $this->assertSame($line->id, $returnLine->original_line_id);
    }

    public function test_return_targets_variant_row_under_pg_partial_indexes(): void
    {
        // PG-gated: under PostgreSQL the post-T2 partial unique indexes
        // (stock_levels_non_variant / stock_levels_with_variant) are real,
        // so multiple rows per (product, location) actually coexist under
        // index enforcement. Same acceptance shape as the SQLite test above.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('partial unique indexes are pgsql-only');
        }

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
        ]);

        $stockVariant = $this->createStockLevel($product->id, $variant->id, '8.0000');
        $stockNull = $this->createStockLevel($product->id, null, '50.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'variant_id' => $variant->id,
            'quantity' => '2.000',
        ]);

        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame('10.0000', (string) $stockVariant->refresh()->quantity);
        $this->assertSame('50.0000', (string) $stockNull->refresh()->quantity);
    }

    // =========================================================================
    // Batch restitution on returns (F4 — variant retrofit audit 2026-06-10)
    // =========================================================================

    public function test_partial_return_restores_batch_stock_proportionally(): void
    {
        // Sale consumed 5 units across two batches (3 from batch1, 2 from
        // batch2). A partial return of 2 units restores proportionally:
        // batch1 += trunc4(3 * 2/5) = 1.2, batch2 += trunc4(2 * 2/5) = 0.8.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $stockLevel = $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '5.000',
        ]);

        [$batch1, $batchStock1] = $this->createBatchWithStock($product, 'B1', '0.0000');
        [$batch2, $batchStock2] = $this->createBatchWithStock($product, 'B2', '3.0000');

        $this->createAllocation($saleReceipt, $line, $batch1, '3.0000');
        $this->createAllocation($saleReceipt, $line, $batch2, '2.0000');

        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame('1.2000', (string) $batchStock1->refresh()->quantity);
        $this->assertSame('3.8000', (string) $batchStock2->refresh()->quantity);

        // Aggregate stock_levels restore matches the batch restitution sum
        // (1.2 + 0.8 = 2.0 = returned quantity).
        $this->assertSame('12.0000', (string) $stockLevel->refresh()->quantity);
    }

    public function test_full_return_after_partial_caps_cumulative_batch_restitution(): void
    {
        // Cumulative restitution invariant: after returning everything,
        // each batch is restored by EXACTLY its original allocation —
        // never more — across repeated partial returns.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '5.000',
        ]);

        [$batch1, $batchStock1] = $this->createBatchWithStock($product, 'B1', '0.0000');
        [$batch2, $batchStock2] = $this->createBatchWithStock($product, 'B2', '3.0000');

        $this->createAllocation($saleReceipt, $line, $batch1, '3.0000');
        $this->createAllocation($saleReceipt, $line, $batch2, '2.0000');

        // First partial return: 2 of 5.
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Second return: the remaining 3 of 5.
        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '3.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Cumulative restitution == original allocation, exactly.
        // batch1: 0.0 + 3.0 = 3.0; batch2: 3.0 + 2.0 = 5.0.
        $this->assertSame('3.0000', (string) $batchStock1->refresh()->quantity);
        $this->assertSame('5.0000', (string) $batchStock2->refresh()->quantity);
    }

    public function test_return_of_non_batch_product_leaves_batch_stock_untouched(): void
    {
        // A returned line with no batch allocations must not touch any
        // inventory_batch_stock row (including other products' batches).
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');

        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        [, $otherBatchStock] = $this->createBatchWithStock($otherProduct, 'OTHER', '7.0000');

        $saleReceipt = $this->createReceipt();
        $line = $this->createProductLine($saleReceipt, $product, [
            'quantity' => '2.000',
        ]);

        $this->service->processReturn(
            originalReceiptId: $saleReceipt->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame('7.0000', (string) $otherBatchStock->refresh()->quantity);
    }

    // =========================================================================
    // Helpers (variant / batch fixtures)
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

    /**
     * Create a batch + its inventory_batch_stock row at the test location.
     *
     * @return array{0: Batch, 1: BatchStock}
     */
    private function createBatchWithStock(Product $product, string $batchNumber, string $quantity): array
    {
        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        $batchStock = BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0',
        ]);

        return [$batch, $batchStock];
    }

    private function createAllocation(
        Receipt $receipt,
        ReceiptLine $line,
        Batch $batch,
        string $quantity,
    ): ReceiptLineBatchAllocation {
        return ReceiptLineBatchAllocation::create([
            'receipt_id' => $receipt->id,
            'receipt_line_id' => $line->id,
            'batch_id' => $batch->id,
            'quantity' => $quantity,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date,
        ]);
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

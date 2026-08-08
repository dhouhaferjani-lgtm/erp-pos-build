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
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Application\Services\ReturnScrapWriteOffService;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
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
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            $this->app->make(RestockPolicyResolver::class),
            $this->app->make(LegacyCorrectionGuard::class),
            $this->app->make(ReturnScrapWriteOffService::class),
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
    // Task 7: disposition-branch stock step
    // =========================================================================

    public function test_scrap_writes_two_movements_and_skips_batch(): void
    {
        // Arrange: 10 units in stock; one batch allocation seeded to prove
        // batch restitution is skipped for SCRAP.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $stock = $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        [$batch, $batchStock] = $this->createBatchWithStock($product, 'SCR-B1', '5.0000');
        $this->createAllocation($sale, $line, $batch, '2.0000');

        // Act
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '2.000',
                'physical_receipt' => true, 'resalable' => false, 'disposition' => 'scrap']],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Net sellable unchanged vs before-return baseline of 10 (receive +2, write-off -2).
        $this->assertSame('10.0000', (string) $stock->refresh()->quantity);

        // One pos_return receive movement (+qty) and one write_off subtract movement (-qty).
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'pos_return')->count());
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'write_off')->count());

        // DPA V10: the write-off leg now goes through the compliant chain
        // (StockAdjustmentService::issue), so its movement_type is the canonical
        // decrement type ISSUE — the same shape BatchWriteOffService produces —
        // instead of the ad-hoc ADJUSTMENT the raw pre-V10 INSERT wrote. The
        // reference_type string is UNCHANGED (it is now the backing value of
        // StockMovementReferenceType::PosReceiptReturnScrap).
        $writeOff = StockMovement::where('product_id', $product->id)->where('reason', 'write_off')->firstOrFail();
        $this->assertSame(MovementType::Issue->value, $writeOff->movement_type->value);
        $this->assertSame('pos_receipt_return_scrap', $writeOff->reference_type);

        // Batch stock NOT inflated — restitution was skipped for SCRAP.
        $this->assertSame('5.0000', (string) $batchStock->refresh()->quantity);
    }

    public function test_not_received_writes_zero_movements(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $stock = $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        // Act
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '1.000',
                'physical_receipt' => false, 'resalable' => null, 'disposition' => 'not_received']],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Stock level unchanged — zero movements written.
        $this->assertSame('10.0000', (string) $stock->refresh()->quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_explicit_restock_increases_stock_and_runs_batch_restitution(): void
    {
        // Arrange: explicit disposition=restock with valid physical_receipt + resalable.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $stock = $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        [$batch, $batchStock] = $this->createBatchWithStock($product, 'RST-B1', '0.0000');
        $this->createAllocation($sale, $line, $batch, '2.0000');

        // Act: explicit restock
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '2.000',
                'physical_receipt' => true, 'resalable' => true, 'disposition' => 'restock']],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Stock increased by returned qty.
        $this->assertSame('12.0000', (string) $stock->refresh()->quantity);

        // Only one movement (pos_return); no write_off.
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'pos_return')->count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->where('reason', 'write_off')->count());

        // Batch restitution ran — batch stock was restored.
        $this->assertSame('2.0000', (string) $batchStock->refresh()->quantity);
    }

    public function test_variant_aware_scrap_both_movements_target_variant_row(): void
    {
        // Arrange: one product with two variants + a null/product-level row.
        // Only variantA should be touched by either movement.
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

        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, [
            'variant_id' => $variantA->id,
            'quantity' => '2.000',
        ]);

        // Act: SCRAP on a variant-A line
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [['line_id' => $line->id, 'quantity' => '2.000',
                'physical_receipt' => true, 'resalable' => false, 'disposition' => 'scrap']],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // All three stock rows: variantA net unchanged (+2 receive, −2 write-off); decoys untouched.
        $this->assertSame('8.0000', (string) $stockA->refresh()->quantity);
        $this->assertSame('5.0000', (string) $stockB->refresh()->quantity);
        $this->assertSame('50.0000', (string) $stockNull->refresh()->quantity);

        // Both movements carry variantA's id; no movement for variantB or NULL.
        $movements = StockMovement::where('product_id', $product->id)->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame($variantA->id, $movement->variant_id);
        }
    }

    // =========================================================================
    // Task 8: disposition + facts persisted on return ReceiptLine rows
    // =========================================================================

    public function test_scrap_return_line_persists_disposition_physical_receipt_resalable(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        // Act
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                [
                    'line_id' => $line->id,
                    'quantity' => '2.000',
                    'disposition' => 'scrap',
                    'physical_receipt' => true,
                    'resalable' => false,
                ],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: enum-cast round-trip from DB
        $returnLine = ReceiptLine::where('receipt_id', $returnReceipt->id)->firstOrFail();
        $this->assertSame(ReturnLineDisposition::Scrap, $returnLine->disposition);
        $this->assertTrue($returnLine->physical_receipt);
        $this->assertFalse($returnLine->resalable);
    }

    public function test_default_no_disposition_return_line_persists_restock(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        // Act: no disposition key — defaults to RESTOCK
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert
        $returnLine = ReceiptLine::where('receipt_id', $returnReceipt->id)->firstOrFail();
        $this->assertSame(ReturnLineDisposition::Restock, $returnLine->disposition);
    }

    public function test_not_received_return_line_persists_disposition_and_physical_receipt_false(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '3.000']);

        // Act
        $returnReceipt = $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                [
                    'line_id' => $line->id,
                    'quantity' => '3.000',
                    'disposition' => 'not_received',
                    'physical_receipt' => false,
                ],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert
        $returnLine = ReceiptLine::where('receipt_id', $returnReceipt->id)->firstOrFail();
        $this->assertSame(ReturnLineDisposition::NotReceived, $returnLine->disposition);
        $this->assertFalse($returnLine->physical_receipt);
    }

    // =========================================================================
    // Task 9: never-policy regulated-goods guard
    // =========================================================================

    public function test_never_policy_product_restock_disposition_throws(): void
    {
        // Arrange: product with restock_policy = never
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        // Assert: restock disposition on a never-policy product throws
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/policy: never/');

        // Act: explicit restock disposition
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000',
                    'physical_receipt' => true, 'resalable' => true, 'disposition' => 'restock'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    public function test_never_policy_product_default_disposition_restock_throws(): void
    {
        // Arrange: product with restock_policy = never; no explicit disposition → defaults to RESTOCK
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);
        $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/policy: never/');

        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    public function test_never_policy_product_scrap_disposition_succeeds(): void
    {
        // Arrange: product with restock_policy = never
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);
        $stock = $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        // Act: SCRAP is allowed even for never-policy products
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000',
                    'physical_receipt' => true, 'resalable' => false, 'disposition' => 'scrap'],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: two movements (receive + write-off), net stock unchanged
        $this->assertSame('10.0000', (string) $stock->refresh()->quantity);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'pos_return')->count());
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('reason', 'write_off')->count());
    }

    public function test_never_policy_product_not_received_disposition_succeeds(): void
    {
        // Arrange: product with restock_policy = never
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);
        $stock = $this->createStockLevel($product->id, null, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '1.000']);

        // Act: NOT_RECEIVED is allowed even for never-policy products
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '1.000',
                    'physical_receipt' => false, 'resalable' => null, 'disposition' => 'not_received'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: zero stock movements, stock unchanged
        $this->assertSame('10.0000', (string) $stock->refresh()->quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_normal_product_no_policy_restock_still_works(): void
    {
        // Arrange: product with no restock_policy (falls back to default_allow)
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => null,
        ]);
        $stock = $this->createStockLevel($product->id, null, '5.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        // Act: default RESTOCK must still succeed
        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                ['line_id' => $line->id, 'quantity' => '2.000'],
            ],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // Assert: stock increased by returned qty
        $this->assertSame('7.0000', (string) $stock->refresh()->quantity);
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
}

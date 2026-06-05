<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\Events\StockTransferCancelled;
use App\Modules\Inventory\Domain\Events\StockTransferCompleted;
use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private Location $otherCompanyShop;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Auto',
            'legal_name' => 'Acme Auto LLC',
            'tax_id' => 'TAX-ACME',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX-OTHER',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer User',
            'email' => 'transfer@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-01',
            'name' => 'Downtown Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->otherCompanyShop = Location::create([
            'company_id' => $this->otherCompany->id,
            'code' => 'SH-OTHER',
            'name' => 'Other Co Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-A',
            'name' => 'Brake Pad',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-B',
            'name' => 'Oil Filter',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '10.0000',
            'sale_price' => '20.0000',
        ]);
    }

    private function service(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    private function seedStock(Product $product, Location $location, string $qty): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $location->id,
            quantity: $qty,
            reference: 'SEED',
            userId: $this->user->id,
        );
    }

    private function seedBatchStock(Product $product, Batch $batch, Location $location, string $qty): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $location->id,
            quantity: $qty,
            reference: 'BATCH-SEED',
            userId: $this->user->id,
            batchId: (int) $batch->id,
            expectedCompanyId: $this->company->id,
        );
    }

    private function createBatch(Product $product, string $batchNumber = 'LOT-A', ?string $expiryDate = null): Batch
    {
        /** @var Batch $batch */
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'manufacturing_date' => now()->subMonth()->toDateString(),
            'expiry_date' => $expiryDate ?? now()->addMonths(8)->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        return $batch;
    }

    /**
     * @param  list<InitiateTransferLineData>  $lines
     */
    private function initiateData(
        string $sourceLocationId,
        string $destLocationId,
        array $lines,
        string $transferCost = '0',
        ?string $idempotencyKey = null,
    ): InitiateTransferData {
        return new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $sourceLocationId,
            destinationLocationId: $destLocationId,
            initiatedByUserId: $this->user->id,
            lines: $lines,
            transferCost: $transferCost,
            idempotencyKey: $idempotencyKey,
        );
    }

    public function test_cross_company_locations_are_rejected(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '20.0000');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->otherCompanyShop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));
    }

    public function test_same_source_and_destination_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->warehouse->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));
    }

    public function test_transfer_with_no_lines_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [],
        ));
    }

    public function test_intercompany_transfer_type_is_rejected_today(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->productA->id, '5.0000')],
            transferType: TransferType::Intercompany,
        ));
    }

    public function test_initiate_atomically_decrements_source_and_records_transfer_out_movement(): void
    {
        Event::fake([StockTransferInitiated::class]);

        $this->seedStock($this->productA, $this->warehouse, '20.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '7.0000')],
        ));

        $this->assertSame(TransferStatus::InTransit, $transfer->status);
        $this->assertNotNull($transfer->initiated_at);
        $this->assertSame(TransferType::Intracompany, $transfer->transfer_type);
        $this->assertNotEmpty($transfer->transfer_number);

        $sourceStock = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $destStock = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->shop->id)
            ->first();

        $this->assertNotNull($sourceStock);
        $this->assertEquals('13.0000', $sourceStock->quantity);
        $this->assertTrue($destStock === null || (float) $destStock->quantity === 0.0);

        $line = $transfer->lines->first();
        $this->assertNotNull($line);
        $this->assertNotNull($line->unit_cost_snapshot);

        $this->assertEquals(1, StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->where('location_id', $this->warehouse->id)
            ->where('movement_type', MovementType::TransferOut)
            ->count());

        Event::assertDispatched(StockTransferInitiated::class);
    }

    public function test_initiate_requires_sufficient_source_stock(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '2.0000');

        $this->expectException(InsufficientStockException::class);

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));
    }

    public function test_initiate_is_idempotent_when_same_idempotency_key_is_used(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '50.0000');

        $key = 'retry-1';
        $first = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
            idempotencyKey: $key,
        ));
        $second = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
            idempotencyKey: $key,
        ));

        $this->assertSame($first->id, $second->id);
        // Source only decremented once.
        $sourceStock = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($sourceStock);
        $this->assertEquals('45.0000', $sourceStock->quantity);
    }

    public function test_complete_increments_destination_and_records_both_legs(): void
    {
        Event::fake([StockTransferCompleted::class]);

        $this->seedStock($this->productA, $this->warehouse, '20.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '7.0000')],
        ));
        $received = $this->service()->complete($transfer->id, $this->user->id);

        $this->assertSame(TransferStatus::Completed, $received->status);
        $this->assertNotNull($received->completed_at);

        $sourceStock = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $destStock = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->shop->id)
            ->first();

        $this->assertNotNull($sourceStock);
        $this->assertNotNull($destStock);
        $this->assertEquals('13.0000', $sourceStock->quantity);
        $this->assertEquals('7.0000', $destStock->quantity);

        $this->assertEquals(1, StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $received->id)
            ->where('movement_type', MovementType::TransferOut)
            ->count());
        $this->assertEquals(1, StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $received->id)
            ->where('movement_type', MovementType::TransferIn)
            ->count());

        Event::assertDispatched(StockTransferCompleted::class);
    }

    public function test_batch_allocations_move_source_to_in_transit_then_destination_on_complete(): void
    {
        $this->productA->update(['requires_batch_tracking' => true]);
        $batch = $this->createBatch($this->productA, 'LOT-FEFO-1');
        $this->seedBatchStock($this->productA, $batch, $this->warehouse, '10.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [
                new InitiateTransferLineData(
                    productId: $this->productA->id,
                    quantity: '4.0000',
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData((int) $batch->id, '4.0000'),
                    ],
                ),
            ],
        ));

        $line = $transfer->lines->first();
        $this->assertNotNull($line);
        $this->assertCount(1, $line->batchAllocations);
        $this->assertSame((int) $batch->id, $line->batchAllocations->first()->batch_id);
        $this->assertEquals('4.0000', $line->batchAllocations->first()->quantity);

        $sourceAfterInitiate = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $destinationAfterInitiate = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->shop->id)
            ->first();

        $this->assertNotNull($sourceAfterInitiate);
        $this->assertEquals('6.0000', $sourceAfterInitiate->quantity);
        $this->assertTrue($destinationAfterInitiate === null || bccomp((string) $destinationAfterInitiate->quantity, '0.0000', 4) === 0);

        $completed = $this->service()->complete($transfer->id, $this->user->id);
        $completedLine = $completed->lines->first();
        $this->assertNotNull($completedLine);
        $this->assertCount(1, $completedLine->batchAllocations);

        $sourceAfterComplete = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $destinationAfterComplete = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->shop->id)
            ->first();

        $this->assertNotNull($sourceAfterComplete);
        $this->assertNotNull($destinationAfterComplete);
        $this->assertEquals('6.0000', $sourceAfterComplete->quantity);
        $this->assertEquals('4.0000', $destinationAfterComplete->quantity);
    }

    public function test_batch_tracked_transfer_requires_allocations_that_match_line_quantity(): void
    {
        $this->productA->update(['requires_batch_tracking' => true]);
        $batch = $this->createBatch($this->productA, 'LOT-QTY-MISMATCH');
        $this->seedBatchStock($this->productA, $batch, $this->warehouse, '10.0000');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch allocation quantity must equal the transfer line quantity.');

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [
                new InitiateTransferLineData(
                    productId: $this->productA->id,
                    quantity: '4.0000',
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData((int) $batch->id, '3.0000'),
                    ],
                ),
            ],
        ));
    }

    public function test_batch_tracked_transfer_blocks_recalled_or_expired_batches(): void
    {
        $this->productA->update(['requires_batch_tracking' => true]);
        $batch = $this->createBatch($this->productA, 'LOT-RECALLED');
        $batch->recall('supplier recall');
        $this->seedBatchStock($this->productA, $batch, $this->warehouse, '10.0000');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch cannot be transferred because it is expired, recalled, or inactive.');

        $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [
                new InitiateTransferLineData(
                    productId: $this->productA->id,
                    quantity: '4.0000',
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData((int) $batch->id, '4.0000'),
                    ],
                ),
            ],
        ));
    }

    public function test_cancelling_batch_transfer_returns_batch_stock_to_source(): void
    {
        $this->productA->update(['requires_batch_tracking' => true]);
        $batch = $this->createBatch($this->productA, 'LOT-CANCEL');
        $this->seedBatchStock($this->productA, $batch, $this->warehouse, '10.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [
                new InitiateTransferLineData(
                    productId: $this->productA->id,
                    quantity: '4.0000',
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData((int) $batch->id, '4.0000'),
                    ],
                ),
            ],
        ));

        $this->service()->cancel($transfer->id, $this->user->id, 'shipment cancelled');

        $sourceAfterCancel = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $destinationAfterCancel = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->shop->id)
            ->first();

        $this->assertNotNull($sourceAfterCancel);
        $this->assertEquals('10.0000', $sourceAfterCancel->quantity);
        $this->assertTrue($destinationAfterCancel === null || bccomp((string) $destinationAfterCancel->quantity, '0.0000', 4) === 0);
    }

    public function test_cannot_complete_an_already_completed_transfer(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '50.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));
        $this->service()->complete($transfer->id, $this->user->id);

        $this->expectException(TransferStateException::class);
        $this->service()->complete($transfer->id, $this->user->id);
    }

    public function test_complete_with_transfer_cost_recomputes_company_wide_wac(): void
    {
        // Company on-hand A = 120 (100 at warehouse + 20 at shop), cost = 5.
        $this->seedStock($this->productA, $this->warehouse, '100.0000');
        $this->seedStock($this->productA, $this->shop, '20.0000');
        $this->assertEquals('5.000000', $this->productA->fresh()->cost_price);

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '10.0000')],
            transferCost: '60.0000',
        ));

        $this->service()->complete($transfer->id, $this->user->id);

        // Company on-hand still 120 after complete (90 + 30).
        // WAC delta = 60 / 120 = 0.50 → 5.50
        $this->assertEquals('5.500000', $this->productA->fresh()->cost_price);
    }

    public function test_cancelling_in_transit_transfer_returns_stock_to_source(): void
    {
        Event::fake([StockTransferCancelled::class]);

        $this->seedStock($this->productA, $this->warehouse, '20.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));

        $sourceAfterInit = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($sourceAfterInit);
        $this->assertEquals('15.0000', $sourceAfterInit->quantity);

        $cancelled = $this->service()->cancel($transfer->id, $this->user->id, 'shipment lost');

        $this->assertSame(TransferStatus::Cancelled, $cancelled->status);

        $sourceAfterCancel = StockLevel::query()
            ->where('product_id', $this->productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($sourceAfterCancel);
        $this->assertEquals('20.0000', $sourceAfterCancel->quantity);

        Event::assertDispatched(StockTransferCancelled::class);
    }

    public function test_cannot_cancel_completed_transfer(): void
    {
        $this->seedStock($this->productA, $this->warehouse, '20.0000');

        $transfer = $this->service()->initiate($this->initiateData(
            $this->warehouse->id,
            $this->shop->id,
            [new InitiateTransferLineData($this->productA->id, '5.0000')],
        ));
        $this->service()->complete($transfer->id, $this->user->id);

        $this->expectException(TransferStateException::class);
        $this->service()->cancel($transfer->id, $this->user->id, 'too late');
    }

    public function test_transfer_cost_allocation_conserves_total_across_uneven_lines(): void
    {
        // Three equal lines (qty 10, cost 5) sharing a transfer cost that does
        // NOT divide evenly: 100 / 3 = 33.333333... per line. Rounding each
        // share independently loses a millième; the residual must land on the
        // last cost-bearing line so the allocated shares sum EXACTLY to 100.
        $productC = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-C',
            'name' => 'Spark Plug',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->seedStock($this->productA, $this->warehouse, '50.0000');
        $this->seedStock($this->productB, $this->warehouse, '50.0000');
        $this->seedStock($productC, $this->warehouse, '50.0000');

        $transfer = $this->service()->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData($this->productA->id, '10.0000'),
                new InitiateTransferLineData($this->productB->id, '10.0000'),
                new InitiateTransferLineData($productC->id, '10.0000'),
            ],
            transferCost: '100.0000',
            transferCostDistribution: TransferCostDistribution::EqualPerLine,
        ));

        $completed = $this->service()->complete($transfer->id, $this->user->id);

        $sumAllocated = '0';
        foreach ($completed->lines as $line) {
            $sumAllocated = bcadd($sumAllocated, (string) $line->allocated_transfer_cost, 4);
        }

        // No millième lost or gained: Σ allocated == transfer cost, exactly.
        $this->assertSame(
            '100.0000',
            bcadd($sumAllocated, '0', 4),
            'Per-line transfer-cost allocations must sum to the total transfer cost.'
        );
    }

    public function test_transfer_cost_allocation_conserves_total_across_seven_equal_lines(): void
    {
        // Seven equal lines sharing transfer cost 10: 10 / 7 = 1.428571...
        // The weighted share rounds to 1.4285 at the PERSISTED 4-dp scale
        // (the 6th digit is dropped). Six lines × 1.4285 = 8.5710; reconciling
        // the residual at the 6-dp WORKING scale and then truncating it to 4 dp
        // on persist (the pre-fix behaviour) yields Σ = 9.9995 ≠ 10.0000 — a
        // millième LOST. The fix reconciles at the 4-dp persisted scale so the
        // last cost-bearing line absorbs the residual (10.0000 − 8.5710 = 1.4290)
        // and Σ allocated_transfer_cost == 10.0000 EXACTLY.
        //
        // (transferCost 100 / 7 does NOT expose this with EqualPerLine because
        // 100 × (1/7) rounds to 14.285700 — exact at 4 dp. The bug needs a value
        // whose per-line share has a non-zero 5th/6th decimal; 10 / 7 does.)
        $lines = [];
        for ($i = 0; $i < 7; $i++) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => 'PROD-EQ-'.$i,
                'name' => 'Equal Line Part '.$i,
                'type' => ProductType::Part,
                'is_active' => true,
                'cost_price' => '5.0000',
                'sale_price' => '10.0000',
            ]);
            $this->seedStock($product, $this->warehouse, '50.0000');
            $lines[] = new InitiateTransferLineData($product->id, '10.0000');
        }

        $transfer = $this->service()->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: $lines,
            transferCost: '10.0000',
            transferCostDistribution: TransferCostDistribution::EqualPerLine,
        ));

        $completed = $this->service()->complete($transfer->id, $this->user->id);

        $sumAllocated = '0';
        foreach ($completed->lines as $line) {
            $sumAllocated = bcadd($sumAllocated, (string) $line->allocated_transfer_cost, 4);
        }

        $this->assertSame(
            '10.0000',
            bcadd($sumAllocated, '0', 4),
            'Seven equal-line allocations must sum to the total transfer cost at the persisted 4-dp scale.'
        );
    }
}

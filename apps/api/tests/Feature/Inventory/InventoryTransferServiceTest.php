<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
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
}

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
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

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
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive']);

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

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    public function test_can_receive_stock(): void
    {
        $service = app(StockAdjustmentService::class);

        $result = $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $this->assertNotNull($result);

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($stockLevel);
        $this->assertEquals('10.00', $stockLevel->quantity);
    }

    public function test_can_issue_stock(): void
    {
        $service = app(StockAdjustmentService::class);

        // First receive stock
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Then issue stock
        $result = $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertEquals('15.00', $stockLevel->quantity);
    }

    public function test_cannot_issue_more_than_available(): void
    {
        $service = app(StockAdjustmentService::class);

        // Receive 10 units
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Try to issue 15 units
        $this->expectException(InsufficientStockException::class);

        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '15.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );
    }

    public function test_can_reserve_stock(): void
    {
        $service = app(StockAdjustmentService::class);

        // Receive stock
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Reserve some stock
        $service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'ORDER-001',
        );

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertEquals('20.00', $stockLevel->quantity);
        $this->assertEquals('5.00', $stockLevel->reserved);
        $this->assertEquals('15.0000', $stockLevel->getAvailableQuantity());
    }

    public function test_cannot_reserve_more_than_available(): void
    {
        $service = app(StockAdjustmentService::class);

        // Receive 10 units
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Try to reserve 15 units
        $this->expectException(InsufficientStockException::class);

        $service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '15.00',
            reference: 'ORDER-001',
        );
    }

    public function test_can_release_reservation(): void
    {
        $service = app(StockAdjustmentService::class);

        // Receive and reserve
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'ORDER-001',
        );

        // Release reservation
        $service->releaseReservation(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '3.00',
            reference: 'ORDER-001-CANCEL',
        );

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertEquals('2.00', $stockLevel->reserved);
        $this->assertEquals('18.0000', $stockLevel->getAvailableQuantity());
    }

    public function test_can_transfer_stock_between_locations(): void
    {
        $service = app(StockAdjustmentService::class);

        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-02',
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Receive stock at first warehouse
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Transfer to second warehouse
        $service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->warehouse->id,
            toLocationId: $secondWarehouse->id,
            quantity: '8.00',
            reference: 'TR-001',
            userId: $this->user->id,
        );

        $sourceStock = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $destStock = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $secondWarehouse->id)
            ->first();

        $this->assertEquals('12.00', $sourceStock->quantity);
        $this->assertEquals('8.00', $destStock->quantity);
    }

    public function test_stock_movement_is_recorded(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(MovementType::Receipt, $movement->movement_type);
        $this->assertEquals('10.00', $movement->quantity);
        $this->assertEquals('PO-001', $movement->reference);
    }

    public function test_can_adjust_stock(): void
    {
        $service = app(StockAdjustmentService::class);

        // Receive initial stock
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Adjust stock (e.g., inventory count reveals 12 units)
        $service->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '12.00',
            reason: 'Physical inventory count',
            userId: $this->user->id,
        );

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertEquals('12.00', $stockLevel->quantity);
    }

    public function test_location_api_returns_locations(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/locations');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_stock_level_api_returns_stock(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-levels');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }
}

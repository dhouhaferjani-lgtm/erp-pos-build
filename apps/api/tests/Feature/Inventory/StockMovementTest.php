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
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockMovementTest extends TestCase
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

    public function test_can_receive_stock_via_api(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/receive', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '25.00',
            'reference' => 'PO-2025-001',
            'notes' => 'Purchase order receipt',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'receipt');
        $response->assertJsonPath('data.quantity', '25.0000');

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '25.00',
        ]);
    }

    public function test_can_issue_stock_via_api(): void
    {
        // First receive stock
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '50.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/issue', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '15.00',
            'reference' => 'SO-2025-001',
            'notes' => 'Sales delivery',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'issue');
        $response->assertJsonPath('data.quantity', '15.0000');

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '35.00',
        ]);
    }

    public function test_issue_fails_with_insufficient_stock(): void
    {
        // Receive only 10 units
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/issue', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '20.00',
            'reference' => 'SO-001',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }

    public function test_can_transfer_stock_via_api(): void
    {
        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-02',
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Receive stock at first warehouse
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/transfer', [
            'product_id' => $this->product->id,
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $secondWarehouse->id,
            'quantity' => '30.00',
            'reference' => 'TR-2025-001',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Transfer completed successfully');

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '70.00',
        ]);

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $this->product->id,
            'location_id' => $secondWarehouse->id,
            'quantity' => '30.00',
        ]);
    }

    public function test_can_adjust_stock_via_api(): void
    {
        // Receive initial stock
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '50.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/adjust', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'new_quantity' => '45.00',
            'reason' => 'Physical inventory count - 5 units damaged',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'adjustment');

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '45.00',
        ]);
    }

    public function test_can_list_stock_movements(): void
    {
        $service = app(StockAdjustmentService::class);

        // Create multiple movements
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '25.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_filter_movements_by_product(): void
    {
        $product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-002',
            'name' => 'Second Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '50.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->receive(
            productId: $product2->id,
            locationId: $this->warehouse->id,
            quantity: '30.00',
            reference: 'PO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_filter_movements_by_type(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '15.00',
            reference: 'SO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?movement_type=issue');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_movements_are_ordered_by_date_descending(): void
    {
        $service = app(StockAdjustmentService::class);

        $receiptMovement = $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Wait 1 second to ensure different timestamps
        sleep(1);

        $issueMovement = $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        // Most recent (issue) should be first
        $response->assertJsonPath('data.0.movement_type', 'issue');
        $response->assertJsonPath('data.1.movement_type', 'receipt');
    }

    public function test_unauthorized_user_cannot_receive_stock(): void
    {
        $this->user->revokePermissionTo('inventory.receive');

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/receive', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.00',
            'reference' => 'PO-001',
        ]);

        $response->assertStatus(403);
    }
}

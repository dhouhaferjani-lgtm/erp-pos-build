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

/**
 * Regression coverage for the quantity-precision drift bug.
 *
 * Inventory quantities are produced at 4 decimal places (transfer lines,
 * document lines, counting items) but {@see StockAdjustmentService} and the
 * `stock_levels` / `stock_movements` storage used to operate at 2 decimals.
 * A quantity such as 7.1234 was silently truncated to 7.12 when it round-tripped
 * through a stock adjustment, leaving un-reconcilable ghost stock.
 *
 * These tests assert that a sub-centi-unit quantity survives a full round trip
 * through the service, the stock-level rows and the movement audit rows.
 */
class StockQuantityPrecisionTest extends TestCase
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

    public function test_quantity_precision_survives_round_trip_through_receive(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '7.1234',
            reference: 'PO-PRECISION',
            userId: $this->user->id,
        );

        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->where('movement_type', MovementType::Receipt)
            ->firstOrFail();

        $this->assertSame('7.1234', $stockLevel->quantity);
        $this->assertSame('7.1234', $movement->quantity);
        $this->assertSame('7.1234', $movement->quantity_after);
    }

    public function test_quantity_precision_survives_round_trip_through_stock_levels(): void
    {
        $service = app(StockAdjustmentService::class);

        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-02',
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Seed source with ample, full-precision stock.
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.0000',
            reference: 'PO-SEED',
            userId: $this->user->id,
        );

        // Transfer a sub-centi-unit quantity between locations.
        $service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->warehouse->id,
            toLocationId: $secondWarehouse->id,
            quantity: '7.1234',
            reference: 'TR-PRECISION',
            userId: $this->user->id,
        );

        $sourceStock = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();

        $destStock = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $secondWarehouse->id)
            ->firstOrFail();

        $sourceMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->where('movement_type', MovementType::TransferOut)
            ->firstOrFail();

        $destMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $secondWarehouse->id)
            ->where('movement_type', MovementType::TransferIn)
            ->firstOrFail();

        // Source: 100.0000 - 7.1234 = 92.8766 (no truncation on the subtraction).
        $this->assertSame('92.8766', $sourceStock->quantity);
        // Destination receives the exact transferred quantity.
        $this->assertSame('7.1234', $destStock->quantity);
        // Both audit rows carry the exact quantity.
        $this->assertSame('7.1234', $sourceMovement->quantity);
        $this->assertSame('7.1234', $destMovement->quantity);
    }
}

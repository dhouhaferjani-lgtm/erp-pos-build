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
use App\Modules\Inventory\Domain\StockLevel;
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
 * DPA V7 / T19 — the two lot fields must round-trip on BOTH stock-level
 * endpoints, because the frontend's lot rules are decided from them before any
 * request is sent (D1b part 2). Without them the operator could only discover
 * BATCH_REQUIRED_FOR_LINE by 422.
 */
final class StockLevelLotFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lot Fields Tenant',
            'slug' => 'lot-fields-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lot Fields Co',
            'legal_name' => 'Lot Fields Co LLC',
            'tax_id' => 'TAX-LF',
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
            'name' => 'Lot Fields User',
            'email' => 'lot-fields@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'LF-01',
            'name' => 'Lot Fields Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_both_lot_fields_round_trip_on_index_and_show(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LF-001',
            'name' => 'Lot Fields Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        // Door 1 — flag true, ZERO lots: the lot picker would be empty, so a
        // positive line must stay authorable without one.
        $index = $this->actingAs($this->user)->getJson('/api/v1/stock-levels');
        $index->assertOk();
        $index->assertJsonPath('data.0.requires_batch_tracking', true);
        $index->assertJsonPath('data.0.has_lots_at_location', false);

        $show = $this->actingAs($this->user)
            ->getJson("/api/v1/stock-levels/{$product->id}/{$this->warehouse->id}");
        $show->assertOk();
        $show->assertJsonPath('data.requires_batch_tracking', true);
        $show->assertJsonPath('data.has_lots_at_location', false);
        // D15b uses this endpoint for the fresh authoring anchor, so it must
        // carry the product unit's precision.
        $this->assertIsInt($show->json('data.quantity_decimals'));

        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => 'LOT-LF',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);
        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved_quantity' => '0.0000',
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/stock-levels')
            ->assertJsonPath('data.0.has_lots_at_location', true);
        $this->actingAs($this->user)
            ->getJson("/api/v1/stock-levels/{$product->id}/{$this->warehouse->id}")
            ->assertJsonPath('data.has_lots_at_location', true);
    }
}

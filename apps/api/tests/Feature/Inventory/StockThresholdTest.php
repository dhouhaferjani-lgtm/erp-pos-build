<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StockThresholdTest extends TestCase
{
    use RefreshDatabase;

    /** Threshold precision and uniqueness are contractually verified on PostgreSQL. */
    protected array $connectionsToTransact = ['pgsql'];

    protected function beforeRefreshingDatabase(): void
    {
        config(['database.default' => 'pgsql']);
    }

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Threshold Tenant', 'slug' => 'threshold-'.Str::lower(Str::random(8)), 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional]);
        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Threshold Company', 'legal_name' => 'Threshold Company LLC', 'tax_id' => 'THRESHOLD-TAX', 'country_code' => 'TN', 'currency' => 'TND', 'locale' => 'fr_TN', 'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Threshold User', 'email' => 'threshold-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password'), 'status' => UserStatus::Active]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin', 'status' => 'active']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->location = Location::create(['company_id' => $this->company->id, 'code' => 'THRESHOLD', 'name' => 'Threshold Location', 'type' => 'warehouse', 'is_active' => true, 'is_default' => true]);
        $this->product = Product::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Threshold Product', 'sku' => 'THRESHOLD', 'type' => ProductType::Part, 'is_active' => true, 'cost_price' => '1.0000', 'sale_price' => '2.0000']);
    }

    public function test_sets_thresholds_on_existing_stock_row(): void
    {
        $this->stock('7', '2');
        $response = $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1.2', '9.8765'));
        $response->assertOk()->assertJsonPath('data.min_quantity', '1.2000')->assertJsonPath('data.max_quantity', '9.8765');
        $this->assertDatabaseHas('stock_levels', ['product_id' => $this->product->id, 'location_id' => $this->location->id, 'min_quantity' => '1.2000', 'max_quantity' => '9.8765']);
    }

    public function test_creates_zero_stock_row_and_clears_thresholds_with_null(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('2', '5'))->assertOk();
        $this->assertDatabaseHas('stock_levels', ['product_id' => $this->product->id, 'quantity' => '0.0000', 'reserved' => '0.0000']);
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload(null, null))->assertOk();
        $this->assertDatabaseHas('stock_levels', ['product_id' => $this->product->id, 'location_id' => $this->location->id, 'min_quantity' => null, 'max_quantity' => null]);
    }

    public function test_rejects_invalid_order_and_decimal_ceiling(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('5', '2'))->assertUnprocessable()->assertJsonPath('error.errors.max_quantity.0', 'max_quantity must be ≥ min_quantity.');
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1', '1.23456'))->assertUnprocessable()->assertJsonPath('error.errors.max_quantity.0', 'must have at most 4 decimal places.');
    }

    public function test_restricted_location_is_rejected_by_shared_access_rule(): void
    {
        $other = Location::create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name' => 'Other Location', 'type' => 'warehouse', 'is_active' => true]);
        UserCompanyMembership::where('user_id', $this->user->id)->update(['allowed_location_ids' => [$this->location->id]]);
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1', '2', $other->id))->assertUnprocessable()->assertJsonPath('error.errors.location_id.0', 'You do not have permission to access this location.');
        $this->assertDatabaseCount('stock_levels', 0);
    }

    public function test_null_membership_is_all_access(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1', '2'))->assertOk();
    }

    public function test_variant_threshold_does_not_touch_null_variant_grain(): void
    {
        $variant = ProductVariant::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'variant_code' => 'RED', 'sku' => 'THRESHOLD-RED', 'name_suffix' => 'Red', 'is_active' => true]);
        $this->stock('4', '0');
        StockLevel::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'variant_id' => $variant->id, 'location_id' => $this->location->id, 'quantity' => '3', 'reserved' => '0', 'min_quantity' => '1', 'max_quantity' => '2']);
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', array_replace($this->payload('5', '6', $this->location->id), ['variant_id' => $variant->id]))->assertOk();
        $this->assertDatabaseHas('stock_levels', ['product_id' => $this->product->id, 'variant_id' => null, 'min_quantity' => null, 'max_quantity' => null]);
        $this->assertDatabaseHas('stock_levels', ['product_id' => $this->product->id, 'variant_id' => $variant->id, 'min_quantity' => '5.0000', 'max_quantity' => '6.0000']);
    }

    public function test_absent_company_membership_is_rejected(): void
    {
        UserCompanyMembership::where('user_id', $this->user->id)->delete();
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1', '2'))->assertForbidden();
        $this->assertDatabaseCount('stock_levels', 0);
    }

    public function test_foreign_company_location_is_rejected(): void
    {
        $otherCompany = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Other Company', 'legal_name' => 'Other Company LLC', 'tax_id' => 'OTHER-TAX', 'country_code' => 'TN', 'currency' => 'TND', 'locale' => 'fr_TN', 'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active]);
        $foreign = Location::create(['company_id' => $otherCompany->id, 'code' => 'FOREIGN', 'name' => 'Foreign Location', 'type' => 'warehouse', 'is_active' => true]);
        $this->actingAs($this->user)->putJson('/api/v1/inventory/stock-levels/thresholds', $this->payload('1', '2', $foreign->id))->assertUnprocessable()->assertJsonPath('error.errors.location_id.0', 'The selected location id is invalid.');
        $this->assertDatabaseCount('stock_levels', 0);
    }

    /** @return array<string, string|null> */
    private function payload(?string $min, ?string $max, ?string $locationId = null): array
    {
        return ['product_id' => $this->product->id, 'variant_id' => null, 'location_id' => $locationId ?? $this->location->id, 'min_quantity' => $min, 'max_quantity' => $max];
    }

    private function stock(string $quantity, string $reserved): void
    {
        StockLevel::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'variant_id' => null, 'location_id' => $this->location->id, 'quantity' => $quantity, 'reserved' => $reserved]);
    }
}

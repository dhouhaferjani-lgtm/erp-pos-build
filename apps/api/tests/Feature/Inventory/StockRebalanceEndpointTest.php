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

final class StockRebalanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** These aggregate queries are contractually verified on PostgreSQL. */
    protected array $connectionsToTransact = ['pgsql'];

    protected function beforeRefreshingDatabase(): void
    {
        config(['database.default' => 'pgsql']);
    }

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Rebalance Tenant', 'slug' => 'rebalance-'.Str::lower(Str::random(8)), 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional]);
        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Rebalance Company', 'legal_name' => 'Rebalance Company', 'tax_id' => 'REB-1', 'country_code' => 'TN', 'currency' => 'TND', 'locale' => 'fr_TN', 'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Rebalance User', 'email' => 'rebalance-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password'), 'status' => UserStatus::Active]);
        $this->user->givePermissionTo('inventory.view');
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin', 'status' => 'active']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->a = $this->location('A');
        $this->b = $this->location('B');
    }

    public function test_threshold_classification_emits_deficit_and_surplus(): void
    {
        $product = $this->product('Thresholded');
        $this->stock($product, $this->a, '1', '0', '4', '8');
        $this->stock($product, $this->b, '12', '0', '2', '5');

        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix/rebalance?location_ids[]='.$this->a->id.'&location_ids[]='.$this->b->id);

        $response->assertOk()->assertJsonCount(1, 'data');
        $row = $response->json('data.0');
        self::assertSame($product->id, $row['product_id']);
        self::assertSame($this->a->id, $row['deficits'][0]['location_id']);
        self::assertSame($this->b->id, $row['surpluses'][0]['location_id']);
        self::assertSame('7.0000', $row['surpluses'][0]['excess']);
    }

    public function test_null_thresholds_use_positive_donor_and_non_positive_receiver(): void
    {
        $product = $this->product('Fallback');
        $this->stock($product, $this->a, '0', '0');
        $this->stock($product, $this->b, '6', '0');

        $row = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix/rebalance')->assertOk()->json('data.0');
        self::assertSame($this->a->id, $row['deficits'][0]['location_id']);
        self::assertSame($this->b->id, $row['surpluses'][0]['location_id']);
        self::assertSame('6.0000', $row['surpluses'][0]['excess']);
    }

    private function location(string $suffix): Location
    {
        return Location::create(['company_id' => $this->company->id, 'code' => 'REB-'.$suffix, 'name' => 'Rebalance '.$suffix, 'type' => 'warehouse', 'is_active' => true]);
    }

    private function product(string $name): Product
    {
        return Product::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => $name, 'sku' => 'REB-'.Str::upper(Str::random(6)), 'type' => ProductType::Part, 'is_active' => true]);
    }

    private function stock(Product $product, Location $location, string $quantity, string $reserved, ?string $min = null, ?string $max = null): void
    {
        StockLevel::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved' => $reserved, 'min_quantity' => $min, 'max_quantity' => $max]);
    }
}

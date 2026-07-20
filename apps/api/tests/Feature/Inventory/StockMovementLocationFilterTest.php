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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StockMovementLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    /** These location filters are contractually verified on PostgreSQL. */
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

    private Location $c;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Movement Tenant', 'slug' => 'movement-'.Str::lower(Str::random(8)), 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional]);
        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Movement Company', 'legal_name' => 'Movement Company', 'tax_id' => 'MOV-1', 'country_code' => 'TN', 'currency' => 'TND', 'locale' => 'fr_TN', 'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Movement User', 'email' => 'movement-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password'), 'status' => UserStatus::Active]);
        $this->user->givePermissionTo('inventory.view');
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin', 'status' => 'active']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->a = $this->location('A');
        $this->b = $this->location('B');
        $this->c = $this->location('C');
        $this->product = Product::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'Movement Product', 'sku' => 'MOV-1', 'type' => ProductType::Part, 'is_active' => true]);
        $service = app(StockAdjustmentService::class);
        foreach ([$this->a, $this->b, $this->c] as $location) {
            $service->receive(productId: $this->product->id, locationId: $location->id, quantity: '1', reference: 'movement-test', userId: $this->user->id);
        }
    }

    public function test_multiple_location_ids_are_applied_server_side(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id.'&location_ids[]='.$this->a->id.'&location_ids[]='.$this->b->id);
        $response->assertOk();
        /** @var list<array{location_id: string}> $rows */
        $rows = $response->json('data');
        $locationIds = array_map(static fn (array $row): string => $row['location_id'], $rows);
        sort($locationIds);
        $expected = [$this->a->id, $this->b->id];
        sort($expected);
        self::assertSame($expected, $locationIds);
    }

    public function test_restricted_membership_no_param_is_fail_closed(): void
    {
        UserCompanyMembership::where('user_id', $this->user->id)->update(['allowed_location_ids' => [$this->a->id]]);
        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);
        $response->assertOk();
        /** @var list<array{location_id: string}> $rows */
        $rows = $response->json('data');
        $locationIds = array_values(array_unique(array_map(static fn (array $row): string => $row['location_id'], $rows)));
        self::assertSame([$this->a->id], $locationIds);
    }

    private function location(string $suffix): Location
    {
        return Location::create(['company_id' => $this->company->id, 'code' => 'MOV-'.$suffix, 'name' => 'Movement '.$suffix, 'type' => 'warehouse', 'is_active' => true]);
    }
}

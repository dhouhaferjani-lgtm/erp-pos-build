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

final class StockMatrixEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Matrix Tenant', 'slug' => 'matrix-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Matrix Company',
            'legal_name' => 'Matrix Company LLC', 'tax_id' => 'MATRIX-TAX',
            'country_code' => 'TN', 'currency' => 'TND', 'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis', 'status' => CompanyStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Matrix User',
            'email' => 'matrix-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'), 'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('inventory.view');
        UserCompanyMembership::create([
            'user_id' => $this->user->id, 'company_id' => $this->company->id,
            'role' => 'admin', 'status' => 'active',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->locationA = $this->makeLocation('A');
        $this->locationB = $this->makeLocation('B');
    }

    public function test_pivots_stock_by_location_and_zero_fills_cells(): void
    {
        $first = $this->makeProduct('Alpha', 'SKU-A', 'BAR-A');
        $second = $this->makeProduct('Beta', 'SKU-B', 'BAR-B');
        $this->makeStock($first, $this->locationA, '5', '1', '2', '10');
        $this->makeStock($first, $this->locationB, '3.5', '0.5', '1', '8');
        $this->makeStock($second, $this->locationA, '2', '0', null, null);

        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix?location_ids[]='.$this->locationA->id.'&location_ids[]='.$this->locationB->id);

        $response->assertOk();
        $rows = $response->json('data');
        self::assertCount(2, $rows);
        self::assertSame('4.0000', $rows[0]['cells'][$this->locationA->id]['available']);
        self::assertSame('3.0000', $rows[0]['cells'][$this->locationB->id]['available']);
        self::assertSame('0.0000', $rows[1]['cells'][$this->locationB->id]['on_hand']);
    }

    public function test_variant_rollup_counts_every_grain_once_and_emits_base_leaf(): void
    {
        $product = $this->makeProduct('Variants', 'SKU-V', 'BAR-V');
        $variantA = $this->makeVariant($product, 'Red');
        $variantB = $this->makeVariant($product, 'Blue');
        $this->makeStock($product, $this->locationA, '2', '0', '1', '9', null);
        $this->makeStock($product, $this->locationA, '3', '1', '2', '8', $variantA->id);
        $this->makeStock($product, $this->locationA, '4', '0', '1', '7', $variantB->id);

        $rows = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix?location_ids[]='.$this->locationA->id)->assertOk()->json('data');
        self::assertCount(4, $rows);
        self::assertSame('9.0000', $rows[0]['cells'][$this->locationA->id]['on_hand']);
        self::assertTrue($rows[0]['is_variant_parent']);
        self::assertNull($rows[0]['cells'][$this->locationA->id]['min_quantity']);
        self::assertSame('Variants Red', $rows[1]['name']);
        self::assertSame('Variants Blue', $rows[2]['name']);
        self::assertStringEndsWith(' (base)', $rows[3]['name']);
        self::assertSame('1.0000', $rows[3]['cells'][$this->locationA->id]['min_quantity']);
        self::assertSame('9.0000', bcadd(bcadd($rows[1]['cells'][$this->locationA->id]['on_hand'], $rows[2]['cells'][$this->locationA->id]['on_hand'], 4), $rows[3]['cells'][$this->locationA->id]['on_hand'], 4));
    }

    public function test_search_and_pagination_are_stable(): void
    {
        foreach (range(1, 30) as $number) {
            $this->makeProduct('Product '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'SEARCH-'.$number, 'CODE-'.$number);
        }
        $pageOne = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix?search=SEARCH-&per_page=10&page=1')->assertOk();
        $pageTwo = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix?search=SEARCH-&per_page=10&page=2')->assertOk();
        self::assertSame(30, $pageOne->json('meta.total'));
        self::assertSame(3, $pageOne->json('meta.last_page'));
        self::assertEmpty(array_intersect(array_column($pageOne->json('data'), 'product_id'), array_column($pageTwo->json('data'), 'product_id')));
    }

    public function test_restricted_membership_is_resolved_and_out_of_scope_request_is_forbidden(): void
    {
        UserCompanyMembership::where('user_id', $this->user->id)->update(['allowed_location_ids' => [$this->locationA->id]]);
        $product = $this->makeProduct('Scoped', 'SCOPED', 'SCOPED-BAR');
        $this->makeStock($product, $this->locationA, '1', '0');
        $this->makeStock($product, $this->locationB, '2', '0');

        $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix?location_ids[]='.$this->locationB->id)->assertForbidden();
        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/stock-matrix')->assertOk();
        self::assertSame([$this->locationA->id], array_keys($response->json('data.0.cells')));
    }

    private function makeLocation(string $suffix): Location
    {
        return Location::create(['company_id' => $this->company->id, 'code' => 'LOC-'.$suffix, 'name' => 'Location '.$suffix, 'type' => 'warehouse', 'is_active' => true, 'is_default' => $suffix === 'A']);
    }

    private function makeProduct(string $name, string $sku, string $barcode): Product
    {
        return Product::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => $name, 'sku' => $sku, 'barcode' => $barcode, 'type' => ProductType::Part, 'is_active' => true, 'cost_price' => '1.0000', 'sale_price' => '2.0000']);
    }

    private function makeVariant(Product $product, string $suffix): ProductVariant
    {
        return ProductVariant::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $product->id, 'variant_code' => Str::upper($suffix), 'sku' => $product->sku.'-'.$suffix, 'name_suffix' => $suffix, 'is_active' => true]);
    }

    private function makeStock(Product $product, Location $location, string $quantity, string $reserved, ?string $min = null, ?string $max = null, ?string $variantId = null): void
    {
        StockLevel::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $product->id, 'variant_id' => $variantId, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved' => $reserved, 'min_quantity' => $min, 'max_quantity' => $max]);
    }
}

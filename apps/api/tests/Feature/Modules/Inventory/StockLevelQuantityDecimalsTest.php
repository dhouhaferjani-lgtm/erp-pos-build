<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The /stock-levels index must expose quantity_decimals per row (from the
 * product's unit) so the stock-adjustment modal steps quantity by the unit.
 */
final class StockLevelQuantityDecimalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Stock Qty Tenant',
            'slug' => 'stock-qty-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stock Qty Company',
            'legal_name' => 'Stock Qty Company LLC',
            'tax_id' => 'STOCKQTY123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stock Qty User',
            'email' => 'stock-qty@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
    }

    private function makePiecesProduct(): Product
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'cat-pc',
            'name' => 'Count',
            'is_system' => true,
            'is_active' => true,
        ]);

        $unit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'pc',
            'name' => 'Piece',
            'symbol' => 'pc',
            'decimal_places' => 0,
            'is_system' => true,
            'is_active' => true,
        ]);

        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
        ]);
    }

    #[Test]
    public function stock_levels_index_exposes_zero_quantity_decimals_for_pieces(): void
    {
        $product = $this->makePiecesProduct();

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '100.00',
            'reserved' => '0.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/stock-levels')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);

        $this->assertNotNull($row, 'stock level row for the product must be present');
        $this->assertSame(0, $row['quantity_decimals']);
    }

    #[Test]
    public function stock_levels_index_does_not_500_when_product_is_soft_deleted(): void
    {
        $product = $this->makePiecesProduct();

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '5.00',
            'reserved' => '0.00',
        ]);

        // Archiving a product soft-deletes it; the eager-loaded `product` relation
        // then resolves to null. The endpoint must degrade to fallback 4, not 500.
        $product->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/stock-levels')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);

        $this->assertNotNull($row, 'stock level row must still serialize for an archived product');
        $this->assertSame(4, $row['quantity_decimals']);
    }
}

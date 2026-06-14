<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/products/{product}/stock-distribution
 *
 * Cross-location stock distribution endpoint (Task B5) — gated on the
 * `pos.view_cross_location_stock` permission AND the company master switch
 * `companies.allow_cross_location_stock_view`, and variant-aware.
 */
final class StockDistributionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'allow_cross_location_stock_view' => true,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->locationA = Location::factory()->create(['company_id' => $this->company->id]);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function grantViewPermission(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_cross_location_stock', 'sanctum');
        $user->givePermissionTo('pos.view_cross_location_stock');
    }

    private function stockAt(Location $location, Product $product, string $qty, string $reserved = '0.0000', ?string $variantId = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'location_id' => $location->id,
            'quantity' => $qty,
            'reserved' => $reserved,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_distribution_when_permitted_and_flag_on(): void
    {
        $this->grantViewPermission($this->user);
        Sanctum::actingAs($this->user);

        $this->stockAt($this->locationA, $this->product, '5.0000', '1.0000');
        $this->stockAt($this->locationB, $this->product, '8.0000', '0.0000');

        $response = $this->getJson(
            "/api/v1/pos/products/{$this->product->id}/stock-distribution?current_location_id={$this->locationA->id}"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.product_id', $this->product->id);
        $response->assertJsonPath('data.variant_id', null);

        // Locate the row for location A and assert its on-hand / incoming.
        $locations = $response->json('data.locations');
        $this->assertIsArray($locations);
        $rowA = collect($locations)->firstWhere('location_id', $this->locationA->id);
        $this->assertNotNull($rowA);
        $this->assertSame('4.0000', $rowA['on_hand']);
        $this->assertIsString($rowA['on_hand']);
        $this->assertIsString($rowA['incoming_transfer']);
        $this->assertTrue($rowA['is_current']);

        // Location B is also represented in the cross-location distribution.
        $rowB = collect($locations)->firstWhere('location_id', $this->locationB->id);
        $this->assertNotNull($rowB);
        $this->assertSame('8.0000', $rowB['on_hand']);
        $this->assertFalse($rowB['is_current']);

        $this->assertArrayHasKey('totals', $response->json('data'));
        $this->assertNotNull($response->json('data.as_of'));
    }

    public function test_403_without_permission(): void
    {
        // Flag is on (setUp), but the user is NOT granted the permission.
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/pos/products/{$this->product->id}/stock-distribution");

        $response->assertStatus(403);
    }

    public function test_403_when_flag_off(): void
    {
        $this->company->update(['allow_cross_location_stock_view' => false]);

        $this->grantViewPermission($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/pos/products/{$this->product->id}/stock-distribution");

        $response->assertStatus(403);
    }

    public function test_422_when_variant_product_missing_variant_id(): void
    {
        $this->grantViewPermission($this->user);
        Sanctum::actingAs($this->user);

        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/pos/products/{$this->product->id}/stock-distribution");

        $response->assertStatus(422);
    }

    public function test_404_on_non_uuid(): void
    {
        $this->grantViewPermission($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/pos/products/not-a-uuid/stock-distribution');

        $response->assertStatus(404);
    }
}

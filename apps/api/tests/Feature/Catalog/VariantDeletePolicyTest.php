<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Variant delete guard (D2): a variant with on-hand stock cannot be deleted —
 * the caller is steered to deactivate instead. Catalog reads on-hand stock only
 * via the Shared VariantStockReader contract (never Inventory tables directly).
 *
 * Real DB (RefreshDatabase) + seeded permissions, no mocked HTTP.
 */
class VariantDeletePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'catalog.variants.delete',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);

        $this->product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function makeVariant(): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'V-DEL',
            'sku' => 'V-DEL',
            'name_suffix' => 'Delete',
        ]);
    }

    public function test_delete_blocked_when_variant_has_on_hand_stock(): void
    {
        $variant = $this->makeVariant();
        $location = Location::factory()->create(['company_id' => $this->company->id]);

        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'location_id' => $location->id,
            'quantity' => '4.0000',
            'reserved' => '0.0000',
        ]);

        $resp = $this->deleteJson("/api/v1/product-variants/{$variant->id}");

        $resp->assertStatus(422);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'deleted_at' => null,
        ]);
    }

    public function test_delete_allowed_when_zero_stock(): void
    {
        $variant = $this->makeVariant();

        $resp = $this->deleteJson("/api/v1/product-variants/{$variant->id}");

        $resp->assertNoContent();
        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }
}

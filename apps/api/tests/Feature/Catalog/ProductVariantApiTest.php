<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature coverage for Task 28 — REST endpoints for product attributes + variants.
 *
 * Real DB (RefreshDatabase) + seeded permissions (RolesAndPermissionsSeeder).
 * No mocked HTTP — the full middleware/auth/permission stack runs.
 */
class ProductVariantApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'catalog.attributes.view',
            'catalog.attributes.create',
            'catalog.attributes.update',
            'catalog.attributes.delete',
            'catalog.variants.view',
            'catalog.variants.create',
            'catalog.variants.update',
            'catalog.variants.delete',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    public function test_create_attribute_endpoint(): void
    {
        $resp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'taille',
            'name' => 'Taille',
            'data_type' => 'selection',
            'is_variant_axis' => true,
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('data.code', 'taille');
        $resp->assertJsonPath('data.is_variant_axis', true);
        $this->assertDatabaseHas('product_attributes', [
            'code' => 'taille',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_list_attributes_endpoint(): void
    {
        ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'couleur',
        ]);

        $resp = $this->getJson('/api/v1/product-attributes');

        $resp->assertOk();
        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonPath('data.0.code', 'couleur');
    }

    public function test_add_attribute_value_endpoint(): void
    {
        $attribute = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'couleur',
        ]);

        $resp = $this->postJson("/api/v1/product-attributes/{$attribute->id}/values", [
            'code' => 'noir',
            'label' => 'Noir',
            'hex_color' => '#000000',
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('data.code', 'noir');
        $this->assertDatabaseHas('product_attribute_values', [
            'attribute_id' => $attribute->id,
            'code' => 'noir',
        ]);
    }

    public function test_generate_matrix_endpoint_returns_18_variants(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SHOE',
        ]);

        $sizeAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'size',
        ]);
        foreach (['36', '37', '38', '39', '40', '41'] as $code) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $sizeAttr->id,
                'code' => $code,
                'label' => $code,
            ]);
        }

        $colorAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'color',
        ]);
        foreach (['black', 'white', 'red'] as $code) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $colorAttr->id,
                'code' => $code,
                'label' => ucfirst($code),
            ]);
        }

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'attribute_ids' => [$sizeAttr->id, $colorAttr->id],
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonCount(18, 'data');
        $this->assertDatabaseCount('product_variants', 18);
    }

    public function test_list_variants_for_product_endpoint(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-1',
            'sku' => 'V-1',
            'name_suffix' => 'One',
            'is_default' => true,
        ]);
        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-2',
            'sku' => 'V-2',
            'name_suffix' => 'Two',
        ]);

        $resp = $this->getJson("/api/v1/products/{$product->id}/variants");

        $resp->assertOk();
        $resp->assertJsonCount(2, 'data');
    }

    public function test_create_variant_endpoint(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $sizeAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'size',
        ]);
        $sizeXl = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $sizeAttr->id,
            'code' => 'xl',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_code' => 'PROD-XL',
            'sku' => 'PROD-XL',
            'name_suffix' => 'XL',
            'is_default' => true,
            'price_override' => '12.500',
            'attribute_values' => [
                ['attribute_id' => $sizeAttr->id, 'attribute_value_id' => $sizeXl->id],
            ],
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('data.sku', 'PROD-XL');
        $resp->assertJsonPath('data.price_override', '12.500');
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'PROD-XL',
        ]);
        $this->assertDatabaseHas('product_variant_attribute_values', [
            'attribute_id' => $sizeAttr->id,
            'attribute_value_id' => $sizeXl->id,
        ]);
    }

    public function test_update_variant_endpoint(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-UP',
            'sku' => 'V-UP',
            'name_suffix' => 'Original',
        ]);

        $resp = $this->patchJson("/api/v1/product-variants/{$variant->id}", [
            'name_suffix' => 'Updated',
            'price_override' => '9.990',
            'is_active' => false,
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('data.name_suffix', 'Updated');
        $resp->assertJsonPath('data.price_override', '9.990');
        $resp->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'name_suffix' => 'Updated',
            'is_active' => false,
        ]);
    }

    public function test_delete_variant_endpoint(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-DEL',
            'sku' => 'V-DEL',
            'name_suffix' => 'Delete',
        ]);

        $resp = $this->deleteJson("/api/v1/product-variants/{$variant->id}");

        $resp->assertNoContent();
        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // Fresh guard with no acting user.
        $this->app['auth']->forgetGuards();

        $resp = $this->getJson('/api/v1/product-attributes', [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $resp->assertStatus(401);
    }

    public function test_missing_permission_returns_403(): void
    {
        $noPermUser = User::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        $this->actingAs($noPermUser);

        $resp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'forbidden',
            'name' => 'Forbidden',
            'data_type' => 'selection',
        ]);

        $resp->assertStatus(403);
    }

    public function test_invalid_attribute_id_returns_400(): void
    {
        $resp = $this->postJson('/api/v1/product-attributes/not-a-uuid/values', [
            'code' => 'x',
            'label' => 'X',
        ]);

        $resp->assertStatus(400);
    }
}

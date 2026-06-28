<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class ProductBrandTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Brand $brand;

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
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
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

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'La Roche-Posay',
            'slug' => Brand::slugFor('La Roche-Posay'),
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // (a) GET tests — show returns brand, null, nullOnDelete
    // ------------------------------------------------------------------

    public function test_show_product_with_brand_returns_brand_name(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Moisturiser',
            'sku' => 'SKU-001',
            'brand_id' => $this->brand->id,
            'brand_source' => BrandSource::User->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.brand.name', 'La Roche-Posay')
            ->assertJsonPath('data.brand.id', $this->brand->id);
    }

    public function test_show_product_without_brand_returns_null_brand(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Unbranded Product',
            'sku' => 'SKU-002',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.brand', null);
    }

    public function test_deleting_brand_nullifies_product_brand_id(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Orphan Product',
            'sku' => 'SKU-003',
            'brand_id' => $this->brand->id,
            'brand_source' => BrandSource::User->value,
        ]);

        // Delete the brand — FK nullOnDelete should null brand_id on the DB row.
        // brand_source is NOT part of the FK cascade; it records the provenance
        // of how the brand was originally assigned (user vs enriched). Clearing
        // brand_source when a brand is hard-deleted requires an observer/trigger,
        // which is out of scope for Task 4 (C-2 only covers the API write path).
        $this->brand->delete();

        $product->refresh();
        $this->assertNull($product->brand_id);
    }

    // ------------------------------------------------------------------
    // (b) POST / PATCH — request path sets brand_source='user' (C-2)
    // ------------------------------------------------------------------

    public function test_create_product_with_brand_id_sets_brand_source_user(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Serum A',
                'sku' => 'SKU-004',
                'brand_id' => $this->brand->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.brand.name', 'La Roche-Posay');

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-004',
            'brand_id' => $this->brand->id,
            'brand_source' => BrandSource::User->value,
        ]);
    }

    public function test_create_product_without_brand_id_leaves_brand_null(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Plain Product',
                'sku' => 'SKU-005',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.brand', null);

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-005',
            'brand_id' => null,
            'brand_source' => null,
        ]);
    }

    public function test_patch_product_with_brand_id_sets_brand_source_user(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Existing Product',
            'sku' => 'SKU-006',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'brand_id' => $this->brand->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.brand.name', 'La Roche-Posay');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'brand_id' => $this->brand->id,
            'brand_source' => BrandSource::User->value,
        ]);
    }

    public function test_patch_clearing_brand_id_to_null_clears_brand_source(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Branded Product',
            'sku' => 'SKU-007',
            'brand_id' => $this->brand->id,
            'brand_source' => BrandSource::User->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'brand_id' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.brand', null);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'brand_id' => null,
            'brand_source' => null,
        ]);
    }

    public function test_create_product_with_brand_from_another_tenant_is_rejected(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $foreignBrand = Brand::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Foreign Brand',
            'slug' => Brand::slugFor('Foreign Brand'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Product X',
                'sku' => 'SKU-008',
                'brand_id' => $foreignBrand->id,
            ]);

        $this->assertJsonValidationErrors($response, ['brand_id']);
    }

    public function test_client_cannot_supply_brand_source_directly(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Hijack Attempt',
                'sku' => 'SKU-009',
                'brand_id' => $this->brand->id,
                'brand_source' => 'enriched',
            ]);

        $response->assertCreated();

        // brand_source must be 'user', not the client-supplied 'enriched'
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-009',
            'brand_source' => BrandSource::User->value,
        ]);
    }
}

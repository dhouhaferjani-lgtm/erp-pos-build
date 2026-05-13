<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant-isolation regression coverage for ProductImageController.
 *
 * Master plan §M2.1 — six CrossTenantRoute annotations on
 * ProductImageController (index, store, update, destroy, download,
 * reorder). All six were KNOWN gaps caused by Route Model Binding
 * resolving Product/ProductImage globally without tenant or company
 * predicates.
 *
 * These tests enumerate the failure modes the audit called out plus the
 * extras the 2026-05-13 Opus adversarial review asked for: mixed IDs,
 * list endpoint scoping, mass-assignment, 404 consistency, and the
 * happy path.
 *
 * After the controller fix (dev-remediation/B.M2.1), every operation
 * resolves the product (and image, when present) through the caller's
 * tenant + company scope and returns 404 on any cross-tenant or
 * cross-company id, matching the same not-found shape used for missing
 * ids — so id enumeration cannot distinguish "exists in another tenant"
 * from "does not exist."
 */
final class ProductImageTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyA2;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Product $productA;

    private Product $productA2;

    private Product $productB;

    private ProductImage $imageA;

    private ProductImage $imageA2;

    private ProductImage $imageB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-img-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-img-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A1', 'TAX-A1');
        $this->companyA2 = $this->makeCompany($this->tenantA->id, 'Company A2', 'TAX-A2');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-B');

        // Spatie permissions are stored team-scoped; seed once per tenant
        // and then re-seed roles so the named role rows + permission
        // pivots exist for each.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = $this->makeAdmin($this->tenantA->id, 'alice-img@example.com');
        $this->userB = $this->makeAdmin($this->tenantB->id, 'bob-img@example.com');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        // Two products in Tenant A: one in company A1 (userA's home company),
        // one in company A2 (different company same tenant). userA must NOT
        // be able to reach the company-A2 product.
        $this->productA = $this->makeProduct($this->tenantA->id, $this->companyA->id, 'SKU-A1');
        $this->productA2 = $this->makeProduct($this->tenantA->id, $this->companyA2->id, 'SKU-A2');
        $this->productB = $this->makeProduct($this->tenantB->id, $this->companyB->id, 'SKU-B');

        $this->imageA = $this->makeImage($this->productA);
        $this->imageA2 = $this->makeImage($this->productA2);
        $this->imageB = $this->makeImage($this->productB);
    }

    // ----------------------------------------------------------------
    // Cross-tenant gap closures
    // ----------------------------------------------------------------

    public function test_index_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productB->id.'/images')
            ->assertStatus(404);
    }

    public function test_store_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/products/'.$this->productB->id.'/images', [
                'image' => '@noop',
            ])
            ->assertStatus(404);
    }

    public function test_update_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->imageB->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->imageB->id)
            ->assertStatus(404);
    }

    public function test_download_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->imageB->id.'/download')
            ->assertStatus(404);
    }

    public function test_reorder_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/products/'.$this->productB->id.'/images/reorder', [
                'image_ids' => [$this->imageB->id],
            ])
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Cross-company within same tenant
    // ----------------------------------------------------------------

    public function test_index_rejects_cross_company_same_tenant_product(): void
    {
        // productA2 belongs to tenant A but to company A2; userA is in
        // company A1 only. The route must NOT leak company-A2 images.
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA2->id.'/images')
            ->assertStatus(404);
    }

    public function test_update_rejects_cross_company_same_tenant_image(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA2->id.'/images/'.$this->imageA2->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Mixed-ID requests (product belongs to scope but image does not)
    // ----------------------------------------------------------------

    public function test_update_rejects_mixed_ids_product_a_image_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->imageB->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_mixed_ids_product_a_image_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->imageB->id)
            ->assertStatus(404);
    }

    public function test_download_rejects_mixed_ids_product_a_image_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->imageB->id.'/download')
            ->assertStatus(404);
    }

    public function test_reorder_rejects_image_ids_from_other_product(): void
    {
        // image_ids must belong to the bound product, not just exist somewhere.
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/products/'.$this->productA->id.'/images/reorder', [
                'image_ids' => [$this->imageB->id],
            ])
            ->assertStatus(422);
    }

    // ----------------------------------------------------------------
    // List endpoint scoping
    // ----------------------------------------------------------------

    public function test_index_lists_only_images_belonging_to_the_scoped_product(): void
    {
        // Add a second image to productA so the response has > 1 row.
        $extra = $this->makeImage($this->productA);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA->id.'/images');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->imageA->id, $extra->id],
            $ids,
            'index endpoint must only return images belonging to the scoped product (no cross-tenant or cross-product leakage).',
        );
    }

    // ----------------------------------------------------------------
    // Foreign / nonexistent ids share the not-found shape
    // ----------------------------------------------------------------

    public function test_nonexistent_product_returns_404(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/00000000-0000-0000-0000-000000000000/images')
            ->assertStatus(404);
    }

    public function test_nonexistent_image_returns_404(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/00000000-0000-0000-0000-000000000000', [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Happy path — confirm the same-tenant flow still works
    // ----------------------------------------------------------------

    public function test_same_tenant_update_sets_primary_flag(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->imageA->id, [
                'is_primary' => true,
            ]);

        $response->assertStatus(200);
        $this->assertTrue(ProductImage::find($this->imageA->id)->is_primary);
    }

    public function test_same_tenant_destroy_soft_deletes_image(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->imageA->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('product_images', ['id' => $this->imageA->id]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function makeCompany(string $tenantId, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeAdmin(string $tenantId, string $email): User
    {
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => 'Admin '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        $user->assignRole('admin');

        return $user;
    }

    private function makeProduct(string $tenantId, string $companyId, string $sku): Product
    {
        return Product::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'unit_price' => 10.00,
            'vat_rate' => 20.00,
            'cost_price' => 5.00,
            'is_active' => true,
        ]);
    }

    private function makeImage(Product $product): ProductImage
    {
        return ProductImage::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'filename' => 'img-'.$product->id.'-'.uniqid().'.jpg',
            'original_filename' => 'original.jpg',
            'storage_path' => 'products/'.$product->id.'/img.jpg',
            'storage_disk' => 'public',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'width' => 800,
            'height' => 600,
            'sort_order' => 0,
            'is_primary' => false,
        ]);
    }
}

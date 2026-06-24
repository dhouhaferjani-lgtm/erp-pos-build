<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Enums\Vertical;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant-isolation regression coverage for ProductMediaController (façade).
 *
 * Ports all cases from ProductImageTenantIsolationTest to the new
 * media_attachments-backed endpoint. {image} = media_attachments.id.
 *
 * All six endpoints (index, store, update, destroy, download, reorder) must
 * enforce tenant + company scope: cross-tenant ids → 404, cross-company ids
 * within the same tenant → 404, mixed ids (in-scope product but foreign
 * attachment) → 404 (or 422 for reorder), reorder foreign ids → 422.
 */
final class ProductImageFacadeTenantIsolationTest extends TestCase
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

    private MediaAttachment $attachmentA;

    private MediaAttachment $attachmentA2;

    private MediaAttachment $attachmentB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Bus::fake();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A Iso',
            'slug' => 'tenant-a-facade-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant B Iso',
            'slug' => 'tenant-b-facade-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A1 Iso', 'ISO-A1');
        $this->companyA2 = $this->makeCompany($this->tenantA->id, 'Company A2 Iso', 'ISO-A2');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B Iso', 'ISO-B');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = $this->makeAdmin($this->tenantA->id, 'alice-facade-iso@example.com');
        $this->userB = $this->makeAdmin($this->tenantB->id, 'bob-facade-iso@example.com');

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

        $this->productA = $this->makeProduct($this->tenantA->id, $this->companyA->id, 'ISO-SKU-A1');
        $this->productA2 = $this->makeProduct($this->tenantA->id, $this->companyA2->id, 'ISO-SKU-A2');
        $this->productB = $this->makeProduct($this->tenantB->id, $this->companyB->id, 'ISO-SKU-B');

        $this->attachmentA = $this->makeAttachment($this->tenantA->id, $this->productA->id, MediaRole::Primary, 0);
        $this->attachmentA2 = $this->makeAttachment($this->tenantA->id, $this->productA2->id, MediaRole::Primary, 0);
        $this->attachmentB = $this->makeAttachment($this->tenantB->id, $this->productB->id, MediaRole::Primary, 0);
    }

    // -----------------------------------------------------------------------
    // Cross-tenant gap closures
    // -----------------------------------------------------------------------

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
            ->patchJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->attachmentB->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->attachmentB->id)
            ->assertStatus(404);
    }

    public function test_download_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productB->id.'/images/'.$this->attachmentB->id.'/download')
            ->assertStatus(404);
    }

    public function test_reorder_rejects_cross_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/products/'.$this->productB->id.'/images/reorder', [
                'image_ids' => [$this->attachmentB->id],
            ])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Cross-company within same tenant
    // -----------------------------------------------------------------------

    public function test_index_rejects_cross_company_same_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA2->id.'/images')
            ->assertStatus(404);
    }

    public function test_update_rejects_cross_company_same_tenant_product(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA2->id.'/images/'.$this->attachmentA2->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Mixed-ID requests (product belongs to scope but attachment does not)
    // -----------------------------------------------------------------------

    public function test_update_rejects_mixed_ids_product_a_attachment_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->attachmentB->id, [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_mixed_ids_product_a_attachment_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->attachmentB->id)
            ->assertStatus(404);
    }

    public function test_download_rejects_mixed_ids_product_a_attachment_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA->id.'/images/'.$this->attachmentB->id.'/download')
            ->assertStatus(404);
    }

    public function test_reorder_rejects_attachment_ids_from_other_product(): void
    {
        // Attachment from productB should fail validation (422) when reordering productA
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/products/'.$this->productA->id.'/images/reorder', [
                'image_ids' => [$this->attachmentB->id],
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // List endpoint scoping
    // -----------------------------------------------------------------------

    public function test_index_lists_only_attachments_belonging_to_the_scoped_product(): void
    {
        // Add a second attachment to productA
        $extra = $this->makeAttachment($this->tenantA->id, $this->productA->id, MediaRole::Gallery, 1);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/'.$this->productA->id.'/images');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$this->attachmentA->id, $extra->id],
            $ids,
            'index endpoint must only return attachments belonging to the scoped product.',
        );
        $this->assertNotContains($this->attachmentA2->id, $ids, 'cross-company attachment must not appear');
        $this->assertNotContains($this->attachmentB->id, $ids, 'cross-tenant attachment must not appear');
    }

    // -----------------------------------------------------------------------
    // Nonexistent ids share the not-found shape
    // -----------------------------------------------------------------------

    public function test_nonexistent_product_returns_404(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/products/00000000-0000-0000-0000-000000000000/images')
            ->assertStatus(404);
    }

    public function test_nonexistent_attachment_returns_404(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/00000000-0000-0000-0000-000000000000', [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Happy path — same tenant/company flow
    // -----------------------------------------------------------------------

    public function test_same_tenant_update_sets_primary_flag(): void
    {
        $gallery = $this->makeAttachment($this->tenantA->id, $this->productA->id, MediaRole::Gallery, 1);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->productA->id.'/images/'.$gallery->id, [
                'is_primary' => true,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('media_attachments', ['id' => $gallery->id, 'role' => MediaRole::Primary->value]);
    }

    public function test_same_tenant_destroy_removes_attachment(): void
    {
        $gallery = $this->makeAttachment($this->tenantA->id, $this->productA->id, MediaRole::Gallery, 1);

        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->productA->id.'/images/'.$gallery->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('media_attachments', ['id' => $gallery->id]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

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

    private function makeAttachment(
        string $tenantId,
        string $productId,
        MediaRole $role,
        int $sortOrder,
    ): MediaAttachment {
        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.com/img-'.uniqid().'.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        return MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => $role,
            'sort_order' => $sortOrder,
        ]);
    }
}

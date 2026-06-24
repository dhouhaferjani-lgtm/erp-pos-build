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
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CRUD parity tests for the ProductMediaController façade.
 *
 * The façade exposes the same /products/{product}/images[...] routes that
 * ProductImageController did, but now backed by the media_assets /
 * media_attachments model instead of product_images.
 *
 * {image} in every URL is a media_attachments.id (UUID), not a
 * product_images.id.
 */
final class ProductImageFacadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Bus::fake();

        $this->tenant = Tenant::create([
            'name' => 'Facade Test Tenant',
            'slug' => 'facade-test-tenant-images',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Facade Test Company',
            'legal_name' => 'Facade Test Company LLC',
            'tax_id' => 'TAX-FACADE-001',
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
            'name' => 'Facade Test Admin',
            'email' => 'facade-admin@example.com',
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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Facade Test Product',
            'sku' => 'FACADE-001',
        ]);
    }

    // -----------------------------------------------------------------------
    // index — list
    // -----------------------------------------------------------------------

    public function test_index_returns_empty_list_when_no_attachments(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/images");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_returns_attachment_list_with_expected_fields(): void
    {
        $attachment = $this->makeReadyAttachment($this->product->id, MediaRole::Primary, 0);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/images");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attachment->id)
            ->assertJsonPath('data.0.is_primary', true)
            ->assertJsonPath('data.0.sort_order', 0);
    }

    public function test_index_orders_by_sort_order(): void
    {
        $first = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 0);
        $second = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 1);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/images");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    // -----------------------------------------------------------------------
    // download
    // -----------------------------------------------------------------------

    public function test_download_redirects_for_external_url_asset(): void
    {
        $attachment = $this->makeExternalUrlAttachment($this->product->id, MediaRole::Primary, 0);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/products/{$this->product->id}/images/{$attachment->id}/download");

        // External URLs result in a 302 redirect
        $response->assertRedirect();
    }

    public function test_download_with_variant_parameter_is_accepted(): void
    {
        // For an external URL asset, a variant query param is still accepted (ignored for external URLs)
        $attachment = $this->makeExternalUrlAttachment($this->product->id, MediaRole::Primary, 0);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/products/{$this->product->id}/images/{$attachment->id}/download?variant=sm");

        $response->assertRedirect();
    }

    public function test_download_with_unknown_image_id_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/products/'.$this->product->id.'/images/00000000-0000-0000-0000-000000000000/download')
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // store — upload
    // -----------------------------------------------------------------------

    public function test_store_uploads_image_and_returns_201_with_id_field(): void
    {
        $file = UploadedFile::fake()->image('product.jpg', 800, 600)->size(512);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'is_primary', 'sort_order']]);

        // The returned id is a media_attachments row id.
        $attachmentId = $response->json('data.id');
        $this->assertDatabaseHas('media_attachments', ['id' => $attachmentId, 'owner_id' => $this->product->id]);
    }

    public function test_store_first_image_becomes_primary(): void
    {
        $file = UploadedFile::fake()->image('first.jpg', 800, 600)->size(256);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.is_primary'));
    }

    public function test_store_second_image_becomes_gallery(): void
    {
        // Create an existing PRIMARY first
        $this->makeReadyAttachment($this->product->id, MediaRole::Primary, 0);

        $file = UploadedFile::fake()->image('second.jpg', 800, 600)->size(256);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertCreated();
        $this->assertFalse($response->json('data.is_primary'));
    }

    public function test_store_rejects_non_image_file(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // update (PATCH) — set primary / sort_order
    // -----------------------------------------------------------------------

    public function test_update_sets_primary_flag_and_demotes_former_primary(): void
    {
        $first = $this->makeReadyAttachment($this->product->id, MediaRole::Primary, 0);
        $second = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 1);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}/images/{$second->id}", [
                'is_primary' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_primary', true);

        // Former primary must have been demoted
        $this->assertDatabaseHas('media_attachments', ['id' => $first->id, 'role' => MediaRole::Gallery->value]);
        $this->assertDatabaseHas('media_attachments', ['id' => $second->id, 'role' => MediaRole::Primary->value]);
    }

    public function test_update_sort_order(): void
    {
        $attachment = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 5);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}/images/{$attachment->id}", [
                'sort_order' => 2,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('media_attachments', ['id' => $attachment->id, 'sort_order' => 2]);
    }

    public function test_update_unknown_image_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/products/'.$this->product->id.'/images/00000000-0000-0000-0000-000000000000', [
                'is_primary' => true,
            ])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // reorder
    // -----------------------------------------------------------------------

    public function test_reorder_updates_sort_order_and_returns_list(): void
    {
        $first = $this->makeReadyAttachment($this->product->id, MediaRole::Primary, 0);
        $second = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 1);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images/reorder", [
                'image_ids' => [$second->id, $first->id],
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('media_attachments', ['id' => $second->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('media_attachments', ['id' => $first->id, 'sort_order' => 1]);
    }

    public function test_reorder_with_ids_from_another_product_returns_422(): void
    {
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OTHER-PRODUCT-002',
        ]);
        $foreignAttachment = $this->makeReadyAttachment($otherProduct->id, MediaRole::Gallery, 0);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images/reorder", [
                'image_ids' => [$foreignAttachment->id],
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // destroy
    // -----------------------------------------------------------------------

    public function test_destroy_removes_attachment_and_returns_204(): void
    {
        $attachment = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 0);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$this->product->id}/images/{$attachment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media_attachments', ['id' => $attachment->id]);
    }

    public function test_destroy_primary_promotes_next_by_sort_order(): void
    {
        $primary = $this->makeReadyAttachment($this->product->id, MediaRole::Primary, 0);
        $gallery = $this->makeReadyAttachment($this->product->id, MediaRole::Gallery, 1);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$this->product->id}/images/{$primary->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media_attachments', ['id' => $primary->id]);
        $this->assertDatabaseHas('media_attachments', ['id' => $gallery->id, 'role' => MediaRole::Primary->value]);
    }

    public function test_destroy_unknown_image_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/products/'.$this->product->id.'/images/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Auth gates
    // -----------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}/images")
            ->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a READY UPLOAD asset + attachment for the given owner (product).
     */
    private function makeReadyAttachment(
        string $productId,
        MediaRole $role,
        int $sortOrder,
    ): MediaAttachment {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$this->tenant->id.'/'.$productId.'/'.uniqid().'/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        // Put a placeholder file on the fake disk so serve() doesn't explode
        Storage::disk('s3')->put((string) $asset->storage_path, 'PLACEHOLDER');

        return MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => $role,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Create a READY EXTERNAL_URL asset + attachment for the given owner.
     */
    private function makeExternalUrlAttachment(
        string $productId,
        MediaRole $role,
        int $sortOrder,
    ): MediaAttachment {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.com/image.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        return MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => $role,
            'sort_order' => $sortOrder,
        ]);
    }
}

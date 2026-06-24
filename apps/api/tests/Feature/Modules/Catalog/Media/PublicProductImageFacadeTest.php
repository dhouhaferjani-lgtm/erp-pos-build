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
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public storefront parity tests for the PublicProductMediaController façade.
 *
 * The façade exposes the same /api/v1/public/products/{product}/images[...]
 * routes that PublicProductImageController did, but backed by the
 * media_assets / media_attachments model instead of product_images.
 *
 * No authentication required — these routes are rate-limited only.
 * The is_active_for_ecommerce flag is the access gate.
 */
final class PublicProductImageFacadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $activeProduct;

    private Product $inactiveProduct;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->tenant = Tenant::create([
            'name' => 'Public Media Facade Tenant',
            'slug' => 'public-media-facade-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Public Media Facade Company',
            'legal_name' => 'Public Media Facade Company LLC',
            'tax_id' => 'TAX-PUB-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->activeProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Public Active Product',
            'sku' => 'PUB-ACTIVE-001',
            'is_active_for_ecommerce' => true,
        ]);

        $this->inactiveProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Public Inactive Product',
            'sku' => 'PUB-INACTIVE-001',
            'is_active_for_ecommerce' => false,
        ]);
    }

    // -----------------------------------------------------------------------
    // index — active product with a READY primary attachment
    // -----------------------------------------------------------------------

    public function test_index_returns_preserved_shape_for_ecommerce_active_product(): void
    {
        $attachment = $this->makePrimaryExternalUrlAttachment($this->activeProduct->id);

        $response = $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images"
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attachment->id)
            ->assertJsonPath('data.0.is_primary', true)
            ->assertJsonPath('data.0.sort_order', 0)
            ->assertJsonPath('data.0.url', 'https://example.com/product-image.jpg');

        // Confirm the top-level envelope keys match the old public controller shape
        $this->assertArrayHasKey('data', $response->json());
        $item = $response->json('data.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('is_primary', $item);
        $this->assertArrayHasKey('sort_order', $item);
    }

    public function test_index_returns_empty_list_for_active_product_with_no_attachments(): void
    {
        $response = $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images"
        );

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_orders_results_by_sort_order(): void
    {
        $first = $this->makeExternalUrlAttachment($this->activeProduct->id, MediaRole::Primary, 0);
        $second = $this->makeExternalUrlAttachment($this->activeProduct->id, MediaRole::Gallery, 1);

        $response = $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images"
        );

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    // -----------------------------------------------------------------------
    // is_active_for_ecommerce gate — inactive product → 404
    // -----------------------------------------------------------------------

    public function test_index_returns_404_for_ecommerce_inactive_product(): void
    {
        $this->makePrimaryExternalUrlAttachment($this->inactiveProduct->id);

        $this->getJson(
            "/api/v1/public/products/{$this->inactiveProduct->id}/images"
        )->assertStatus(404);
    }

    public function test_show_returns_404_for_ecommerce_inactive_product(): void
    {
        $attachment = $this->makePrimaryExternalUrlAttachment($this->inactiveProduct->id);

        $this->getJson(
            "/api/v1/public/products/{$this->inactiveProduct->id}/images/{$attachment->id}"
        )->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // show — single image
    // -----------------------------------------------------------------------

    public function test_show_returns_single_image_with_preserved_shape(): void
    {
        $attachment = $this->makePrimaryExternalUrlAttachment($this->activeProduct->id);

        $response = $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images/{$attachment->id}"
        );

        $response->assertOk()
            ->assertJsonPath('data.id', $attachment->id)
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.url', 'https://example.com/product-image.jpg');

        $item = $response->json('data');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('is_primary', $item);
        $this->assertArrayHasKey('sort_order', $item);
    }

    public function test_show_returns_404_when_image_belongs_to_different_product(): void
    {
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OTHER-PUB-002',
            'is_active_for_ecommerce' => true,
        ]);
        $foreignAttachment = $this->makePrimaryExternalUrlAttachment($otherProduct->id);

        $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images/{$foreignAttachment->id}"
        )->assertStatus(404);
    }

    public function test_show_returns_404_for_unknown_attachment_id(): void
    {
        $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images/00000000-0000-0000-0000-000000000000"
        )->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // No auth required
    // -----------------------------------------------------------------------

    public function test_index_does_not_require_authentication(): void
    {
        // No actingAs — the request must succeed for an active product
        $response = $this->getJson(
            "/api/v1/public/products/{$this->activeProduct->id}/images"
        );

        // Not 401/403 — the route is public
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a READY EXTERNAL_URL asset + PRIMARY attachment for the given product.
     */
    private function makePrimaryExternalUrlAttachment(string $productId): MediaAttachment
    {
        return $this->makeExternalUrlAttachment($productId, MediaRole::Primary, 0);
    }

    /**
     * Create a READY EXTERNAL_URL asset + attachment with a given role and sort_order.
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
            'external_url' => 'https://example.com/product-image.jpg',
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

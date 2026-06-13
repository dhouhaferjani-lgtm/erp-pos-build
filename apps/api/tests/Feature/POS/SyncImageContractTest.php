<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression guard for the POS sync payload's image_url contract.
 *
 * The Tauri POS caches product images keyed by (product_id + remote URL).
 * Changing the payload shape or the URL structure would silently break the
 * POS image cache on existing installations.
 *
 * Contract (MUST NOT change without a coordinated POS release):
 *  - Each product in the payload has exactly ONE scalar `image_url` field.
 *  - When a READY primary exists, `image_url` is a string containing:
 *      - "/images/"  (identifies the download route)
 *      - "variant=sm"  (the size token the POS caches by)
 *  - The product payload MUST NOT contain a `media` array key (POS is single-scalar only).
 */
final class SyncImageContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    /**
     * A product with a READY UPLOAD primary attachment must emit image_url
     * that follows the /images/{attachmentId}/download?variant=sm shape.
     */
    public function test_sync_emits_same_primary_image_url_field_and_shape(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        // Seed a READY UPLOAD asset + PRIMARY attachment for this product.
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => "products/{$this->tenant->id}/{$product->id}/slot/original.jpg",
            'mime_type' => 'image/jpeg',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $product->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/v1/pos/sync/pull');
        $response->assertStatus(200);

        $products = $response->json('data.products');
        $this->assertNotEmpty($products);

        // Find our product in the payload.
        $productPayload = collect($products)->firstWhere('id', $product->id);
        $this->assertNotNull($productPayload, 'Expected product not found in sync payload');

        // --- Core contract assertions ---

        // 1. image_url must be present as a scalar field (null OR a string).
        $this->assertArrayHasKey('image_url', $productPayload);
        $url = $productPayload['image_url'];

        // 2. When a READY primary exists, the URL must match the download-route shape.
        //    The POS SQLite cache is keyed on this URL — its structure is frozen.
        self::assertIsString($url, 'image_url must be a string when a READY primary attachment exists');
        self::assertStringContainsString('/images/', $url, 'image_url must contain /images/ (download route)');
        self::assertStringContainsString('variant=sm', $url, 'image_url must contain variant=sm (POS cache key)');

        // 3. The attachment id must appear in the URL (façade identity: attachment, not asset).
        self::assertStringContainsString($attachment->id, $url, 'image_url must use the attachment id, not the asset id');

        // 4. The product payload MUST NOT contain a `media` array key.
        //    POS contract: single image_url scalar only — never a media array.
        self::assertArrayNotHasKey('media', $productPayload, 'POS contract: product payload must not expose a media array');
    }

    /**
     * A product with NO media attachments must emit image_url = null
     * and still must not contain a media array key.
     */
    public function test_sync_emits_null_image_url_when_product_has_no_attachment(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/pos/sync/pull');
        $response->assertStatus(200);

        $products = $response->json('data.products');
        $productPayload = collect($products)->firstWhere('id', $product->id);
        $this->assertNotNull($productPayload);

        $this->assertArrayHasKey('image_url', $productPayload);
        self::assertNull($productPayload['image_url']);
        self::assertArrayNotHasKey('media', $productPayload);
    }

    /**
     * A product with an ExternalUrl (READY) primary must emit the raw URL
     * directly as image_url (no download route wrapping).
     */
    public function test_sync_emits_external_url_directly_for_external_url_asset(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $externalUrl = 'https://cdn.example.com/placeholder.jpg';
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => $externalUrl,
        ]);

        MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $product->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/v1/pos/sync/pull');
        $response->assertStatus(200);

        $products = $response->json('data.products');
        $productPayload = collect($products)->firstWhere('id', $product->id);
        $this->assertNotNull($productPayload);

        self::assertSame($externalUrl, $productPayload['image_url']);
        self::assertArrayNotHasKey('media', $productPayload);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }
}

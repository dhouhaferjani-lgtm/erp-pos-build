<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 13: ProductData + ProductController media composition.
 *
 * 1. Verifies primary_image_url parity across EXTERNAL_URL / UPLOAD / no-media
 *    products for both GET /api/v1/products (index) and GET /api/v1/products/{id} (show).
 * 2. Verifies the index path makes exactly ONE batched call to forProducts() and
 *    never calls forProduct() per-row (no-N+1 contract).
 */
final class ProductDataMediaParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $productA; // EXTERNAL_URL primary

    private Product $productB; // UPLOAD READY primary

    private Product $productC; // no media

    private string $externalUrl = 'https://example.com/product-a.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->tenant = Tenant::create([
            'name' => 'Parity Test Tenant',
            'slug' => 'parity-test-tenant-media',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parity Test Company',
            'legal_name' => 'Parity Test Company LLC',
            'tax_id' => 'TAX-PARITY-001',
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
            'name' => 'Parity Test Admin',
            'email' => 'parity-admin@example.com',
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

        // Product A — EXTERNAL_URL primary attachment
        $this->productA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product A External',
            'sku' => 'PARITY-A-001',
        ]);
        $externalAsset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => $this->externalUrl,
            'mime_type' => 'image/jpeg',
        ]);
        MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $externalAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $this->productA->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        // Product B — UPLOAD READY primary attachment
        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product B Upload',
            'sku' => 'PARITY-B-001',
        ]);
        $uploadAsset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$this->tenant->id.'/'.$this->productB->id.'/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);
        Storage::disk('s3')->put((string) $uploadAsset->storage_path, 'PLACEHOLDER');
        MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $uploadAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $this->productB->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        // Product C — no media at all
        $this->productC = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product C No Media',
            'sku' => 'PARITY-C-001',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 1: parity across sources and endpoints
    // -------------------------------------------------------------------------

    public function test_primary_image_url_parity_across_sources_and_endpoints(): void
    {
        // --- index endpoint ---
        $indexResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?per_page=50')
            ->assertOk();

        $indexData = collect($indexResponse->json('data'));

        $rowA = $indexData->firstWhere('id', $this->productA->id);
        $rowB = $indexData->firstWhere('id', $this->productB->id);
        $rowC = $indexData->firstWhere('id', $this->productC->id);

        self::assertNotNull($rowA, 'Product A must appear in index');
        self::assertNotNull($rowB, 'Product B must appear in index');
        self::assertNotNull($rowC, 'Product C must appear in index');

        // Product A (EXTERNAL_URL): primary_image_url == the external URL verbatim
        self::assertSame(
            $this->externalUrl,
            $rowA['primary_image_url'],
            'Product A index: primary_image_url must equal the external URL'
        );

        // Product B (UPLOAD READY): primary_image_url is a RELATIVE HMAC-signed
        // media.serve URL with the md (hero) variant — BUG-005 / RCA A1. It must NOT
        // be the auth:sanctum-gated products.images.download URL, which an <img> tag
        // cannot authenticate.
        self::assertNotNull($rowB['primary_image_url'], 'Product B index: primary_image_url must not be null');
        self::assertStringStartsWith('/', $rowB['primary_image_url'], 'Product B index: URL must be relative');
        self::assertStringContainsString('/media/', $rowB['primary_image_url'], 'Product B index: URL must contain /media/');
        self::assertStringContainsString('signature=', $rowB['primary_image_url'], 'Product B index: URL must be HMAC-signed');
        self::assertStringContainsString('variant=md', $rowB['primary_image_url'], 'Product B index: URL must contain variant=md');
        self::assertStringNotContainsString('/download', $rowB['primary_image_url'], 'Product B index: URL must not be the auth-gated download route');

        // Product B URL must reference product B's own tenant (signed tenant segment)
        self::assertStringContainsString($this->tenant->id, $rowB['primary_image_url'], 'Product B index: URL must reference the tenant');

        // The per-attachment media[] array keeps the frozen POS URL shape.
        self::assertNotSame([], $rowB['media'], 'Product B index: media array must not be empty');
        self::assertStringContainsString('/images/', $rowB['media'][0]['url'], 'Product B index: media[].url keeps the POS download shape');
        self::assertStringContainsString('variant=sm', $rowB['media'][0]['url'], 'Product B index: media[].url keeps variant=sm');

        // Product C (no media): primary_image_url must be null
        self::assertNull($rowC['primary_image_url'], 'Product C index: primary_image_url must be null');

        // Both must also have the media array field
        self::assertArrayHasKey('media', $rowA, 'Product A index: media field must be present');
        self::assertIsArray($rowA['media'], 'Product A index: media must be an array');

        // --- show endpoint for each product ---
        // Product A show
        $showA = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->productA->id}")
            ->assertOk()
            ->json('data');

        self::assertSame(
            $this->externalUrl,
            $showA['primary_image_url'],
            'Product A show: primary_image_url must equal the external URL'
        );
        self::assertArrayHasKey('media', $showA, 'Product A show: media field must be present');
        self::assertIsArray($showA['media']);

        // Product B show
        $showB = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->productB->id}")
            ->assertOk()
            ->json('data');

        self::assertNotNull($showB['primary_image_url'], 'Product B show: primary_image_url must not be null');
        self::assertStringStartsWith('/', $showB['primary_image_url']);
        self::assertStringContainsString('/media/', $showB['primary_image_url']);
        self::assertStringContainsString('signature=', $showB['primary_image_url']);
        self::assertStringContainsString('variant=md', $showB['primary_image_url']);

        // Product C show
        $showC = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->productC->id}")
            ->assertOk()
            ->json('data');

        self::assertNull($showC['primary_image_url'], 'Product C show: primary_image_url must be null');

        // Verify index and show agree for product A and B
        self::assertSame(
            $rowA['primary_image_url'],
            $showA['primary_image_url'],
            'Product A: index and show primary_image_url must match'
        );
        // Signed URLs embed a per-request `expires` timestamp, so compare the route
        // path (the stable part of the contract) rather than the full signed string —
        // otherwise the assertion flakes whenever the two requests straddle a second.
        self::assertSame(
            strtok((string) $rowB['primary_image_url'], '?'),
            strtok((string) $showB['primary_image_url'], '?'),
            'Product B: index and show primary_image_url must resolve the same media.serve path'
        );
    }

    // -------------------------------------------------------------------------
    // Test 2: no-N+1 — index resolves media in ONE batched call
    // -------------------------------------------------------------------------

    public function test_index_resolves_media_in_one_batched_call_no_n_plus_one(): void
    {
        // Seed >= 5 products each with a primary attachment
        $products = [];
        for ($i = 1; $i <= 5; $i++) {
            $product = Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => "N+1 Test Product $i",
                'sku' => "N1-TEST-{$i}",
            ]);
            $asset = MediaAsset::create([
                'tenant_id' => $this->tenant->id,
                'type' => MediaAssetType::Image,
                'source' => MediaSource::ExternalUrl,
                'status' => MediaStatus::Ready,
                'storage_disk' => 'url',
                'external_url' => "https://example.com/n1-product-{$i}.jpg",
                'mime_type' => 'image/jpeg',
            ]);
            MediaAttachment::create([
                'tenant_id' => $this->tenant->id,
                'media_asset_id' => $asset->id,
                'owner_type' => MediaOwnerType::Product,
                'owner_id' => $product->id,
                'role' => MediaRole::Primary,
                'sort_order' => 0,
            ]);
            $products[] = $product;
        }

        // Build a fake map for the spy to return
        $fakeMap = [];
        foreach ($products as $p) {
            $fakeMap[$p->id] = ProductMediaData::makeEmpty();
        }
        // Also include the 3 products from setUp() so the spy doesn't fail on real products
        $fakeMap[$this->productA->id] = ProductMediaData::makeEmpty();
        $fakeMap[$this->productB->id] = ProductMediaData::makeEmpty();
        $fakeMap[$this->productC->id] = ProductMediaData::makeEmpty();

        /** @var MockInterface&CatalogMediaQueryInterface $spy */
        $spy = $this->mock(CatalogMediaQueryInterface::class);

        $spy->shouldReceive('forProducts')
            ->once()
            ->andReturnUsing(function (array $ids) use ($fakeMap): array {
                $result = [];
                foreach ($ids as $id) {
                    $result[$id] = $fakeMap[$id] ?? ProductMediaData::makeEmpty();
                }

                return $result;
            });

        // Must never call the single-product variant
        $spy->shouldNotReceive('forProduct');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?per_page=50')
            ->assertOk();
    }
}

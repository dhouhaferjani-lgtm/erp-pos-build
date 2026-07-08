<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Services\CatalogEnrichmentService;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use App\Shared\DTOs\CatalogProductDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end proof that a real image, once persisted by enrichment, flows
 * through to BOTH read surfaces the app relies on:
 *
 *   1. {@see ProductData::fromModel()}'s `primary_image_url` (via
 *      {@see CatalogMediaQueryInterface::forProduct()} — the same query the
 *      SPA/product-detail path uses).
 *   2. The POS `SyncController::pull()` payload's `image_url` (hit for real
 *      over HTTP, exercising the exact controller code).
 *
 * Both enrichment entry points are covered:
 *   - Path A: {@see CatalogEnrichmentService::applyCatalogHit()} (barcode-lookup catalog hit).
 *   - Path B: {@see EnrichmentReviewService::accept()} with an empty accepted-fields list (auto).
 *
 * Three gotchas this test must handle (see class-level notes below each one is applied):
 *   1. RemoteImageFetcher uses cURL/DNS, not the Http facade — Http::fake() would not
 *      intercept it. HostResolverInterface + PinnedImageDownloaderInterface are faked
 *      in-memory instead (mirrors EnrichmentImagePersisterTest).
 *   2. PersistEnrichmentImagesJob is dispatched ->afterCommit(), which never fires inside
 *      RefreshDatabase's wrapping transaction. Bus::fake() intercepts the dispatch (proving
 *      the hook fired), then the persister is invoked directly to materialize the media.
 *   3. CatalogMediaQuery/the read path only returns READY media assets, but the persister
 *      (via MediaUploadService) creates them UPLOADED — GenerateRenditions (which promotes
 *      Uploaded -> Ready) is swallowed by Bus::fake(). This test manually flips the created
 *      asset(s) to MediaStatus::Ready to simulate rendition completion before asserting.
 */
final class EnrichmentImageEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private EndToEndFakeHostResolver $resolver;

    private EndToEndFakePinnedDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        // Gotcha #1: bind in-memory fakes for the real RemoteImageFetcher's two
        // collaborators so the download never touches real DNS/cURL.
        $this->resolver = new EndToEndFakeHostResolver;
        $this->downloader = new EndToEndFakePinnedDownloader;
        $this->app->instance(HostResolverInterface::class, $this->resolver);
        $this->app->instance(PinnedImageDownloaderInterface::class, $this->downloader);

        $this->tenant = Tenant::create([
            'name' => 'Enrichment Image E2E',
            'slug' => 'enrichment-image-e2e-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Enrichment Image E2E Company',
            'legal_name' => 'Enrichment Image E2E Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_catalog_hit_image_flows_to_product_data_and_pos_sync_payload(): void
    {
        Bus::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Local Product',
            'barcode' => '3017620422003',
            'platform_product_id' => 'platform-product-e2e-a',
            'is_active' => true,
        ]);

        $imageUrl = 'https://pharma-shop.tn/e2e-a.png';
        $this->downloader->register($imageUrl, $this->png(4, 4));

        $catalog = new CatalogProductDTO(
            platformProductId: 'platform-product-e2e-a',
            barcode: '3017620422003',
            name: 'Catalog Cream',
            brand: 'La Roche-Posay',
            description: 'Hydrating care',
            classification: ['category' => 'cosmetic'],
            ingredients: [],
            images: [['url' => $imageUrl, 'thumbnail' => null, 'type' => 'front']],
            confidenceScore: 96,
            enrichmentTier: 'catalog',
        );

        $this->app->make(CatalogEnrichmentService::class)->applyCatalogHit($product, $catalog);

        // Gotcha #2 (proof half): assert the hook actually dispatched the job.
        Bus::assertDispatched(
            PersistEnrichmentImagesJob::class,
            fn (PersistEnrichmentImagesJob $j): bool => $j->productId === $product->id
                && $j->tenantId === $product->tenant_id
                && $j->images !== [],
        );

        // Gotcha #2 (materialize half): ->afterCommit() never ran under RefreshDatabase's
        // transaction, so run the persister directly with the same payload the job carries.
        $outcome = $this->app->make(EnrichmentImagePersister::class)->persist(
            $product->id,
            $product->tenant_id,
            [['url' => $imageUrl, 'thumbnail' => null, 'type' => 'front']],
            null,
        );
        self::assertSame(1, $outcome->attached, 'Expected exactly one image to be attached.');

        $this->markAllProductAssetsReady($product->id);

        $this->assertResolvesEndToEnd($product);
    }

    public function test_review_accept_image_flows_to_product_data_and_pos_sync_payload(): void
    {
        Bus::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'is_active' => true,
        ]);

        $reviewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Reviewer',
            'email' => 'reviewer-e2e@example.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $imageUrl = 'https://pharma-shop.tn/e2e-b.png';
        $this->downloader->register($imageUrl, $this->png(5, 5));
        $images = [['url' => $imageUrl, 'thumbnail' => null, 'type' => null]];

        $result = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: $images,
                confidence_score: 85,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);

        // Empty acceptedFields = auto-dispatch (Path B), per EnrichmentReviewService::accept().
        $this->app->make(EnrichmentReviewService::class)->accept($result, [], $reviewer->id);

        Bus::assertDispatched(
            PersistEnrichmentImagesJob::class,
            fn (PersistEnrichmentImagesJob $j): bool => $j->productId === $product->id
                && $j->tenantId === $product->tenant_id
                && $j->images !== [],
        );

        $outcome = $this->app->make(EnrichmentImagePersister::class)->persist(
            $product->id,
            $product->tenant_id,
            $images,
            $reviewer->id,
        );
        self::assertSame(1, $outcome->attached, 'Expected exactly one image to be attached.');

        $this->markAllProductAssetsReady($product->id);

        $this->assertResolvesEndToEnd($product);
    }

    /**
     * Gotcha #3: simulate GenerateRenditions completion (swallowed by Bus::fake()) by
     * flipping every MediaAsset the persister just created/attached for this product
     * from UPLOADED to READY — the only status the read repository returns.
     */
    private function markAllProductAssetsReady(string $productId): void
    {
        $assetIds = MediaAttachment::query()
            ->where('owner_id', $productId)
            ->pluck('media_asset_id');

        self::assertNotEmpty($assetIds, 'Expected at least one media attachment to have been created.');

        MediaAsset::query()->whereIn('id', $assetIds)->update(['status' => MediaStatus::Ready]);
    }

    /**
     * Assert BOTH read surfaces resolve the persisted image, via the exact paths the
     * app uses: CatalogMediaQuery -> ProductData::fromModel() for the product page, and
     * a real HTTP hit against the POS SyncController::pull() endpoint.
     */
    private function assertResolvesEndToEnd(Product $product): void
    {
        // (i) ProductData path — CatalogMediaQuery + ProductData::fromModel().
        /** @var CatalogMediaQueryInterface $mediaQuery */
        $mediaQuery = $this->app->make(CatalogMediaQueryInterface::class);
        $media = $mediaQuery->forProduct($product->id, $product->tenant_id);

        $productData = ProductData::fromModel($product, $media);
        self::assertNotNull($productData->primary_image_url, 'ProductData::primary_image_url must resolve non-null.');
        self::assertNotSame([], $productData->media);

        // (ii) POS SyncController path — real HTTP GET against /api/v1/pos/sync/pull,
        // exercising SyncController::pull()'s exact image_url emission (lines 74-89).
        $posUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Operator',
            'email' => 'pos-operator-'.Str::lower(Str::random(6)).'@example.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $posUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $posUser->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($posUser);

        $response = $this->getJson('/api/v1/pos/sync/pull');
        $response->assertStatus(200);

        $payloadProducts = $response->json('data.products');
        self::assertIsArray($payloadProducts);

        $match = null;
        foreach ($payloadProducts as $row) {
            if (($row['id'] ?? null) === $product->id) {
                $match = $row;
                break;
            }
        }

        self::assertNotNull($match, 'Product not found in POS sync payload.');
        self::assertArrayHasKey('image_url', $match);
        self::assertNotNull($match['image_url'], 'POS sync payload image_url must resolve non-null.');
    }

    /** Generate valid PNG bytes so the fake downloader/upload pipeline handles real bytes. */
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, $w % 255, $h % 255, ($w + $h) % 255);
        imagefill($img, 0, 0, $color);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}

/**
 * In-memory host resolver returning a fixed public IP for every host, so the
 * real RemoteImageFetcher's private-range guard passes without touching DNS.
 *
 * Named distinctly from EnrichmentImagePersisterTest's FakeHostResolver — both
 * files share the `Tests\Feature\Modules\Product` namespace and PSR-4
 * autoloading maps a class strictly to its own file, so a second class with
 * the same name declared in a different file would fatal ("Cannot redeclare
 * class") whenever both test files load in the same PHPUnit process.
 */
final class EndToEndFakeHostResolver implements HostResolverInterface
{
    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        return ['93.184.216.34']; // documentation range — public, not disallowed
    }
}

/**
 * In-memory pinned downloader. Registered URLs materialise their bytes into a
 * temp file and return a FetchedImage; unregistered URLs throw (simulating 404).
 */
final class EndToEndFakePinnedDownloader implements PinnedImageDownloaderInterface
{
    /** @var array<string, string> */
    private array $registry = [];

    public function register(string $url, string $bytes): void
    {
        $this->registry[$url] = $bytes;
    }

    public function download(string $url, string $host, string $pinnedIp, int $port, int $maxBytes): FetchedImage
    {
        if (! array_key_exists($url, $this->registry)) {
            throw new RemoteImageFetchException("Simulated fetch failure (unregistered): {$url}");
        }

        $bytes = $this->registry[$url];
        $tmp = (string) tempnam(sys_get_temp_dir(), 'enrich-img-e2e-');
        file_put_contents($tmp, $bytes);

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path) ?: 'image.png';

        return new FetchedImage($tmp, 'image/png', $filename, strlen($bytes));
    }
}

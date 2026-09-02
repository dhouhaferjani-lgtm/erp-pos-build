<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\Contracts\CatalogLookupResultInterface;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\EnrichmentStatus;
use App\Shared\Exceptions\PlatformCatalogUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApplyCatalogEnrichmentJobTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_PRODUCT_ID = '11111111-1111-4111-8111-111111111111';

    private const OTHER_PLATFORM_PRODUCT_ID = '22222222-2222-4222-8222-222222222222';

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Apply Catalog Tenant',
            'slug' => 'apply-catalog-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apply Catalog Company',
            'legal_name' => 'Apply Catalog Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_handle_applies_matching_catalog_hit(): void
    {
        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);
        $this->bindLookup($this->makeCatalogProduct(self::PLATFORM_PRODUCT_ID));

        $this->runJob($product, self::PLATFORM_PRODUCT_ID);

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        // The apply never overwrites the user's own name (form edits win).
        $this->assertSame('Local Product', $product->name);
        $this->assertSame(self::PLATFORM_PRODUCT_ID, $product->platform_product_id);
        $this->assertSame(1, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_handle_clears_backlink_when_lookup_misses(): void
    {
        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);
        $this->bindLookup(null);

        $this->runJob($product, self::PLATFORM_PRODUCT_ID);

        $product->refresh();
        $this->assertNull($product->platform_product_id);
        $this->assertNull($product->enrichment_status);
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_handle_clears_backlink_when_lookup_returns_different_platform_product(): void
    {
        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);
        $this->bindLookup($this->makeCatalogProduct(self::OTHER_PLATFORM_PRODUCT_ID));

        $this->runJob($product, self::PLATFORM_PRODUCT_ID);

        $product->refresh();
        $this->assertNull($product->platform_product_id);
        $this->assertNull($product->enrichment_status);
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_handle_preserves_backlink_when_platform_unavailable(): void
    {
        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);
        $this->app->instance(CatalogLookupInterface::class, new ThrowingCatalogLookup);

        try {
            $this->runJob($product, self::PLATFORM_PRODUCT_ID);
            $this->fail('Expected PlatformCatalogUnavailableException to bubble for queue retry.');
        } catch (PlatformCatalogUnavailableException) {
            // rethrown so the queue's retry/backoff owns the transient outage
        }

        $product->refresh();
        // A transient platform outage must NOT be treated as not_found:
        // the verified backlink survives and no enrichment state is touched.
        $this->assertSame(self::PLATFORM_PRODUCT_ID, $product->platform_product_id);
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_job_applies_catalog_hit_on_real_queue_path_without_company_context(): void
    {
        // A queue worker binds no CompanyContext. Every other test in this file
        // masks that reality twice: setUp() binds a context, and the lookup
        // interface is replaced with a fake — so PlatformHttpClient's
        // requireCompanyId() call is never exercised. This test dispatches the
        // job through the real queue pipeline against the real
        // BarcodeLookupService with only the HTTP layer faked.
        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
        Cache::flush();

        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => self::PLATFORM_PRODUCT_ID,
                    'barcode' => '3017620422003',
                    'name' => 'Catalog Cream',
                    'brand' => 'La Roche-Posay',
                    'description' => 'Hydrating care',
                    'classification' => ['category' => 'cosmetic'],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 96,
                    'enrichment_tier' => 'catalog',
                ],
            ], 200),
        ]);

        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);

        app(CompanyContext::class)->clear();

        ApplyCatalogEnrichmentJob::dispatch(
            productId: $product->id,
            expectedPlatformProductId: self::PLATFORM_PRODUCT_ID,
            barcode: '3017620422003',
            vertical: 'parapharmacy',
            tenantId: $this->tenant->id,
        );

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        $this->assertSame(self::PLATFORM_PRODUCT_ID, $product->platform_product_id);
        $this->assertSame(1, EnrichmentResult::query()->where('product_id', $product->id)->count());
        // The job must not leak its bound context into subsequent jobs on the worker.
        $this->assertNull(app(CompanyContext::class)->getCompanyId());
    }

    public function test_pre_deploy_payload_without_tenant_id_derives_it_from_the_product(): void
    {
        $product = $this->makeProduct(self::PLATFORM_PRODUCT_ID);
        $this->bindLookup($this->makeCatalogProduct(self::PLATFORM_PRODUCT_ID));

        $job = new ApplyCatalogEnrichmentJob(
            productId: $product->id,
            expectedPlatformProductId: self::PLATFORM_PRODUCT_ID,
            barcode: '3017620422003',
            vertical: 'parapharmacy',
        );
        $this->app->call([$job, 'handle']);

        $this->assertSame($this->tenant->id, $job->tenantId);
        $this->assertSame(EnrichmentStatus::Completed, $product->refresh()->enrichment_status);
        $this->assertNull(app(CompanyContext::class)->getCompanyId());
    }

    private function makeProduct(string $platformProductId): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Local Product',
            'barcode' => '3017620422003',
            'platform_product_id' => $platformProductId,
        ]);
    }

    private function makeCatalogProduct(string $platformProductId): CatalogProductDTO
    {
        return new CatalogProductDTO(
            platformProductId: $platformProductId,
            barcode: '3017620422003',
            name: 'Catalog Cream',
            brand: 'La Roche-Posay',
            description: 'Hydrating care',
            classification: ['category' => 'cosmetic'],
            ingredients: [],
            images: [],
            confidenceScore: 96,
            enrichmentTier: 'catalog',
        );
    }

    private function bindLookup(?CatalogProductDTO $catalog): void
    {
        $this->app->instance(CatalogLookupInterface::class, new FakeCatalogLookup($catalog));
    }

    private function runJob(Product $product, string $expectedPlatformProductId): void
    {
        $job = new ApplyCatalogEnrichmentJob(
            productId: $product->id,
            expectedPlatformProductId: $expectedPlatformProductId,
            barcode: '3017620422003',
            vertical: 'parapharmacy',
            tenantId: $this->tenant->id,
        );

        $this->app->call([$job, 'handle']);
    }
}

final readonly class FakeCatalogLookup implements CatalogLookupInterface
{
    public function __construct(
        private ?CatalogProductDTO $catalog,
    ) {}

    public function normalizeBarcode(string $barcode): ?string
    {
        return $barcode;
    }

    public function lookup(string $barcode, ?string $vertical = null): CatalogLookupResultInterface
    {
        throw new \LogicException('ApplyCatalogEnrichmentJob uses lookupCatalogProduct().');
    }

    public function isCircuitOpen(): bool
    {
        return false;
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        return $this->catalog;
    }
}

final readonly class ThrowingCatalogLookup implements CatalogLookupInterface
{
    public function normalizeBarcode(string $barcode): ?string
    {
        return $barcode;
    }

    public function lookup(string $barcode, ?string $vertical = null): CatalogLookupResultInterface
    {
        throw new \LogicException('ApplyCatalogEnrichmentJob uses lookupCatalogProduct().');
    }

    public function isCircuitOpen(): bool
    {
        return false;
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        throw new PlatformCatalogUnavailableException('platform_error');
    }
}

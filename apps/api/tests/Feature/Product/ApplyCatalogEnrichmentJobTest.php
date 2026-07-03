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
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApplyCatalogEnrichmentJobTest extends TestCase
{
    use RefreshDatabase;

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
        $product = $this->makeProduct('platform-product-001');
        $this->bindLookup($this->makeCatalogProduct('platform-product-001'));

        $this->runJob($product, 'platform-product-001');

        $product->refresh();
        $this->assertSame(EnrichmentStatus::Completed, $product->enrichment_status);
        // The apply never overwrites the user's own name (form edits win).
        $this->assertSame('Local Product', $product->name);
        $this->assertSame('platform-product-001', $product->platform_product_id);
        $this->assertSame(1, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_handle_clears_backlink_when_lookup_misses(): void
    {
        $product = $this->makeProduct('platform-product-001');
        $this->bindLookup(null);

        $this->runJob($product, 'platform-product-001');

        $product->refresh();
        $this->assertNull($product->platform_product_id);
        $this->assertNull($product->enrichment_status);
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->count());
    }

    public function test_handle_clears_backlink_when_lookup_returns_different_platform_product(): void
    {
        $product = $this->makeProduct('platform-product-001');
        $this->bindLookup($this->makeCatalogProduct('platform-product-999'));

        $this->runJob($product, 'platform-product-001');

        $product->refresh();
        $this->assertNull($product->platform_product_id);
        $this->assertNull($product->enrichment_status);
        $this->assertSame(0, EnrichmentResult::query()->where('product_id', $product->id)->count());
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
        );

        $this->app->call([$job, 'handle']);
    }
}

final readonly class FakeCatalogLookup implements CatalogLookupInterface
{
    public function __construct(
        private ?CatalogProductDTO $catalog,
    ) {}

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        return $this->catalog;
    }
}

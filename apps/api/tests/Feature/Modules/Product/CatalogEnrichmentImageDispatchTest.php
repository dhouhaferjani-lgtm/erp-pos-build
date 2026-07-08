<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Services\CatalogEnrichmentService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\CatalogProductDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogEnrichmentImageDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private CatalogEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Catalog Enrichment Image Dispatch',
            'slug' => 'catalog-enrichment-image-dispatch-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Catalog Enrichment Image Dispatch Company',
            'legal_name' => 'Catalog Enrichment Image Dispatch Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->service = $this->app->make(CatalogEnrichmentService::class);
    }

    public function test_apply_catalog_hit_with_images_dispatches_persist_job(): void
    {
        Bus::fake();

        [$product, $catalog] = $this->makeProductAndCatalogWithImages([
            ['url' => 'https://pharma-shop.tn/a.jpg', 'thumbnail' => null, 'type' => 'front'],
        ]);

        $this->service->applyCatalogHit($product, $catalog);

        Bus::assertDispatched(PersistEnrichmentImagesJob::class, fn (PersistEnrichmentImagesJob $j): bool => $j->productId === $product->id
            && $j->tenantId === $product->tenant_id
            && $j->images !== []);
    }

    public function test_apply_catalog_hit_without_images_does_not_dispatch_persist_job(): void
    {
        Bus::fake();

        [$product, $catalog] = $this->makeProductAndCatalogWithImages([]);

        $this->service->applyCatalogHit($product, $catalog);

        Bus::assertNotDispatched(PersistEnrichmentImagesJob::class);
    }

    /**
     * @param  list<array{url: ?string, thumbnail: ?string, type: ?string}>  $images
     * @return array{0: Product, 1: CatalogProductDTO}
     */
    private function makeProductAndCatalogWithImages(array $images): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Local Product',
            'barcode' => '3017620422003',
            'platform_product_id' => 'platform-product-001',
        ]);

        $catalog = new CatalogProductDTO(
            platformProductId: 'platform-product-001',
            barcode: '3017620422003',
            name: 'Catalog Cream',
            brand: 'La Roche-Posay',
            description: 'Hydrating care',
            classification: ['category' => 'cosmetic'],
            ingredients: [],
            images: $images,
            confidenceScore: 96,
            enrichmentTier: 'catalog',
        );

        return [$product, $catalog];
    }
}

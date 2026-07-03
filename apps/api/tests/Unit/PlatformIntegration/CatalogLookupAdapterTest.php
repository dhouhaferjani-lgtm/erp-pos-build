<?php

declare(strict_types=1);

namespace Tests\Unit\PlatformIntegration;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Exceptions\PlatformCatalogUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class CatalogLookupAdapterTest extends TestCase
{
    private const TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const COMPANY_ID = '00000000-0000-0000-0000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::flush();
    }

    public function test_lookup_catalog_product_maps_found_lookup_to_shared_dto(): void
    {
        $service = $this->makeService();

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => 'prod-catalog-001',
                    'barcode' => '3017620422003',
                    'name' => 'Catalog Cream',
                    'brand' => 'La Roche-Posay',
                    'description' => 'Hydrating care',
                    'classification' => ['category' => 'cosmetic'],
                    'ingredients' => [
                        ['name' => 'Aqua', 'position' => 1],
                    ],
                    'images' => [
                        ['url' => 'https://example.test/front.jpg', 'thumbnail' => null, 'type' => 'front'],
                    ],
                    'confidence_score' => 96,
                    'enrichment_tier' => 'catalog',
                ],
            ]),
        ]);

        $catalog = $service->lookupCatalogProduct('3017620422003', 'parapharmacy');

        $this->assertNotNull($catalog);
        $this->assertSame('prod-catalog-001', $catalog->platformProductId);
        $this->assertSame('3017620422003', $catalog->barcode);
        $this->assertSame('Catalog Cream', $catalog->name);
        $this->assertSame('La Roche-Posay', $catalog->brand);
        $this->assertSame('Hydrating care', $catalog->description);
        $this->assertSame(['category' => 'cosmetic'], $catalog->classification);
        $this->assertSame([['name' => 'Aqua', 'position' => 1]], $catalog->ingredients);
        $this->assertSame([['url' => 'https://example.test/front.jpg', 'thumbnail' => null, 'type' => 'front']], $catalog->images);
        $this->assertSame(96, $catalog->confidenceScore);
        $this->assertSame('catalog', $catalog->enrichmentTier);
    }

    public function test_lookup_catalog_product_returns_null_on_not_found(): void
    {
        $service = $this->makeService();

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
                'tracking_id' => 'trk-001',
            ]),
        ]);

        $this->assertNull($service->lookupCatalogProduct('3017620422003', 'parapharmacy'));
    }

    public function test_lookup_catalog_product_returns_null_on_permanent_error(): void
    {
        $service = $this->makeService();

        // invalid_barcode is permanent — "cannot confirm", not "try again later"
        $this->assertNull($service->lookupCatalogProduct('!!!', 'parapharmacy'));
    }

    public function test_lookup_catalog_product_throws_on_transient_platform_failure(): void
    {
        $service = $this->makeService();

        Http::fake([
            'platform.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(PlatformCatalogUnavailableException::class);

        $service->lookupCatalogProduct('3017620422003', 'parapharmacy');
    }

    private function makeService(): BarcodeLookupService
    {
        $tenant = Mockery::mock(Tenant::class);
        $tenant->allows('__get')->with('id')->andReturn(self::TENANT_ID);

        $companyContext = Mockery::mock(CompanyContext::class);
        $companyContext->allows('requireTenantId')->andReturn(self::TENANT_ID);
        $companyContext->allows('requireCompanyId')->andReturn(self::COMPANY_ID);

        return new BarcodeLookupService(
            new PlatformHttpClient($companyContext),
            $companyContext,
            new BarcodeNormalizer,
        );
    }
}

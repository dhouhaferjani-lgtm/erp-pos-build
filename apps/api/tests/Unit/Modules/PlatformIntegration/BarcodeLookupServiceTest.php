<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Product\Application\Services\BrandResolutionService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Tests for the refactored BarcodeLookupService that calls the universal
 * POST /products/lookup endpoint instead of the automotive-specific one.
 */
class BarcodeLookupServiceTest extends TestCase
{
    private BarcodeLookupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::flush();

    }

    public function test_lookup_found_returns_platform_product_data(): void
    {
        $barcode = '3017620422003';
        $this->buildServiceWithVertical(Vertical::Mechanic);

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => 'prod-001',
                    'barcode' => $barcode,
                    'name' => 'Test Product',
                    'brand' => 'TestBrand',
                    'description' => 'A test product',
                    'classification' => ['category' => 'auto-parts'],
                    'ingredients' => [],
                    'images' => [['url' => 'https://example.com/img.jpg', 'thumbnail' => null, 'type' => 'main']],
                    'confidence_score' => 95,
                    'enrichment_tier' => 'full',
                ],
            ]),
        ]);

        $result = $this->service->lookup($barcode);

        $this->assertSame('found', $result->status);
        $this->assertSame($barcode, $result->barcode);
        $this->assertInstanceOf(PlatformProductData::class, $result->product);
        $this->assertSame('prod-001', $result->product->id);
        $this->assertSame('Test Product', $result->product->name);
        $this->assertSame('TestBrand', $result->product->brand);
        $this->assertNull($result->trackingId);
        $this->assertNull($result->errorReason);

        // Verify suggestedProduct shape
        $this->assertNotNull($result->suggestedProduct);
        $this->assertSame('Test Product', $result->suggestedProduct['name']);
        $this->assertSame($barcode, $result->suggestedProduct['barcode']);
        $this->assertSame('TestBrand', $result->suggestedProduct['brand']);
        $this->assertSame('prod-001', $result->suggestedProduct['platform_product_id']);
    }

    public function test_lookup_not_found_returns_tracking_id(): void
    {
        $barcode = '3017620422003';
        $this->buildServiceWithVertical(Vertical::Mechanic);

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
                'tracking_id' => 'trk-abc-123',
            ]),
        ]);

        $result = $this->service->lookup($barcode);

        $this->assertSame('not_found', $result->status);
        $this->assertSame($barcode, $result->barcode);
        $this->assertNull($result->product);
        $this->assertSame('trk-abc-123', $result->trackingId);
        $this->assertNull($result->suggestedProduct);
        $this->assertNull($result->errorReason);
    }

    public function test_lookup_returns_error_for_unsupported_vertical(): void
    {
        // Retail has no platform vertical mapping (returns null)
        $this->buildServiceWithVertical(Vertical::Retail);

        $result = $this->service->lookup('3017620422003');

        $this->assertSame('error', $result->status);
        $this->assertSame('vertical_not_supported', $result->errorReason);
    }

    public function test_lookup_normalizes_upc12_to_ean13(): void
    {
        $upc12 = '012345678905';
        $expectedEan13 = '0012345678905';

        $this->buildServiceWithVertical(Vertical::Mechanic);

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
            ]),
        ]);

        $result = $this->service->lookup($upc12);

        $this->assertSame($expectedEan13, $result->barcode);

        Http::assertSent(function ($request) use ($expectedEan13) {
            $body = $request->data();

            return $body['barcode'] === $expectedEan13;
        });
    }

    public function test_lookup_validates_ean13_check_digit(): void
    {
        // Valid EAN-13: 3017620422003 (check digit 3 is correct)
        // Invalid: change last digit to 4
        $this->buildServiceWithVertical(Vertical::Mechanic);

        $result = $this->service->lookup('3017620422004');

        $this->assertSame('error', $result->status);
        $this->assertSame('invalid_barcode', $result->errorReason);
    }

    public function test_lookup_uses_cache(): void
    {
        $barcode = '3017620422003';
        $this->buildServiceWithVertical(Vertical::Mechanic);

        // Pre-populate cache
        $cachedResult = BarcodeLookupResultData::notFound($barcode, 'trk-cached');
        Cache::put('platform:lookup:v2:automotive:'.$barcode, $cachedResult, 3600);

        Http::fake(); // nothing should be sent

        $result = $this->service->lookup($barcode);

        $this->assertSame('not_found', $result->status);
        $this->assertSame('trk-cached', $result->trackingId);

        Http::assertNothingSent();
    }

    public function test_lookup_returns_error_on_circuit_open(): void
    {
        $this->buildServiceWithVertical(Vertical::Mechanic);

        // Open the circuit breaker
        Cache::put('platform:circuit_breaker', true, 30);

        $result = $this->service->lookup('3017620422003');

        $this->assertSame('error', $result->status);
        $this->assertSame('platform_unavailable', $result->errorReason);
    }

    /**
     * Build the service with a real PlatformHttpClient (uses Http::fake)
     * and a mocked CompanyContext returning the given vertical.
     */
    private function buildServiceWithVertical(Vertical $vertical): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $tenant->shouldReceive('getAttribute')->with('vertical')->andReturn($vertical);
        $tenant->allows('__get')->with('vertical')->andReturn($vertical);

        $company = Mockery::mock(Company::class);
        $company->shouldReceive('getAttribute')->with('tenant')->andReturn($tenant);
        $company->allows('__get')->with('tenant')->andReturn($tenant);

        $companyContext = Mockery::mock(CompanyContext::class);
        $companyContext->allows('requireCompany')->andReturn($company);
        // PlatformHttpClient requires both for X-Tenant-Id + X-Company-Id
        // headers (api.platform-integration cluster, mandatory fail-loud).
        $companyContext->allows('requireTenantId')->andReturn('00000000-0000-0000-0000-000000000001');
        $companyContext->allows('requireCompanyId')->andReturn('00000000-0000-0000-0000-000000000002');

        $this->service = new BarcodeLookupService(
            new PlatformHttpClient($companyContext),
            $companyContext,
            new BrandResolutionService,
            new BarcodeNormalizer,
        );
    }
}

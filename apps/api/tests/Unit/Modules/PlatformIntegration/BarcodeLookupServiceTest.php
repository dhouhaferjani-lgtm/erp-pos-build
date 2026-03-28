<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BarcodeLookupServiceTest extends TestCase
{
    private PlatformHttpClient&MockInterface $platformClient;

    private CompanyContext&MockInterface $companyContext;

    private BarcodeLookupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformClient = Mockery::mock(PlatformHttpClient::class);
        $this->companyContext = Mockery::mock(CompanyContext::class);

        $this->service = new BarcodeLookupService(
            $this->platformClient,
            $this->companyContext,
        );
    }

    public function test_lookup_found_returns_platform_product_data(): void
    {
        $barcode = '3017620422003';
        $this->mockCompanyContextWithVertical('automotive');
        $this->platformClient->allows('isCircuitOpen')->andReturnFalse();

        $this->platformClient->expects('postRaw')
            ->with('/api/v1/products/lookup', [
                'barcode' => $barcode,
                'vertical' => 'automotive',
            ])
            ->andReturn([
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
            ]);

        Cache::shouldReceive('get')->once()->andReturnNull();
        Cache::shouldReceive('put')->once();

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
        $this->mockCompanyContextWithVertical('automotive');
        $this->platformClient->allows('isCircuitOpen')->andReturnFalse();

        $this->platformClient->expects('postRaw')
            ->with('/api/v1/products/lookup', [
                'barcode' => $barcode,
                'vertical' => 'automotive',
            ])
            ->andReturn([
                'status' => 'not_found',
                'tracking_id' => 'trk-abc-123',
            ]);

        Cache::shouldReceive('get')->once()->andReturnNull();
        Cache::shouldReceive('put')->once();

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
        $barcode = '3017620422003';

        // Mock a vertical that returns null for platformVertical()
        $this->mockCompanyContextWithVertical(null);

        $result = $this->service->lookup($barcode);

        $this->assertSame('error', $result->status);
        $this->assertSame($barcode, $result->barcode);
        $this->assertSame('vertical_not_supported', $result->errorReason);
    }

    public function test_lookup_normalizes_upc12_to_ean13(): void
    {
        // UPC-12: 012345678905 -> EAN-13: 0012345678905
        $upc12 = '012345678905';
        $expectedEan13 = '0012345678905';

        $this->mockCompanyContextWithVertical('automotive');
        $this->platformClient->allows('isCircuitOpen')->andReturnFalse();

        $this->platformClient->expects('postRaw')
            ->with('/api/v1/products/lookup', [
                'barcode' => $expectedEan13,
                'vertical' => 'automotive',
            ])
            ->andReturn([
                'status' => 'not_found',
            ]);

        Cache::shouldReceive('get')->once()->andReturnNull();
        Cache::shouldReceive('put')->once();

        $result = $this->service->lookup($upc12);

        $this->assertSame($expectedEan13, $result->barcode);
    }

    public function test_lookup_validates_ean13_check_digit(): void
    {
        // Valid EAN-13: 3017620422003 (check digit 3 is correct)
        // Invalid: change last digit to 4
        $invalidEan13 = '3017620422004';

        $result = $this->service->lookup($invalidEan13);

        $this->assertSame('error', $result->status);
        $this->assertSame('invalid_barcode', $result->errorReason);
    }

    public function test_lookup_uses_cache(): void
    {
        $barcode = '3017620422003';
        $this->mockCompanyContextWithVertical('automotive');

        $cachedResult = BarcodeLookupResultData::notFound($barcode, 'trk-cached');

        Cache::shouldReceive('get')
            ->once()
            ->with('platform:lookup:automotive:' . $barcode)
            ->andReturn($cachedResult);

        // Platform client should NOT be called
        $this->platformClient->shouldNotReceive('postRaw');
        $this->platformClient->shouldNotReceive('isCircuitOpen');

        $result = $this->service->lookup($barcode);

        $this->assertSame('not_found', $result->status);
        $this->assertSame('trk-cached', $result->trackingId);
    }

    public function test_lookup_returns_error_on_circuit_open(): void
    {
        $barcode = '3017620422003';
        $this->mockCompanyContextWithVertical('automotive');

        Cache::shouldReceive('get')->once()->andReturnNull();

        $this->platformClient->allows('isCircuitOpen')->andReturnTrue();
        $this->platformClient->shouldNotReceive('postRaw');

        $result = $this->service->lookup($barcode);

        $this->assertSame('error', $result->status);
        $this->assertSame('platform_unavailable', $result->errorReason);
    }

    private function mockCompanyContextWithVertical(?string $platformVertical): void
    {
        // Use a real Vertical enum value that maps to the desired platformVertical
        $vertical = $this->resolveVerticalForPlatform($platformVertical);

        $tenant = Mockery::mock(Tenant::class);
        $tenant->shouldReceive('getAttribute')->with('vertical')->andReturn($vertical);
        $tenant->shouldReceive('__get')->with('vertical')->andReturn($vertical);

        $company = Mockery::mock(Company::class);
        $company->shouldReceive('getAttribute')->with('tenant')->andReturn($tenant);
        $company->shouldReceive('__get')->with('tenant')->andReturn($tenant);

        $this->companyContext->allows('requireCompany')->andReturn($company);
    }

    private function resolveVerticalForPlatform(?string $platformVertical): Vertical
    {
        // Map platform vertical strings to real enum values
        return match ($platformVertical) {
            'automotive' => Vertical::Mechanic,
            'parapharmacy' => Vertical::Parapharmacy,
            'pharmacy' => Vertical::Pharmacy,
            // For null (unsupported), use Retail which has no platform mapping
            null => Vertical::Retail,
            default => Vertical::Retail,
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Product\Application\Services\BrandResolutionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PlatformPushGateReadPathsTest extends TestCase
{
    public function test_barcode_lookup_still_calls_platform_when_platform_push_is_disabled(): void
    {
        config([
            'services.platform.url' => 'https://platform.test',
            'services.platform.api_key' => 'test-key',
            'services.platform.push_enabled' => false,
        ]);
        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
                'tracking_id' => 'trk-read-001',
            ]),
        ]);

        $companyContext = new PlatformPushGateReadCompanyContext;
        $service = new BarcodeLookupService(
            new PlatformHttpClient($companyContext),
            $companyContext,
            new BrandResolutionService,
            new BarcodeNormalizer,
        );

        $result = $service->lookup('3017620422003', 'automotive');

        $this->assertSame('not_found', $result->status);
        $this->assertSame('trk-read-001', $result->trackingId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v1/products/lookup'));
    }
}

final class PlatformPushGateReadCompanyContext extends CompanyContext
{
    public function requireTenantId(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    public function requireCompanyId(): string
    {
        return '00000000-0000-0000-0000-000000000002';
    }
}

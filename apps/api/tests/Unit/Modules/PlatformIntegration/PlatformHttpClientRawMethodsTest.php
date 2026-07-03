<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PlatformHttpClientRawMethodsTest extends TestCase
{
    private PlatformHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        // CompanyContext stub — api.platform-integration cluster made
        // X-Tenant-Id + X-Company-Id mandatory on every outbound. These
        // raw-method tests assert response-shape behavior, NOT header
        // content; the stub satisfies the new fail-loud contract.
        $companyContext = Mockery::mock(CompanyContext::class);
        $companyContext->allows('requireTenantId')->andReturn('00000000-0000-0000-0000-000000000001');
        $companyContext->allows('requireCompanyId')->andReturn('00000000-0000-0000-0000-000000000002');

        $this->client = new PlatformHttpClient($companyContext);
        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::forget('platform:circuit_breaker');
        Cache::forget('platform:circuit_failures');
    }

    public function test_post_raw_returns_full_response_body(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => ['id' => 'abc-123', 'name' => 'Test Product'],
            ]),
        ]);

        $result = $this->client->postRaw('/api/v1/products/lookup', ['barcode' => '123']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('found', $result['status']);
        $this->assertArrayHasKey('product', $result);
    }

    public function test_get_raw_returns_full_response_body(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'track-123',
                'status' => 'enriching',
                'enriched_data' => null,
            ]),
        ]);

        $result = $this->client->getRaw('/api/v1/products/lookup-status/track-123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tracking_id', $result);
        $this->assertEquals('enriching', $result['status']);
    }

    public function test_post_raw_returns_null_on_404(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([], 404),
        ]);

        $result = $this->client->postRaw('/api/v1/products/lookup', ['barcode' => '123']);

        $this->assertNull($result);
    }

    public function test_post_raw_throws_on_circuit_open(): void
    {
        Cache::put('platform:circuit_breaker', true, 30);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform circuit breaker is open');

        $this->client->postRaw('/api/v1/products/lookup', []);
    }

    public function test_post_raw_with_custom_headers(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['tracking_id' => 'abc']),
        ]);

        $result = $this->client->postRaw(
            '/api/v1/products/submit',
            ['name' => 'Test'],
            ['Idempotency-Key' => 'idem-123']
        );

        $this->assertIsArray($result);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Idempotency-Key', 'idem-123');
        });
    }

    public function test_post_with_status_returns_status_and_body_on_success(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['data' => ['brand_id' => 'x']], 200),
        ]);

        $response = $this->client->postWithStatus('/api/v1/brands/abc/external-mapping', ['external_brand_id' => 'b1']);

        $this->assertSame(200, $response->status);
        $this->assertSame(['data' => ['brand_id' => 'x']], $response->body);
    }

    public function test_post_with_status_returns_422_without_tripping_circuit(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'already mapped']], 422),
        ]);

        $response = $this->client->postWithStatus('/x', []);

        $this->assertSame(422, $response->status);
        $this->assertSame('VALIDATION_ERROR', $response->body['error']['code'] ?? null);
        $this->assertFalse($this->client->isCircuitOpen());
        $this->assertNull(Cache::get('platform:circuit_failures'));
    }

    public function test_post_with_status_returns_404_without_tripping_circuit(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => ['code' => 'RESOURCE_NOT_FOUND']], 404),
        ]);

        $response = $this->client->postWithStatus('/x', []);

        $this->assertSame(404, $response->status);
        $this->assertNull(Cache::get('platform:circuit_failures'));
    }

    public function test_post_with_status_records_failure_on_5xx(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => 'boom'], 503),
        ]);

        $response = $this->client->postWithStatus('/x', []);

        $this->assertSame(503, $response->status);
        $this->assertNotNull(Cache::get('platform:circuit_failures'));
    }

    public function test_post_with_status_throws_and_records_failure_on_connection_error(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('unreachable'));

        $this->expectException(ConnectionException::class);

        try {
            $this->client->postWithStatus('/x', []);
        } finally {
            $this->assertNotNull(Cache::get('platform:circuit_failures'));
        }
    }
}

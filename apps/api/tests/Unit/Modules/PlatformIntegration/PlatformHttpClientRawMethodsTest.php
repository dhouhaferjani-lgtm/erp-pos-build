<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformHttpClientRawMethodsTest extends TestCase
{
    private PlatformHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new PlatformHttpClient();
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
}

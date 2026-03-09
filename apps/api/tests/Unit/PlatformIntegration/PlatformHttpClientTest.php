<?php

declare(strict_types=1);

namespace Tests\Unit\PlatformIntegration;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);

        // Ensure circuit breaker is reset
        Cache::forget('platform:circuit_breaker');
        Cache::forget('platform:circuit_failures');
    }

    /** @test */
    public function it_opens_circuit_after_three_failures(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $client = new PlatformHttpClient;

        // Each call triggers retry (3 attempts) then throws RequestException.
        // PlatformHttpClient catches it, records a failure, and re-throws.
        // After CIRCUIT_FAILURE_THRESHOLD (3) recorded failures, circuit opens.
        for ($i = 0; $i < 3; $i++) {
            try {
                $client->get('/api/v1/automotive/test');
            } catch (\Throwable $e) {
                // Expected: RequestException from HTTP retry exhaustion
            }
        }

        // Circuit should now be open
        $this->assertTrue($client->isCircuitOpen());

        // 4th call should throw due to open circuit (before making HTTP request)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform circuit breaker is open');
        $client->get('/api/v1/automotive/test');
    }

    /** @test */
    public function it_retries_on_429_response(): void
    {
        Http::fake([
            'platform.test/*' => Http::sequence()
                ->push(['error' => 'Too Many Requests'], 429)
                ->push(['data' => ['id' => 'test-123', 'name' => 'Test']], 200),
        ]);

        $client = new PlatformHttpClient;
        $result = $client->get('/api/v1/automotive/test');

        $this->assertNotNull($result);
        $this->assertSame('test-123', $result['id']);
    }

    /** @test */
    public function it_returns_response_on_success(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'data' => [
                    'id' => 'art-001',
                    'article_number' => '0986494123',
                    'name' => 'Brake Pad Set',
                    'supplier_brand' => 'Bosch',
                ],
            ], 200),
        ]);

        $client = new PlatformHttpClient;
        $result = $client->get('/api/v1/automotive/articles/art-001');

        $this->assertNotNull($result);
        $this->assertSame('art-001', $result['id']);
        $this->assertSame('0986494123', $result['article_number']);
        $this->assertSame('Bosch', $result['supplier_brand']);
    }
}

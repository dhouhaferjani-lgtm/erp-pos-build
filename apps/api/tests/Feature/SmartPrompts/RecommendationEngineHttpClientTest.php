<?php

declare(strict_types=1);

namespace Tests\Feature\SmartPrompts;

use App\Modules\SmartPrompts\Application\DTOs\RecommendationRequestData;
use App\Modules\SmartPrompts\Domain\Enums\RecommendationContext;
use App\Modules\SmartPrompts\Infrastructure\Http\RecommendationEngineHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationEngineHttpClientTest extends TestCase
{
    private RecommendationEngineHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new RecommendationEngineHttpClient(
            baseUrl: 'http://erp-ml:8002',
            timeout: 5,
            connectTimeout: 3,
            retryTimes: 2,
            retryDelay: 100,
            circuitBreakerThreshold: 3,
            circuitBreakerCooldown: 30,
        );

        Cache::flush();
    }

    public function test_returns_recommendations_on_success(): void
    {
        Http::fake([
            'erp-ml:8002/api/v1/recommendations/' => Http::response([
                'recommendations' => [
                    [
                        'product_id' => '550e8400-e29b-41d4-a716-446655440001',
                        'product_name' => 'Test Product',
                        'score' => 0.85,
                        'reason' => 'Complete your routine',
                        'strategy' => 'routine_completion',
                    ],
                ],
                'context' => 'cart',
                'generated_at' => '2026-03-27T12:00:00Z',
            ]),
        ]);

        $request = new RecommendationRequestData(
            productIds: ['550e8400-e29b-41d4-a716-446655440000'],
            context: RecommendationContext::Cart,
        );

        $result = $this->client->getRecommendations($request, 'tenant-id', 'company-id');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['recommendations']);
        $this->assertEquals('Test Product', $result['recommendations'][0]['product_name']);
    }

    public function test_returns_null_on_server_error(): void
    {
        Http::fake([
            'erp-ml:8002/api/v1/recommendations/' => Http::sequence()
                ->push([], 500)
                ->push([], 500)
                ->push([], 500),
        ]);

        $request = new RecommendationRequestData(
            productIds: ['550e8400-e29b-41d4-a716-446655440000'],
            context: RecommendationContext::Cart,
        );

        $result = $this->client->getRecommendations($request, 'tenant-id', 'company-id');

        $this->assertNull($result);
    }

    public function test_circuit_breaker_opens_after_threshold(): void
    {
        Http::fake([
            'erp-ml:8002/api/v1/recommendations/' => Http::sequence()
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([], 500),
        ]);

        $request = new RecommendationRequestData(
            productIds: ['550e8400-e29b-41d4-a716-446655440000'],
            context: RecommendationContext::Cart,
        );

        for ($i = 0; $i < 3; $i++) {
            $this->client->getRecommendations($request, 'tenant-id', 'company-id');
        }

        $this->assertTrue($this->client->isCircuitOpen());
    }

    public function test_returns_null_when_circuit_open(): void
    {
        Http::fake();

        Cache::put('recommendation_engine:circuit_breaker', true, 30);

        $request = new RecommendationRequestData(
            productIds: ['550e8400-e29b-41d4-a716-446655440000'],
            context: RecommendationContext::Cart,
        );

        $result = $this->client->getRecommendations($request, 'tenant-id', 'company-id');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_forwards_tenant_headers(): void
    {
        Http::fake([
            'erp-ml:8002/api/v1/recommendations/' => Http::response([
                'recommendations' => [],
                'context' => 'cart',
                'generated_at' => '2026-03-27T12:00:00Z',
            ]),
        ]);

        $request = new RecommendationRequestData(
            productIds: ['550e8400-e29b-41d4-a716-446655440000'],
            context: RecommendationContext::Cart,
        );

        $this->client->getRecommendations($request, 'my-tenant-id', 'my-company-id');

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Tenant-Id', 'my-tenant-id')
                && $request->hasHeader('X-Company-Id', 'my-company-id');
        });
    }
}

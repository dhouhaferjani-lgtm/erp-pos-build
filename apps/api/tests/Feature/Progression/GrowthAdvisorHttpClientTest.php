<?php

declare(strict_types=1);

namespace Tests\Feature\Progression;

use App\Modules\Progression\Infrastructure\Http\GrowthAdvisorHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GrowthAdvisorHttpClientTest extends TestCase
{
    private GrowthAdvisorHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->client = new GrowthAdvisorHttpClient(
            baseUrl: 'http://localhost:8004',
            timeout: 10,
            connectTimeout: 5,
            retryTimes: 2,
            retryDelay: 100,
            circuitBreakerThreshold: 3,
            circuitBreakerCooldown: 30,
        );
    }

    public function test_get_company_profile_returns_data_on_success(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/comp-1' => Http::response([
                'data' => ['id' => 'comp-1', 'current_stage' => 'stabilize'],
            ]),
        ]);

        $result = $this->client->getCompanyProfile('comp-1');

        $this->assertNotNull($result);
        $this->assertSame('comp-1', $result['id']);
        $this->assertSame('stabilize', $result['current_stage']);
    }

    public function test_get_company_profile_returns_null_on_404(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/unknown' => Http::response(null, 404),
        ]);

        $result = $this->client->getCompanyProfile('unknown');

        $this->assertNull($result);
    }

    public function test_circuit_opens_after_threshold_failures(): void
    {
        Http::fake([
            'localhost:8004/*' => Http::response(null, 500),
        ]);

        // Trigger 3 failures
        $this->client->getCompanyProfile('comp-1');
        $this->client->getCompanyProfile('comp-1');
        $this->client->getCompanyProfile('comp-1');

        $this->assertTrue($this->client->isCircuitOpen());
    }

    public function test_circuit_open_returns_null_without_http_call(): void
    {
        // Open the circuit manually via cache
        Cache::put('growth_advisor:circuit_breaker', true, 30);

        Http::fake(); // No requests should be made

        $result = $this->client->getCompanyProfile('comp-1');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_register_company_sends_post_request(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies' => Http::response([
                'data' => ['id' => 'comp-new', 'current_stage' => 'launch'],
            ]),
        ]);

        $result = $this->client->registerCompany([
            'company_id' => 'comp-new',
            'tenant_id' => 'tenant-1',
            'vertical' => 'coffee_shop',
            'country' => 'TN',
            'product' => 'izipos',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('comp-new', $result['id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v1/companies')
            && $request['company_id'] === 'comp-new');
    }

    public function test_get_milestones_returns_array(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/comp-1/milestones' => Http::response([
                'data' => [
                    ['id' => 'ms-1', 'name' => 'First sale', 'status' => 'completed'],
                    ['id' => 'ms-2', 'name' => 'Add products', 'status' => 'pending'],
                ],
            ]),
        ]);

        $result = $this->client->getMilestones('comp-1');

        $this->assertCount(2, $result);
        $this->assertSame('ms-1', $result[0]['id']);
    }

    public function test_get_milestones_returns_empty_array_on_failure(): void
    {
        Http::fake([
            'localhost:8004/*' => Http::response(null, 500),
        ]);

        $result = $this->client->getMilestones('comp-1');

        $this->assertSame([], $result);
    }

    public function test_activate_module_sends_post(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/comp-1/modules/mod-inv/activate' => Http::response([
                'data' => ['id' => 'mod-inv', 'status' => 'active'],
            ]),
        ]);

        $result = $this->client->activateModule('comp-1', 'mod-inv');

        $this->assertNotNull($result);
        $this->assertSame('active', $result['status']);
    }

    public function test_accept_recommendation_sends_post(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/comp-1/recommendations/rec-1/accept' => Http::response([
                'data' => ['id' => 'rec-1', 'status' => 'accepted'],
            ]),
        ]);

        $result = $this->client->acceptRecommendation('comp-1', 'rec-1');

        $this->assertNotNull($result);
        $this->assertSame('accepted', $result['status']);
    }

    public function test_dismiss_recommendation_sends_post(): void
    {
        Http::fake([
            'localhost:8004/api/v1/companies/comp-1/recommendations/rec-1/dismiss' => Http::response([
                'data' => ['id' => 'rec-1', 'status' => 'dismissed'],
            ]),
        ]);

        $result = $this->client->dismissRecommendation('comp-1', 'rec-1');

        $this->assertNotNull($result);
        $this->assertSame('dismissed', $result['status']);
    }
}

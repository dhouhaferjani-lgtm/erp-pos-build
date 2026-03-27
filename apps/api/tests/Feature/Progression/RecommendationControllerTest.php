<?php

declare(strict_types=1);

namespace Tests\Feature\Progression;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RecommendationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private GrowthAdvisorClientInterface $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->mockClient = $this->createMock(GrowthAdvisorClientInterface::class);
        $this->app->instance(GrowthAdvisorClientInterface::class, $this->mockClient);
    }

    public function test_index_returns_sorted_recommendations(): void
    {
        $this->mockClient->method('getRecommendations')
            ->willReturn([
                ['id' => 'rec-1', 'title' => 'Low pri', 'description' => 'D', 'priority' => 'low', 'action_label' => '', 'action_route' => '', 'status' => 'pending'],
                ['id' => 'rec-2', 'title' => 'High pri', 'description' => 'D', 'priority' => 'high', 'action_label' => 'Do it', 'action_route' => '/products', 'status' => 'pending'],
            ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/progression/recommendations');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.priority', 'high')
            ->assertJsonPath('data.1.priority', 'low');
    }

    public function test_accept_returns_updated_recommendation(): void
    {
        $this->mockClient->method('acceptRecommendation')
            ->willReturn(['id' => 'rec-1', 'title' => 'Track costs', 'description' => 'D', 'priority' => 'high', 'action_label' => '', 'action_route' => '', 'status' => 'accepted']);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/progression/recommendations/rec-1/accept');

        $response->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_dismiss_returns_updated_recommendation(): void
    {
        $this->mockClient->method('dismissRecommendation')
            ->willReturn(['id' => 'rec-1', 'title' => 'Track costs', 'description' => 'D', 'priority' => 'high', 'action_label' => '', 'action_route' => '', 'status' => 'dismissed']);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/progression/recommendations/rec-1/dismiss');

        $response->assertOk()
            ->assertJsonPath('data.status', 'dismissed');
    }
}

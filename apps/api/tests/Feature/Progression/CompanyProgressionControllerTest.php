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

final class CompanyProgressionControllerTest extends TestCase
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

    public function test_show_returns_company_profile(): void
    {
        // ProgressionService asserts the upstream response's `id` echo
        // matches the request's expected company id (api.module-gating
        // cluster). Mock response must echo the real company id, not a
        // hardcoded placeholder, or the response-id guard fail-louds.
        $this->mockClient->method('getCompanyProfile')
            ->willReturn([
                'id' => $this->company->id,
                'tenant_id' => $this->tenant->id,
                'vertical' => 'coffee_shop',
                'country' => 'TN',
                'current_stage' => 'stabilize',
                'stage_progress_percent' => 65,
                'total_milestones' => 8,
                'completed_milestones' => 5,
            ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/progression/profile');

        $response->assertOk()
            ->assertJsonPath('data.current_stage', 'stabilize')
            ->assertJsonPath('data.stage_progress_percent', 65);
    }

    public function test_show_returns_503_when_service_unavailable(): void
    {
        $this->mockClient->method('getCompanyProfile')
            ->willReturn(null);
        $this->mockClient->method('isCircuitOpen')
            ->willReturn(true);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/progression/profile');

        $response->assertServiceUnavailable();
    }

    public function test_milestones_returns_milestone_list(): void
    {
        $this->mockClient->method('getMilestones')
            ->willReturn([
                ['id' => 'ms-1', 'name' => 'First sale', 'description' => 'Make a sale', 'status' => 'completed', 'progress_percent' => 100, 'stage' => 'launch'],
            ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/progression/milestones');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_register_creates_company_in_growth_advisor(): void
    {
        // Response-id contract (api.module-gating cluster): mock must echo
        // both `id` (matching the controller-resolved companyId) and
        // `tenant_id` (matching the resolved tenantId), or the
        // assertResponse{Company,Tenant}Matches guards fail-loud.
        $this->mockClient->method('registerCompany')
            ->willReturn([
                'id' => $this->company->id,
                'tenant_id' => $this->tenant->id,
                'vertical' => 'coffee_shop',
                'country' => 'TN',
                'current_stage' => 'launch',
                'stage_progress_percent' => 0,
                'total_milestones' => 8,
                'completed_milestones' => 0,
            ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/progression/register');

        $response->assertCreated()
            ->assertJsonPath('data.current_stage', 'launch');
    }
}

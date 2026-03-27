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

final class ModuleReadinessControllerTest extends TestCase
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

    public function test_index_returns_module_list(): void
    {
        $this->mockClient->method('getModules')
            ->willReturn([
                ['id' => 'mod-pos', 'name' => 'POS', 'description' => 'Point of Sale', 'icon' => 'shopping-cart', 'status' => 'active', 'readiness_percent' => 100, 'stage' => 'launch', 'discount_percent' => 0, 'requirements' => []],
                ['id' => 'mod-inv', 'name' => 'Inventory', 'description' => 'Track stock', 'icon' => 'package', 'status' => 'ready', 'readiness_percent' => 92, 'stage' => 'stabilize', 'discount_percent' => 15, 'requirements' => ['Track COGS']],
            ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/progression/modules');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_activate_returns_activated_module(): void
    {
        $this->mockClient->method('activateModule')
            ->willReturn(['id' => 'mod-inv', 'name' => 'Inventory', 'description' => 'Track stock', 'icon' => 'package', 'status' => 'active', 'readiness_percent' => 100, 'stage' => 'stabilize', 'discount_percent' => 15, 'requirements' => []]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/progression/modules/mod-inv/activate');

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_activate_returns_503_when_service_unavailable(): void
    {
        $this->mockClient->method('activateModule')
            ->willReturn(null);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/progression/modules/mod-inv/activate');

        $response->assertServiceUnavailable();
    }
}

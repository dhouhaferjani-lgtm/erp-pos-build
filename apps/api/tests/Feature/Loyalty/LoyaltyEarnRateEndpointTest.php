<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test for GET /api/v1/loyalty/earn-rate
 *
 * Verifies the endpoint returns the active Spend earning rule's reward_value,
 * null when no active program exists, and 403 when the Loyalty module is disabled.
 */
final class LoyaltyEarnRateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('loyalty.view', 'sanctum');
        $this->user->givePermissionTo('loyalty.view');
    }

    public function test_it_returns_the_active_spend_rate(): void
    {
        Sanctum::actingAs($this->user);

        $program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);

        EarningRule::create([
            'program_id' => $program->id,
            'name' => 'Base Spend Rule',
            'rule_type' => EarningRuleType::Spend,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'reward_value' => '2',
            'reward_type' => 'fixed',
        ]);

        $response = $this->getJson('/api/v1/loyalty/earn-rate');

        $response->assertOk();
        $response->assertJsonPath('data.rate', '2.0000');
    }

    public function test_it_returns_null_rate_when_no_active_program(): void
    {
        Sanctum::actingAs($this->user);

        // No program created — expect null rate
        $response = $this->getJson('/api/v1/loyalty/earn-rate');

        $response->assertOk();
        $response->assertJsonPath('data.rate', null);
    }

    public function test_it_403s_when_loyalty_module_disabled(): void
    {
        // Create a tenant without Loyalty in enabled_extras (mechanic vertical has no Loyalty)
        $tenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => [],
        ]);
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/loyalty/earn-rate');

        $response->assertForbidden();
    }
}

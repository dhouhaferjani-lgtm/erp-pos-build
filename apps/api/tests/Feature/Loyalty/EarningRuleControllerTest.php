<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class EarningRuleControllerTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private LoyaltyProgram $program;

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

        $this->permissions()->setPermissionsTeamId($this->tenant->id);
        Permission::firstOrCreate(['name' => 'loyalty.manage', 'guard_name' => 'sanctum']);
        $this->user->givePermissionTo('loyalty.manage');

        $this->program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'company_ids' => null,
            'name' => 'Launch Rewards',
            'program_type' => ProgramType::Points,
            'currency' => 'Points',
            'status' => ProgramStatus::Active,
        ]);
    }

    public function test_create_earning_rule_allows_empty_conditions_with_reward_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/loyalty/programs/{$this->program->id}/earning-rules", [
                'name' => 'One point per dinar',
                'rule_type' => 'spend',
                'priority' => 1,
                'conditions' => [],
                'reward_value' => '1.0000',
                'reward_type' => 'fixed',
                'max_earn_per_transaction' => null,
                'max_earn_per_day' => null,
                'start_date' => null,
                'end_date' => null,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reward_type', 'fixed');
        $response->assertJsonPath('data.conditions.min_purchase_amount', null);

        $this->assertDatabaseHas('earning_rules', [
            'program_id' => $this->program->id,
            'name' => 'One point per dinar',
            'reward_type' => 'fixed',
        ]);
    }

    public function test_create_earning_rule_requires_reward_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/loyalty/programs/{$this->program->id}/earning-rules", [
                'name' => 'Broken reward rule',
                'rule_type' => 'spend',
                'priority' => 1,
                'conditions' => [],
                'reward_value' => '1.0000',
            ]);

        $this->assertApiValidationErrors($response, ['reward_type']);
    }

    private function permissions(): PermissionRegistrar
    {
        return $this->app->make(PermissionRegistrar::class);
    }
}

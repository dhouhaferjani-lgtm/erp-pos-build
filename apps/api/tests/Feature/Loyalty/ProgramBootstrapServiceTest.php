<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\ProgramBootstrapService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProgramBootstrapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_active_program_with_default_spend_rule(): void
    {
        $tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $service = $this->app->make(ProgramBootstrapService::class);

        $program = $service->ensureActiveProgram($tenant->id, 'TND', 'Programme fidélité');

        $this->assertSame(ProgramStatus::Active, $program->status);
        $this->assertSame('TND', $program->currency);
        $rule = EarningRule::query()->where('program_id', $program->id)->sole();
        $this->assertSame(EarningRuleType::Spend, $rule->rule_type);
        $this->assertSame('1.0000', (string) $rule->reward_value); // decimal:4 cast — see SeedDefaultEarningRuleOnProgramActivatedTest
        $this->assertTrue($rule->is_active);
    }

    public function test_idempotent_when_active_program_exists(): void
    {
        $tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $existing = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenant->id, 'status' => ProgramStatus::Active,
        ]);
        $service = $this->app->make(ProgramBootstrapService::class);

        $program = $service->ensureActiveProgram($tenant->id, 'TND', 'Programme fidélité');

        $this->assertSame($existing->id, $program->id);
        $this->assertSame(1, LoyaltyProgram::query()->where('tenant_id', $tenant->id)->count());
        // Does NOT add a rule to a program the admin configured.
        $this->assertSame(0, EarningRule::query()->where('program_id', $existing->id)->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\ProgramManagementService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeedDefaultEarningRuleOnProgramActivatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_seeds_a_single_spend_rule_on_a_fresh_program(): void
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => (string) Str::uuid(),
            'status' => ProgramStatus::Draft,
        ]);

        app(ProgramManagementService::class)->activateProgram($program->id);

        $rules = EarningRule::where('program_id', $program->id)->get();
        self::assertCount(1, $rules);
        self::assertSame(EarningRuleType::Spend, $rules->first()->rule_type);
        self::assertSame('1.0000', $rules->first()->reward_value); // decimal:4 cast
        self::assertTrue($rules->first()->is_active);
    }

    public function test_activation_does_not_seed_when_an_active_rule_already_exists(): void
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => (string) Str::uuid(),
            'status' => ProgramStatus::Draft,
        ]);
        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Item,
            'reward_value' => '5',
            'is_active' => true,
        ]);

        app(ProgramManagementService::class)->activateProgram($program->id);

        self::assertCount(1, EarningRule::where('program_id', $program->id)->get());
    }
}

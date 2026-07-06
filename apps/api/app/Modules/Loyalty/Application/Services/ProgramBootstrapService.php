<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent tenant loyalty bootstrap: guarantee one ACTIVE program with a
 * working default Spend rule. Used by launch seeders (ParapharmacySeeder and,
 * via inheritance, DemoPharmacySeeder); safe for future onboarding UX (PL-6).
 * Creates the row directly as Active (no ProgramActivated event) and seeds the
 * Spend rule explicitly — deterministic in seeder context, mirrors
 * SeedDefaultEarningRuleOnProgramActivated for admin-created programs.
 */
final readonly class ProgramBootstrapService
{
    public function __construct(
        private LoyaltyProgramRepositoryInterface $programRepository,
        private EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function ensureActiveProgram(string $tenantId, string $currency, string $name): LoyaltyProgram
    {
        $existing = $this->programRepository
            ->findByTenantAndStatus($tenantId, ProgramStatus::Active)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($tenantId, $currency, $name): LoyaltyProgram {
            $program = LoyaltyProgram::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'program_type' => ProgramType::Points,
                'status' => ProgramStatus::Active,
                'currency' => $currency,
            ]);

            $this->earningRuleRepository->save(new EarningRule([
                'program_id' => $program->id,
                'name' => 'Points per dinar',
                'rule_type' => EarningRuleType::Spend,
                'priority' => 1,
                'is_active' => true,
                'conditions' => [],
                'reward_value' => '1',
                'reward_type' => 'multiplier',
            ]));

            return $program;
        });
    }
}

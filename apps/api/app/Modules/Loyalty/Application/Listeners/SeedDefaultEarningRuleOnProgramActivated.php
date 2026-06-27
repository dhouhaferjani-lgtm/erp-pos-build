<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Listeners;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;

/**
 * On program activation, seed a default points-per-money-unit Spend rule
 * (1 TND = 1 point) so every purchase earns out of the box. No-op if the
 * program already has an active earn rule (the admin configured their own).
 */
final readonly class SeedDefaultEarningRuleOnProgramActivated
{
    public function __construct(
        private EarningRuleRepositoryInterface $earningRuleRepository,
    ) {}

    public function handle(ProgramActivated $event): void
    {
        $existing = $this->earningRuleRepository->findActiveByProgram($event->programId);
        if ($existing->isNotEmpty()) {
            return;
        }

        // EarningRule uses HasUuids — `id` is auto-generated, NOT fillable (Codex S4).
        $rule = new EarningRule([
            'program_id' => $event->programId,
            'name' => 'Points per dinar',
            'rule_type' => EarningRuleType::Spend,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'reward_value' => '1',
            'reward_type' => 'multiplier',
        ]);

        $this->earningRuleRepository->save($rule);
    }
}

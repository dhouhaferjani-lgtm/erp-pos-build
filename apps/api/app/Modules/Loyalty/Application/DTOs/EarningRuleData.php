<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EarningRuleData extends Data
{
    public function __construct(
        public string $id,
        public string $program_id,
        public string $name,
        public EarningRuleType $rule_type,
        public int $priority,
        public bool $is_active,
        public EarningConditionsData $conditions,
        public string $reward_value,
        public string $reward_type,
        public ?string $start_date,
        public ?string $end_date,
        public ?string $max_earn_per_transaction,
        public ?string $max_earn_per_day,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(EarningRule $rule): self
    {
        return new self(
            id: $rule->id,
            program_id: $rule->program_id,
            name: $rule->name,
            rule_type: $rule->rule_type,
            priority: $rule->priority,
            is_active: $rule->is_active,
            conditions: EarningConditionsData::from($rule->conditions),
            reward_value: (string) $rule->reward_value,
            reward_type: $rule->reward_type,
            start_date: $rule->start_date?->toIso8601String(),
            end_date: $rule->end_date?->toIso8601String(),
            max_earn_per_transaction: $rule->max_earn_per_transaction !== null ? (string) $rule->max_earn_per_transaction : null,
            max_earn_per_day: $rule->max_earn_per_day !== null ? (string) $rule->max_earn_per_day : null,
            created_at: $rule->created_at?->toIso8601String() ?? '',
            updated_at: $rule->updated_at?->toIso8601String(),
        );
    }
}

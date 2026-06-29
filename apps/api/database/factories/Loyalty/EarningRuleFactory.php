<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EarningRule> */
final class EarningRuleFactory extends Factory
{
    protected $model = EarningRule::class;

    public function definition(): array
    {
        return [
            'program_id' => $this->faker->uuid(),
            'name' => $this->faker->words(3, true).' Rule',
            'rule_type' => EarningRuleType::Spend,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'reward_value' => '1.0000',
            'reward_type' => 'fixed',
            'start_date' => null,
            'end_date' => null,
            'max_earn_per_transaction' => null,
            'max_earn_per_day' => null,
        ];
    }
}

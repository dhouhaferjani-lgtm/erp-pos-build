<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'code' => $this->faker->unique()->numerify('###'),
            'name' => $this->faker->words(2, true),
            'type' => AccountType::Asset,
            'is_active' => true,
            'is_system' => false,
            'balance' => '0.00',
        ];
    }

    public function asset(): static
    {
        return $this->state(fn () => ['type' => AccountType::Asset]);
    }

    public function liability(): static
    {
        return $this->state(fn () => ['type' => AccountType::Liability]);
    }

    public function revenue(): static
    {
        return $this->state(fn () => ['type' => AccountType::Revenue]);
    }

    public function expense(): static
    {
        return $this->state(fn () => ['type' => AccountType::Expense]);
    }
}

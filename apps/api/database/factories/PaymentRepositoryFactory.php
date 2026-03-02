<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRepository>
 */
class PaymentRepositoryFactory extends Factory
{
    protected $model = PaymentRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'code' => strtoupper(Str::random(6)),
            'name' => $this->faker->words(2, true),
            'type' => RepositoryType::CashRegister,
            'balance' => '0.00',
            'is_active' => true,
        ];
    }
}

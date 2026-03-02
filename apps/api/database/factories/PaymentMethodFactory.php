<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'code' => strtoupper(Str::random(6)),
            'name' => $this->faker->randomElement(['Cash', 'Credit Card', 'Debit Card', 'Check', 'Bank Transfer']),
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }
}

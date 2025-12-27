<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Partner\Domain\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Partner\Domain\Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyTypes = ['SARL', 'SAS', 'SA', 'EURL', 'SNC', 'Auto-Entrepreneur', ''];
        $companyType = $this->faker->randomElement($companyTypes);
        $companyName = $this->faker->company();
        $fullName = $companyType ? "{$companyName} {$companyType}" : $companyName;

        return [
            'id' => Str::uuid()->toString(),
            'company_id' => null, // Will be set by seeder
            'type' => $this->faker->randomElement(['customer', 'supplier', 'both']),
            'code' => strtoupper(Str::random(3)).$this->faker->unique()->numberBetween(100, 999),
            'name' => $fullName,
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'vat_number' => $this->faker->optional(0.8)->regexify('FR[0-9]{11}'),
            'country_code' => 'FR',
            'notes' => $this->faker->optional(0.3)->paragraph(),
            'is_active' => $this->faker->boolean(95),
        ];
    }

    /**
     * Indicate that the partner is a customer.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'customer',
            'code' => 'CUST'.$this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Indicate that the partner is a supplier.
     */
    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'supplier',
            'code' => 'SUPP'.$this->faker->unique()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Indicate that the partner is both customer and supplier.
     */
    public function both(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'both',
        ]);
    }

    /**
     * Indicate that the partner is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

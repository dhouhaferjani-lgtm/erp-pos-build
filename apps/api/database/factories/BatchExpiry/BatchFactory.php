<?php

declare(strict_types=1);

namespace Database\Factories\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'tenant_id' => Tenant::factory(),
            'company_id' => fn (array $attributes) => $attributes['tenant_id'],
            'product_id' => Product::factory(),
            'batch_number' => 'BATCH-'.strtoupper($this->faker->bothify('####-??##')),
            'manufacturing_date' => $this->faker->dateTimeBetween('-6 months', '-1 month'),
            'expiry_date' => $this->faker->dateTimeBetween('+1 month', '+2 years'),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => $this->faker->dateTimeBetween('-1 year', '-1 day'),
            'is_expired' => true,
        ]);
    }

    public function recalled(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_recalled' => true,
            'recall_reason' => $this->faker->sentence(),
            'recalled_at' => now(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

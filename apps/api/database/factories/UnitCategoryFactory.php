<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Uom\Domain\Entities\UnitCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitCategory>
 */
class UnitCategoryFactory extends Factory
{
    protected $model = UnitCategory::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null, // System category by default
            'code' => $this->faker->unique()->slug(1),
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'base_unit_id' => null,
            'is_system' => true,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that this is a tenant-specific category
     */
    public function tenant(string $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'is_system' => false,
        ]);
    }

    /**
     * Indicate that this category is inactive
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

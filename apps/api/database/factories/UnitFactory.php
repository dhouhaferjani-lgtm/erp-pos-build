<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null, // System unit by default
            'category_id' => UnitCategory::factory(),
            'code' => $this->faker->unique()->lexify('???'),
            'name' => $this->faker->word(),
            'symbol' => $this->faker->lexify('??'),
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
            'is_base_unit' => false,
            'is_system' => true,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that this is a tenant-specific unit
     */
    public function tenant(string $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'is_system' => false,
        ]);
    }

    /**
     * Indicate that this is a base unit
     */
    public function baseUnit(): static
    {
        return $this->state(fn (array $attributes) => [
            'conversion_factor' => '1',
            'is_base_unit' => true,
        ]);
    }

    /**
     * Indicate that this unit is inactive
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific conversion factor
     */
    public function withConversionFactor(string $factor): static
    {
        return $this->state(fn (array $attributes) => [
            'conversion_factor' => $factor,
        ]);
    }

    /**
     * Set specific rounding method
     */
    public function withRounding(RoundingMethod $method): static
    {
        return $this->state(fn (array $attributes) => [
            'rounding_method' => $method,
        ]);
    }
}

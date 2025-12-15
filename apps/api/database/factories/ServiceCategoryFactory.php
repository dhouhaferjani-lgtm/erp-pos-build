<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    /**
     * @var class-string<ServiceCategory>
     */
    protected $model = ServiceCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'parent_id' => null,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a parent category.
     */
    public function withParent(ServiceCategory $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->id,
            'tenant_id' => $parent->tenant_id,
            'company_id' => $parent->company_id,
        ]);
    }

    /**
     * Common service categories for automotive.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Maintenance',
            'description' => 'Regular maintenance and service',
        ]);
    }

    /**
     * Repair service category.
     */
    public function repair(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Repair',
            'description' => 'Mechanical and electrical repairs',
        ]);
    }

    /**
     * Diagnostics service category.
     */
    public function diagnostics(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Diagnostics',
            'description' => 'Vehicle diagnostics and inspection',
        ]);
    }
}

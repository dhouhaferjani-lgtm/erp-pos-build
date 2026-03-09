<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Cart\Domain\Models\CatalogCart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCart>
 */
class CatalogCartFactory extends Factory
{
    protected $model = CatalogCart::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'user_id' => null,
            'name' => fake()->optional(0.5)->words(3, true),
            'status' => CartStatus::Active,
            'is_shared' => false,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_shared' => true,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CartStatus::Archived,
        ]);
    }
}

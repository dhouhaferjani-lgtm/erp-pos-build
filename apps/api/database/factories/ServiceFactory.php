<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @var class-string<Service>
     */
    protected $model = Service::class;

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
            'code' => strtoupper($this->faker->unique()->bothify('SRV-###??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'category_id' => null,
            'pricing_type' => PricingType::FlatRate,
            'base_price' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'TND',
            'default_duration_minutes' => $this->faker->optional()->numberBetween(15, 480),
            'hourly_rate' => null,
            'tax_rate' => '19.00',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the service is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a category for the service.
     */
    public function inCategory(ServiceCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category_id' => $category->id,
            'tenant_id' => $category->tenant_id,
            'company_id' => $category->company_id,
        ]);
    }

    /**
     * Set pricing type to flat rate.
     */
    public function flatRate(string $price = '100.00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::FlatRate,
            'base_price' => $price,
            'hourly_rate' => null,
        ]);
    }

    /**
     * Set pricing type to hourly.
     */
    public function hourly(string $rate = '50.00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::Hourly,
            'hourly_rate' => $rate,
            'base_price' => '0.00',
        ]);
    }

    /**
     * Set pricing type to percentage.
     */
    public function percentage(string $rate = '10.00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::Percentage,
            'base_price' => $rate,
            'hourly_rate' => null,
        ]);
    }

    /**
     * Common service: Oil Change.
     */
    public function oilChange(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'SRV-OIL',
            'name' => 'Oil Change',
            'description' => 'Engine oil and filter replacement',
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '45.00',
            'default_duration_minutes' => 30,
        ]);
    }

    /**
     * Common service: Brake Service.
     */
    public function brakeService(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'SRV-BRAKE',
            'name' => 'Brake Service',
            'description' => 'Brake pad replacement and inspection',
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '120.00',
            'default_duration_minutes' => 60,
        ]);
    }

    /**
     * Common service: Diagnostic.
     */
    public function diagnostic(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'SRV-DIAG',
            'name' => 'Diagnostic Service',
            'description' => 'Computer diagnostic and vehicle inspection',
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '35.00',
            'default_duration_minutes' => 45,
        ]);
    }

    /**
     * Common service: Labor (hourly).
     */
    public function labor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'SRV-LABOR',
            'name' => 'Labor',
            'description' => 'Hourly labor rate',
            'pricing_type' => PricingType::Hourly,
            'hourly_rate' => '40.00',
            'base_price' => '0.00',
            'default_duration_minutes' => 60,
        ]);
    }
}

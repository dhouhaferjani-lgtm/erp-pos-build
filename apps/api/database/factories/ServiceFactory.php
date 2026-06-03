<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\CurrencyScale;
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
        // Default currency TND (scale 3). Monetary fields are canonical
        // numeric strings produced via bcmath (no float storage).
        $scale = CurrencyScale::for('TND');

        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('SRV-###??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'category_id' => null,
            'pricing_type' => PricingType::FlatRate,
            'base_price' => CurrencyScale::bcformat($this->faker->randomFloat(2, 10, 500), $scale),
            'currency' => 'TND',
            'default_duration_minutes' => $this->faker->optional()->numberBetween(15, 480),
            'hourly_rate' => null,
            'tax_rate' => CurrencyScale::bcformat('19.00', $scale),
            'is_active' => true,
        ];
    }

    /**
     * Re-scale the monetary fields to the decimal scale of the given currency.
     */
    public function currency(string $currencyCode): static
    {
        $scale = CurrencyScale::for($currencyCode);

        return $this->state(function (array $attributes) use ($currencyCode, $scale): array {
            /** @var string|int|float|null $hourlyRate */
            $hourlyRate = $attributes['hourly_rate'] ?? null;
            /** @var string|int|float $basePrice */
            $basePrice = $attributes['base_price'] ?? '0';
            /** @var string|int|float $taxRate */
            $taxRate = $attributes['tax_rate'] ?? '0';

            return [
                'currency' => $currencyCode,
                'base_price' => CurrencyScale::bcformat($basePrice, $scale),
                'hourly_rate' => $hourlyRate === null
                    ? null
                    : CurrencyScale::bcformat($hourlyRate, $scale),
                'tax_rate' => CurrencyScale::bcformat($taxRate, $scale),
            ];
        });
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
    public function flatRate(string $price = '100.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::FlatRate,
            'base_price' => CurrencyScale::bcformat($price, 3),
            'hourly_rate' => null,
        ]);
    }

    /**
     * Set pricing type to hourly.
     */
    public function hourly(string $rate = '50.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::Hourly,
            'hourly_rate' => CurrencyScale::bcformat($rate, 3),
            'base_price' => CurrencyScale::bcformat('0', 3),
        ]);
    }

    /**
     * Set pricing type to percentage.
     */
    public function percentage(string $rate = '10.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_type' => PricingType::Percentage,
            'base_price' => CurrencyScale::bcformat($rate, 3),
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
            'base_price' => CurrencyScale::bcformat('45.00', 3),
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
            'base_price' => CurrencyScale::bcformat('120.00', 3),
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
            'base_price' => CurrencyScale::bcformat('35.00', 3),
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
            'hourly_rate' => CurrencyScale::bcformat('40.00', 3),
            'base_price' => CurrencyScale::bcformat('0', 3),
            'default_duration_minutes' => 60,
        ]);
    }
}

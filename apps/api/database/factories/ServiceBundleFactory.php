<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceBundle>
 */
class ServiceBundleFactory extends Factory
{
    /**
     * @var class-string<ServiceBundle>
     */
    protected $model = ServiceBundle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = Tenant::factory()->create();

        return [
            'tenant_id' => $tenant->id,
            'company_id' => fn (array $attrs): string => Company::factory()
                ->for(Tenant::find($attrs['tenant_id']))
                ->create()->id,
            'code' => strtoupper($this->faker->unique()->bothify('BDL-###??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'pricing_mode' => BundlePricingMode::Standard,
            'base_price' => null,
            'currency' => 'TND',
            'tax_rate' => '19.00',
            'estimated_labor_hours' => null,
            'service_interval_km' => null,
            'service_interval_months' => null,
            'is_active' => true,
        ];
    }

    /**
     * Flag bundle as using flat fixed pricing with the given base price.
     */
    public function fixedBundle(string $basePrice = '120.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_mode' => BundlePricingMode::FixedBundle,
            'base_price' => $basePrice,
        ]);
    }

    /**
     * Flag bundle as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Attach the bundle to a specific tenant + company.
     */
    public function forCompany(string $tenantId, string $companyId): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * Apply periodic-service interval metadata (km + months).
     */
    public function withServiceInterval(int $km, int $months): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_interval_km' => $km,
            'service_interval_months' => $months,
        ]);
    }
}

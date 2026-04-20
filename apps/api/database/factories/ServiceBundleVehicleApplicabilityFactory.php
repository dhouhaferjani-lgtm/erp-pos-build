<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceBundleVehicleApplicability>
 */
class ServiceBundleVehicleApplicabilityFactory extends Factory
{
    /**
     * @var class-string<ServiceBundleVehicleApplicability>
     */
    protected $model = ServiceBundleVehicleApplicability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bundle = ServiceBundle::factory();

        return [
            'tenant_id' => fn (array $attrs): string => ServiceBundle::find($attrs['bundle_id'])?->tenant_id
                ?? $this->faker->uuid(),
            'bundle_id' => $bundle,
            'platform_vehicle_id' => null,
            'vehicle_type' => null,
            'vehicle_display' => null,
            'year_from' => null,
            'year_to' => null,
        ];
    }

    /**
     * Apply to a specific platform vehicle (UUID).
     */
    public function forVehicle(string $platformVehicleId, VehicleTypeRef $vehicleType = VehicleTypeRef::Pc, ?string $display = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform_vehicle_id' => $platformVehicleId,
            'vehicle_type' => $vehicleType,
            'vehicle_display' => $display ?? 'Seeded Vehicle',
        ]);
    }

    /**
     * Universal applicability (no vehicle scope).
     */
    public function universal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform_vehicle_id' => null,
            'vehicle_type' => null,
            'vehicle_display' => null,
        ]);
    }

    /**
     * Attach the applicability to a specific bundle (inherits tenant).
     */
    public function forBundle(ServiceBundle $bundle): static
    {
        return $this->state(fn (array $attributes): array => [
            'bundle_id' => $bundle->id,
            'tenant_id' => $bundle->tenant_id,
        ]);
    }

    /**
     * Apply a year range.
     */
    public function yearRange(int $from, int $to): static
    {
        return $this->state(fn (array $attributes): array => [
            'year_from' => $from,
            'year_to' => $to,
        ]);
    }
}

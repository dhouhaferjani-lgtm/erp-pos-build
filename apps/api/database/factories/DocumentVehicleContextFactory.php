<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Document\Domain\DocumentVehicleContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DocumentVehicleContextFactory extends Factory
{
    protected $model = DocumentVehicleContext::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'document_id' => null, // Set via factory relationship
            'vehicle_id' => Str::uuid()->toString(),
            'vehicle_snapshot' => null,
            'mileage_at_service' => $this->faker->numberBetween(10000, 250000),
            'context_data' => null,
        ];
    }

    /**
     * Add vehicle snapshot data
     */
    public function withSnapshot(?array $snapshot = null): static
    {
        $defaultSnapshot = [
            'license_plate' => $this->faker->regexify('[A-Z]{2}-[0-9]{3}-[A-Z]{2}'),
            'brand' => $this->faker->randomElement(['Peugeot', 'Renault', 'Toyota', 'Honda', 'BMW']),
            'model' => $this->faker->randomElement(['208', 'Clio', 'Corolla', 'Civic', 'X3']),
            'year' => $this->faker->numberBetween(2015, 2024),
            'vin' => strtoupper($this->faker->bothify('VF3?????????????')),
            'color' => $this->faker->safeColorName(),
            'fuel_type' => $this->faker->randomElement(['gasoline', 'diesel', 'electric', 'hybrid']),
        ];

        return $this->state(fn (array $attributes): array => [
            'vehicle_snapshot' => $snapshot ?? $defaultSnapshot,
        ]);
    }

    /**
     * Set specific mileage
     */
    public function withMileage(int $mileage): static
    {
        return $this->state(fn (array $attributes): array => [
            'mileage_at_service' => $mileage,
        ]);
    }

    /**
     * Create without vehicle data
     */
    public function withoutVehicle(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vehicle_id' => null,
            'vehicle_snapshot' => null,
            'mileage_at_service' => null,
        ]);
    }

    /**
     * Complete service context (snapshot + mileage)
     */
    public function completeServiceContext(): static
    {
        return $this->withSnapshot()->withMileage($this->faker->numberBetween(50000, 150000));
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleMileageReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMileageReading>
 */
final class VehicleMileageReadingFactory extends Factory
{
    protected $model = VehicleMileageReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vehicle = Vehicle::first() ?? Vehicle::factory()->create();

        return [
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'mileage' => $this->faker->numberBetween(10_000, 250_000),
            'recorded_at' => now()->subDays($this->faker->numberBetween(0, 30)),
            'source' => MileageSource::Manual->value,
            'context_document_id' => null,
            'context_work_order_id' => null,
            'recorded_by_user_id' => null,
            'notes' => null,
            'created_at' => now(),
        ];
    }
}

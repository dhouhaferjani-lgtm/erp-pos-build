<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleOwnership>
 */
final class VehicleOwnershipFactory extends Factory
{
    protected $model = VehicleOwnership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vehicle = Vehicle::first() ?? Vehicle::factory()->create();
        $partner = Partner::where('tenant_id', $vehicle->tenant_id)
            ->where('company_id', $vehicle->company_id)
            ->first()
            ?? Partner::factory()->create([
                'tenant_id' => $vehicle->tenant_id,
                'company_id' => $vehicle->company_id,
                'type' => 'customer',
            ]);

        return [
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $partner->id,
            'acquired_at' => now()->subMonth(),
            'released_at' => null,
            'reason_code' => OwnershipReason::Purchase->value,
            'notes' => null,
            'recorded_by_user_id' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'released_at' => now()->subDay(),
        ]);
    }
}

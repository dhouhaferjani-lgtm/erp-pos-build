<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Services;

use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Builds vehicle context by fetching and snapshotting Vehicle data.
 *
 * This ensures the Document module gets authoritative vehicle data
 * even though it doesn't directly depend on the Vehicle module.
 */
final class VehicleContextBuilder
{
    /**
     * Build vehicle context from a vehicle ID.
     *
     * @param  string  $vehicleId  Vehicle UUID to snapshot
     * @param  string  $tenantId  Tenant ID for ownership validation
     * @param  string  $companyId  Company ID for ownership validation
     * @param  int|null  $mileage  Mileage at service time
     * @return array{vehicle_id: string, snapshot: array<string, mixed>, mileage: int|null, additional_data: null}
     *
     * @throws ValidationException If vehicle doesn't exist or doesn't belong to tenant/company
     */
    public function buildFromVehicleId(
        string $vehicleId,
        string $tenantId,
        string $companyId,
        ?int $mileage = null
    ): array {
        // Fetch vehicle with ownership validation
        $vehicle = Vehicle::where('id', $vehicleId)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_context.vehicle_id' => 'The selected vehicle does not exist or does not belong to your company.',
            ]);
        }

        // Build snapshot from authoritative Vehicle data
        $snapshot = [
            'license_plate' => $vehicle->license_plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'vin' => $vehicle->vin,
            'color' => $vehicle->color ?? null,
            'fuel_type' => $vehicle->fuel_type ?? null,
        ];

        return [
            'vehicle_id' => $vehicleId,
            'snapshot' => $snapshot,
            'mileage' => $mileage,
            'additional_data' => null,
        ];
    }

    /**
     * Build vehicle context from client-provided data (for bulk imports, external integrations).
     *
     * Use this when you have snapshot data but no Vehicle record (e.g., historical imports).
     *
     * @param  string  $vehicleId  Vehicle UUID (not validated)
     * @param  array<string, mixed>  $snapshot  Pre-built snapshot
     * @param  int|null  $mileage  Mileage at service time
     * @return array{vehicle_id: string, snapshot: array<string, mixed>, mileage: int|null, additional_data: null}
     */
    public function buildFromSnapshot(
        string $vehicleId,
        array $snapshot,
        ?int $mileage = null
    ): array {
        return [
            'vehicle_id' => $vehicleId,
            'snapshot' => $snapshot,
            'mileage' => $mileage,
            'additional_data' => null,
        ];
    }
}

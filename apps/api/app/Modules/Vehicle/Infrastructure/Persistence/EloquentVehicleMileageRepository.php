<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Persistence;

use App\Modules\Vehicle\Domain\Contracts\VehicleMileageRepositoryInterface;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\VehicleMileageReading;
use Illuminate\Support\Collection;

final class EloquentVehicleMileageRepository implements VehicleMileageRepositoryInterface
{
    public function logReading(
        string $tenantId,
        string $companyId,
        string $vehicleId,
        int $mileage,
        \DateTimeImmutable $recordedAt,
        MileageSource $source,
        ?string $contextDocumentId,
        ?string $contextWorkOrderId,
        ?string $recordedByUserId,
        ?string $notes,
    ): VehicleMileageReading {
        $reading = new VehicleMileageReading;
        $reading->forceFill([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
            'mileage' => $mileage,
            'recorded_at' => $recordedAt,
            'source' => $source->value,
            'context_document_id' => $contextDocumentId,
            'context_work_order_id' => $contextWorkOrderId,
            'recorded_by_user_id' => $recordedByUserId,
            'notes' => $notes,
            'created_at' => now(),
        ]);
        $reading->save();

        return $reading;
    }

    public function latestForVehicle(string $vehicleId): ?VehicleMileageReading
    {
        return VehicleMileageReading::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();
    }

    /** @return Collection<int, VehicleMileageReading> */
    public function recentForVehicle(string $vehicleId, int $limit): Collection
    {
        return VehicleMileageReading::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get();
    }
}

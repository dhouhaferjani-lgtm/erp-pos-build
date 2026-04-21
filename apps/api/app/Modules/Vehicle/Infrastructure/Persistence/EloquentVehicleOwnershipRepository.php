<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Persistence;

use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Support\Collection;

final class EloquentVehicleOwnershipRepository implements VehicleOwnershipRepositoryInterface
{
    public function findOpenForVehicle(string $vehicleId): ?VehicleOwnership
    {
        return VehicleOwnership::query()
            ->where('vehicle_id', $vehicleId)
            ->open()
            ->first();
    }

    /** @return Collection<int, VehicleOwnership> */
    public function findHistoryForVehicle(string $vehicleId): Collection
    {
        return VehicleOwnership::query()
            ->where('vehicle_id', $vehicleId)
            ->with('ownerPartner')
            ->orderByDesc('acquired_at')
            ->get();
    }

    public function open(
        Vehicle $vehicle,
        string $ownerPartnerId,
        \DateTimeImmutable $acquiredAt,
        OwnershipReason $reason,
        ?string $notes,
        ?string $recordedByUserId,
    ): VehicleOwnership {
        $row = new VehicleOwnership;
        $row->forceFill([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $ownerPartnerId,
            'acquired_at' => $acquiredAt,
            'released_at' => null,
            'reason_code' => $reason->value,
            'notes' => $notes,
            'recorded_by_user_id' => $recordedByUserId,
        ]);
        $row->save();

        return $row;
    }

    public function close(VehicleOwnership $ownership, \DateTimeImmutable $releasedAt): void
    {
        $ownership->forceFill([
            'released_at' => $releasedAt,
        ])->save();
    }

    /** @return Collection<int, VehicleOwnership> */
    public function findOpenForOwner(string $ownerPartnerId): Collection
    {
        return VehicleOwnership::query()
            ->where('owner_partner_id', $ownerPartnerId)
            ->open()
            ->get();
    }
}

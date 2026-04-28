<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Services;

use App\Modules\Vehicle\Application\Commands\TransferVehicleOwnershipCommand;
use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Support\Facades\DB;

final class VehicleOwnershipService
{
    public function __construct(
        private readonly VehicleRepositoryInterface $vehicles,
        private readonly VehicleOwnershipRepositoryInterface $ownerships,
    ) {}

    public function transfer(TransferVehicleOwnershipCommand $command): VehicleOwnership
    {
        return DB::transaction(function () use ($command): VehicleOwnership {
            $vehicle = $this->vehicles->findForUpdate($command->vehicle_id);

            $previousRow = $this->ownerships->findOpenForVehicle($vehicle->id);
            $previousOwnerId = $previousRow?->owner_partner_id;

            if ($previousRow !== null) {
                $this->ownerships->close($previousRow, $command->occurred_at);
            }

            $newRow = $this->ownerships->open(
                vehicle: $vehicle,
                ownerPartnerId: $command->new_owner_partner_id,
                acquiredAt: $command->occurred_at,
                reason: $command->reason,
                notes: $command->notes,
                recordedByUserId: $command->actor_user_id,
            );

            event(new VehicleOwnerChanged(
                vehicle_id: $vehicle->id,
                tenant_id: $vehicle->tenant_id,
                previous_owner_partner_id: $previousOwnerId,
                new_owner_partner_id: $command->new_owner_partner_id,
                reason: $command->reason,
                occurred_at: $command->occurred_at,
            ));

            return $newRow;
        });
    }

    /**
     * Open the initial ownership history row for a freshly-created vehicle.
     *
     * Called from the vehicle creation path (controller/seeder) when partner_id
     * is supplied, so the customer→vehicle lineage is preserved from the start
     * of the chain. Idempotent: if an open row already exists for this vehicle,
     * the method is a no-op and returns the existing row rather than creating a
     * duplicate (this is also enforced at the DB level by the
     * `uq_vehicle_open_ownership` partial unique index on PostgreSQL).
     */
    public function openInitialOwnership(
        Vehicle $vehicle,
        string $ownerPartnerId,
        \DateTimeImmutable $acquiredAt,
        ?string $recordedByUserId = null,
    ): VehicleOwnership {
        return DB::transaction(function () use ($vehicle, $ownerPartnerId, $acquiredAt, $recordedByUserId): VehicleOwnership {
            $existing = $this->ownerships->findOpenForVehicle($vehicle->id);
            if ($existing !== null) {
                return $existing;
            }

            $row = $this->ownerships->open(
                vehicle: $vehicle,
                ownerPartnerId: $ownerPartnerId,
                acquiredAt: $acquiredAt,
                reason: OwnershipReason::InitialRegistration,
                notes: null,
                recordedByUserId: $recordedByUserId,
            );

            event(new VehicleOwnerChanged(
                vehicle_id: $vehicle->id,
                tenant_id: $vehicle->tenant_id,
                previous_owner_partner_id: null,
                new_owner_partner_id: $ownerPartnerId,
                reason: OwnershipReason::InitialRegistration,
                occurred_at: $acquiredAt,
            ));

            return $row;
        });
    }

    public function handlePartnerDeleted(string $partnerId): int
    {
        $openRows = $this->ownerships->findOpenForOwner($partnerId);
        $now = new \DateTimeImmutable;
        foreach ($openRows as $row) {
            $this->ownerships->close($row, $now);
        }

        return $openRows->count();
    }
}

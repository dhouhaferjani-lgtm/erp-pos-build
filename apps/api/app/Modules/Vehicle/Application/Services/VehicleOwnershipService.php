<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Services;

use App\Modules\Vehicle\Application\Commands\TransferVehicleOwnershipCommand;
use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
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

<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Contracts;

use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Support\Collection;

interface VehicleOwnershipRepositoryInterface
{
    public function findOpenForVehicle(string $vehicleId): ?VehicleOwnership;

    /** @return Collection<int, VehicleOwnership> */
    public function findHistoryForVehicle(string $vehicleId): Collection;

    public function open(
        Vehicle $vehicle,
        string $ownerPartnerId,
        \DateTimeImmutable $acquiredAt,
        OwnershipReason $reason,
        ?string $notes,
        ?string $recordedByUserId,
    ): VehicleOwnership;

    public function close(VehicleOwnership $ownership, \DateTimeImmutable $releasedAt): void;

    /** @return Collection<int, VehicleOwnership> */
    public function findOpenForOwner(string $ownerPartnerId): Collection;
}

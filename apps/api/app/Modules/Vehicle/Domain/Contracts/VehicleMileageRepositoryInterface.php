<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Contracts;

use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\VehicleMileageReading;
use Illuminate\Support\Collection;

interface VehicleMileageRepositoryInterface
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
    ): VehicleMileageReading;

    public function latestForVehicle(string $vehicleId): ?VehicleMileageReading;

    /** @return Collection<int, VehicleMileageReading> */
    public function recentForVehicle(string $vehicleId, int $limit): Collection;
}

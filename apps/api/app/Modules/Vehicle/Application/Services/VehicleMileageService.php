<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Services;

use App\Modules\Vehicle\Application\Commands\LogVehicleMileageCommand;
use App\Modules\Vehicle\Domain\Contracts\VehicleMileageRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Events\MileageAnomalyDetected;
use App\Modules\Vehicle\Domain\Events\MileageReadingLogged;
use App\Modules\Vehicle\Domain\VehicleMileageReading;

final class VehicleMileageService
{
    private const float ANOMALY_THRESHOLD_PERCENT = 5.0;

    public function __construct(
        private readonly VehicleMileageRepositoryInterface $mileage,
        private readonly VehicleRepositoryInterface $vehicles,
    ) {}

    public function log(LogVehicleMileageCommand $command): VehicleMileageReading
    {
        $vehicle = $this->vehicles->findById($command->vehicle_id)
            ?? throw new \InvalidArgumentException('Vehicle not found');

        $previous = $this->mileage->latestForVehicle($command->vehicle_id);

        $reading = $this->mileage->logReading(
            tenantId: $vehicle->tenant_id,
            companyId: $vehicle->company_id,
            vehicleId: $command->vehicle_id,
            mileage: $command->mileage,
            recordedAt: $command->recorded_at,
            source: $command->source,
            contextDocumentId: $command->context_document_id,
            contextWorkOrderId: $command->context_work_order_id,
            recordedByUserId: $command->recorded_by_user_id,
            notes: $command->notes,
        );

        event(new MileageReadingLogged(
            reading_id: $reading->id,
            vehicle_id: $command->vehicle_id,
            mileage: $command->mileage,
            source: $command->source,
            recorded_at: $command->recorded_at,
        ));

        if ($previous !== null && $previous->mileage > 0) {
            $decreasePercent = (($previous->mileage - $command->mileage) / $previous->mileage) * 100.0;
            if ($decreasePercent >= self::ANOMALY_THRESHOLD_PERCENT) {
                event(new MileageAnomalyDetected(
                    vehicle_id: $command->vehicle_id,
                    new_reading: $command->mileage,
                    previous_reading: $previous->mileage,
                    decrease_percent: $decreasePercent,
                    detected_at: new \DateTimeImmutable,
                ));
            }
        }

        return $reading;
    }
}

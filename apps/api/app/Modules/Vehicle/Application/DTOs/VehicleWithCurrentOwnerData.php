<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

use App\Modules\Vehicle\Domain\Vehicle;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class VehicleWithCurrentOwnerData extends Data
{
    /**
     * @param  DataCollection<int, VehicleMileageReadingData>  $recent_mileage_readings
     */
    public function __construct(
        public VehicleData $vehicle,
        public ?VehicleOwnershipData $current_ownership,
        #[DataCollectionOf(VehicleMileageReadingData::class)]
        public DataCollection $recent_mileage_readings,
    ) {}

    public static function fromModel(Vehicle $vehicle, int $recentMileageLimit = 10): self
    {
        $vehicle->loadMissing(['currentOwnership.ownerPartner']);

        $currentOwnership = $vehicle->currentOwnership;

        $recentReadings = $vehicle
            ->mileageReadings()
            ->limit($recentMileageLimit)
            ->get()
            ->map(static fn ($reading): VehicleMileageReadingData => VehicleMileageReadingData::fromModel($reading))
            ->all();

        return new self(
            vehicle: VehicleData::fromModel($vehicle),
            current_ownership: $currentOwnership !== null
                ? VehicleOwnershipData::fromModel($currentOwnership)
                : null,
            recent_mileage_readings: new DataCollection(VehicleMileageReadingData::class, $recentReadings),
        );
    }
}

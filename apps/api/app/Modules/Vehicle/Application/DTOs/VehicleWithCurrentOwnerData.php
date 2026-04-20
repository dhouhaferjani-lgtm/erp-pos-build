<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

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
}

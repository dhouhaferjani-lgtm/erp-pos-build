<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\DTOs;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ServiceBundleVehicleApplicabilityData extends Data
{
    public function __construct(
        public string $id,
        public string $bundle_id,
        public ?string $platform_vehicle_id,
        public ?VehicleTypeRef $vehicle_type,
        public ?string $vehicle_display,
        public ?int $year_from,
        public ?int $year_to,
    ) {}

    public static function fromModel(ServiceBundleVehicleApplicability $applicability): self
    {
        return new self(
            id: $applicability->id,
            bundle_id: $applicability->bundle_id,
            platform_vehicle_id: $applicability->platform_vehicle_id,
            vehicle_type: $applicability->vehicle_type,
            vehicle_display: $applicability->vehicle_display,
            year_from: $applicability->year_from,
            year_to: $applicability->year_to,
        );
    }
}

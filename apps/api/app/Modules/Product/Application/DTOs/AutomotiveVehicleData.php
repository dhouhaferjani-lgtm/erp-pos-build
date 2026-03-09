<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\AutomotiveProductVehicle;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AutomotiveVehicleData extends Data
{
    public function __construct(
        public string $id,
        public ?string $platform_vehicle_id,
        public VehicleTypeRef $vehicle_type,
        public string $vehicle_display,
        public ?int $year_from,
        public ?int $year_to,
        public ?string $notes,
        public ?string $platform_synced_at,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(AutomotiveProductVehicle $vehicle): self
    {
        return new self(
            id: $vehicle->id,
            platform_vehicle_id: $vehicle->platform_vehicle_id,
            vehicle_type: $vehicle->vehicle_type,
            vehicle_display: $vehicle->vehicle_display,
            year_from: $vehicle->year_from,
            year_to: $vehicle->year_to,
            notes: $vehicle->notes,
            platform_synced_at: $vehicle->platform_synced_at?->toIso8601String(),
            created_at: $vehicle->created_at?->toIso8601String() ?? '',
            updated_at: $vehicle->updated_at?->toIso8601String(),
        );
    }
}

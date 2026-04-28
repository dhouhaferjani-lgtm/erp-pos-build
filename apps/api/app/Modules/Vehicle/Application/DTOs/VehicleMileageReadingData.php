<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\VehicleMileageReading;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class VehicleMileageReadingData extends Data
{
    public function __construct(
        public string $id,
        public string $vehicle_id,
        public int $mileage,
        public string $recorded_at,
        public MileageSource $source,
        public ?string $context_document_id,
        public ?string $context_work_order_id,
        public ?string $notes,
    ) {}

    public static function fromModel(VehicleMileageReading $reading): self
    {
        return new self(
            id: $reading->id,
            vehicle_id: $reading->vehicle_id,
            mileage: $reading->mileage,
            recorded_at: $reading->recorded_at->toIso8601String(),
            source: $reading->source,
            context_document_id: $reading->context_document_id,
            context_work_order_id: $reading->context_work_order_id,
            notes: $reading->notes,
        );
    }
}

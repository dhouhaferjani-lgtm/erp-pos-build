<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\DocumentVehicleContext;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class VehicleContextData extends Data
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @param  array<string, mixed>|null  $additional_data
     */
    public function __construct(
        public string $vehicle_id,
        public ?array $snapshot,
        public ?int $mileage,
        public ?string $display,
        public ?array $additional_data,
    ) {}

    public static function fromModel(DocumentVehicleContext $context): self
    {
        $display = null;
        if ($context->vehicle_snapshot !== null) {
            $snapshot = $context->vehicle_snapshot;
            $parts = array_filter([
                $snapshot['license_plate'] ?? null,
                $snapshot['brand'] ?? null,
                $snapshot['model'] ?? null,
                isset($snapshot['year']) ? (string) $snapshot['year'] : null,
            ]);
            $display = implode(' - ', $parts);
        }

        return new self(
            vehicle_id: $context->vehicle_id,
            snapshot: $context->vehicle_snapshot,
            mileage: $context->mileage_at_service,
            display: $display,
            additional_data: $context->context_data,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

use App\Modules\Vehicle\Domain\Enums\BodyType;
use App\Modules\Vehicle\Domain\Enums\FuelType;
use App\Modules\Vehicle\Domain\Enums\TransmissionType;
use App\Modules\Vehicle\Domain\Vehicle;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class VehicleData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public string $license_plate,
        public string $brand,
        public string $model,
        public ?int $year,
        public ?string $color,
        public ?int $mileage,
        public ?string $vin,
        public ?string $engine_code,
        public ?FuelType $fuel_type,
        public ?TransmissionType $transmission,
        public ?BodyType $body_type,
        public ?string $notes,
        public ?string $current_owner_partner_id,
        public ?string $current_owner_display_name,
        /**
         * @deprecated use current_owner_partner_id; kept for backward compat during the
         *             2026-04-19 ownership-migration window.
         */
        public ?string $partner_id,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Vehicle $vehicle): self
    {
        $vehicle->loadMissing('currentOwnership.ownerPartner');
        $openOwnership = $vehicle->currentOwnership;

        return new self(
            id: $vehicle->id,
            tenant_id: $vehicle->tenant_id,
            company_id: $vehicle->company_id,
            license_plate: $vehicle->license_plate,
            brand: $vehicle->brand,
            model: $vehicle->model,
            year: $vehicle->year,
            color: $vehicle->color,
            mileage: $vehicle->mileage,
            vin: $vehicle->vin,
            engine_code: $vehicle->engine_code,
            fuel_type: $vehicle->fuel_type,
            transmission: $vehicle->transmission,
            body_type: $vehicle->body_type,
            notes: $vehicle->notes,
            current_owner_partner_id: $openOwnership?->owner_partner_id,
            current_owner_display_name: $openOwnership?->ownerPartner?->getDisplayName(),
            partner_id: $vehicle->partner_id,
            created_at: $vehicle->created_at?->toIso8601String() ?? '',
            updated_at: $vehicle->updated_at?->toIso8601String(),
        );
    }
}

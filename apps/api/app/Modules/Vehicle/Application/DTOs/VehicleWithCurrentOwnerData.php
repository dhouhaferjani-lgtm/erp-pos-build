<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

use App\Modules\Vehicle\Domain\Enums\BodyType;
use App\Modules\Vehicle\Domain\Enums\FuelType;
use App\Modules\Vehicle\Domain\Enums\TransmissionType;
use App\Modules\Vehicle\Domain\Vehicle;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Detail-response DTO for `GET /api/v1/vehicles/{id}`.
 *
 * Serialised shape is flat: every field from {@see VehicleData} is declared
 * at the top level so the JSON envelope matches the list endpoint shape
 * (`{data: {id, license_plate, brand, ...}}`). Related collections
 * (`current_ownership`, `recent_mileage_readings`) live alongside the
 * vehicle fields — not wrapped in a sub-object.
 *
 * Closes audit finding 🟠-3 (list vs detail envelope mismatch).
 */
#[TypeScript]
final class VehicleWithCurrentOwnerData extends Data
{
    /**
     * @param  DataCollection<int, VehicleMileageReadingData>  $recent_mileage_readings
     */
    public function __construct(
        // Vehicle fields — flattened to mirror {@see VehicleData}. Spatie
        // Data serialises these as top-level JSON keys under `data`.
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
        // Related collections — siblings of the vehicle fields.
        public ?VehicleOwnershipData $current_ownership,
        #[DataCollectionOf(VehicleMileageReadingData::class)]
        public DataCollection $recent_mileage_readings,
    ) {}

    public static function fromModel(Vehicle $vehicle, int $recentMileageLimit = 10): self
    {
        $vehicle->loadMissing(['currentOwnership.ownerPartner']);

        $currentOwnership = $vehicle->currentOwnership;
        $base = VehicleData::fromModel($vehicle);

        $recentReadings = $vehicle
            ->mileageReadings()
            ->limit($recentMileageLimit)
            ->get()
            ->map(static fn ($reading): VehicleMileageReadingData => VehicleMileageReadingData::fromModel($reading))
            ->all();

        return new self(
            id: $base->id,
            tenant_id: $base->tenant_id,
            company_id: $base->company_id,
            license_plate: $base->license_plate,
            brand: $base->brand,
            model: $base->model,
            year: $base->year,
            color: $base->color,
            mileage: $base->mileage,
            vin: $base->vin,
            engine_code: $base->engine_code,
            fuel_type: $base->fuel_type,
            transmission: $base->transmission,
            body_type: $base->body_type,
            notes: $base->notes,
            current_owner_partner_id: $base->current_owner_partner_id,
            current_owner_display_name: $base->current_owner_display_name,
            partner_id: $base->partner_id,
            created_at: $base->created_at,
            updated_at: $base->updated_at,
            current_ownership: $currentOwnership !== null
                ? VehicleOwnershipData::fromModel($currentOwnership)
                : null,
            recent_mileage_readings: new DataCollection(VehicleMileageReadingData::class, $recentReadings),
        );
    }
}

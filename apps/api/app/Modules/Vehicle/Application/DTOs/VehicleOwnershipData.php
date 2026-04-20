<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\DTOs;

use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class VehicleOwnershipData extends Data
{
    public function __construct(
        public string $id,
        public string $vehicle_id,
        public string $owner_partner_id,
        public string $owner_display_name,
        public string $acquired_at,
        public ?string $released_at,
        public OwnershipReason $reason_code,
        public ?string $notes,
        public ?string $recorded_by_user_id,
    ) {}

    public static function fromModel(VehicleOwnership $ownership): self
    {
        $ownership->loadMissing('ownerPartner');

        return new self(
            id: $ownership->id,
            vehicle_id: $ownership->vehicle_id,
            owner_partner_id: $ownership->owner_partner_id,
            owner_display_name: $ownership->ownerPartner?->getDisplayName() ?? '(deleted)',
            acquired_at: $ownership->acquired_at->toIso8601String(),
            released_at: $ownership->released_at?->toIso8601String(),
            reason_code: $ownership->reason_code,
            notes: $ownership->notes,
            recorded_by_user_id: $ownership->recorded_by_user_id,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Events;

use App\Modules\Vehicle\Domain\Enums\OwnershipReason;

final readonly class VehicleOwnerChanged
{
    public function __construct(
        public string $vehicle_id,
        public string $tenant_id,
        public ?string $previous_owner_partner_id,
        public string $new_owner_partner_id,
        public OwnershipReason $reason,
        public \DateTimeImmutable $occurred_at,
    ) {}
}

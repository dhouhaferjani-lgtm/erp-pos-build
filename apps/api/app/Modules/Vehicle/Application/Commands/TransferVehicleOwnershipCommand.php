<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Commands;

use App\Modules\Vehicle\Domain\Enums\OwnershipReason;

final readonly class TransferVehicleOwnershipCommand
{
    public function __construct(
        public string $vehicle_id,
        public string $new_owner_partner_id,
        public \DateTimeImmutable $occurred_at,
        public OwnershipReason $reason,
        public ?string $notes,
        public ?string $actor_user_id,
    ) {}
}

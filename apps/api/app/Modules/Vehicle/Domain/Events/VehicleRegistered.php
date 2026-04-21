<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Events;

final readonly class VehicleRegistered
{
    public function __construct(
        public string $vehicle_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $initial_owner_partner_id,
        public \DateTimeImmutable $occurred_at,
    ) {}
}

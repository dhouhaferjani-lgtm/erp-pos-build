<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Events;

final readonly class VehicleAttributesUpdated
{
    /**
     * @param  array<string, mixed>  $changes  keyed by field name, values are the new values
     */
    public function __construct(
        public string $vehicle_id,
        public string $tenant_id,
        public array $changes,
        public \DateTimeImmutable $occurred_at,
    ) {}
}

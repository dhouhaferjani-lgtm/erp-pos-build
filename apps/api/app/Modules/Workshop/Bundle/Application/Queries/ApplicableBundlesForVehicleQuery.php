<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Queries;

/**
 * Query carrying the context needed to resolve applicable bundles for a
 * vehicle. `platform_vehicle_id` and `vehicle_type` are both-or-nothing
 * — pass null pair for "universal-only" (no vehicle context).
 */
final readonly class ApplicableBundlesForVehicleQuery
{
    public function __construct(
        public string $tenant_id,
        public string $company_id,
        public ?string $platform_vehicle_id,
        public ?string $vehicle_type,
        public ?string $search,
    ) {}
}

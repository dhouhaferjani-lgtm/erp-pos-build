<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Contracts;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformVehicleRef;

/**
 * Local adapter contract for platform vehicle lookups. Delegates to
 * `PlatformVehicleQueryInterface` at the infrastructure boundary —
 * Bundle's Domain layer only speaks this interface, never the
 * PlatformIntegration module directly.
 */
interface PlatformVehicleResolverInterface
{
    public function findForApplicability(string $platformVehicleId, string $vehicleType): ?PlatformVehicleRef;
}

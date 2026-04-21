<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Infrastructure\Resolvers;

use App\Modules\PlatformIntegration\Application\Contracts\PlatformVehicleQueryInterface;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformVehicleRef;
use App\Modules\Workshop\Bundle\Domain\Contracts\PlatformVehicleResolverInterface;

/**
 * Bundle-side adapter that forwards vehicle lookups to the
 * PlatformIntegration module's public query interface. Keeping this
 * adapter local lets Bundle's Domain layer stay free of
 * PlatformIntegration imports.
 */
final readonly class PlatformIntegrationVehicleResolver implements PlatformVehicleResolverInterface
{
    public function __construct(
        private PlatformVehicleQueryInterface $query,
    ) {}

    public function findForApplicability(string $platformVehicleId, string $vehicleType): ?PlatformVehicleRef
    {
        return $this->query->findVehicle($platformVehicleId, $vehicleType);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Contracts;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformVehicleRef;

/**
 * Public read-through contract for platform vehicle lookups.
 *
 * Downstream modules (Workshop/Bundle, future WorkOrder, etc.) rely on
 * this interface to resolve and search platform vehicles without taking
 * a direct dependency on PlatformIntegration internals.
 *
 * Canonical argument order for `findVehicle`: ($vehicleId, $vehicleType).
 * The underlying `CatalogBrowseService::getVehicle($vehicleType, $vehicleId)`
 * uses the reverse order; adapter implementations must re-order.
 */
interface PlatformVehicleQueryInterface
{
    public function findVehicle(string $vehicleId, string $vehicleType): ?PlatformVehicleRef;

    /**
     * @return list<PlatformVehicleRef>
     */
    public function searchVehicles(string $query, ?string $vehicleType, int $limit): array;
}

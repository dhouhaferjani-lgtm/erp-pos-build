<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Platform;

use App\Modules\PlatformIntegration\Application\Contracts\PlatformVehicleQueryInterface;
use App\Modules\PlatformIntegration\Application\Services\CatalogBrowseService;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformVehicleRef;

/**
 * Infrastructure-layer adapter that wraps the existing
 * {@see CatalogBrowseService} (untyped arrays over HTTP) into the typed
 * {@see PlatformVehicleQueryInterface} contract.
 *
 * This lives in Infrastructure per the hexagonal rule: Application-layer
 * code must not depend on Eloquent or HTTP details. The adapter is bound
 * in the PlatformIntegrationServiceProvider.
 */
final readonly class EloquentPlatformVehicleQuery implements PlatformVehicleQueryInterface
{
    public function __construct(
        private CatalogBrowseService $catalogBrowse,
    ) {}

    public function findVehicle(string $vehicleId, string $vehicleType): ?PlatformVehicleRef
    {
        // Re-order: contract is (vehicleId, vehicleType); upstream wants (vehicleType, vehicleId).
        $raw = $this->catalogBrowse->getVehicle($vehicleType, $vehicleId);

        if ($raw === null) {
            return null;
        }

        $payload = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;

        if ($payload === []) {
            return null;
        }

        // Ensure required keys are present; if the upstream shape is
        // unexpected, surface as "not found" rather than throwing — this
        // mirrors the rest of the CatalogBrowseService contract which
        // returns nullable arrays.
        if (! isset($payload['vehicle_id']) && ! isset($payload['platform_vehicle_id'])) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return PlatformVehicleRef::fromApiResponse($payload);
    }

    /**
     * @return list<PlatformVehicleRef>
     */
    public function searchVehicles(string $query, ?string $vehicleType, int $limit): array
    {
        // The underlying CatalogBrowseService does not yet expose a generic
        // vehicle-search endpoint (search is scoped by model-series). Until
        // a dedicated endpoint lands, return an empty list rather than
        // fabricating results. Downstream callers (BundleResolutionService)
        // already treat this as "no hits" and fall back to universal bundles.
        unset($query, $vehicleType, $limit);

        return [];
    }
}

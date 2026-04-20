<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Contracts;

use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Repository contract for the ServiceBundle aggregate.
 *
 * Application services depend only on this interface; the Eloquent
 * implementation lives in `Infrastructure/Persistence`.
 */
interface BundleRepositoryInterface
{
    public function findById(string $bundleId): ?ServiceBundle;

    /**
     * Load a bundle with its components and vehicle applicabilities
     * eagerly attached. Returns null if the bundle is missing.
     */
    public function findWithComponentsAndApplicabilities(string $bundleId): ?ServiceBundle;

    /**
     * @param  array{active?: bool, search?: string}  $filters
     * @return LengthAwarePaginator<int, ServiceBundle>
     */
    public function paginateForCompany(string $tenantId, string $companyId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Return bundles applicable for a given vehicle scope: universal
     * applicabilities plus the matching (platform_vehicle_id, vehicle_type)
     * tuple. When no vehicle is provided, only universal bundles are
     * returned.
     *
     * @return Collection<int, ServiceBundle>
     */
    public function applicableForVehicle(
        string $tenantId,
        string $companyId,
        ?string $platformVehicleId,
        ?string $vehicleType,
        ?string $searchText,
    ): Collection;

    public function save(ServiceBundle $bundle): ServiceBundle;

    public function softDelete(ServiceBundle $bundle): void;
}

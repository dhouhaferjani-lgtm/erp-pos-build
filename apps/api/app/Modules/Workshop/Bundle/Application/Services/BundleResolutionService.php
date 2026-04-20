<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Services;

use App\Modules\Workshop\Bundle\Application\Queries\ApplicableBundlesForVehicleQuery;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;

/**
 * Resolves applicable-for-vehicle bundles. Pure read path — delegates
 * filtering to the repository and returns a list of ServiceBundle
 * models with `components` + `vehicleApplicabilities` eager-loaded.
 */
final readonly class BundleResolutionService
{
    public function __construct(
        private BundleRepositoryInterface $bundles,
    ) {}

    /**
     * @return list<ServiceBundle>
     */
    public function applicableForVehicle(ApplicableBundlesForVehicleQuery $query): array
    {
        $collection = $this->bundles->applicableForVehicle(
            tenantId: $query->tenant_id,
            companyId: $query->company_id,
            platformVehicleId: $query->platform_vehicle_id,
            vehicleType: $query->vehicle_type,
            searchText: $query->search,
        );

        return array_values($collection->all());
    }
}

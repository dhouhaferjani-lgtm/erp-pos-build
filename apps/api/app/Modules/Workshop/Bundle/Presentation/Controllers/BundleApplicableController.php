<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Bundle\Application\DTOs\ApplicableBundleData;
use App\Modules\Workshop\Bundle\Application\Queries\ApplicableBundlesForVehicleQuery;
use App\Modules\Workshop\Bundle\Application\Services\BundleResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BundleApplicableController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BundleResolutionService $resolution,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->can('workshop-bundles.view')) {
            abort(403);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        $vehicleId = $request->input('vehicle_id');
        $vehicleType = $request->input('vehicle_type');
        $search = $request->input('q');

        $vehicleIdStr = is_string($vehicleId) && Str::isUuid($vehicleId) ? $vehicleId : null;
        $vehicleTypeStr = is_string($vehicleType) && $vehicleType !== '' ? $vehicleType : null;
        $searchStr = is_string($search) && $search !== '' ? $search : null;

        $bundles = $this->resolution->applicableForVehicle(new ApplicableBundlesForVehicleQuery(
            tenant_id: $company->tenant_id,
            company_id: $companyId,
            platform_vehicle_id: $vehicleIdStr,
            vehicle_type: $vehicleTypeStr,
            search: $searchStr,
        ));

        return response()->json([
            'data' => array_map(fn ($b): ApplicableBundleData => ApplicableBundleData::fromModel($b), $bundles),
        ]);
    }
}
